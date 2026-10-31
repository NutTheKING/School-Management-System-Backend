<?php
declare(strict_types=1);

$public = __DIR__ . '/public';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_contains($path, '\\') || str_contains($path, "\0") || preg_match('#(^|/)\.#', $path)) {
    http_response_code(404);
    exit;
}

if ($path === '/' || $path === '/index.php') {
    require $public . '/index.php';
    exit;
}

if ($path === '/api.php') {
    require $public . '/api.php';
    exit;
}
$file = realpath($public . $path);
$root = realpath($public) . DIRECTORY_SEPARATOR;
$types = ['css'=>'text/css', 'js'=>'text/javascript', 'png'=>'image/png', 'jpg'=>'image/jpeg',
    'jpeg'=>'image/jpeg', 'svg'=>'image/svg+xml', 'webp'=>'image/webp', 'ico'=>'image/x-icon',
    'woff'=>'font/woff', 'woff2'=>'font/woff2', 'ttf'=>'font/ttf'];
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if ($file && str_starts_with(strtolower($file), strtolower($root)) && is_file($file) && isset($types[$extension])) {
    header('Content-Type: ' . $types[$extension]);
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

http_response_code(404);
echo 'Not found';
