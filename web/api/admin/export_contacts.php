<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('GET');
start_app_session();
require_permission('manage_contacts');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="contacts_export.csv"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fputcsv($out, ['name', 'phone', 'company', 'notes', 'is_active']);
$stmt = db()->query('SELECT name, phone_e164, company, notes, is_active FROM contacts ORDER BY id');
while ($row = $stmt->fetch()) {
    fputcsv($out, [
        csv_sanitize_cell((string) $row['name']),
        csv_sanitize_cell((string) $row['phone_e164']),
        csv_sanitize_cell((string) $row['company']),
        csv_sanitize_cell((string) ($row['notes'] ?? '')),
        (string) $row['is_active'],
    ]);
}
fclose($out);
exit;
