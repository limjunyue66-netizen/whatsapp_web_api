# Engineering Report — WhatsApp Bot Control Panel

**Date:** 2026-09-09  
**Status:** Greenfield rebuild (repository previously contained empty folders only; no prior source was present to audit)

---

## A. Architecture summary

Consent-based messaging control panel:

- **PHP 8** admin UI + REST APIs + MySQL queue ownership
- **Windows Python worker** with Playwright persistent profile talks only to worker APIs via Bearer token
- Campaign/job queue with atomic claim, ownership-checked reporting, stale recovery, server-side rate counters
- Delivery model: **at-least-once** (not exactly-once)

## B. Files changed / created

Entire project created under:

- `database/` — schema, seed, password CLI
- `web/includes/` — auth, CSRF, permissions, queue, campaigns, media, phone, templates, rate limits
- `web/api/admin/` — login, contacts, groups, templates, campaigns, send, workers, media, logs, settings, export
- `web/api/worker/` — heartbeat, claim, report, download_media, status
- `web/admin/` — full control panel UI
- `worker/` — API client, browser manager, WA adapter, main loop, batch scripts
- `tests/` — unit + queue tests
- `docs/` — architecture, deployment, this report

## C. Bugs / risks addressed by design

| Problem | Impact | Root cause | Fix |
|---------|--------|------------|-----|
| Empty repo / no source | No runnable system | Scaffold only | Full implementation |
| Cross-worker result spoofing | False sent/failed stats | Missing ownership check | `report_job_result` requires `worker_id` match |
| Jobs stuck in `processing` | Silent queue stall | Worker crash | Stale recovery via claimed_at + heartbeat |
| Duplicate claim | Double send | Race | `FOR UPDATE` (+ SKIP LOCKED when available) |
| Duplicate report corruption | Drifted counters | Non-idempotent update | Terminal-state short-circuit |
| Auth reload destroying QR | Link failures | Aggressive navigation | Adapter skips reload while QR / `post_logout` |
| Send URL vs logout race | Auth errors mid-send | Navigation race | Detect `post_logout` / QR before send; fail with reconnect message |
| Campaign counter drift | Wrong UI stats | Incremental counters | Recalc from `message_jobs` |
| Media path abuse | File disclosure | Trust upload name | Random stored names + auth download |
| CSV formula injection | Spreadsheet RCE risk | Export raw cells | Prefix dangerous cells |

## D. Security findings

### Critical
- None known in shipped code paths; **operator must set MySQL password** and change default admin password.

### High
- Default/dev session `secure=false` — must enable for HTTPS production.
- Worker token shown once; if leaked, regenerate immediately.

### Medium
- Unofficial WA automation is inherently account-risk (not a code bug).
- Login throttling is IP + account based; shared NAT may lock multiple users.

### Low
- Admin UI uses simple fetch CSRF header (adequate for same-site session app).
- Message body stored in DB (needed for send); avoid logging full bodies in app.log.

### Informational
- Selectors will break when WhatsApp Web UI changes — diagnostics/screenshots help.

## E. Database changes

Single install file: `database/install.sql` (tables + default admin/settings) for cPanel/phpMyAdmin — users, workers, contacts, groups, templates, media, campaigns, message_jobs, message_logs, audit_logs, system_settings, login_attempts.

Indexes on claim path (`status, scheduled_at, id`), stale (`status, claimed_at`), uniqueness on phones and worker names.

## F. Worker changes

- Centralized `wa_adapter/whatsapp.py` selectors/operations
- Persistent profile; recovery without deleting profile
- Link mode (`--link` / `2_LINK_WHATSAPP.bat`) waits for QR **without** claim loop / unnecessary reloads
- Heartbeat carries rate-limit config from server
- Temp media cleanup after send
- Auth-required errors instruct reconnect batch

## G. API changes (initial surface)

**Worker:** `heartbeat.php`, `claim_job.php`, `report_result.php`, `download_media.php`, `status.php`  
**Admin:** `login.php`, `logout.php`, `dashboard.php`, `contacts.php`, `groups.php`, `templates.php`, `campaigns.php`, `send.php`, `workers.php`, `media.php`, `logs.php`, `settings.php`, `export_contacts.php`

JSON shape: `{ success, message, data }`

## H. Testing

| Test | Expected | Actual | Status |
|------|----------|--------|--------|
| Phone normalize local/E.164/00 | Normalized `60…` | Pass | PASS |
| Template vars / unsupported | Replace / preserve | Pass | PASS |
| Campaign transitions | Valid only | Pass | PASS |
| CSV sanitize | Prefix `=` | Pass | PASS |
| Worker token hash | SHA-256 | Pass | PASS |
| Queue claim / ownership / retry / stale | DB integration | Blocked — MySQL root password unknown on this machine | PENDING (script ready: `tests/test_queue.php`) |

Run after configuring DB:

```bat
php tests\run_tests.php
php tests\test_queue.php
```

## I. Remaining limitations

1. WhatsApp Web UI can change anytime  
2. Unofficial Playwright automation  
3. “Sent” ≠ recipient read receipt  
4. Crash window → possible duplicate  
5. Not exactly-once  
6. Multi-worker rate limits are global via logs, not a formal distributed lock beyond claim  
7. WhatsApp may still restrict accounts  
8. QR requires interactive browser session  
9. Python was not installed on the build machine — worker must be installed on the Windows host before use  

## J. Deployment instructions

See `docs/DEPLOYMENT.md` and `README.md`.

Minimum path:

1. Configure `web/includes/database.php` with MySQL credentials  
2. Import schema + seed; set admin password  
3. Open admin UI; register worker; copy token  
4. Install Python worker; link WhatsApp; start worker  
5. Use **Send Message** for single sends or Campaigns for bulk consent-based messaging  

---

## Note on scope

Built for **send-message** as the product function (contacts → queue → WhatsApp), with the supporting control-panel features required to operate that pipeline safely. No spam, anti-detection, or ban-evasion features.
