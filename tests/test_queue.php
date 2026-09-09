<?php
declare(strict_types=1);

/**
 * Integration-ish queue tests against live DB.
 * Requires schema installed. Run: php tests/test_queue.php
 */

require dirname(__DIR__) . '/web/includes/bootstrap.php';

function assert_true(bool $c, string $m): void
{
    if (!$c) {
        throw new RuntimeException("ASSERT: $m");
    }
    echo "[PASS] $m\n";
}

$pdo = db();

// Ensure a test worker
$token = generate_worker_token();
$hash = hash_worker_token($token);
$pdo->prepare('DELETE FROM workers WHERE name = ?')->execute(['test-worker-a']);
$pdo->prepare('DELETE FROM workers WHERE name = ?')->execute(['test-worker-b']);
$pdo->prepare(
    "INSERT INTO workers (name, token_hash, token_hint, is_enabled, status, whatsapp_status)
     VALUES ('test-worker-a', ?, 'test…a', 1, 'online', 'connected')"
)->execute([$hash]);
$workerAId = (int) $pdo->lastInsertId();

$tokenB = generate_worker_token();
$pdo->prepare(
    "INSERT INTO workers (name, token_hash, token_hint, is_enabled, status, whatsapp_status)
     VALUES ('test-worker-b', ?, 'test…b', 1, 'online', 'connected')"
)->execute([hash_worker_token($tokenB)]);
$workerBId = (int) $pdo->lastInsertId();

$workerA = $pdo->query("SELECT * FROM workers WHERE id = $workerAId")->fetch();
$workerB = $pdo->query("SELECT * FROM workers WHERE id = $workerBId")->fetch();

// Create a job
$pdo->prepare(
    "INSERT INTO message_jobs (phone_e164, recipient_name, message_body, status, max_attempts)
     VALUES ('60199998888', 'Test', 'Hello test', 'pending', 3)"
)->execute();
$jobId = (int) $pdo->lastInsertId();

$job = claim_next_job($workerA);
assert_true($job !== null && (int) $job['id'] === $jobId, 'worker A claims job');

$job2 = claim_next_job($workerB);
assert_true($job2 === null || (int) $job2['id'] !== $jobId, 'worker B cannot claim same job');

// Wrong worker report
$bad = report_job_result($workerB, $jobId, 'sent');
assert_true(!$bad['ok'] && ($bad['http'] ?? 0) === 403, 'ownership enforced on report');

// Success report
$ok = report_job_result($workerA, $jobId, 'sent');
assert_true($ok['ok'], 'owner can report sent');

// Idempotent duplicate
$dup = report_job_result($workerA, $jobId, 'sent');
assert_true($dup['ok'] && !empty($dup['data']['idempotent']), 'duplicate sent is idempotent');

// Retry path
$pdo->prepare(
    "INSERT INTO message_jobs (phone_e164, recipient_name, message_body, status, max_attempts)
     VALUES ('60199997777', 'Test2', 'Hello retry', 'pending', 2)"
)->execute();
$jobId2 = (int) $pdo->lastInsertId();
$j = claim_next_job($workerA);
assert_true($j && (int) $j['id'] === $jobId2, 'claim retry job');
$r = report_job_result($workerA, $jobId2, 'failed', 'temp error');
assert_true($r['ok'], 'report failed for retry');
$row = $pdo->query("SELECT status, attempts FROM message_jobs WHERE id=$jobId2")->fetch();
assert_true($row['status'] === 'pending' && (int) $row['attempts'] === 1, 'requeued pending after fail');

$j = claim_next_job($workerA);
assert_true($j && (int) $j['id'] === $jobId2, 'reclaim for final attempt');
$r = report_job_result($workerA, $jobId2, 'failed', 'still failing');
assert_true($r['ok'], 'final fail report');
$row = $pdo->query("SELECT status FROM message_jobs WHERE id=$jobId2")->fetch();
assert_true($row['status'] === 'failed', 'max attempts → failed');

// Stale recovery
$pdo->prepare(
    "INSERT INTO message_jobs (phone_e164, recipient_name, message_body, status, worker_id, attempts, max_attempts, claimed_at)
     VALUES ('60199996666', 'Stale', 'x', 'processing', ?, 1, 3, UTC_TIMESTAMP() - INTERVAL 1 DAY)"
)->execute([$workerAId]);
$staleId = (int) $pdo->lastInsertId();
$pdo->prepare("UPDATE workers SET last_heartbeat_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id=?")->execute([$workerAId]);
$n = recover_stale_jobs();
assert_true($n >= 1, 'stale jobs recovered');
$row = $pdo->query("SELECT status FROM message_jobs WHERE id=$staleId")->fetch();
assert_true($row['status'] === 'pending', 'stale processing requeued');

echo "Queue tests completed.\n";
