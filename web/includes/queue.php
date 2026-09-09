<?php
declare(strict_types=1);

/**
 * Queue operations — atomic claim, ownership-checked reporting, stale recovery.
 * Delivery semantics: at-least-once job execution with external WhatsApp uncertainty.
 */

function recover_stale_jobs(): int
{
    $timeout = setting_int('stale_job_timeout_seconds', 300);
    $hbTimeout = setting_int('worker_heartbeat_timeout_seconds', 90);
    $pdo = db();
    $recovered = 0;

    $pdo->beginTransaction();
    try {
        // Jobs stuck in processing where claimed_at is old AND worker heartbeat is stale or missing
        $sql = "
            SELECT j.id, j.attempts, j.max_attempts, j.campaign_id, j.worker_id
            FROM message_jobs j
            LEFT JOIN workers w ON w.id = j.worker_id
            WHERE j.status = 'processing'
              AND j.claimed_at IS NOT NULL
              AND j.claimed_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)
              AND (
                    w.id IS NULL
                 OR w.last_heartbeat_at IS NULL
                 OR w.last_heartbeat_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND)
              )
            FOR UPDATE
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$timeout, $hbTimeout]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $job) {
            $attempts = (int) $job['attempts'];
            $max = (int) $job['max_attempts'];
            if ($attempts >= $max) {
                $upd = $pdo->prepare(
                    "UPDATE message_jobs
                     SET status = 'failed', last_error = ?, worker_id = NULL, updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND status = 'processing'"
                );
                $upd->execute(['Stale processing: max attempts reached (ambiguous delivery possible)', $job['id']]);
                message_log('stale_failed', 'failed', 'Max attempts after stale recovery', (int) $job['id'], $job['campaign_id'] ? (int) $job['campaign_id'] : null, $job['worker_id'] ? (int) $job['worker_id'] : null);
            } else {
                // Requeue — at-least-once: may duplicate WhatsApp message if prior send succeeded
                $upd = $pdo->prepare(
                    "UPDATE message_jobs
                     SET status = 'pending', worker_id = NULL, claimed_at = NULL,
                         last_error = ?, updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND status = 'processing'"
                );
                $upd->execute(['Requeued after stale processing (possible duplicate send)', $job['id']]);
                message_log('stale_requeue', 'pending', 'Stale job requeued', (int) $job['id'], $job['campaign_id'] ? (int) $job['campaign_id'] : null, $job['worker_id'] ? (int) $job['worker_id'] : null);
            }
            if (!empty($job['campaign_id'])) {
                campaign_recalc_stats((int) $job['campaign_id'], $pdo);
            }
            $recovered++;
        }

        // Mark offline workers with stale heartbeat
        $pdo->prepare(
            "UPDATE workers
             SET status = 'offline', current_job_id = NULL
             WHERE is_enabled = 1
               AND status <> 'offline'
               AND (last_heartbeat_at IS NULL OR last_heartbeat_at < (UTC_TIMESTAMP() - INTERVAL ? SECOND))"
        )->execute([$hbTimeout]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        app_log('error', 'recover_stale_jobs_failed', ['error' => $e->getMessage()]);
        throw $e;
    }

    return $recovered;
}

