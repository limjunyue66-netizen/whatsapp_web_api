<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('POST');
$worker = require_worker();

if (($worker['whatsapp_status'] ?? '') === 'qr_required') {
    // Still allow claim only if connected — worker should not claim when not connected.
}

$body = request_json();
// Worker must report connected whatsapp to claim
$wa = $body['whatsapp_status'] ?? null;
if ($wa !== null && $wa !== 'connected') {
    json_fail('WhatsApp not connected', 409);
}

try {
    $job = claim_next_job($worker);
} catch (Throwable $e) {
    app_log('error', 'claim_endpoint_error', ['error' => $e->getMessage()]);
    json_fail('Claim failed', 500);
}

if (!$job) {
    json_ok('No jobs', ['job' => null]);
}

json_ok('Job claimed', [
    'job' => [
        'id' => (int) $job['id'],
        'phone_e164' => $job['phone_e164'],
        'recipient_name' => $job['recipient_name'],
        'message_body' => $job['message_body'],
        'campaign_id' => $job['campaign_id'] ? (int) $job['campaign_id'] : null,
        'attempts' => (int) $job['attempts'],
        'max_attempts' => (int) $job['max_attempts'],
        'media' => $job['media'],
    ],
]);
