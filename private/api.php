<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require __DIR__ . '/Http/session.php';
require __DIR__ . '/Http/headers.php';
require __DIR__ . '/Http/request.php';
foreach (['student', 'finance', 'access', 'accounting'] as $service) {
    require __DIR__ . '/Services/' . $service . '.php';
}
require __DIR__ . '/Infrastructure/database.php';
require __DIR__ . '/Contracts/StudentAuthRepository.php';
require __DIR__ . '/Infrastructure/PdoStudentAuthRepository.php';
require __DIR__ . '/Application/StudentAuthService.php';
$studentAuth = new StudentAuthService(new PdoStudentAuthRepository($db));
$resource = $_GET['resource'] ?? 'bootstrap';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($resource === 'health' && $method === 'GET') respond(['status' => 'ok']);
    require __DIR__ . '/Http/Controllers/auth.php';
    require __DIR__ . '/Http/Controllers/mobile.php';
    require __DIR__ . '/Http/Controllers/admin.php';
    respond(['error' => 'Route not found'], 404);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('WG API request failed: ' . $e->getMessage());
    respond(['error' => 'Request failed'], 500);
}