function promote_due_scheduled_jobs(): int
{
    $stmt = db()->prepare(
        "UPDATE message_jobs
         SET status = 'pending', updated_at = UTC_TIMESTAMP()
         WHERE status = 'scheduled'
           AND scheduled_at IS NOT NULL
           AND scheduled_at <= UTC_TIMESTAMP()"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Atomically claim one due job for a worker.
 * Also respects campaign pause/cancel and server-side hourly/daily rate limits.
 */
function claim_next_job(array $worker): ?array
{
    recover_stale_jobs();
    promote_due_scheduled_jobs();

    if (!rate_limit_allows_send()) {
        return null;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sql = "
            SELECT j.*
            FROM message_jobs j
            LEFT JOIN campaigns c ON c.id = j.campaign_id
            WHERE j.status = 'pending'
              AND (j.scheduled_at IS NULL OR j.scheduled_at <= UTC_TIMESTAMP())
              AND (j.campaign_id IS NULL OR c.status IN ('queued','running'))
            ORDER BY j.id ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        ";
        // MariaDB 10.4 may not support SKIP LOCKED — fallback without it
        try {
            $stmt = $pdo->query($sql);
            $job = $stmt->fetch();
        } catch (PDOException $e) {
            $stmt = $pdo->query(str_replace(' SKIP LOCKED', '', $sql));
            $job = $stmt->fetch();
        }

        if (!$job) {
            $pdo->commit();
            return null;
        }

        $maxAttempts = (int) $job['max_attempts'];
        $attempts = (int) $job['attempts'] + 1;

        $upd = $pdo->prepare(
            "UPDATE message_jobs
             SET status = 'processing',
                 worker_id = ?,
                 attempts = ?,
                 claimed_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP(),
                 last_error = NULL
             WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([(int) $worker['id'], $attempts, $job['id']]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }

        $pdo->prepare(
            "UPDATE workers SET status = 'busy', current_job_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([$job['id'], $worker['id']]);

        if (!empty($job['campaign_id'])) {
            $pdo->prepare(
                "UPDATE campaigns SET status = IF(status = 'queued', 'running', status),
                    started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                    updated_at = UTC_TIMESTAMP()
                 WHERE id = ?"
            )->execute([$job['campaign_id']]);
            campaign_recalc_stats((int) $job['campaign_id'], $pdo);
        }

        $pdo->commit();

        $job['status'] = 'processing';
        $job['worker_id'] = (int) $worker['id'];
        $job['attempts'] = $attempts;

        message_log('claimed', 'processing', 'Job claimed', (int) $job['id'], $job['campaign_id'] ? (int) $job['campaign_id'] : null, (int) $worker['id'], (string) $job['phone_e164']);
        audit_log('job_claimed', 'message_job', (int) $job['id'], null, null, (int) $worker['id']);

        // Attach media metadata if any
        if (!empty($job['media_id'])) {
            $m = $pdo->prepare('SELECT id, original_name, stored_name, mime_type, extension, size_bytes FROM media_files WHERE id = ?');
            $m->execute([$job['media_id']]);
            $job['media'] = $m->fetch() ?: null;
        } else {
            $job['media'] = null;
        }

        return $job;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        app_log('error', 'claim_job_failed', ['error' => $e->getMessage()]);
        throw $e;
    }
}

/**
 * Report job result with ownership and state checks. Idempotent for duplicate reports.
 */
function report_job_result(array $worker, int $jobId, string $result, string $error = ''): array
{
    $result = strtolower($result);
    if (!in_array($result, ['sent', 'failed'], true)) {
        return ['ok' => false, 'http' => 422, 'message' => 'result must be sent or failed'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM message_jobs WHERE id = ? FOR UPDATE');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            $pdo->rollBack();
            return ['ok' => false, 'http' => 404, 'message' => 'Job not found'];
        }

        // Idempotent: already terminal with same outcome
        if ($job['status'] === 'sent' && $result === 'sent') {
            $pdo->commit();
            return ['ok' => true, 'http' => 200, 'message' => 'Already recorded as sent', 'data' => ['idempotent' => true]];
        }
        if ($job['status'] === 'failed' && $result === 'failed' && (int) $job['attempts'] >= (int) $job['max_attempts']) {
            $pdo->commit();
            return ['ok' => true, 'http' => 200, 'message' => 'Already recorded as failed', 'data' => ['idempotent' => true]];
        }

        // Cancelled jobs must not become sent
        if ($job['status'] === 'cancelled') {
            $pdo->rollBack();
            return ['ok' => false, 'http' => 409, 'message' => 'Job is cancelled'];
        }

        if ($job['status'] !== 'processing') {
            $pdo->rollBack();
            return ['ok' => false, 'http' => 409, 'message' => 'Job is not processing'];
        }

        // Ownership enforcement
        if ((int) $job['worker_id'] !== (int) $worker['id']) {
            $pdo->rollBack();
            return ['ok' => false, 'http' => 403, 'message' => 'Worker does not own this job'];
        }

        if ($result === 'sent') {
            $pdo->prepare(
                "UPDATE message_jobs
                 SET status = 'sent', sent_at = UTC_TIMESTAMP(), last_error = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'processing' AND worker_id = ?"
            )->execute([$jobId, $worker['id']]);
            message_log('sent', 'sent', 'Reported sent', $jobId, $job['campaign_id'] ? (int) $job['campaign_id'] : null, (int) $worker['id'], (string) $job['phone_e164']);
            rate_limit_record_send();
        } else {
            $error = substr($error !== '' ? $error : 'Unknown failure', 0, 500);
            $attempts = (int) $job['attempts'];
            $max = (int) $job['max_attempts'];
            if ($attempts < $max) {
                $pdo->prepare(
                    "UPDATE message_jobs
                     SET status = 'pending', worker_id = NULL, claimed_at = NULL,
                         last_error = ?, updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND status = 'processing' AND worker_id = ?"
                )->execute([$error, $jobId, $worker['id']]);
                message_log('retry', 'pending', $error, $jobId, $job['campaign_id'] ? (int) $job['campaign_id'] : null, (int) $worker['id'], (string) $job['phone_e164']);
            } else {
                $pdo->prepare(
                    "UPDATE message_jobs
                     SET status = 'failed', last_error = ?, updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND status = 'processing' AND worker_id = ?"
                )->execute([$error, $jobId, $worker['id']]);
                message_log('failed', 'failed', $error, $jobId, $job['campaign_id'] ? (int) $job['campaign_id'] : null, (int) $worker['id'], (string) $job['phone_e164']);
            }
        }

        $pdo->prepare(
            "UPDATE workers SET status = 'online', current_job_id = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?"
        )->execute([$worker['id']]);

        if (!empty($job['campaign_id'])) {
            campaign_recalc_stats((int) $job['campaign_id'], $pdo);
            campaign_maybe_complete((int) $job['campaign_id'], $pdo);
        }

        $pdo->commit();
        return ['ok' => true, 'http' => 200, 'message' => 'Result recorded', 'data' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        app_log('error', 'report_job_result_failed', ['error' => $e->getMessage(), 'job_id' => $jobId]);
        return ['ok' => false, 'http' => 500, 'message' => 'Server error'];
    }
}

function create_jobs_for_recipients(
    array $recipients,
    string $messageBody,
    ?int $campaignId,
    ?int $mediaId,
    ?string $scheduledAtUtc,
    int $maxAttempts
): int {
    $pdo = db();
    $count = 0;
    $ins = $pdo->prepare(
        'INSERT INTO message_jobs
         (campaign_id, contact_id, phone_e164, recipient_name, message_body, media_id, status, scheduled_at, max_attempts, idempotency_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($recipients as $r) {
        $body = render_template($messageBody, [
            'name' => $r['name'] ?? '',
            'phone' => $r['phone_e164'] ?? '',
            'company' => $r['company'] ?? '',
        ]);
        $status = ($scheduledAtUtc !== null && $scheduledAtUtc > now_utc()->format('Y-m-d H:i:s'))
            ? 'scheduled'
            : 'pending';
        $idem = hash('sha256', implode('|', [
            (string) $campaignId,
            (string) ($r['contact_id'] ?? ''),
            $r['phone_e164'],
            $body,
            (string) $mediaId,
            (string) $scheduledAtUtc,
            (string) microtime(true),
            bin2hex(random_bytes(4)),
        ]));
        $ins->execute([
            $campaignId,
            $r['contact_id'] ?? null,
            $r['phone_e164'],
            $r['name'] ?? '',
            $body,
            $mediaId,
            $status,
            $scheduledAtUtc,
            $maxAttempts,
            $idem,
        ]);
        $count++;
    }
    return $count;
}
