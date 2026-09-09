<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

start_app_session();
$method = request_method();

if ($method === 'GET') {
    require_permission('manage_templates');
    if (isset($_GET['preview'])) {
        $body = (string) ($_GET['body'] ?? '');
        $preview = render_template($body, [
            'name' => (string) ($_GET['name'] ?? 'Ali'),
            'phone' => (string) ($_GET['phone'] ?? '60123456789'),
            'company' => (string) ($_GET['company'] ?? 'Acme'),
        ]);
        json_ok('OK', [
            'preview' => $preview,
            'unsupported' => template_unsupported_vars($body),
            'supported' => TEMPLATE_VARS,
        ]);
    }
    $items = db()->query('SELECT * FROM message_templates ORDER BY id DESC')->fetchAll();
    json_ok('OK', ['items' => $items, 'supported_vars' => TEMPLATE_VARS]);
}

if ($method === 'POST') {
    require_permission('manage_templates');
    csrf_verify_request();
    $body = body_or_post();
    $action = $body['action'] ?? 'create';
    $user = current_user();

    if ($action === 'create' || $action === 'update') {
        $name = v_string($body['name'] ?? '', 1, 120, 'name');
        $text = v_string($body['body'] ?? '', 1, 4000, 'body');
        $unsupported = template_unsupported_vars($text);
        if ($action === 'create') {
            db()->prepare('INSERT INTO message_templates (name, body, created_by) VALUES (?, ?, ?)')
                ->execute([$name, $text, $user['id']]);
            $id = (int) db()->lastInsertId();
            audit_log('template_created', 'template', $id);
            json_ok('Created', ['id' => $id, 'unsupported' => $unsupported], 201);
        }
        $id = v_int($body['id'] ?? null, 'id');
        db()->prepare('UPDATE message_templates SET name=?, body=?, updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$name, $text, $id]);
        audit_log('template_updated', 'template', $id);
        json_ok('Updated', ['unsupported' => $unsupported]);
    }

    if ($action === 'delete') {
        $id = v_int($body['id'] ?? null, 'id');
        db()->prepare('DELETE FROM message_templates WHERE id=?')->execute([$id]);
        audit_log('template_deleted', 'template', $id);
        json_ok('Deleted');
    }

    json_fail('Unknown action', 400);
}

json_fail('Method not allowed', 405);
