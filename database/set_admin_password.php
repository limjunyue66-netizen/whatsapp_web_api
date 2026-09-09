<?php
declare(strict_types=1);

/**
 * Set admin password from CLI.
 * Usage: php database/set_admin_password.php "YourStrongPassword"
 */

require dirname(__DIR__) . '/web/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$password = $argv[1] ?? '';
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo = db();
$stmt = $pdo->prepare('UPDATE users SET password_hash = ?, failed_login_count = 0, locked_until = NULL WHERE username = ?');
$stmt->execute([$hash, 'admin']);

if ($stmt->rowCount() === 0) {
    $ins = $pdo->prepare(
        'INSERT INTO users (username, password_hash, display_name, role, permissions_json, is_active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $perms = json_encode([
        'manage_contacts','manage_campaigns','send_messages','manage_workers',
        'manage_settings','view_logs','manage_templates','manage_media',
    ]);
    $ins->execute(['admin', $hash, 'System Admin', 'admin', $perms]);
}

echo "Admin password updated.\n";
