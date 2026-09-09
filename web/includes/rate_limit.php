<?php
declare(strict_types=1);

/**
 * Server-side rate limiting based on message_logs 'sent' events (global across workers).
 * Does not guarantee WhatsApp account safety.
 */

function rate_limit_allows_send(): bool
{
    $pdo = db();
    $perHour = setting_int('max_messages_per_hour', 60);
    $perDay = setting_int('max_messages_per_day', 400);

    $h = $pdo->query(
        "SELECT COUNT(*) FROM message_logs
         WHERE event_type = 'sent' AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)"
    )->fetchColumn();
    if ((int) $h >= $perHour) {
        return false;
    }

    $d = $pdo->query(
        "SELECT COUNT(*) FROM message_logs
         WHERE event_type = 'sent' AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
    )->fetchColumn();
    if ((int) $d >= $perDay) {
        return false;
    }

    return true;
}

function rate_limit_record_send(): void
{
    // Counting is derived from message_logs inserts performed by report_job_result.
}

function rate_limit_status(): array
{
    $pdo = db();
    $hour = (int) $pdo->query(
        "SELECT COUNT(*) FROM message_logs
         WHERE event_type = 'sent' AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)"
    )->fetchColumn();
    $day = (int) $pdo->query(
        "SELECT COUNT(*) FROM message_logs
         WHERE event_type = 'sent' AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
    )->fetchColumn();

    return [
        'sent_last_hour' => $hour,
        'sent_last_day' => $day,
        'max_per_hour' => setting_int('max_messages_per_hour', 60),
        'max_per_day' => setting_int('max_messages_per_day', 400),
        'min_delay_seconds' => setting_int('min_delay_seconds', 3),
        'max_delay_seconds' => setting_int('max_delay_seconds', 8),
        'max_messages_per_batch' => setting_int('max_messages_per_batch', 20),
        'pause_between_batches_seconds' => setting_int('pause_between_batches_seconds', 60),
    ];
}
