<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$normalizedPath = strtolower(str_replace('\\', '/', rawurldecode($path)));
if ($normalizedPath === '/storage' || str_starts_with($normalizedPath, '/storage/')) {
    http_response_code(404);
    exit;
}

if ($path === '/') {
    require __DIR__ . '/index.php';
    exit;
}

$file = realpath(__DIR__ . $path);
$root = rtrim(realpath(__DIR__) ?: __DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if ($file !== false && str_starts_with(strtolower($file), strtolower($root)) && is_file($file)) {
    return false;
}

http_response_code(404);
echo 'Not found';
