<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function admin_header(string $title, array $user): void
{
    $app = e((string) setting('app_name', app_config('app_name')));
    $csrf = e(csrf_token());
    $name = e($user['display_name'] ?: $user['username']);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' — ' . $app . '</title>';
    echo '<link rel="stylesheet" href="../assets/css/app.css">';
    echo '<script src="../assets/js/app.js"></script>';
    echo '<meta name="csrf-token" content="' . $csrf . '">';
    echo '</head><body>';
    echo '<div class="shell">';
    echo '<aside class="sidebar"><div class="brand">' . $app . '</div><nav>';
    $links = [
        'dashboard.php' => 'Dashboard',
        'send.php' => 'Send Message',
        'contacts.php' => 'Contacts',
        'groups.php' => 'Groups',
        'templates.php' => 'Templates',
        'campaigns.php' => 'Campaigns',
        'media.php' => 'Media',
        'workers.php' => 'Workers',
        'logs.php' => 'Logs',
        'settings.php' => 'Settings',
    ];
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    foreach ($links as $href => $label) {
        $cls = $current === $href ? 'active' : '';
        echo '<a class="' . $cls . '" href="' . $href . '">' . e($label) . '</a>';
    }
    echo '</nav><div class="sidebar-foot"><div class="user">' . $name . '</div>';
    echo '<button type="button" id="logoutBtn" class="btn btn-ghost">Logout</button></div></aside>';
    echo '<main class="content"><header class="top"><h1>' . e($title) . '</h1></header>';
    echo '<div class="notice">Consent-based messaging only. Delivery is at-least-once via unofficial WhatsApp Web automation — not exactly-once.</div>';
}

function admin_footer(): void
{
    echo '</main></div></body></html>';
}
