<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

start_app_session();
$method = request_method();

if ($method === 'GET') {
    require_permission('manage_workers');
    try {
        recover_stale_jobs();
    } catch (Throwable $e) {
    }
    $items = db()->query(
        'SELECT id, name, token_hint, is_enabled, status, whatsapp_status, current_job_id,
                last_heartbeat_at, browser_name, os_name, python_version, worker_version, last_error,
                created_at, updated_at
         FROM workers ORDER BY id'
    )->fetchAll();
    json_ok('OK', ['items' => $items]);
}

if ($method === 'POST') {
    require_permission('manage_workers');
    csrf_verify_request();
    $body = body_or_post();
    $action = $body['action'] ?? 'register';

    if ($action === 'register') {
        $name = v_string($body['name'] ?? '', 1, 100, 'name');
        $token = generate_worker_token();
        $hash = hash_worker_token($token);
        $hint = substr($token, 0, 6) . '…' . substr($token, -4);
        try {
            db()->prepare(
                'INSERT INTO workers (name, token_hash, token_hint, is_enabled, status) VALUES (?, ?, ?, 1, ?)'
            )->execute([$name, $hash, $hint, 'offline']);
            $id = (int) db()->lastInsertId();
            audit_log('worker_registered', 'worker', $id);
            // Token shown once only
            json_ok('Worker registered. Copy the token now; it will not be shown again.', [
                'id' => $id,
                'token' => $token,
                'token_hint' => $hint,
            ], 201);
        } catch (PDOException $e) {
            json_fail('Worker name already exists', 409);
        }
    }

    if ($action === 'regenerate_token') {
        $id = v_int($body['id'] ?? null, 'id');
        $token = generate_worker_token();
        $hash = hash_worker_token($token);
        $hint = substr($token, 0, 6) . '…' . substr($token, -4);
        $stmt = db()->prepare('UPDATE workers SET token_hash=?, token_hint=?, updated_at=UTC_TIMESTAMP() WHERE id=?');
        $stmt->execute([$hash, $hint, $id]);
        if ($stmt->rowCount() === 0) {
            json_fail('Not found', 404);
        }
        audit_log('worker_token_regenerated', 'worker', $id);
        json_ok('Token regenerated. Copy now.', ['token' => $token, 'token_hint' => $hint]);
    }

    if ($action === 'set_enabled') {
        $id = v_int($body['id'] ?? null, 'id');
        $enabled = v_bool($body['is_enabled'] ?? false) ? 1 : 0;
        db()->prepare('UPDATE workers SET is_enabled=?, updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$enabled, $id]);
        audit_log($enabled ? 'worker_enabled' : 'worker_disabled', 'worker', $id);
        json_ok($enabled ? 'Enabled' : 'Disabled');
    }

    if ($action === 'delete') {
        $id = v_int($body['id'] ?? null, 'id');
        db()->prepare('DELETE FROM workers WHERE id=?')->execute([$id]);
        audit_log('worker_deleted', 'worker', $id);
        json_ok('Deleted');
    }

    json_fail('Unknown action', 400);
}

json_fail('Method not allowed', 405);
