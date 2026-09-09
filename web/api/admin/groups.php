<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

start_app_session();
$method = request_method();

if ($method === 'GET') {
    require_permission('manage_contacts');
    $groups = db()->query(
        'SELECT g.*, (SELECT COUNT(*) FROM contact_group_members m WHERE m.group_id = g.id) AS member_count
         FROM contact_groups g ORDER BY g.name'
    )->fetchAll();
    $groupId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $members = [];
    if ($groupId > 0) {
        $stmt = db()->prepare(
            'SELECT c.id, c.name, c.phone_e164, c.company
             FROM contact_group_members m JOIN contacts c ON c.id = m.contact_id
             WHERE m.group_id = ? ORDER BY c.name'
        );
        $stmt->execute([$groupId]);
        $members = $stmt->fetchAll();
    }
    json_ok('OK', ['groups' => $groups, 'members' => $members]);
}

if ($method === 'POST') {
    require_permission('manage_contacts');
    csrf_verify_request();
    $body = body_or_post();
    $action = $body['action'] ?? 'create';
    $user = current_user();

    if ($action === 'create') {
        $name = v_string($body['name'] ?? '', 1, 120, 'name');
        $desc = v_optional_string($body['description'] ?? '', 255);
        try {
            db()->prepare('INSERT INTO contact_groups (name, description, created_by) VALUES (?, ?, ?)')
                ->execute([$name, $desc, $user['id']]);
            $id = (int) db()->lastInsertId();
            audit_log('group_created', 'contact_group', $id);
            json_ok('Created', ['id' => $id], 201);
        } catch (PDOException $e) {
            json_fail('Duplicate group name', 409);
        }
    }

    if ($action === 'update') {
        $id = v_int($body['id'] ?? null, 'id');
        $name = v_string($body['name'] ?? '', 1, 120, 'name');
        $desc = v_optional_string($body['description'] ?? '', 255);
        db()->prepare('UPDATE contact_groups SET name=?, description=?, updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$name, $desc, $id]);
        audit_log('group_updated', 'contact_group', $id);
        json_ok('Updated');
    }

    if ($action === 'delete') {
        $id = v_int($body['id'] ?? null, 'id');
        db()->prepare('DELETE FROM contact_groups WHERE id=?')->execute([$id]);
        audit_log('group_deleted', 'contact_group', $id);
        json_ok('Deleted');
    }

    if ($action === 'set_members') {
        $id = v_int($body['id'] ?? null, 'id');
        $contactIds = $body['contact_ids'] ?? [];
        if (!is_array($contactIds)) {
            json_fail('contact_ids must be array', 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM contact_group_members WHERE group_id=?')->execute([$id]);
        $ins = $pdo->prepare('INSERT IGNORE INTO contact_group_members (group_id, contact_id) VALUES (?, ?)');
        foreach ($contactIds as $cid) {
            $ins->execute([$id, (int) $cid]);
        }
        $pdo->commit();
        audit_log('group_members_set', 'contact_group', $id, ['count' => count($contactIds)]);
        json_ok('Members updated');
    }

    json_fail('Unknown action', 400);
}

json_fail('Method not allowed', 405);
