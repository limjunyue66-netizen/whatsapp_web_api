<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('GET');
$worker = require_worker();

$mediaId = v_int($_GET['media_id'] ?? null, 'media_id');
$jobId = v_int($_GET['job_id'] ?? null, 'job_id');

// Worker may only download media for a job it currently owns
$stmt = db()->prepare(
    "SELECT * FROM message_jobs WHERE id = ? AND worker_id = ? AND status = 'processing' LIMIT 1"
);
$stmt->execute([$jobId, $worker['id']]);
$job = $stmt->fetch();
if (!$job) {
    json_fail('Job not found or not owned', 403);
}
if ((int) ($job['media_id'] ?? 0) !== $mediaId) {
    json_fail('Media does not belong to job', 403);
}

$media = media_path_by_id($mediaId);
if (!$media) {
    json_fail('Media not found', 404);
}

$path = $media['absolute_path'];
header('Content-Type: ' . $media['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . basename($media['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
