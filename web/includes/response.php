<?php
declare(strict_types=1);

function json_response(bool $success, string $message, mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(string $message = 'OK', mixed $data = null, int $status = 200): never
{
    json_response(true, $message, $data, $status);
}

function json_fail(string $message, int $status = 400, mixed $data = null): never
{
    json_response(false, $message, $data, $status);
}
