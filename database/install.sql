-- WhatsApp Bot Control Panel — ONE SQL for cPanel / phpMyAdmin
-- MySQL 8+ or MariaDB 10.4+
--
-- How to import on cPanel:
--   1. Create an empty database + user in cPanel → MySQL Databases
--   2. Open phpMyAdmin → select THAT database
--   3. Import this file only (install.sql)
--
-- Do NOT use CREATE DATABASE here — shared hosting already created the DB.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL DEFAULT '',
  role ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  permissions_json JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_hint VARCHAR(12) NOT NULL DEFAULT '',
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('offline','online','busy','error') NOT NULL DEFAULT 'offline',
  whatsapp_status ENUM('unknown','disconnected','qr_required','connected') NOT NULL DEFAULT 'unknown',
  current_job_id BIGINT UNSIGNED NULL,
  last_heartbeat_at DATETIME NULL,
  browser_name VARCHAR(80) NULL,
  os_name VARCHAR(120) NULL,
  python_version VARCHAR(40) NULL,
  worker_version VARCHAR(40) NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_workers_name (name),
  KEY idx_workers_heartbeat (last_heartbeat_at),
  KEY idx_workers_enabled_status (is_enabled, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone_e164 VARCHAR(20) NOT NULL,
  phone_raw VARCHAR(40) NOT NULL DEFAULT '',
  company VARCHAR(150) NOT NULL DEFAULT '',
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contacts_phone (phone_e164),
  KEY idx_contacts_active_name (is_active, name),
  CONSTRAINT fk_contacts_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact_groups_name (name),
  CONSTRAINT fk_groups_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_group_members (
  group_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, contact_id),
  CONSTRAINT fk_cgm_group FOREIGN KEY (group_id) REFERENCES contact_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_cgm_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_templates_name (name),
  CONSTRAINT fk_templates_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(64) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  extension VARCHAR(16) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_media_stored (stored_name),
  KEY idx_media_sha (sha256),
  CONSTRAINT fk_media_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  message_body TEXT NOT NULL,
  template_id INT UNSIGNED NULL,
  media_id INT UNSIGNED NULL,
  status ENUM('draft','queued','running','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL COMMENT 'UTC',
  total_count INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  pending_count INT UNSIGNED NOT NULL DEFAULT 0,
  processing_count INT UNSIGNED NOT NULL DEFAULT 0,
  cancelled_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  KEY idx_campaigns_status (status),
  KEY idx_campaigns_scheduled (scheduled_at),
  CONSTRAINT fk_campaigns_template FOREIGN KEY (template_id) REFERENCES message_templates(id) ON DELETE SET NULL,
  CONSTRAINT fk_campaigns_media FOREIGN KEY (media_id) REFERENCES media_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_campaigns_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NULL,
  contact_id INT UNSIGNED NULL,
  phone_e164 VARCHAR(20) NOT NULL,
  recipient_name VARCHAR(150) NOT NULL DEFAULT '',
  message_body TEXT NOT NULL,
  media_id INT UNSIGNED NULL,
  status ENUM('pending','scheduled','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  scheduled_at DATETIME NULL COMMENT 'UTC',
  worker_id INT UNSIGNED NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
  claimed_at DATETIME NULL,
  sent_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  idempotency_key VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_jobs_claim (status, scheduled_at, id),
  KEY idx_jobs_worker (worker_id, status),
  KEY idx_jobs_campaign (campaign_id, status),
  KEY idx_jobs_stale (status, claimed_at),
  UNIQUE KEY uq_jobs_idempotency (idempotency_key),
  CONSTRAINT fk_jobs_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_media FOREIGN KEY (media_id) REFERENCES media_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NULL,
  campaign_id INT UNSIGNED NULL,
  worker_id INT UNSIGNED NULL,
  phone_e164 VARCHAR(20) NOT NULL DEFAULT '',
  event_type VARCHAR(40) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT '',
  detail VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_msg_logs_created (created_at),
  KEY idx_msg_logs_job (job_id),
  KEY idx_msg_logs_campaign (campaign_id),
  CONSTRAINT fk_msg_logs_job FOREIGN KEY (job_id) REFERENCES message_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  worker_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NOT NULL DEFAULT '',
  entity_id VARCHAR(40) NOT NULL DEFAULT '',
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action),
  KEY idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_ip_time (ip_address, created_at),
  KEY idx_login_user_time (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin user (change password after first login)
-- Temporary hash is a placeholder — set a real password with:
--   php database/set_admin_password.php "YourStrongPassword"
INSERT INTO users (username, password_hash, display_name, role, permissions_json, is_active)
VALUES (
  'admin',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'System Admin',
  'admin',
  '["manage_contacts","manage_campaigns","send_messages","manage_workers","manage_settings","view_logs","manage_templates","manage_media"]',
  1
)
ON DUPLICATE KEY UPDATE username = username;

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
