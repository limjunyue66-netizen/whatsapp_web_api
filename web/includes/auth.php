<?php
declare(strict_types=1);

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = app_config('session');
    session_name((string) $cfg['name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool) $cfg['secure'],
        'httponly' => (bool) $cfg['httponly'],
        'samesite' => (string) $cfg['samesite'],
    ]);
    session_start();
}

function current_user(): ?array
{
    start_app_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $lifetime = setting_int('session_lifetime_minutes', (int) app_config('session.lifetime_minutes', 480));
    $last = (int) ($_SESSION['last_activity'] ?? 0);
    if ($last > 0 && (time() - $last) > ($lifetime * 60)) {
        logout_user();
        return null;
    }
    $_SESSION['last_activity'] = time();

    static $cached = null;
    static $cachedId = null;
    $uid = (int) $_SESSION['user_id'];
    if ($cached !== null && $cachedId === $uid) {
        return $cached;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) {
        logout_user();
        return null;
    }
    $cached = $user;
    $cachedId = $uid;
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_fail('Unauthorized', 401);
    }
    return $user;
}

function require_login_page(): array
{
    start_app_session();
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function login_user(string $username, string $password): array
{
    $username = trim($username);
    $ip = client_ip();
    $maxAttempts = setting_int('login_max_attempts', 8);
    $lockMinutes = setting_int('login_lockout_minutes', 15);

    // IP throttle
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ? AND succeeded = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)'
    );
    $stmt->execute([$ip, $lockMinutes]);
    if ((int) $stmt->fetchColumn() >= $maxAttempts) {
        return ['ok' => false, 'message' => 'Too many failed attempts. Try again later.'];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $recordAttempt = static function (bool $ok) use ($username, $ip): void {
        $ins = db()->prepare('INSERT INTO login_attempts (username, ip_address, succeeded) VALUES (?, ?, ?)');
        $ins->execute([$username, $ip, $ok ? 1 : 0]);
    };

    if (!$user || !(int) $user['is_active']) {
        $recordAttempt(false);
        // Uniform message to reduce username enumeration
        return ['ok' => false, 'message' => 'Invalid username or password'];
    }

    if (!empty($user['locked_until'])) {
        $lockedUntil = new DateTimeImmutable($user['locked_until'], new DateTimeZone('UTC'));
        if ($lockedUntil > now_utc()) {
            $recordAttempt(false);
            return ['ok' => false, 'message' => 'Account temporarily locked. Try again later.'];
        }
    }

    if (!password_verify($password, $user['password_hash'])) {
        $recordAttempt(false);
        $fails = (int) $user['failed_login_count'] + 1;
        $locked = null;
        if ($fails >= $maxAttempts) {
            $locked = now_utc()->modify("+{$lockMinutes} minutes")->format('Y-m-d H:i:s');
            $fails = 0;
        }
        $upd = db()->prepare('UPDATE users SET failed_login_count = ?, locked_until = ? WHERE id = ?');
        $upd->execute([$fails, $locked, $user['id']]);
        audit_log('login_failed', 'user', $user['id'], ['username' => $username], null);
        return ['ok' => false, 'message' => 'Invalid username or password'];
    }

    $recordAttempt(true);
    start_app_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['_csrf']);

    $upd = db()->prepare(
        'UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP() WHERE id = ?'
    );
    $upd->execute([$user['id']]);

    audit_log('login', 'user', (int) $user['id'], null, (int) $user['id']);
    return ['ok' => true, 'user' => $user];
}

function logout_user(): void
{
    start_app_session();
    $uid = $_SESSION['user_id'] ?? null;
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
    if ($uid) {
        // cannot audit with session; write directly
        try {
            $stmt = db()->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([(int) $uid, 'logout', 'user', (string) $uid, client_ip(), client_ua()]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}
