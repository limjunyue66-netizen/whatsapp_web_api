<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

start_app_session();
$method = request_method();

if ($method === 'GET') {
    require_permission('manage_contacts');
    $q = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $per;

    $where = '1=1';
    $params = [];
    if ($q !== '') {
        $where .= ' AND (name LIKE ? OR phone_e164 LIKE ? OR company LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like];
    }

    $countStmt = db()->prepare("SELECT COUNT(*) FROM contacts WHERE $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT id, name, phone_e164, phone_raw, company, notes, is_active, created_at, updated_at
         FROM contacts WHERE $where ORDER BY id DESC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    json_ok('OK', ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $per]);
}

if ($method === 'POST') {
    require_permission('manage_contacts');
    csrf_verify_request();
    $body = body_or_post();
    $action = $body['action'] ?? 'create';

    if ($action === 'create' || $action === 'update') {
        $name = v_string($body['name'] ?? '', 1, 150, 'name');
        $norm = normalize_phone((string) ($body['phone'] ?? ''));
        if (!$norm['ok']) {
            json_fail($norm['error'], 422);
        }
        $company = v_optional_string($body['company'] ?? '', 150);
        $notes = v_optional_string($body['notes'] ?? '', 2000);
        $active = isset($body['is_active']) ? (v_bool($body['is_active']) ? 1 : 0) : 1;
        $user = current_user();

        if ($action === 'create') {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO contacts (name, phone_e164, phone_raw, company, notes, is_active, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$name, $norm['phone'], $norm['raw'] ?? '', $company, $notes, $active, $user['id']]);
                $id = (int) db()->lastInsertId();
                audit_log('contact_created', 'contact', $id);
                json_ok('Created', ['id' => $id], 201);
            } catch (PDOException $e) {
                if ((int) $e->getCode() === 23000) {
                    json_fail('Duplicate phone number', 409);
                }
                throw $e;
            }
        }

        $id = v_int($body['id'] ?? null, 'id');
        try {
            $stmt = db()->prepare(
                'UPDATE contacts SET name=?, phone_e164=?, phone_raw=?, company=?, notes=?, is_active=?, updated_at=UTC_TIMESTAMP()
                 WHERE id=?'
            );
            $stmt->execute([$name, $norm['phone'], $norm['raw'] ?? '', $company, $notes, $active, $id]);
            audit_log('contact_updated', 'contact', $id);
            json_ok('Updated', ['id' => $id]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                json_fail('Duplicate phone number', 409);
            }
            throw $e;
        }
    }

    if ($action === 'delete') {
        $id = v_int($body['id'] ?? null, 'id');
        db()->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
        audit_log('contact_deleted', 'contact', $id);
        json_ok('Deleted');
    }

    if ($action === 'import_csv') {
        if (empty($_FILES['file'])) {
            json_fail('CSV file required', 422);
        }
        $tmp = $_FILES['file']['tmp_name'];
        $fh = fopen($tmp, 'r');
        if (!$fh) {
            json_fail('Cannot read CSV', 400);
        }
        $header = fgetcsv($fh);
        if (!$header) {
            json_fail('Empty CSV', 422);
        }
        $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
        $required = ['name', 'phone'];
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                json_fail("Missing column: $col", 422);
            }
        }
        $idx = array_flip($header);
        $pdo = db();
        $pdo->beginTransaction();
        $ok = 0;
        $errors = [];
        $rowNum = 1;
        $user = current_user();
        $ins = $pdo->prepare(
            'INSERT INTO contacts (name, phone_e164, phone_raw, company, notes, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE name=VALUES(name), company=VALUES(company), notes=VALUES(notes), is_active=1'
        );
        while (($row = fgetcsv($fh)) !== false) {
            $rowNum++;
            if (count(array_filter($row, static fn($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $name = trim((string) ($row[$idx['name']] ?? ''));
            $phone = trim((string) ($row[$idx['phone']] ?? ''));
            $company = trim((string) ($row[$idx['company'] ?? -1] ?? ''));
            $notes = trim((string) ($row[$idx['notes'] ?? -1] ?? ''));
            if (mb_strlen($name) > 150 || mb_strlen($company) > 150 || mb_strlen($notes) > 2000) {
                $errors[] = "Row $rowNum: field too long";
                continue;
            }
            $norm = normalize_phone($phone);
            if (!$norm['ok'] || $name === '') {
                $errors[] = "Row $rowNum: " . ($norm['error'] ?? 'invalid');
                continue;
            }
            $ins->execute([$name, $norm['phone'], $norm['raw'] ?? '', $company, $notes, $user['id']]);
            $ok++;
        }
        fclose($fh);
        $pdo->commit();
        audit_log('contacts_imported', 'contact', null, ['imported' => $ok, 'errors' => count($errors)]);
        json_ok('Import finished', ['imported' => $ok, 'errors' => $errors]);
    }

    json_fail('Unknown action', 400);
}

json_fail('Method not allowed', 405);
