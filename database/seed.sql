-- Default seed data. Password for admin: Admin@12345 (change immediately)
USE whatsapp_bot;

INSERT INTO users (username, password_hash, display_name, role, permissions_json, is_active)
VALUES (
  'admin',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'System Admin',
  'admin',
  JSON_ARRAY(
    'manage_contacts','manage_campaigns','send_messages','manage_workers',
    'manage_settings','view_logs','manage_templates','manage_media'
  ),
  1
)
ON DUPLICATE KEY UPDATE username = username;

-- Note: The hash above is Laravel's "password" demo hash.
-- After install, run: php database/set_admin_password.php Admin@12345

INSERT INTO system_settings (setting_key, setting_value) VALUES
('app_timezone', 'Asia/Kuala_Lumpur'),
('default_country_code', '60'),
('min_delay_seconds', '3'),
('max_delay_seconds', '8'),
('max_messages_per_batch', '20'),
('max_messages_per_hour', '60'),
('max_messages_per_day', '400'),
('pause_between_batches_seconds', '60'),
('max_retry_attempts', '3'),
('worker_heartbeat_timeout_seconds', '90'),
('stale_job_timeout_seconds', '300'),
('media_max_bytes', '10485760'),
('session_lifetime_minutes', '480'),
('login_max_attempts', '8'),
('login_lockout_minutes', '15'),
('large_campaign_confirm_threshold', '50'),
('app_name', 'WhatsApp Bot Control Panel'),
('disclaimer', 'Consent-based messaging only. Unofficial WhatsApp Web automation. At-least-once delivery; not exactly-once.')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
