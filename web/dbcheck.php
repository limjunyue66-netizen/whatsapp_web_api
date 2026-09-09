<?php
/**
 * Temporary cPanel DB check — DELETE this file after it works.
 * Open: https://YOUR-DOMAIN/whatsapp_web_api/web/dbcheck.php
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/includes/database.php';
if (!is_file($file)) {
    echo "MISSING: web/includes/database.php\n";
    echo "Upload/copy database.php.example to database.php and set name/user/pass.\n";
    exit;
}

$db = require $file;
if (isset($db['db']) && is_array($db['db'])) {
    $db = $db['db'];
}

$host = (string) ($db['host'] ?? '');
$port = (int) ($db['port'] ?? 3306);
$name = (string) ($db['name'] ?? '');
$user = (string) ($db['user'] ?? '');
$pass = (string) ($db['pass'] ?? '');
$charset = (string) ($db['charset'] ?? 'utf8mb4');

echo "Using host={$host} port={$port} name={$name} user={$user} pass_len=" . strlen($pass) . "\n";

$local = __DIR__ . '/includes/config.local.php';
echo 'config.local.php: ' . (is_file($local) ? "EXISTS (db block is now ignored by app)\n" : "absent (ok)\n");

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "CONNECT_OK\n";
    echo 'tables=' . count($tables) . "\n";
    if (count($tables) === 0) {
        echo "WARNING: database is empty — import database/install.sql in phpMyAdmin.\n";
    } else {
        echo "OK: import looks present. Delete web/dbcheck.php now.\n";
    }
} catch (Throwable $e) {
    echo "CONNECT_FAIL\n";
    echo $e->getMessage() . "\n\n";
    echo "cPanel checklist:\n";
    echo "1) MySQL Databases: user is ADDED to this database\n";
    echo "2) password exact match (copy from cPanel)\n";
    echo "3) host=localhost\n";
    echo "4) phpMyAdmin selected THIS database before Import install.sql\n";
}
