<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('GET');
$worker = require_worker();

json_ok('OK', [
    'worker' => [
        'id' => (int) $worker['id'],
        'name' => $worker['name'],
        'is_enabled' => (int) $worker['is_enabled'] === 1,
        'status' => $worker['status'],
        'whatsapp_status' => $worker['whatsapp_status'],
    ],
    'api_version' => app_config('worker_api_version'),
    'rate_limits' => rate_limit_status(),
    'server_time_utc' => now_utc()->format('Y-m-d H:i:s'),
]);
