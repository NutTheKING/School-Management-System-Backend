<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/private/bootstrap.php';
require PROJECT_ROOT . '/private/Http/request.php';
require PROJECT_ROOT . '/private/Services/student.php';
require PROJECT_ROOT . '/private/Infrastructure/database.php';

// Explicit CLI action only. Never replace an existing user's credentials.
$username = 'local_admin';
$studentId = 'ST-LOCAL-001';
$check = $db->prepare('SELECT COUNT(*) FROM system_users WHERE username=? OR email=?');
$check->execute([$username, 'local.admin@school.test']);
$studentCheck = $db->prepare('SELECT COUNT(*) FROM students WHERE id=?');
$studentCheck->execute([$studentId]);
if ($check->fetchColumn() || $studentCheck->fetchColumn()) {
    fwrite(STDERR, "Local accounts already exist; their passwords were not changed.\n");
    exit(1);
}
$adminPassword = 'Admin-' . bin2hex(random_bytes(6)) . '!';
$studentPassword = 'Student-' . bin2hex(random_bytes(6)) . '!';
$db->beginTransaction();
try {
    $payload = ['User Name'=>$username, 'Email'=>'local.admin@school.test',
        'First Name'=>'Local', 'Last Name'=>'Administrator', 'Role'=>'Super Administrator',
        'Status'=>'Active', 'Allow Full'=>'Yes'];
    $db->prepare("INSERT INTO workflow_records(workflow,payload) VALUES('users',?)")
        ->execute([json_encode($payload)]);
    $db->prepare('INSERT INTO system_users(workflow_id,username,email,password_hash,role,status,allow_full,must_change_password) VALUES(?,?,?,?,?,?,1,1)')
        ->execute([(int)$db->lastInsertId(), $username, 'local.admin@school.test',
            password_hash($adminPassword, PASSWORD_DEFAULT), 'Super Administrator', 'Active']);
    $db->prepare('INSERT INTO students(id,name,class,guardian,status,name_english) VALUES(?,?,?,?,?,?)')
        ->execute([$studentId, 'Local Mobile Test', 'Local Test', 'Test Guardian', 'Active', 'Local Mobile Test']);
    $db->prepare('INSERT INTO mobile_accounts(student_id,password_hash,active,must_change_password) VALUES(?,?,1,1)')
        ->execute([$studentId, password_hash($studentPassword, PASSWORD_DEFAULT)]);
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
echo json_encode(['admin'=>['username'=>$username, 'password'=>$adminPassword],
    'mobile'=>['student_id'=>$studentId, 'password'=>$studentPassword]], JSON_PRETTY_PRINT), "\n";
