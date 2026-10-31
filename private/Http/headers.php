<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$configuredMobileOrigin = getenv('MOBILE_APP_ORIGIN') ?: '';
$localDevelopmentOrigin = preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#i', $requestOrigin) === 1;
if ($requestOrigin !== '' && ($requestOrigin === $configuredMobileOrigin || $localDevelopmentOrigin)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

