<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('GET');
require_login();

$pdo = db();
try {
    recover_stale_jobs();
    promote_due_scheduled_jobs();
} catch (Throwable $e) {
    // non-fatal for dashboard
}

$queue = (int) $pdo->query(
    "SELECT COUNT(*) FROM message_jobs WHERE status IN ('pending','scheduled')"
)->fetchColumn();
$processing = (int) $pdo->query("SELECT COUNT(*) FROM message_jobs WHERE status = 'processing'")->fetchColumn();
$sentToday = (int) $pdo->query(
    "SELECT COUNT(*) FROM message_jobs WHERE status = 'sent' AND sent_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
)->fetchColumn();
$failedToday = (int) $pdo->query(
    "SELECT COUNT(*) FROM message_jobs WHERE status = 'failed' AND updated_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
)->fetchColumn();

$workers = $pdo->query(
    'SELECT id, name, is_enabled, status, whatsapp_status, last_heartbeat_at, current_job_id,
            browser_name, worker_version, last_error
     FROM workers ORDER BY id'
)->fetchAll();

$campaigns = $pdo->query(
    "SELECT id, name, status, total_count, sent_count, failed_count, pending_count, processing_count, updated_at
     FROM campaigns WHERE status IN ('queued','running','paused') ORDER BY id DESC LIMIT 10"
)->fetchAll();

$recent = $pdo->query(
    'SELECT id, job_id, phone_e164, event_type, status, detail, created_at
     FROM message_logs ORDER BY id DESC LIMIT 20'
)->fetchAll();

json_ok('OK', [
    'stats' => [
        'queue' => $queue,
        'processing' => $processing,
        'sent_today' => $sentToday,
        'failed_today' => $failedToday,
    ],
    'rate_limits' => rate_limit_status(),
    'workers' => $workers,
    'active_campaigns' => $campaigns,
    'recent_activity' => $recent,
    'disclaimer' => setting('disclaimer', ''),
]);
