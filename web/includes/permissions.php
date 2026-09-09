<?php
declare(strict_types=1);

const ALL_PERMISSIONS = [
    'manage_contacts',
    'manage_campaigns',
    'send_messages',
    'manage_workers',
    'manage_settings',
    'view_logs',
    'manage_templates',
    'manage_media',
];

function user_permissions(array $user): array
{
    if (($user['role'] ?? '') === 'admin') {
        return ALL_PERMISSIONS;
    }
    $raw = $user['permissions_json'] ?? '[]';
    if (is_array($raw)) {
        return array_values(array_intersect($raw, ALL_PERMISSIONS));
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_intersect($decoded, ALL_PERMISSIONS));
}

function user_can(array $user, string $permission): bool
{
    return in_array($permission, user_permissions($user), true);
}

function require_permission(string $permission): void
{
    $user = require_login();
    if (!user_can($user, $permission)) {
        json_fail('Forbidden', 403);
    }
}
