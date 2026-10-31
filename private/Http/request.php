<?php
declare(strict_types=1);
function respond(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    if((int)($_SERVER['CONTENT_LENGTH']??0)>12*1024*1024)respond(['error'=>'Request is too large'],413);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if(($raw??'')!==''&&json_last_error()!==JSON_ERROR_NONE)respond(['error'=>'Invalid JSON request'],400);
    return is_array($data) ? $data : [];
}

function requireCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        respond(['error' => 'Invalid CSRF token'], 419);
    }
}

function bearerToken(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $match)) return trim($match[1]);
    return '';
}

