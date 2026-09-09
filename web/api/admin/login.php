<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('POST');
start_app_session();
$body = body_or_post();
// CSRF optional on login (no session yet) — still regenerate after success

$username = v_string($body['username'] ?? '', 1, 64, 'username');
$password = (string) ($body['password'] ?? '');
if ($password === '') {
    json_fail('Password required', 422);
}

$result = login_user($username, $password);
if (!$result['ok']) {
    json_fail($result['message'], 401);
}

json_ok('Logged in', [
    'user' => [
        'id' => (int) $result['user']['id'],
        'username' => $result['user']['username'],
        'display_name' => $result['user']['display_name'],
        'role' => $result['user']['role'],
        'permissions' => user_permissions($result['user']),
    ],
    'csrf' => csrf_token(),
]);
