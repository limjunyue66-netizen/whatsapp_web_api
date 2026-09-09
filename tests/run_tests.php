<?php
declare(strict_types=1);

/**
 * Lightweight PHP test suite (no PHPUnit required).
 * Run: php tests/run_tests.php
 */

require dirname(__DIR__) . '/web/includes/bootstrap.php';

$pass = 0;
$fail = 0;

function expect(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] $name\n";
        $pass++;
    } else {
        echo "[FAIL] $name\n";
        $fail++;
    }
}

// Phone normalization
$r = normalize_phone('012-345 6789');
expect($r['ok'] && $r['phone'] === '60123456789', 'normalize local MY number');

$r = normalize_phone('+60 12 345 6789');
expect($r['ok'] && $r['phone'] === '60123456789', 'normalize +60 number');

$r = normalize_phone('0060123456789');
expect($r['ok'] && $r['phone'] === '60123456789', 'normalize 00 prefix');

$r = normalize_phone('123');
expect(!$r['ok'], 'reject short phone');

// Template
$out = render_template("Hi {{name}}\nPhone {{phone}} @ {{company}}", [
    'name' => 'Ali', 'phone' => '6011', 'company' => 'Acme',
]);
expect($out === "Hi Ali\nPhone 6011 @ Acme", 'template replace multiline');

$out = render_template('Hello {{unknown}}', ['name' => 'x']);
expect($out === 'Hello {{unknown}}', 'unsupported var preserved');

expect(template_unsupported_vars('{{name}} {{foo}}') === ['foo'], 'detect unsupported vars');

// Campaign transitions
expect(campaign_can_transition('draft', 'queued'), 'draft→queued');
expect(campaign_can_transition('running', 'paused'), 'running→paused');
expect(!campaign_can_transition('completed', 'running'), 'completed cannot resume');
expect(!campaign_can_transition('cancelled', 'queued'), 'cancelled terminal');

// CSRF / password hash
$hash = password_hash('Admin@12345', PASSWORD_DEFAULT);
expect(password_verify('Admin@12345', $hash), 'password hash verify');

// CSV sanitize
expect(csv_sanitize_cell('=cmd') === "'=cmd", 'csv formula injection guard');

// Worker token hash
$t = generate_worker_token();
expect(strlen($t) === 64, 'worker token length');
expect(hash_worker_token($t) === hash('sha256', $t), 'worker token hash');

echo "\nPassed: $pass  Failed: $fail\n";
exit($fail > 0 ? 1 : 0);
