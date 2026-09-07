<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$token = bearerToken();
if ($token !== '') {
    $stmt = mobileDb()->prepare('DELETE FROM mobile_tokens WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
}

mobileRespond(['success' => true, 'message' => 'Logged out']);
