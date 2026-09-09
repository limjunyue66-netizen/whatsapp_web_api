<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token = null): void
{
    $token = $token ?? ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $session = $_SESSION['_csrf'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($session) || !hash_equals($session, $token)) {
        json_fail('Invalid CSRF token', 403);
    }
}

function csrf_verify_request(): void
{
    $json = request_json();
    $token = $json['_csrf'] ?? ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null));
    csrf_verify(is_string($token) ? $token : null);
}
