<?php
declare(strict_types=1);

/**
 * App settings only — NOT database credentials.
 * Database: edit web/includes/database.php only.
 */
return [
    'app_name' => 'WhatsApp Bot Control Panel',
    'app_env' => 'development',
    'base_url' => 'http://localhost/whatsapp_web_api/web',
    'timezone' => 'Asia/Kuala_Lumpur',

    'session' => [
        'name' => 'wa_bot_sess',
        'lifetime_minutes' => 480,
        'secure' => false, // true on HTTPS / cPanel
        'httponly' => true,
        'samesite' => 'Lax',
    ],

    'paths' => [
        'media' => dirname(__DIR__) . '/uploads/media',
        'logs' => dirname(__DIR__) . '/storage/logs',
    ],

    'media' => [
        'max_bytes' => 10 * 1024 * 1024,
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'],
        'allowed_mimes' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ],
    ],

    'worker_api_version' => '1.0.0',
];
