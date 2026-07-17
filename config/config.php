<?php

function loadEnvFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $value = trim($value, "\"'");

        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

function envValue(string $name, string $default = ''): string
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function firstEnvValue(array $names, string $default = ''): string
{
    foreach ($names as $name) {
        $value = envValue($name);

        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function envBool(string $name, bool $default = false): bool
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function envInt(string $name, int $default): int
{
    $value = getenv($name);

    if ($value === false || $value === '' || !is_numeric($value)) {
        return $default;
    }

    return (int)$value;
}

function envFloat(string $name, float $default): float
{
    $value = getenv($name);

    if ($value === false || $value === '' || !is_numeric($value)) {
        return $default;
    }

    return (float)$value;
}

function envPrompt(string $name, string $default = ''): string
{
    $value = envValue($name);

    if ($value === '') {
        return $default;
    }

    // Versuche mehrere Pfad-Varianten
    $paths = [
        $value,  // absolut oder relativ vom aktuellen Working Directory
        __DIR__ . '/../' . $value,  // relativ vom Project Root
    ];

    foreach ($paths as $path) {
        if (file_exists($path) && is_readable($path)) {
            $content = file_get_contents($path);
            if ($content !== false) {
                return $content;
            }
        }
    }

    // Fallback: direkt als String verwenden
    return $value;
}

loadEnvFile(__DIR__ . '/../.env');

return [
    'blueant_base_url' => envValue('BLUEANT_BASE_URL', 'https://dashboard-examples.blueant.cloud/rest'),
    'blueant_token' => envValue('BLUEANT_TOKEN'),
    'traffic_light_field_id' => envValue('BLUEANT_TRAFFIC_LIGHT_FIELD_ID', '832814142'),
    'traffic_light_field_name' => envValue('BLUEANT_TRAFFIC_LIGHT_FIELD_NAME', 'Status Gesamt'),
    'critical_progress_deviation' => envFloat('CRITICAL_PROGRESS_DEVIATION', -20.0),
    'ai' => [
        'enabled' => envBool('AI_ENABLED', false),
        'provider' => 'gemini',
        'base_url' => envValue('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'api_key' => firstEnvValue(['GEMINI_API_KEY', 'gemini-api-key']),
        'model' => firstEnvValue(['GEMINI_MODEL', 'AI_MODEL'], 'gemini-2.5-flash'),
        'temperature' => envFloat('AI_TEMPERATURE', 0.0),
        'timeout_seconds' => envInt('AI_TIMEOUT_SECONDS', 120),
        'prompts' => require __DIR__ . '/prompts.php',
    ],
];
