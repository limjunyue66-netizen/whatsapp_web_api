<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('GET');
start_app_session();
require_permission('view_logs');

$type = v_enum($_GET['type'] ?? 'message', ['message', 'audit'], 'type');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $per;

if ($type === 'message') {
    $total = (int) db()->query('SELECT COUNT(*) FROM message_logs')->fetchColumn();
    $stmt = db()->query(
        "SELECT * FROM message_logs ORDER BY id DESC LIMIT $per OFFSET $offset"
    );
    json_ok('OK', ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page]);
}

$total = (int) db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$stmt = db()->query(
    "SELECT id, user_id, worker_id, action, entity_type, entity_id, ip_address, created_at
     FROM audit_logs ORDER BY id DESC LIMIT $per OFFSET $offset"
);
json_ok('OK', ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page]);
