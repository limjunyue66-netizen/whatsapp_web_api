<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

start_app_session();
$method = request_method();

$editable = [
    'default_country_code',
    'min_delay_seconds',
    'max_delay_seconds',
    'max_messages_per_batch',
    'max_messages_per_hour',
    'max_messages_per_day',
    'pause_between_batches_seconds',
    'max_retry_attempts',
    'worker_heartbeat_timeout_seconds',
    'stale_job_timeout_seconds',
    'media_max_bytes',
    'session_lifetime_minutes',
    'login_max_attempts',
    'login_lockout_minutes',
    'large_campaign_confirm_threshold',
    'app_name',
];

if ($method === 'GET') {
    require_permission('manage_settings');
    json_ok('OK', ['settings' => settings_all(), 'editable' => $editable]);
}

if ($method === 'POST') {
    require_permission('manage_settings');
    csrf_verify_request();
    $body = body_or_post();
    $settings = $body['settings'] ?? [];
    if (!is_array($settings)) {
        json_fail('settings must be object', 422);
    }
    foreach ($settings as $key => $value) {
        if (!in_array($key, $editable, true)) {
            continue;
        }
        setting_set((string) $key, (string) $value);
    }
    audit_log('settings_updated', 'system_settings', null, ['keys' => array_keys($settings)]);
    json_ok('Settings saved', ['settings' => settings_all()]);
}

json_fail('Method not allowed', 405);
