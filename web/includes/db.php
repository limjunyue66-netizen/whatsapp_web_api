<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = app_config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        (int) $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        app_log('error', 'db_connect_failed', [
            'code' => $e->getCode(),
            'sqlstate' => $e->errorInfo[0] ?? null,
            'driver_code' => $e->errorInfo[1] ?? null,
            // Never log password; include host/name/user for cPanel debugging
            'host' => $cfg['host'] ?? '',
            'name' => $cfg['name'] ?? '',
            'user' => $cfg['user'] ?? '',
            'hint' => $e->getMessage(),
        ]);
        $msg = 'Database unavailable. Check web/includes/database.php '
            . '(host/name/user/pass) and import database/install.sql. '
            . 'On cPanel: delete config.local.php if present, set host=localhost, '
            . 'and ensure the DB user is added to the database.';
        if (function_exists('json_fail')) {
            $isApi = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
            $wantsJson = $isApi
                || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
            if ($wantsJson) {
                json_fail($msg, 503);
            }
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg . "\n";
        if ((app_config('app_env') ?? '') !== 'production') {
            echo 'Detail: ' . $e->getMessage() . "\n";
        }
        exit;
    }
    return $pdo;
}
