<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$user = requireMobileAuth();
mobileRespond([
    'success' => true,
    'user' => [
        'email' => $user['user_email'],
        'name' => 'Super Admin',
        'role' => $user['role']
    ]
]);
