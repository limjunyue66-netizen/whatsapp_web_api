<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_method('POST');
$worker = require_worker();
$body = request_json();

$jobId = v_int($body['job_id'] ?? null, 'job_id');
$result = v_string($body['result'] ?? '', 4, 10, 'result');
$error = v_optional_string($body['error'] ?? null, 500);

$out = report_job_result($worker, $jobId, $result, $error);
if (!$out['ok']) {
    json_fail($out['message'], $out['http'] ?? 400, $out['data'] ?? null);
}
json_ok($out['message'], $out['data'] ?? null);
