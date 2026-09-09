<?php
declare(strict_types=1);

const TEMPLATE_VARS = ['name', 'phone', 'company'];

function render_template(string $body, array $vars): string
{
    $map = [
        'name' => (string) ($vars['name'] ?? ''),
        'phone' => (string) ($vars['phone'] ?? ''),
        'company' => (string) ($vars['company'] ?? ''),
    ];

    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function (array $m) use ($map): string {
        $key = strtolower($m[1]);
        if (array_key_exists($key, $map)) {
            return $map[$key];
        }
        // Unsupported variables remain visible for operator awareness
        return $m[0];
    }, $body) ?? $body;
}

function template_unsupported_vars(string $body): array
{
    preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $body, $m);
    $found = [];
    foreach ($m[1] as $name) {
        $key = strtolower($name);
        if (!in_array($key, TEMPLATE_VARS, true)) {
            $found[] = $key;
        }
    }
    return array_values(array_unique($found));
}
