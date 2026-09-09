<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

/**
 * Send-now: typed Phone is ALWAYS the only destination.
 * Contact ID never overrides phone. Works for any number.
 */
require_method('POST');
start_app_session();
require_permission('send_messages');
csrf_verify_request();

$body = body_or_post();
$user = current_user();

$phoneRaw = trim((string) ($body['phone'] ?? ''));
if ($phoneRaw === '') {
    json_fail('Phone is required. Enter the exact WhatsApp number to send to.', 422);
}

$contactId = v_optional_int($body['contact_id'] ?? null);
$message = v_string($body['message_body'] ?? '', 1, 4000, 'message_body');
$mediaId = v_optional_int($body['media_id'] ?? null);
$name = v_optional_string($body['name'] ?? 'Recipient', 150);
$company = v_optional_string($body['company'] ?? '', 150);

$norm = normalize_phone($phoneRaw);
if (!$norm['ok']) {
    json_fail($norm['error'], 422);
}
$phone = $norm['phone'];

$contact = null;
if ($contactId) {
    $stmt = db()->prepare('SELECT id AS contact_id, name, phone_e164, company FROM contacts WHERE id=? AND is_active=1');
    $stmt->execute([$contactId]);
    $contact = $stmt->fetch() ?: null;
}

// Name/company may come from contact for convenience, but phone is always typed value.
$recipient = [
    'contact_id' => ($contact && $contact['phone_e164'] === $phone) ? (int) $contact['contact_id'] : null,
    'name' => ($name !== '' && $name !== 'Recipient') ? $name : ($contact['name'] ?? $name),
    'phone_e164' => $phone,
    'company' => $company !== '' ? $company : (string) ($contact['company'] ?? ''),
];

if ($mediaId && !media_path_by_id($mediaId)) {
    json_fail('Media not found', 404);
}

$maxAttempts = setting_int('max_retry_attempts', 3);
$pdo = db();
$pdo->beginTransaction();
try {
    $campName = 'Send Now ' . now_utc()->format('Y-m-d H:i:s') . ' → ' . $phone;
    $pdo->prepare(
        "INSERT INTO campaigns (name, message_body, media_id, status, created_by, total_count, pending_count)
         VALUES (?, ?, ?, 'queued', ?, 1, 1)"
    )->execute([$campName, $message, $mediaId, $user['id']]);
    $campaignId = (int) $pdo->lastInsertId();
    create_jobs_for_recipients([$recipient], $message, $campaignId, $mediaId, null, $maxAttempts);
    campaign_recalc_stats($campaignId, $pdo);
    $pdo->commit();
    audit_log('send_now', 'campaign', $campaignId, [
        'phone' => $phone,
        'typed_phone_raw' => $phoneRaw,
    ]);
    json_ok('Queued', [
        'campaign_id' => $campaignId,
        'phone_e164' => $phone,
        'recipient_name' => $recipient['name'],
    ], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_log('error', 'send_now_failed', ['error' => $e->getMessage()]);
    json_fail('Failed to queue message', 500);
}
