<?php
declare(strict_types=1);

function bearer_token(): ?string
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $hdr, $m)) {
        return $m[1];
    }
    return null;
}

function hash_worker_token(string $token): string
{
    return hash('sha256', $token);
}

function generate_worker_token(): string
{
    return bin2hex(random_bytes(32));
}

function require_worker(): array
{
    $token = bearer_token();
    if ($token === null || strlen($token) < 32) {
        json_fail('Unauthorized', 401);
    }

    $hash = hash_worker_token($token);
    $stmt = db()->prepare('SELECT * FROM workers WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $worker = $stmt->fetch();
    if (!$worker) {
        json_fail('Unauthorized', 401);
    }
    if (!(int) $worker['is_enabled']) {
        json_fail('Worker disabled', 403);
    }
    return $worker;
}
