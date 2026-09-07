<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

requireMobileAuth();
$db = mobileDb();

$students = (int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn();
$activeStudents = (int)$db->query("SELECT COUNT(*) FROM students WHERE lower(status) = 'active'")->fetchColumn();
$payments = (float)$db->query('SELECT COALESCE(SUM(amount),0) FROM payments')->fetchColumn();
$todayAttendance = (int)$db->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = date('now')")->fetchColumn();

mobileRespond([
    'success' => true,
    'summary' => [
        'students' => $students,
        'active_students' => $activeStudents,
        'total_payments' => $payments,
        'today_attendance_records' => $todayAttendance
    ]
]);
