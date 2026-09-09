<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

start_app_session();
$method = request_method();

if ($method === 'GET') {
    require_permission('manage_media');
    if (isset($_GET['download'])) {
        $id = v_int($_GET['download'], 'id');
        $media = media_path_by_id($id);
        if (!$media) {
            json_fail('Not found', 404);
        }
        header('Content-Type: ' . $media['mime_type']);
        header('Content-Disposition: attachment; filename="' . basename($media['original_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($media['absolute_path']);
        exit;
    }
    $items = db()->query(
        'SELECT id, original_name, mime_type, extension, size_bytes, created_at FROM media_files ORDER BY id DESC LIMIT 200'
    )->fetchAll();
    json_ok('OK', ['items' => $items]);
}

if ($method === 'POST') {
    require_permission('manage_media');
    csrf_verify_request();
    $body = body_or_post();
    $action = $body['action'] ?? 'upload';
    $user = current_user();

    if ($action === 'upload') {
        if (empty($_FILES['file'])) {
            json_fail('file required', 422);
        }
        $out = media_store_upload($_FILES['file'], (int) $user['id']);
        if (!$out['ok']) {
            json_fail($out['message'], 422);
        }
        json_ok('Uploaded', $out, 201);
    }

    if ($action === 'delete') {
        $id = v_int($body['id'] ?? null, 'id');
        $media = media_path_by_id($id);
        db()->prepare('DELETE FROM media_files WHERE id=?')->execute([$id]);
        if ($media && is_file($media['absolute_path'])) {
            @unlink($media['absolute_path']);
        }
        audit_log('media_deleted', 'media', $id);
        json_ok('Deleted');
    }

    json_fail('Unknown action', 400);
}

json_fail('Method not allowed', 405);
