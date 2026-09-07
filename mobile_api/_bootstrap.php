<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function mobileRespond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mobileBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function mobileDb(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    $database = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'northstar.sqlite';
    $db = new PDO('sqlite:' . $database);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('CREATE TABLE IF NOT EXISTS mobile_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_hash TEXT NOT NULL UNIQUE,
        user_email TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "Super Admin",
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at TEXT NOT NULL
    )');

    return $db;
}

function bearerToken(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }
    return '';
}

function requireMobileAuth(): array
{
    $token = bearerToken();
    if ($token === '') {
        mobileRespond(['success' => false, 'message' => 'Authentication required'], 401);
    }

    $db = mobileDb();
    $stmt = $db->prepare('SELECT user_email, role, expires_at FROM mobile_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();

    if (!$row || strtotime((string)$row['expires_at']) <= time()) {
        mobileRespond(['success' => false, 'message' => 'Session expired. Please sign in again.'], 401);
    }

    return $row;
}
