<?php
declare(strict_types=1);

/**
 * Normalize phone numbers to E.164-like digits without leading +.
 * Default country code from settings (e.g. 60 for Malaysia).
 * Does not silently invent country codes for ambiguous short local numbers
 * unless they clearly look like local mobile (leading 0).
 */
function normalize_phone(string $raw, ?string $defaultCountryCode = null): array
{
    $defaultCountryCode = $defaultCountryCode ?? (string) setting('default_country_code', '60');
    $original = trim($raw);
    if ($original === '') {
        return ['ok' => false, 'phone' => '', 'error' => 'Phone is empty'];
    }

    $digits = preg_replace('/\D+/', '', $original) ?? '';
    if ($digits === '') {
        return ['ok' => false, 'phone' => '', 'error' => 'Phone has no digits'];
    }

    // 00 prefix international
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    // Local leading 0 → apply default country code
    if (str_starts_with($digits, '0') && !str_starts_with($digits, $defaultCountryCode)) {
        $digits = $defaultCountryCode . substr($digits, 1);
    }

    // If still short and doesn't start with country code, reject rather than guess
    if (strlen($digits) < 8 || strlen($digits) > 15) {
        return ['ok' => false, 'phone' => '', 'error' => 'Phone length invalid after normalization'];
    }

    // Basic MSISDN: must be digits only now
    if (!preg_match('/^[1-9][0-9]{7,14}$/', $digits)) {
        return ['ok' => false, 'phone' => '', 'error' => 'Phone format invalid'];
    }

    return ['ok' => true, 'phone' => $digits, 'error' => null, 'raw' => $original];
}
