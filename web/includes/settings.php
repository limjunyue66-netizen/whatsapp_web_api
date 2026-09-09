<?php
declare(strict_types=1);

function &settings_cache(): array
{
    static $cache = [];
    static $loaded = false;
    if (!$loaded) {
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            app_log('error', 'settings_load_failed', ['error' => $e->getMessage()]);
        }
        $loaded = true;
    }
    return $cache;
}

function setting(string $key, mixed $default = null): mixed
{
    $cache = settings_cache();
    return $cache[$key] ?? $default;
}

function setting_int(string $key, int $default): int
{
    return (int) setting($key, $default);
}

function settings_all(): array
{
    $rows = db()->query('SELECT setting_key, setting_value, updated_at FROM system_settings ORDER BY setting_key')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

function setting_set(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
    $cache = &settings_cache();
    $cache[$key] = $value;
}
