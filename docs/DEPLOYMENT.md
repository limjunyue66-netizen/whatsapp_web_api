# Deployment

## 1. Database

MySQL 8+ must be running. Set credentials in **`web/includes/database.php` only** (copy from `database.php.example`).

```bat
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p -e "CREATE DATABASE IF NOT EXISTS whatsapp_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p whatsapp_bot < database\install.sql
php database\set_admin_password.php "YourStrongPassword"
```

**cPanel:** MySQL Databases → create DB → phpMyAdmin → select DB → Import → `database/install.sql` only.

Default login: `admin` / password you set above.

## 2. Web (Apache / XAMPP)

- Document root should allow `http://localhost/whatsapp_web_api/web/`
- Ensure `mod_rewrite` enabled (Authorization header passthrough in `web/.htaccess`)
- `web/uploads/media` and `web/storage/logs` blocked from direct listing/download via `.htaccess`
- Production: HTTPS, `app_env=production`, `session.secure=true`, strong DB password, disable PHP `display_errors`

## 3. Worker (Windows)

1. Install Python 3.12+
2. `worker\scripts\1_INSTALL.bat`
3. Create/edit `worker\config.ini` with `api_base_url` and `worker_token` from Admin → Workers
4. `2_LINK_WHATSAPP.bat` — scan QR (do not reload aggressively)
5. `3_START_WORKER.bat` — process queue

## 4. Smoke test

1. Login to admin
2. Add a consenting contact
3. Send Message
4. Confirm worker heartbeat + WhatsApp connected
5. Confirm job → sent in Logs

## 5. Production hardening checklist

- [ ] Change admin password
- [ ] HTTPS + secure cookies
- [ ] Rotate worker tokens
- [ ] Restrict admin by network / VPN
- [ ] Database backups
- [ ] Log retention
- [ ] No committed secrets (`config.ini`, `database.php`)
