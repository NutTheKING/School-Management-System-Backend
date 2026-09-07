<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mobileRespond([
        'success' => false,
        'message' => 'This is a mobile API endpoint. Please use the mobile app to login or send a POST request with email and password.'
    ], 405);
}

$data = mobileBody();
$email = trim((string)($data['email'] ?? $data['username'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($email === '' || $password === '') {
    mobileRespond(['success' => false, 'message' => 'Email and password are required'], 422);
}

// Uses the same login credentials as the existing system_cs web application.
if ($email !== 'admin@scschool.local' || $password !== 'admin123') {
    mobileRespond(['success' => false, 'message' => 'Incorrect email or password'], 401);
}

$db = mobileDb();
$db->prepare('DELETE FROM mobile_tokens WHERE expires_at <= datetime("now")')->execute();

$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + (12 * 60 * 60));
$stmt = $db->prepare('INSERT INTO mobile_tokens(token_hash, user_email, role, expires_at) VALUES(?,?,?,?)');
$stmt->execute([hash('sha256', $token), $email, 'Super Admin', $expires]);

mobileRespond([
    'success' => true,
    'message' => 'Login successful',
    'token' => $token,
    'expires_at' => $expires,
    'user' => [
        'email' => $email,
        'name' => 'Super Admin',
        'role' => 'Super Admin'
    ]
]);
