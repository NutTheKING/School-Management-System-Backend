<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

requireMobileAuth();
$db = mobileDb();

$students = $db->query('SELECT
    id,
    name,
    name_khmer,
    name_english,
    name_chinese,
    class,
    program,
    time,
    room,
    gender,
    phone,
    guardian,
    guardian_name,
    status,
    balance,
    attendance,
    photo,
    birth_date,
    admission_date
FROM students
ORDER BY created_at DESC, id')->fetchAll();

mobileRespond(['success' => true, 'students' => $students]);
