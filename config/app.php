<?php
declare(strict_types=1);
// This directory and .env must remain outside the Nginx document root.
$path = dirname(__DIR__) . '/.env';
$fileEnvironment = [];
if (is_file($path)) {
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) $fileEnvironment[$key] = trim($value, " \t\n\r\0\x0B\"'");
    }
}
// Read .env directly: hosting panels may disable putenv(). Do not change that policy.
$environment = static function (string $key, string $fallback = '') use ($fileEnvironment): string {
    $value = getenv($key);
    return $value === false ? ($fileEnvironment[$key] ?? $fallback) : $value;
};
return [
    'env' => $environment('APP_ENV') ?: 'production',
    'db_host' => $environment('DB_HOST') ?: '127.0.0.1',
    'db_port' => $environment('DB_PORT') ?: '3306',
    'db_name' => $environment('DB_DATABASE') ?: 'tournament',
    'db_user' => $environment('DB_USERNAME') ?: 'tournament',
    'db_password' => $environment('DB_PASSWORD'),
    'secure' => $environment('SESSION_SECURE') !== '0',
];
