# Architecture

## Components

| Layer | Role |
|-------|------|
| `web/admin` | Session-authenticated control panel UI |
| `web/api/admin` | CSRF-protected admin JSON APIs |
| `web/api/worker` | Bearer-token worker APIs |
| MySQL | Source of truth for users, queue, campaigns, logs |
| `worker/` | Windows Playwright process owning WhatsApp Web session |

The worker **never** opens a MySQL connection.

## Authentication

```text
Login page → api/admin/login.php → session + regenerate → permissions
Logout → csrf → session destroy
```

## Worker auth

```text
Admin registers worker → plaintext token shown once → SHA-256 stored
Worker sends Authorization: Bearer <token>
API looks up token_hash
```

## Messaging

```text
Send Now / Campaign → create campaign + jobs (UTC schedule)
→ worker heartbeat / claim (atomic FOR UPDATE)
→ Playwright WhatsApp Web send
→ report_result (ownership check, idempotent)
→ message_logs + campaign stats recalc
```

## Delivery semantics

**At-least-once job execution with external WhatsApp uncertainty.**

Failure window: WhatsApp accepts message → worker crashes → server never gets `sent` → stale recovery may requeue → possible duplicate.

## Stale recovery

`processing` + `claimed_at` older than `stale_job_timeout_seconds` + worker heartbeat stale → requeue (or fail if max attempts).

## Timezone

- User input / display: `Asia/Kuala_Lumpur`
- Database / API timestamps: UTC
