<?php
declare(strict_types=1);

function require_method(string ...$methods): void
{
    $m = request_method();
    $allowed = array_map('strtoupper', $methods);
    if (!in_array($m, $allowed, true)) {
        json_fail('Method not allowed', 405);
    }
}

function body_or_post(): array
{
    $json = request_json();
    if ($json !== []) {
        return $json;
    }
    return $_POST;
}

function v_string(mixed $value, int $min, int $max, string $field): string
{
    if (!is_string($value) && !is_numeric($value)) {
        json_fail("$field is required", 422);
    }
    $s = trim((string) $value);
    $len = mb_strlen($s);
    if ($len < $min || $len > $max) {
        json_fail("$field length must be between $min and $max", 422);
    }
    return $s;
}

function v_optional_string(mixed $value, int $max, string $default = ''): string
{
    if ($value === null || $value === '') {
        return $default;
    }
    $s = trim((string) $value);
    if (mb_strlen($s) > $max) {
        json_fail('Value too long', 422);
    }
    return $s;
}

function v_int(mixed $value, string $field, int $min = 1, ?int $max = null): int
{
    if (!is_numeric($value)) {
        json_fail("$field must be an integer", 422);
    }
    $n = (int) $value;
    if ($n < $min || ($max !== null && $n > $max)) {
        json_fail("$field out of range", 422);
    }
    return $n;
}

function v_optional_int(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return v_int($value, 'id');
}

function v_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === 1 || $value === '1' || $value === 'true') {
        return true;
    }
    return false;
}

function v_enum(mixed $value, array $allowed, string $field): string
{
    $s = (string) $value;
    if (!in_array($s, $allowed, true)) {
        json_fail("Invalid $field", 422);
    }
    return $s;
}

function v_datetime_local(mixed $value, string $field): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $s = trim((string) $value);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s, new DateTimeZone(app_timezone()));
    if (!$dt) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $s, new DateTimeZone(app_timezone()));
    }
    if (!$dt) {
        json_fail("Invalid $field datetime", 422);
    }
    return $dt->format('Y-m-d H:i:s');
}

function csv_sanitize_cell(string $value): string
{
    // Prevent CSV formula injection when exported to spreadsheets
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }
    return $value;
}
