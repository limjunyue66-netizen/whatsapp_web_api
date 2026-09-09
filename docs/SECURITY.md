# Security notes before publishing

## Never commit

- `web/includes/database.php` (DB password) — **only** DB connection file
- `worker/config.ini` (worker API token)
- `worker/browser_profile/` (WhatsApp Web session / cookies)
- `web/uploads/media/` (user media)
- `worker/logs/`, `worker/worker.log`, `worker/screenshots/`
- Any `.env` files

## Safe example in the repo

- `web/includes/database.php.example` → copy to `database.php` and fill credentials
- `worker/config.example.ini`

## After cloning / cPanel upload

1. Copy `database.php.example` → `database.php` and set name/user/pass/host=`localhost`
2. Import `database/install.sql` in phpMyAdmin
3. Set admin password: `php database/set_admin_password.php "YourStrongPassword"`
4. Edit `web/includes/config.php` → `base_url` to your HTTPS URL
5. Rotate any token that was ever pasted into chat or screenshots
