<?php
declare(strict_types=1);
function initialStudentMobilePassword(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return strlen($digits) >= 8 ? $digits : '';
}

function createStudentMobileAccount(PDO $db, string $studentId, string $phone): bool {
    $password = initialStudentMobilePassword($phone);
    if ($password === '') return false;
    $stmt = $db->prepare('INSERT INTO mobile_accounts(student_id,password_hash,active,must_change_password) VALUES(?,?,1,1) ON CONFLICT(student_id) DO NOTHING');
    $stmt->execute([$studentId,password_hash($password,PASSWORD_DEFAULT)]);
    return $stmt->rowCount() > 0;
}

function requireMobileStudent(PDO $db): array {
    $token = bearerToken();
    if ($token === '') respond(['error' => 'Mobile authentication required'], 401);
    $hash = hash('sha256', $token);
    $stmt = $db->prepare('SELECT s.* FROM student_mobile_tokens t JOIN mobile_accounts a ON a.student_id=t.student_id JOIN students s ON s.id=a.student_id WHERE t.token_hash=? AND datetime(t.expires_at)>datetime(CURRENT_TIMESTAMP) AND CAST(a.active AS INTEGER)=1 AND s.status=?');
    $stmt->execute([$hash,'Active']);
    $student = $stmt->fetch();
    if (!$student) respond(['error' => 'Mobile session is invalid or expired'], 401);
    return $student;
}

function studentIdCardSvg(array $student, array $school = []): string {
    $xml = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $name = strtoupper(trim((string)($student['name_english'] ?: $student['name'] ?: 'STUDENT')));
    $studentId = trim((string)$student['id']);
    $class = trim((string)$student['class']) ?: 'Unassigned';
    $session = trim((string)($student['academic_year'] ?? ''));
    if ($session === '') { $year=(int)date('Y'); if ((int)date('n')<6) $year--; $session=$year.'-'.($year+1); }
    $joinDate = trim((string)($student['admission_date'] ?? '')) ?: substr((string)($student['created_at'] ?? ''),0,10);
    $joinDate = $joinDate !== '' ? date('d/m/Y',strtotime($joinDate)) : '—';
    $photo = trim((string)($student['photo'] ?? ''));
    if (!preg_match('#^(data:image/(png|jpeg|jpg|webp);base64,|https?://)#i',$photo)) $photo='';
    $logo=trim((string)($school['companyLogo'] ?? ''));
    if (!preg_match('#^(data:image/(png|jpeg|jpg|webp);base64,|https?://)#i',$logo)) {
        $logoPath=PUBLIC_PATH.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'wg-logo.png';
        $logo=is_file($logoPath)?'data:image/png;base64,'.base64_encode((string)file_get_contents($logoPath)):'';
    }
    $schoolKh=trim((string)($school['companyName'] ?? '')) ?: 'សាលារៀន ហ្គ្រែនវីយូ';
    $schoolEn=strtoupper(trim((string)($school['companyNameEng'] ?? '')) ?: 'GRANDVIEW SCHOOL');
    $dots=''; for($row=0;$row<3;$row++)for($col=0;$col<12;$col++)$dots.='<circle cx="'.(79+$col*13).'" cy="'.(438+$row*11).'" r="3" fill="#fff"/>';
    $photoMarkup=$photo!==''?'<image href="'.$xml($photo).'" x="91" y="115" width="118" height="148" preserveAspectRatio="xMidYMid slice" clip-path="url(#photoClip)"/>':'<rect x="91" y="115" width="118" height="148" rx="2" fill="#eee"/><text x="150" y="195" text-anchor="middle" font-size="34" font-weight="800" fill="#8a4700">'.substr($xml($name),0,2).'</text>';
    return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="300" height="480" viewBox="0 0 300 480"><defs><clipPath id="cardClip"><rect width="300" height="480" rx="24"/></clipPath><clipPath id="photoClip"><rect x="91" y="115" width="118" height="148" rx="2"/></clipPath></defs><g clip-path="url(#cardClip)"><rect width="300" height="480" fill="#8a4700"/><path d="M0 0H300V216Q150 286 0 216Z" fill="#fff"/><path d="M0 216Q150 286 300 216" fill="none" stroke="#8a4700" stroke-width="3"/><rect width="38" height="78" fill="#8a4700"/><circle cx="307" cy="-5" r="49" fill="#8a4700"/><circle cx="307" cy="-5" r="28" fill="#fff"/><image href="'.$xml($logo).'" xlink:href="'.$xml($logo).'" x="51" y="23" width="45" height="45" preserveAspectRatio="xMidYMid meet"/><text x="102" y="38" font-family="Khmer OS,Arial,sans-serif" font-size="13" font-weight="700" fill="#8a4700">'.$xml($schoolKh).'</text><text x="102" y="55" font-family="Arial,sans-serif" font-size="14" font-weight="900" fill="#8a4700">'.$xml($schoolEn).'</text><text x="102" y="69" font-family="Arial,sans-serif" font-size="9" font-weight="700" letter-spacing="1.2" fill="#8a4700">STUDENT ID CARD</text><rect x="88" y="112" width="124" height="154" rx="4" fill="#fff" stroke="#8a4700" stroke-width="3"/>'.$photoMarkup.'<text x="150" y="294" text-anchor="middle" font-family="Arial,sans-serif" font-size="21" font-weight="900" fill="#fff">'.$xml(mb_strimwidth($name,0,23,'…','UTF-8')).'</text><text x="150" y="316" text-anchor="middle" font-family="Arial,sans-serif" font-size="15" fill="#fff">Student</text><line x1="102" y1="329" x2="198" y2="329" stroke="#fff"/><g font-family="Arial,sans-serif" font-size="12" fill="#fff"><text x="54" y="352" font-weight="700">ID</text><text x="116" y="352">:</text><text x="127" y="352">'.$xml($studentId).'</text><text x="54" y="374" font-weight="700">Class</text><text x="116" y="374">:</text><text x="127" y="374">'.$xml($class).'</text><text x="54" y="396" font-weight="700">Session</text><text x="116" y="396">:</text><text x="127" y="396">'.$xml($session).'</text><text x="54" y="418" font-weight="700">Join Date</text><text x="116" y="418">:</text><text x="127" y="418">'.$xml($joinDate).'</text></g>'.$dots.'<circle cx="-2" cy="460" r="44" fill="#fff"/><circle cx="-2" cy="460" r="25" fill="#8a4700"/><rect x="258" y="413" width="46" height="71" rx="8" fill="#fff"/></g></svg>';
}

