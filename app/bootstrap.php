<?php
declare(strict_types=1);
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    }
});
date_default_timezone_set('UTC');
$config = require dirname(__DIR__) . '/config/app.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_encode_safe(mixed $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function view(string $template, array $vars = []): void { extract($vars, EXTR_SKIP); require dirname(__DIR__) . '/views/' . $template . '.php'; }
