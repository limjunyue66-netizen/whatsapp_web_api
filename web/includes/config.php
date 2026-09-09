<?php
declare(strict_types=1);

/**
 * Application configuration.
 * Copy values into config.local.php to override without committing secrets.
 */

return [
    'app_name' => 'WhatsApp Bot Control Panel',
    'app_env' => 'development', // development | production
    'base_url' => 'http://localhost/whatsapp_web_api/web',
    'timezone' => 'Asia/Kuala_Lumpur',

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'whatsapp_bot',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name' => 'wa_bot_sess',
        'lifetime_minutes' => 480,
        'secure' => false, // set true behind HTTPS in production
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
