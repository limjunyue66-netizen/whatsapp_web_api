<?php
declare(strict_types=1);

$configBase = require __DIR__ . '/config.php';
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $configBase = array_replace_recursive($configBase, $local);
    }
}

/** @var array<string,mixed> $CONFIG */
$CONFIG = $configBase;

date_default_timezone_set('UTC');

$isProd = ($CONFIG['app_env'] ?? 'development') === 'production';
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$logDir = $CONFIG['paths']['logs'] ?? (dirname(__DIR__) . '/storage/logs');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/php_error.log');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/template.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/campaign.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/worker_auth.php';

function app_config(?string $key = null, mixed $default = null): mixed
{
    global $CONFIG;
    if ($key === null) {
        return $CONFIG;
    }
    $parts = explode('.', $key);
    $val = $CONFIG;
    foreach ($parts as $p) {
        if (!is_array($val) || !array_key_exists($p, $val)) {
            return $default;
        }
        $val = $val[$p];
    }
    return $val;
}

function app_timezone(): string
{
    return (string) setting('app_timezone', app_config('timezone', 'Asia/Kuala_Lumpur'));
}

function now_utc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function utc_to_app(?string $utcDatetime): ?string
{
    if ($utcDatetime === null || $utcDatetime === '') {
        return null;
    }
    $dt = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone(app_timezone()))->format('Y-m-d H:i:s');
}

function app_to_utc(string $localDatetime): string
{
    $dt = new DateTimeImmutable($localDatetime, new DateTimeZone(app_timezone()));
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function app_log(string $level, string $message, array $context = []): void
{
    $dir = (string) app_config('paths.logs');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $safe = $context;
    foreach (['token', 'password', 'authorization', 'bearer'] as $secret) {
        unset($safe[$secret]);
    }
    $line = sprintf(
        "[%s] %s %s %s\n",
        now_utc()->format('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $safe ? json_encode($safe, JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function client_ua(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}
