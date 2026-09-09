<?php
/**
 * Temporary DB check — DELETE after CONNECT_OK.
 * Open: /whatsapp_web_api/web/dbcheck.php
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/includes/database.php';
if (!is_file($file)) {
    echo "MISSING web/includes/database.php\n";
    exit;
}

$db = require $file;
$host = (string) ($db['host'] ?? '');
$port = (int) ($db['port'] ?? 3306);
$name = (string) ($db['name'] ?? '');
$user = (string) ($db['user'] ?? '');
$pass = (string) ($db['pass'] ?? '');
$charset = (string) ($db['charset'] ?? 'utf8mb4');

echo "host={$host}\nname={$name}\nuser={$user}\npass_len=" . strlen($pass) . "\n\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $n = count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    echo "CONNECT_OK\ntables={$n}\n";
    echo $n === 0
        ? "WARNING: empty DB — import database/install.sql\n"
        : "Delete this dbcheck.php file now.\n";
} catch (Throwable $e) {
    echo "CONNECT_FAIL\n" . $e->getMessage() . "\n";
    echo "\nFix in cPanel:\n";
    echo "1) MySQL user ADDED to this database\n";
    echo "2) password exact\n";
    echo "3) host=localhost\n";
    echo "4) import install.sql into THIS database\n";
}
