<?php
// Development server only: php -S 127.0.0.1:8080 -t public public/router.php
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?? '/');
$file=realpath(__DIR__.$path);
if ($file && str_starts_with($file,__DIR__.DIRECTORY_SEPARATOR) && is_file($file) && preg_match('~^/(assets/[^.][a-zA-Z0-9_./-]+\.(css|js)|uploads/[a-f0-9]+\.(png|jpg|webp))$~',$path)) return false;
require __DIR__.'/index.php';
