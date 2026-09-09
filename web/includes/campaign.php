<?php
declare(strict_types=1);

const CAMPAIGN_TRANSITIONS = [
    'draft' => ['queued', 'cancelled'],
    'queued' => ['running', 'paused', 'cancelled'],
    'running' => ['paused', 'completed', 'cancelled'],
    'paused' => ['queued', 'cancelled'],
    'completed' => [],
    'cancelled' => [],
];

function campaign_can_transition(string $from, string $to): bool
{
    return in_array($to, CAMPAIGN_TRANSITIONS[$from] ?? [], true);
}

function campaign_recalc_stats(int $campaignId, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_count,
            SUM(status = 'sent') AS sent_count,
            SUM(status = 'failed') AS failed_count,
            SUM(status IN ('pending','scheduled')) AS pending_count,
            SUM(status = 'processing') AS processing_count,
            SUM(status = 'cancelled') AS cancelled_count
         FROM message_jobs WHERE campaign_id = ?"
    );
    $stmt->execute([$campaignId]);
    $s = $stmt->fetch() ?: [];
    $upd = $pdo->prepare(
        'UPDATE campaigns SET
            total_count = ?, sent_count = ?, failed_count = ?, pending_count = ?,
            processing_count = ?, cancelled_count = ?, updated_at = UTC_TIMESTAMP()
         WHERE id = ?'
    );
    $upd->execute([
        (int) ($s['total_count'] ?? 0),
        (int) ($s['sent_count'] ?? 0),
        (int) ($s['failed_count'] ?? 0),
        (int) ($s['pending_count'] ?? 0),
        (int) ($s['processing_count'] ?? 0),
        (int) ($s['cancelled_count'] ?? 0),
        $campaignId,
    ]);
}

function campaign_maybe_complete(int $campaignId, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $stmt = $pdo->prepare('SELECT status FROM campaigns WHERE id = ? FOR UPDATE');
    $stmt->execute([$campaignId]);
    $camp = $stmt->fetch();
    if (!$camp || !in_array($camp['status'], ['queued', 'running'], true)) {
        return;
    }
    $open = $pdo->prepare(
        "SELECT COUNT(*) FROM message_jobs
         WHERE campaign_id = ? AND status IN ('pending','scheduled','processing')"
    );
    $open->execute([$campaignId]);
    if ((int) $open->fetchColumn() === 0) {
        $pdo->prepare(
            "UPDATE campaigns SET status = 'completed', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status IN ('queued','running')"
        )->execute([$campaignId]);
    }
}

function campaign_pause(int $campaignId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ? FOR UPDATE');
        $stmt->execute([$campaignId]);
        $c = $stmt->fetch();
        if (!$c) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Not found', 'http' => 404];
        }
        if (!campaign_can_transition($c['status'], 'paused')) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Invalid transition', 'http' => 409];
        }
        $pdo->prepare("UPDATE campaigns SET status = 'paused', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$campaignId]);
        $pdo->commit();
        audit_log('campaign_paused', 'campaign', $campaignId);
        return ['ok' => true, 'message' => 'Paused', 'http' => 200];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Server error', 'http' => 500];
    }
}

function campaign_resume(int $campaignId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ? FOR UPDATE');
        $stmt->execute([$campaignId]);
        $c = $stmt->fetch();
        if (!$c) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Not found', 'http' => 404];
        }
        if (!campaign_can_transition($c['status'], 'queued')) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Invalid transition', 'http' => 409];
        }
        $pdo->prepare("UPDATE campaigns SET status = 'queued', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$campaignId]);
        $pdo->commit();
        audit_log('campaign_resumed', 'campaign', $campaignId);
        return ['ok' => true, 'message' => 'Resumed', 'http' => 200];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Server error', 'http' => 500];
    }
}

function campaign_cancel(int $campaignId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ? FOR UPDATE');
        $stmt->execute([$campaignId]);
        $c = $stmt->fetch();
        if (!$c) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Not found', 'http' => 404];
        }
        if (!campaign_can_transition($c['status'], 'cancelled')) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Invalid transition', 'http' => 409];
        }
        $pdo->prepare("UPDATE campaigns SET status = 'cancelled', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$campaignId]);
        // Cancel jobs that have not started sending
        $pdo->prepare(
            "UPDATE message_jobs SET status = 'cancelled', updated_at = UTC_TIMESTAMP()
             WHERE campaign_id = ? AND status IN ('pending','scheduled')"
        )->execute([$campaignId]);
        // processing jobs: leave as processing; worker report will conflict or complete
        campaign_recalc_stats($campaignId, $pdo);
        $pdo->commit();
        audit_log('campaign_cancelled', 'campaign', $campaignId);
        return ['ok' => true, 'message' => 'Cancelled', 'http' => 200];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Server error', 'http' => 500];
    }
}

function resolve_recipients(array $contactIds, array $groupIds): array
{
    $pdo = db();
    $map = [];

    if ($contactIds) {
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id AS contact_id, name, phone_e164, company FROM contacts
             WHERE is_active = 1 AND id IN ($placeholders)"
        );
        $stmt->execute(array_map('intval', $contactIds));
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['phone_e164']] = $row;
        }
    }

    if ($groupIds) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT c.id AS contact_id, c.name, c.phone_e164, c.company
             FROM contact_group_members m
             JOIN contacts c ON c.id = m.contact_id
             WHERE c.is_active = 1 AND m.group_id IN ($placeholders)"
        );
        $stmt->execute(array_map('intval', $groupIds));
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['phone_e164']] = $row;
        }
    }

    return array_values($map);
}
