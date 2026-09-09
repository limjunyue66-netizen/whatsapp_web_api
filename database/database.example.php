<?php
/**
 * Example database connection settings for this project.
 *
 * HOW TO USE
 * 1. Copy values into: web/includes/config.local.php
 *    (there is also web/includes/config.local.php.example)
 * 2. Never commit real passwords or production hosts.
 * 3. Import schema first (cPanel: phpMyAdmin → select DB → Import install.sql):
 *      mysql -u USER -p DBNAME < database/install.sql
 *      php database/set_admin_password.php "YourStrongPassword"
 */

declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'whatsapp_bot',
        'user' => 'root',
        'pass' => 'YOUR_MYSQL_PASSWORD',
        'charset' => 'utf8mb4',
    ],
];
