<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('POST');
$worker = require_worker();
$body = request_json();

$whatsappStatus = v_enum(
    $body['whatsapp_status'] ?? 'unknown',
    ['unknown', 'disconnected', 'qr_required', 'connected'],
    'whatsapp_status'
);
$status = v_enum(
    $body['status'] ?? 'online',
    ['offline', 'online', 'busy', 'error'],
    'status'
);

$browser = v_optional_string($body['browser_name'] ?? null, 80);
$os = v_optional_string($body['os_name'] ?? null, 120);
$py = v_optional_string($body['python_version'] ?? null, 40);
$ver = v_optional_string($body['worker_version'] ?? null, 40);
$err = v_optional_string($body['last_error'] ?? null, 500);
$currentJob = isset($body['current_job_id']) && $body['current_job_id'] !== '' && $body['current_job_id'] !== null
    ? v_int($body['current_job_id'], 'current_job_id')
    : null;

$stmt = db()->prepare(
    'UPDATE workers SET
        last_heartbeat_at = UTC_TIMESTAMP(),
        whatsapp_status = ?,
        status = ?,
        browser_name = ?,
        os_name = ?,
        python_version = ?,
        worker_version = ?,
        last_error = ?,
        current_job_id = ?,
        updated_at = UTC_TIMESTAMP()
     WHERE id = ?'
);
$stmt->execute([
    $whatsappStatus,
    $status,
    $browser !== '' ? $browser : null,
    $os !== '' ? $os : null,
    $py !== '' ? $py : null,
    $ver !== '' ? $ver : null,
    $err !== '' ? $err : null,
    $currentJob,
    $worker['id'],
]);

// Opportunistic maintenance
try {
    recover_stale_jobs();
    promote_due_scheduled_jobs();
} catch (Throwable $e) {
    app_log('error', 'heartbeat_maintenance_failed', ['error' => $e->getMessage()]);
}

json_ok('Heartbeat recorded', [
    'server_time_utc' => now_utc()->format('Y-m-d H:i:s'),
    'rate_limits' => rate_limit_status(),
    'api_version' => app_config('worker_api_version'),
]);
