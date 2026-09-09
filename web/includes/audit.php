<?php
declare(strict_types=1);

function audit_log(
    string $action,
    string $entityType = '',
    string|int|null $entityId = null,
    ?array $detail = null,
    ?int $userId = null,
    ?int $workerId = null
): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_id, worker_id, action, entity_type, entity_id, ip_address, user_agent, detail_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId ?? (current_user()['id'] ?? null),
            $workerId,
            $action,
            $entityType,
            $entityId !== null ? (string) $entityId : '',
            client_ip(),
            client_ua(),
            $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        app_log('error', 'audit_log_failed', ['error' => $e->getMessage(), 'action' => $action]);
    }
}

function message_log(
    string $eventType,
    string $status = '',
    string $detail = '',
    ?int $jobId = null,
    ?int $campaignId = null,
    ?int $workerId = null,
    string $phone = ''
): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO message_logs (job_id, campaign_id, worker_id, phone_e164, event_type, status, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $jobId,
            $campaignId,
            $workerId,
            substr($phone, 0, 20),
            $eventType,
            $status,
            substr($detail, 0, 500),
        ]);
    } catch (Throwable $e) {
        app_log('error', 'message_log_failed', ['error' => $e->getMessage()]);
    }
}
