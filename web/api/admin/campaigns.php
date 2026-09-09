<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

start_app_session();
$method = request_method();

if ($method === 'GET') {
    require_permission('manage_campaigns');
    if (isset($_GET['id'])) {
        $id = v_int($_GET['id'], 'id');
        $stmt = db()->prepare('SELECT * FROM campaigns WHERE id=?');
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if (!$c) {
            json_fail('Not found', 404);
        }
        campaign_recalc_stats($id);
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        $jobs = db()->prepare(
            'SELECT id, phone_e164, recipient_name, status, attempts, last_error, sent_at, claimed_at, worker_id
             FROM message_jobs WHERE campaign_id=? ORDER BY id DESC LIMIT 200'
        );
        $jobs->execute([$id]);
        json_ok('OK', ['campaign' => $c, 'jobs' => $jobs->fetchAll()]);
    }
    $items = db()->query('SELECT * FROM campaigns ORDER BY id DESC LIMIT 100')->fetchAll();
    json_ok('OK', ['items' => $items]);
}

if ($method === 'POST') {
    $body = body_or_post();
    $action = $body['action'] ?? 'create';

    if ($action === 'create') {
        require_permission('send_messages');
        csrf_verify_request();
        $user = current_user();

        $name = v_string($body['name'] ?? '', 1, 150, 'name');
        $templateId = v_optional_int($body['template_id'] ?? null);
        $mediaId = v_optional_int($body['media_id'] ?? null);
        $message = (string) ($body['message_body'] ?? '');
        $contactIds = $body['contact_ids'] ?? [];
        $groupIds = $body['group_ids'] ?? [];
        $confirmLarge = v_bool($body['confirm_large'] ?? false);

        if (!is_array($contactIds) || !is_array($groupIds)) {
            json_fail('Invalid recipient lists', 422);
        }

        if ($templateId) {
            $t = db()->prepare('SELECT body FROM message_templates WHERE id=?');
            $t->execute([$templateId]);
            $tpl = $t->fetch();
            if (!$tpl) {
                json_fail('Template not found', 404);
            }
            if (trim($message) === '') {
                $message = $tpl['body'];
            }
        }
        $message = v_string($message, 1, 4000, 'message_body');

        if ($mediaId) {
            if (!media_path_by_id($mediaId)) {
                json_fail('Media not found', 404);
            }
        }

        $localSchedule = v_datetime_local($body['scheduled_at'] ?? null, 'scheduled_at');
        $scheduledUtc = $localSchedule ? app_to_utc($localSchedule) : null;

        $recipients = resolve_recipients(
            array_map('intval', $contactIds),
            array_map('intval', $groupIds)
        );
        if (!$recipients) {
            json_fail('No active recipients', 422);
        }

        $threshold = setting_int('large_campaign_confirm_threshold', 50);
        if (count($recipients) >= $threshold && !$confirmLarge) {
            json_fail('Large campaign confirmation required', 422, [
                'requires_confirmation' => true,
                'recipient_count' => count($recipients),
                'threshold' => $threshold,
            ]);
        }

        $maxAttempts = setting_int('max_retry_attempts', 3);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO campaigns (name, message_body, template_id, media_id, status, scheduled_at, created_by, total_count, pending_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $name,
                $message,
                $templateId,
                $mediaId,
                'queued',
                $scheduledUtc,
                $user['id'],
                count($recipients),
                count($recipients),
            ]);
            $campaignId = (int) $pdo->lastInsertId();
            $created = create_jobs_for_recipients($recipients, $message, $campaignId, $mediaId, $scheduledUtc, $maxAttempts);
            campaign_recalc_stats($campaignId, $pdo);
            $pdo->commit();
            audit_log('campaign_created', 'campaign', $campaignId, ['jobs' => $created]);
            json_ok('Campaign queued', ['campaign_id' => $campaignId, 'jobs' => $created], 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('error', 'campaign_create_failed', ['error' => $e->getMessage()]);
            json_fail('Failed to create campaign', 500);
        }
    }

    if ($action === 'pause') {
        require_permission('manage_campaigns');
        csrf_verify_request();
        $id = v_int($body['id'] ?? null, 'id');
        $out = campaign_pause($id);
        if (!$out['ok']) {
            json_fail($out['message'], $out['http']);
        }
        json_ok($out['message']);
    }

    if ($action === 'resume') {
        require_permission('manage_campaigns');
        csrf_verify_request();
        $id = v_int($body['id'] ?? null, 'id');
        $out = campaign_resume($id);
        if (!$out['ok']) {
            json_fail($out['message'], $out['http']);
        }
        json_ok($out['message']);
    }

    if ($action === 'cancel') {
        require_permission('manage_campaigns');
        csrf_verify_request();
        $id = v_int($body['id'] ?? null, 'id');
        $out = campaign_cancel($id);
        if (!$out['ok']) {
            json_fail($out['message'], $out['http']);
        }
        json_ok($out['message']);
    }

    json_fail('Unknown action', 400);
}

json_fail('Method not allowed', 405);
