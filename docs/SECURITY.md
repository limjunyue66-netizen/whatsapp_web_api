# Security notes before publishing

## Never commit

- `web/includes/config.local.php` (DB password)
- `worker/config.ini` (worker API token)
- `worker/browser_profile/` (WhatsApp Web session / cookies)
- `web/uploads/media/` (user media)
- `worker/logs/`, `worker/worker.log`, `worker/screenshots/`
- Any `.env` files

## Safe examples in the repo

- `web/includes/config.local.php.example`
- `database/database.example.php`
- `worker/config.example.ini`

## After cloning

1. Copy example configs and fill secrets locally.
2. Change the default admin password immediately.
3. Rotate any worker token that was ever pasted into chat or screenshots.
4. Use only consent-based messaging; this is unofficial WhatsApp Web automation.
