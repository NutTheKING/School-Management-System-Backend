<?php
declare(strict_types=1);
$sessionPath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
if (!is_dir($sessionPath)) mkdir($sessionPath, 0700, true);
session_save_path($sessionPath);
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Strict']);
session_start();
if(!empty($_SESSION['authenticated'])&&time()-(int)($_SESSION['last_activity']??time())>1800)unset($_SESSION['authenticated'],$_SESSION['user_id']);
if(!empty($_SESSION['authenticated']))$_SESSION['last_activity']=time();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$configuredMobileOrigin = getenv('MOBILE_APP_ORIGIN') ?: '';
$localDevelopmentOrigin = preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#i', $requestOrigin) === 1;
if ($requestOrigin !== '' && ($requestOrigin === $configuredMobileOrigin || $localDevelopmentOrigin)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
        $logoPath=__DIR__.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'wg-logo.png';
        $logo=is_file($logoPath)?'data:image/png;base64,'.base64_encode((string)file_get_contents($logoPath)):'';
    }
    $schoolKh=trim((string)($school['companyName'] ?? '')) ?: 'សាលារៀន ហ្គ្រែនវីយូ';
    $schoolEn=strtoupper(trim((string)($school['companyNameEng'] ?? '')) ?: 'GRANDVIEW SCHOOL');
    $dots=''; for($row=0;$row<3;$row++)for($col=0;$col<12;$col++)$dots.='<circle cx="'.(79+$col*13).'" cy="'.(438+$row*11).'" r="3" fill="#fff"/>';
    $photoMarkup=$photo!==''?'<image href="'.$xml($photo).'" x="91" y="115" width="118" height="148" preserveAspectRatio="xMidYMid slice" clip-path="url(#photoClip)"/>':'<rect x="91" y="115" width="118" height="148" rx="2" fill="#eee"/><text x="150" y="195" text-anchor="middle" font-size="34" font-weight="800" fill="#8a4700">'.substr($xml($name),0,2).'</text>';
    return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="300" height="480" viewBox="0 0 300 480"><defs><clipPath id="cardClip"><rect width="300" height="480" rx="24"/></clipPath><clipPath id="photoClip"><rect x="91" y="115" width="118" height="148" rx="2"/></clipPath></defs><g clip-path="url(#cardClip)"><rect width="300" height="480" fill="#8a4700"/><path d="M0 0H300V216Q150 286 0 216Z" fill="#fff"/><path d="M0 216Q150 286 300 216" fill="none" stroke="#8a4700" stroke-width="3"/><rect width="38" height="78" fill="#8a4700"/><circle cx="307" cy="-5" r="49" fill="#8a4700"/><circle cx="307" cy="-5" r="28" fill="#fff"/><image href="'.$xml($logo).'" xlink:href="'.$xml($logo).'" x="51" y="23" width="45" height="45" preserveAspectRatio="xMidYMid meet"/><text x="102" y="38" font-family="Khmer OS,Arial,sans-serif" font-size="13" font-weight="700" fill="#8a4700">'.$xml($schoolKh).'</text><text x="102" y="55" font-family="Arial,sans-serif" font-size="14" font-weight="900" fill="#8a4700">'.$xml($schoolEn).'</text><text x="102" y="69" font-family="Arial,sans-serif" font-size="9" font-weight="700" letter-spacing="1.2" fill="#8a4700">STUDENT ID CARD</text><rect x="88" y="112" width="124" height="154" rx="4" fill="#fff" stroke="#8a4700" stroke-width="3"/>'.$photoMarkup.'<text x="150" y="294" text-anchor="middle" font-family="Arial,sans-serif" font-size="21" font-weight="900" fill="#fff">'.$xml(mb_strimwidth($name,0,23,'…','UTF-8')).'</text><text x="150" y="316" text-anchor="middle" font-family="Arial,sans-serif" font-size="15" fill="#fff">Student</text><line x1="102" y1="329" x2="198" y2="329" stroke="#fff"/><g font-family="Arial,sans-serif" font-size="12" fill="#fff"><text x="54" y="352" font-weight="700">ID</text><text x="116" y="352">:</text><text x="127" y="352">'.$xml($studentId).'</text><text x="54" y="374" font-weight="700">Class</text><text x="116" y="374">:</text><text x="127" y="374">'.$xml($class).'</text><text x="54" y="396" font-weight="700">Session</text><text x="116" y="396">:</text><text x="127" y="396">'.$xml($session).'</text><text x="54" y="418" font-weight="700">Join Date</text><text x="116" y="418">:</text><text x="127" y="418">'.$xml($joinDate).'</text></g>'.$dots.'<circle cx="-2" cy="460" r="44" fill="#fff"/><circle cx="-2" cy="460" r="25" fill="#8a4700"/><rect x="258" y="413" width="46" height="71" rx="8" fill="#fff"/></g></svg>';
}

function currencySettings(PDO $db): array {
    $rows=[];
    foreach ($db->query("SELECT id,payload FROM workflow_records WHERE workflow='setting-currency' ORDER BY id")->fetchAll() as $record) {
        $row=json_decode((string)$record['payload'],true);
        if (!is_array($row)) continue;
        $code=strtoupper(trim((string)($row['Code']??$row['Currency']??'')));
        $rate=(float)str_replace(',','',(string)($row['Rate']??0));
        if ($code!=='' && $rate>0) $rows[$code]=['id'=>(int)$record['id'],'code'=>$code,'symbol'=>(string)($row['Symbol']??$code),'rate'=>$rate,'default'=>($row['Is Default']??'No')==='Yes'];
    }
    if (!$rows) $rows=['USD'=>['id'=>0,'code'=>'USD','symbol'=>'$','rate'=>1.0,'default'=>true],'KHR'=>['id'=>0,'code'=>'KHR','symbol'=>'៛','rate'=>4100.0,'default'=>false]];
    if (!array_filter($rows,static fn(array $row):bool=>$row['default'])) $rows[array_key_first($rows)]['default']=true;
    return $rows;
}

function validCurrency(PDO $db, string $code): string {
    $code=strtoupper(trim($code));
    if (!isset(currencySettings($db)[$code])) respond(['error'=>'Invalid currency code'],422);
    return $code;
}

function systemUserAccess(PDO $db): array {
    $userId=(int)($_SESSION['user_id']??0);
    $stmt=$db->prepare('SELECT id,workflow_id,username,email,role,status,allow_full,must_change_password FROM system_users WHERE id=?');$stmt->execute([$userId]);$user=$stmt->fetch();
    if(!$user) return ['username'=>'','email'=>'','role'=>'','allow_full'=>false,'modules'=>[],'actions'=>[]];
    $normalizedRole=strtolower(trim((string)$user['role']));
    $full=(bool)$user['allow_full']||in_array($normalizedRole,['super administrator','super admin','vice school director'],true);
    $defaults=[
        'Vice School Director'=>['dashboard','front','student','academic','attendance','report','staff'],
        'Administrative'=>['dashboard','front','student','payment','attendance','report'],
        'Program Supervisor'=>['dashboard','student','academic','attendance','report'],
        'Teacher'=>['dashboard','student','academic','attendance','report'],
        'Parent'=>['dashboard'], 'Driver'=>['dashboard','transport'], 'Security'=>['dashboard','attendance'],
        'Agency'=>['dashboard','front'], 'Seller'=>['dashboard','inventory'], 'Cleaner'=>['dashboard']
    ];
    $modules=$full?['*']:($defaults[(string)$user['role']]??['dashboard']);
    $editableRoles=['Vice School Director','Administrative','Program Supervisor','Teacher'];
    $actions=$full?['view','create','edit','delete']:(in_array((string)$user['role'],$editableRoles,true)?['view','create','edit']:['view']);
    $roleStmt=$db->prepare("SELECT payload FROM workflow_records WHERE workflow='user-access' AND json_extract(payload,'$.Access rights')=? ORDER BY id DESC LIMIT 1");
    $roleStmt->execute([(string)$user['role']]);$rolePayload=json_decode((string)$roleStmt->fetchColumn(),true);
    if(!$full&&is_array($rolePayload)){
        $configured=$rolePayload['Modules']??[];if(is_string($configured))$configured=preg_split('/\s*,\s*/',$configured,-1,PREG_SPLIT_NO_EMPTY);
        if(is_array($configured)&&$configured)$modules=array_values(array_unique(array_map('strval',$configured)));
        $configuredActions=$rolePayload['Actions']??[];if(is_string($configuredActions))$configuredActions=preg_split('/\s*,\s*/',$configuredActions,-1,PREG_SPLIT_NO_EMPTY);
        if(is_array($configuredActions)&&$configuredActions)$actions=array_values(array_unique(array_map('strtolower',$configuredActions)));
    }
    $profile=[];
    if((int)$user['workflow_id']>0){$profileStmt=$db->prepare("SELECT payload FROM workflow_records WHERE id=? AND workflow='users'");$profileStmt->execute([(int)$user['workflow_id']]);$profile=json_decode((string)$profileStmt->fetchColumn(),true);if(!is_array($profile))$profile=[];}
    $displayName=trim((string)($profile['First Name']??'').' '.(string)($profile['Last Name']??''))?:$user['username'];
    return ['id'=>(int)$user['id'],'workflow_id'=>(int)$user['workflow_id'],'username'=>$user['username'],'display_name'=>$displayName,'photo'=>(string)($profile['Photo']??''),'email'=>$user['email'],'role'=>$user['role'],'allow_full'=>$full,'must_change_password'=>(bool)$user['must_change_password'],'modules'=>$modules,'actions'=>$actions];
}

function requireSystemAccess(PDO $db,string $module,string $action='view'): void {
    $access=systemUserAccess($db);
    if($access['allow_full']||in_array('*',$access['modules'],true))return;
    if(!in_array($module,$access['modules'],true)||!in_array(strtolower($action),$access['actions'],true))respond(['error'=>'Your role is not permitted to perform this action'],403);
}

function workflowModule(string $workflow): string {
    if(str_starts_with($workflow,'accounting-'))return 'accounting';
    if(str_starts_with($workflow,'academic-'))return 'academic';
    if(str_starts_with($workflow,'attendance-'))return 'attendance';
    if(str_starts_with($workflow,'inventory-'))return 'inventory';
    if(str_starts_with($workflow,'transport'))return 'transport';
    if(str_starts_with($workflow,'hr-')||$workflow==='staff')return 'staff';
    if(str_starts_with($workflow,'setting-'))return 'settings';
    if(str_starts_with($workflow,'certificate-'))return 'certificate';
    if(in_array($workflow,['users','user-access'],true))return 'users';
    if(in_array($workflow,['pre-invoices','payment-invoice'],true))return 'payment';
    return 'dashboard';
}

function accountingPayload(PDO $db, string $workflow, array $payload): array {
    $allowed = ['accounting-income-type','accounting-expense-type','accounting-chart','accounting-journal'];
    if (!in_array($workflow, $allowed, true)) return $payload;

    $clean = static fn(mixed $value): string => trim((string)$value);
    if ($workflow === 'accounting-chart') {
        foreach (['Name','Code','Type'] as $field) {
            if ($clean($payload[$field] ?? '') === '') respond(['error' => "$field is required"], 422);
        }
        $types = ['Asset','Liability','Income','Expense','Equity'];
        if (!in_array($payload['Type'], $types, true)) respond(['error' => 'Invalid account type'], 422);
        $payload['Name'] = $clean($payload['Name']);
        $payload['Code'] = $clean($payload['Code']);
        return $payload;
    }

    if ($workflow === 'accounting-income-type' || $workflow === 'accounting-expense-type') {
        $nameField = $workflow === 'accounting-expense-type' ? 'Expense Name' : 'Name';
        if ($clean($payload[$nameField] ?? '') === '') respond(['error' => "$nameField is required"], 422);
        $payload[$nameField] = $clean($payload[$nameField]);
        $payload['Asset'] = ($payload['Asset'] ?? 'No') === 'Yes' ? 'Yes' : 'No';
        $flag = $workflow === 'accounting-expense-type' ? 'Expense' : 'Income';
        $payload[$flag] = ($payload[$flag] ?? 'No') === 'Yes' ? 'Yes' : 'No';
        return $payload;
    }

    foreach (['Date','Currency','Operation Type'] as $field) {
        if ($clean($payload[$field] ?? '') === '') respond(['error' => "$field is required"], 422);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $clean($payload['Date']));
    if (!$date || $date->format('Y-m-d') !== $clean($payload['Date'])) respond(['error' => 'Invalid journal date'], 422);
    $payload['Currency']=validCurrency($db,(string)$payload['Currency']);
    if (!in_array($payload['Operation Type'], ['Income','Expense','General'], true)) respond(['error' => 'Invalid operation type'], 422);
    $lines = $payload['Lines'] ?? [];
    if (!is_array($lines) || count($lines) === 0) respond(['error' => 'At least one journal line is required'], 422);
    $debit = 0.0; $credit = 0.0; $normalized = [];
    foreach ($lines as $line) {
        if (!is_array($line)) respond(['error' => 'Invalid journal line'], 422);
        $account = $clean($line['account'] ?? '');
        $lineDebit = round((float)($line['debit'] ?? 0), 2);
        $lineCredit = round((float)($line['credit'] ?? 0), 2);
        if ($account === '') respond(['error' => 'Account name is required on every journal line'], 422);
        if ($lineDebit < 0 || $lineCredit < 0 || ($lineDebit > 0 && $lineCredit > 0)) respond(['error' => 'Each journal line must contain either a debit or a credit'], 422);
        if ($lineDebit === 0.0 && $lineCredit === 0.0) continue;
        $debit += $lineDebit; $credit += $lineCredit;
        $normalized[] = ['account'=>$account,'note'=>$clean($line['note'] ?? ''),'debit'=>$lineDebit,'credit'=>$lineCredit];
    }
    if (!$normalized) respond(['error' => 'Enter a debit or credit amount'], 422);
    if (abs($debit - $credit) > 0.001) respond(['error' => 'Total debit and credit must be equal'], 422);
    $payload['Date'] = $date->format('Y-m-d');
    $payload['Receipt No'] = $clean($payload['Receipt No'] ?? '');
    $payload['Note'] = $clean($payload['Note'] ?? '');
    $payload['Lines'] = $normalized;
    $payload['Total Debit'] = round($debit, 2);
    $payload['Total Credit'] = round($credit, 2);
    return $payload;
}

function financeSummary(PDO $db, array $workflowRecords): array {
    $currentMonth = date('Y-m');
    $previousMonth = date('Y-m', strtotime('first day of last month'));
    $currencies=currencySettings($db); $defaultCode=array_key_first(array_filter($currencies,static fn(array $row):bool=>$row['default']));
    $toDefault=static function(float $amount,string $code) use ($currencies,$defaultCode):float { $from=$currencies[strtoupper($code)]['rate']??1.0; $to=$currencies[$defaultCode]['rate']??1.0; return $amount/$from*$to; };
    $paymentStatement = $db->prepare("SELECT amount,currency FROM payments WHERE substr(paid_at,1,7)=?");
    $paymentStatement->execute([$currentMonth]); $currentPayments=0.0;
    foreach($paymentStatement->fetchAll() as $payment) $currentPayments+=$toDefault((float)$payment['amount'],(string)$payment['currency']);
    $paymentStatement->execute([$previousMonth]); $previousPayments=0.0;
    foreach($paymentStatement->fetchAll() as $payment) $previousPayments+=$toDefault((float)$payment['amount'],(string)$payment['currency']);
    $journalIncome = [$currentMonth=>0.0,$previousMonth=>0.0];
    $journalExpense = [$currentMonth=>0.0,$previousMonth=>0.0];
    $accountingEntries = array_merge($workflowRecords['accounting-journal'] ?? [], $workflowRecords['accounting-operation'] ?? [], $workflowRecords['accounting-income'] ?? []);
    foreach ($accountingEntries as $entry) {
        $month = substr((string)($entry['Date'] ?? $entry['date'] ?? ''), 0, 7);
        if (!array_key_exists($month, $journalIncome)) continue;
        $type = strtolower(trim((string)($entry['Operation Type'] ?? $entry['Type'] ?? $entry['type'] ?? '')));
        $amount=(float)($entry['Total Credit'] ?? $entry['Total Debit'] ?? $entry['Amount'] ?? $entry['amount'] ?? 0);
        $amount=$toDefault($amount,(string)($entry['Currency']??$defaultCode));
        if ($type === 'income') $journalIncome[$month] += $amount;
        if ($type === 'expense') $journalExpense[$month] += $amount;
    }
    $incomeThisMonth = $currentPayments + $journalIncome[$currentMonth];
    $incomeLastMonth = $previousPayments + $journalIncome[$previousMonth];
    $expenseThisMonth = $journalExpense[$currentMonth];
    return [
        'month'=>$currentMonth,
        'previous_month'=>$previousMonth,
        'income_last_month'=>round($incomeLastMonth,2),
        'income_this_month'=>round($incomeThisMonth,2),
        'expense_this_month'=>round($expenseThisMonth,2),
        'profit_this_month'=>round($incomeThisMonth-$expenseThisMonth,2),
        'currency'=>$defaultCode
    ];
}

$storage = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
if (!is_dir($storage) && !mkdir($storage, 0775, true) && !is_dir($storage)) {
    respond(['error' => 'Storage directory could not be created'], 500);
}

try {
    $db = new PDO('sqlite:' . $storage . DIRECTORY_SEPARATOR . 'northstar.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_TIMEOUT, 10);
    $db->exec('PRAGMA busy_timeout = 10000');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $schemaVersion = (int)$db->query('PRAGMA user_version')->fetchColumn();
    $db->exec('CREATE TABLE IF NOT EXISTS students (
        id TEXT PRIMARY KEY, name TEXT NOT NULL, class TEXT NOT NULL,
        guardian TEXT NOT NULL, phone TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "Active",
        balance REAL NOT NULL DEFAULT 0, attendance REAL NOT NULL DEFAULT 100, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $studentColumns = [];
    foreach ($db->query('PRAGMA table_info(students)')->fetchAll() as $column) $studentColumns[$column['name']] = true;
    foreach (['photo','name_khmer','name_english','name_chinese','program','time','room','gender','birth_date','admission_date','father_name','father_phone','father_occupation','mother_name','mother_phone','mother_occupation','guardian_relation','guardian_phone','guardian_occupation','guardian_name','guardian_email','guardian_address','father_photo'] as $column) {
        if (!isset($studentColumns[$column])) $db->exec('ALTER TABLE students ADD COLUMN ' . $column . ' TEXT NOT NULL DEFAULT ""');
    }
    $db->exec('CREATE TABLE IF NOT EXISTS admissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, student_name TEXT NOT NULL, guardian TEXT NOT NULL,
        phone TEXT NOT NULL DEFAULT "", requested_class TEXT NOT NULL, source TEXT NOT NULL DEFAULT "Walk-in",
        status TEXT NOT NULL DEFAULT "Inquiry", created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $admissionColumns = [];
    foreach ($db->query('PRAGMA table_info(admissions)')->fetchAll() as $column) $admissionColumns[$column['name']] = true;
    foreach (['name_english','name_khmer','gender','birth_date','program','section','reviewed_by','reviewed_date','remark'] as $column) {
        if (!isset($admissionColumns[$column])) $db->exec('ALTER TABLE admissions ADD COLUMN ' . $column . ' TEXT NOT NULL DEFAULT ""');
    }
    $db->exec('CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, receipt TEXT NOT NULL UNIQUE, student_id TEXT NOT NULL,
        category TEXT NOT NULL, amount REAL NOT NULL, method TEXT NOT NULL, paid_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
    )');
    $paymentColumns = [];
    foreach ($db->query('PRAGMA table_info(payments)')->fetchAll() as $column) $paymentColumns[$column['name']] = true;
    foreach (['currency','branch','note'] as $column) {
        if (!isset($paymentColumns[$column])) $db->exec('ALTER TABLE payments ADD COLUMN ' . $column . ' TEXT NOT NULL DEFAULT ""');
    }
    $db->exec('CREATE TABLE IF NOT EXISTS attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT, student_id TEXT NOT NULL, attendance_date TEXT NOT NULL,
        session TEXT NOT NULL, state TEXT NOT NULL, UNIQUE(student_id, attendance_date, session),
        FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS workflow_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT, workflow TEXT NOT NULL,
        payload TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS system_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER UNIQUE,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE, email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT "User", status TEXT NOT NULL DEFAULT "Active",
        allow_full INTEGER NOT NULL DEFAULT 0, must_change_password INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $systemUserColumns=[];
    foreach($db->query('PRAGMA table_info(system_users)')->fetchAll() as $column)$systemUserColumns[$column['name']]=true;
    if(!isset($systemUserColumns['must_change_password']))$db->exec('ALTER TABLE system_users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0');
    $db->exec('CREATE TABLE IF NOT EXISTS mobile_accounts (
        student_id TEXT PRIMARY KEY, password_hash TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1,
        must_change_password INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS student_mobile_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, student_id TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS attendance_stations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE,
        audience TEXT NOT NULL DEFAULT "all", expires_at TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        attempt_key TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0,
        first_attempt INTEGER NOT NULL, blocked_until INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_students_created ON students(created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_students_class_status ON students(class,status)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_admissions_status_created ON admissions(status,created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_payments_student_date ON payments(student_id,paid_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_payments_date_method ON payments(paid_at DESC,method)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_attendance_date_session ON attendance(attendance_date DESC,session)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_workflow_type_updated ON workflow_records(workflow,updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_student_mobile_tokens_expiry ON student_mobile_tokens(token_hash,expires_at)');
    $legacyUsers=$db->query("SELECT id,payload FROM workflow_records WHERE workflow='users'")->fetchAll();
    $updateLegacyUser=$db->prepare('UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $migrateLogin=$db->prepare('INSERT INTO system_users(workflow_id,username,email,password_hash,role,status,allow_full) VALUES(?,?,?,?,?,?,?) ON CONFLICT(workflow_id) DO NOTHING');
    foreach($legacyUsers as $legacyRecord){
        $legacy=json_decode((string)$legacyRecord['payload'],true);if(!is_array($legacy))continue;
        $plain=(string)($legacy['Password']??'');$username=trim((string)($legacy['User Name']??''));$email=trim((string)($legacy['Email']??''));
        if($plain!==''&&$username!==''&&filter_var($email,FILTER_VALIDATE_EMAIL)){
            try{$migrateLogin->execute([(int)$legacyRecord['id'],$username,$email,password_hash($plain,PASSWORD_DEFAULT),(string)($legacy['Role']??'User'),(string)($legacy['Status']??'Active'),($legacy['Allow Full']??'No')==='Yes'?1:0]);}catch(PDOException $ignored){}
        }
        if(array_key_exists('Password',$legacy)||array_key_exists('Password Confirmation',$legacy)){unset($legacy['Password'],$legacy['Password Confirmation']);$updateLegacyUser->execute([json_encode($legacy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$legacyRecord['id']]);}
    }
    if ((int)$db->query('SELECT COUNT(*) FROM system_users')->fetchColumn() === 0) {
        $adminPayload=['Branche'=>'SC1','Staff Code'=>'ADMIN-001','First Name'=>'Super','Last Name'=>'Admin','User Name'=>'admin','Email'=>'admin@scschool.local','Phone'=>'','Role'=>'Super Administrator','Status'=>'Active','Allow Full'=>'Yes','Created Date'=>date('Y-m-d')];
        $insertAdminRecord=$db->prepare("INSERT INTO workflow_records(workflow,payload) VALUES('users',?)");
        $insertAdminRecord->execute([json_encode($adminPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $db->prepare('INSERT INTO system_users(workflow_id,username,email,password_hash,role,status,allow_full) VALUES(?,?,?,?,?,?,1)')->execute([(int)$db->lastInsertId(),'admin','admin@scschool.local',password_hash('admin123',PASSWORD_DEFAULT),'Super Administrator','Active']);
    }
    $defaultAdmin=$db->query("SELECT id,password_hash FROM system_users WHERE username='admin' LIMIT 1")->fetch();
    if($defaultAdmin&&password_verify('admin123',(string)$defaultAdmin['password_hash']))$db->prepare('UPDATE system_users SET must_change_password=1 WHERE id=?')->execute([(int)$defaultAdmin['id']]);
    $seedCurrency=$db->prepare("INSERT INTO workflow_records(workflow,payload) VALUES('setting-currency',?)");
    $currencyCodes=[];$hasCurrencyDefault=false;
    foreach($db->query("SELECT payload FROM workflow_records WHERE workflow='setting-currency'")->fetchAll(PDO::FETCH_COLUMN) as $savedCurrencyJson){$savedCurrency=json_decode((string)$savedCurrencyJson,true);if(!is_array($savedCurrency))continue;$currencyCodes[]=strtoupper(trim((string)($savedCurrency['Code']??'')));if(($savedCurrency['Is Default']??'No')==='Yes')$hasCurrencyDefault=true;}
    if(!in_array('USD',$currencyCodes,true))$seedCurrency->execute([json_encode(['Currency'=>'USD','Symbol'=>'$','Code'=>'USD','Rate'=>1,'Is Default'=>$hasCurrencyDefault?'No':'Yes'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    if(!in_array('KHR',$currencyCodes,true))$seedCurrency->execute([json_encode(['Currency'=>'KHR','Symbol'=>'៛','Code'=>'KHR','Rate'=>4100,'Is Default'=>'No'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $legacyInvoiceRows=$db->query("SELECT id,payload FROM workflow_records WHERE workflow='pre-invoices' AND json_type(payload,'$.currency') IS NULL")->fetchAll();
    $updateLegacyInvoice=$db->prepare('UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
    foreach($legacyInvoiceRows as $legacyInvoiceRow){$legacyInvoice=json_decode((string)$legacyInvoiceRow['payload'],true);if(!is_array($legacyInvoice))continue;$legacyInvoice['currency']='USD';$updateLegacyInvoice->execute([json_encode($legacyInvoice,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$legacyInvoiceRow['id']]);}
    if ($schemaVersion < 3) {
        $db->exec("UPDATE payments SET currency='USD' WHERE currency='' OR currency IS NULL");
        $db->exec("UPDATE payments SET branch='SC1' WHERE branch='' OR branch IS NULL");
        $db->exec('PRAGMA user_version = 3');
    }

    if ((int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn() === 0) {
        $seed = [
            ['ST-2041','Maya Chen','Grade 10 · A','Lin Chen','(555) 014-2210','Active',0,96],
            ['ST-2042','Noah Williams','Grade 9 · B','Ava Williams','(555) 014-8821','Active',450,92],
            ['ST-2043','Sofia Patel','Grade 11 · A','Raj Patel','(555) 014-1198','Active',0,98],
            ['ST-2044','Ethan Kim','Grade 8 · C','Min Kim','(555) 014-7734','Active',125,88],
            ['ST-2045','Amelia Santos','Grade 10 · B','Luis Santos','(555) 014-6512','Pending',750,94],
            ['ST-2046','Lucas Martin','Grade 12 · A','Emma Martin','(555) 014-4430','Active',0,97]
        ];
        $stmt = $db->prepare('INSERT INTO students(id,name,class,guardian,phone,status,balance,attendance) VALUES(?,?,?,?,?,?,?,?)');
        foreach ($seed as $row) $stmt->execute($row);
        $db->exec("INSERT INTO admissions(student_name,guardian,phone,requested_class,source,status) VALUES
            ('Harper Reed','Taylor Reed','(555) 013-9210','Grade 8 · A','Website','Inquiry'),
            ('Daniel Okafor','Ada Okafor','(555) 013-2051','Grade 10 · A','Referral','Assessment'),
            ('Isabella Nguyen','Mai Nguyen','(555) 013-6378','Grade 9 · B','Walk-in','Approved')");
    }
    // Provision missing mobile logins from the student's phone digits. The
    // conflict-safe insert preserves every password set by an administrator.
    foreach ($db->query('SELECT s.id,s.phone FROM students s LEFT JOIN mobile_accounts a ON a.student_id=s.id WHERE a.student_id IS NULL')->fetchAll() as $student) {
        createStudentMobileAccount($db,(string)$student['id'],(string)$student['phone']);
    }
} catch (Throwable $e) {
    error_log('WG database initialization failed: '.$e->getMessage());
    respond(['error' => 'Database unavailable'], 500);
}

$resource = $_GET['resource'] ?? 'bootstrap';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'POST' && $resource === 'login') {
        requireCsrf(); $data=body();
        $login=trim((string)($data['email']??'')); $password=(string)($data['password']??'');
        $clientIp=(string)($_SERVER['REMOTE_ADDR']??'unknown');$attemptKey=hash('sha256',$clientIp.'|'.strtolower($login));$now=time();
        $db->prepare('DELETE FROM login_attempts WHERE first_attempt<?')->execute([$now-86400]);
        $attemptStmt=$db->prepare('SELECT attempts,first_attempt,blocked_until FROM login_attempts WHERE attempt_key=?');$attemptStmt->execute([$attemptKey]);$attempt=$attemptStmt->fetch();
        if($attempt&&(int)$attempt['blocked_until']>$now)respond(['error'=>'Too many login attempts. Please wait 15 minutes and try again'],429);
        $stmt=$db->prepare('SELECT * FROM system_users WHERE email=? OR username=? LIMIT 1'); $stmt->execute([$login,$login]); $user=$stmt->fetch();
        if(!$user||$user['status']!=='Active'||!password_verify($password,(string)$user['password_hash'])){$attempts=$attempt&&$now-(int)$attempt['first_attempt']<900?(int)$attempt['attempts']+1:1;$first=$attempts===1?$now:(int)$attempt['first_attempt'];$blocked=$attempts>=5?$now+900:0;$saveAttempt=$db->prepare('INSERT INTO login_attempts(attempt_key,attempts,first_attempt,blocked_until) VALUES(?,?,?,?) ON CONFLICT(attempt_key) DO UPDATE SET attempts=excluded.attempts,first_attempt=excluded.first_attempt,blocked_until=excluded.blocked_until');$saveAttempt->execute([$attemptKey,$attempts,$first,$blocked]);usleep(350000);respond(['error'=>$blocked?'Too many login attempts. Please wait 15 minutes and try again':'Incorrect email or password'],$blocked?429:401);}
        $db->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([$attemptKey]);
        session_regenerate_id(true); $_SESSION['authenticated']=true; $_SESSION['user_id']=(int)$user['id'];$_SESSION['last_activity']=time();
        respond(['ok'=>true,'user'=>systemUserAccess($db)]);
    }
    if ($method === 'POST' && $resource === 'logout') {
        requireCsrf(); unset($_SESSION['authenticated'],$_SESSION['user_id'],$_SESSION['last_activity']); respond(['ok'=>true]);
    }
    if ($method === 'POST' && $resource === 'admin-reset-user-password') {
        requireCsrf();
        $access=systemUserAccess($db);$actorRole=strtolower(trim((string)$access['role']));
        if(!in_array($actorRole,['super administrator','super admin','vice school director'],true))respond(['error'=>'Only Super Administrator or Vice School Director can reset user passwords'],403);
        $data=body();$workflowId=(int)($data['id']??0);
        if($workflowId<=0)respond(['error'=>'User account is required'],422);
        $target=$db->prepare('SELECT id,role FROM system_users WHERE workflow_id=?');$target->execute([$workflowId]);$targetUser=$target->fetch();
        if(!$targetUser)respond(['error'=>'User login account not found'],404);
        if((int)$targetUser['id']===(int)($_SESSION['user_id']??0))respond(['error'=>'Use Account Settings to change your own password'],409);
        $targetRole=strtolower(trim((string)$targetUser['role']));
        if($actorRole==='vice school director'&&in_array($targetRole,['super administrator','super admin'],true))respond(['error'=>'Vice School Director cannot reset a Super Administrator password'],403);
        $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';$temporary='';
        for($i=0;$i<10;$i++)$temporary.=$alphabet[random_int(0,strlen($alphabet)-1)];
        $temporary.='!'.random_int(10,99);
        $update=$db->prepare('UPDATE system_users SET password_hash=?,must_change_password=1,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $update->execute([password_hash($temporary,PASSWORD_DEFAULT),(int)$targetUser['id']]);
        respond(['ok'=>true,'temporary_password'=>$temporary,'must_change_password'=>true]);
    }
    if ($method === 'POST' && $resource === 'change-required-password') {
        requireCsrf();$userId=(int)($_SESSION['user_id']??0);
        if($userId<=0)respond(['error'=>'Please sign in again'],401);
        $data=body();$password=(string)($data['new_password']??'');$confirmation=(string)($data['password_confirmation']??'');
        if(strlen($password)<8)respond(['error'=>'New password must be at least 8 characters'],422);
        if($password!==$confirmation)respond(['error'=>'Password confirmation does not match'],422);
        $stmt=$db->prepare('UPDATE system_users SET password_hash=?,must_change_password=0,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([password_hash($password,PASSWORD_DEFAULT),$userId]);
        respond(['ok'=>true]);
    }
    if($method==='POST'&&!empty($_SESSION['authenticated'])){
        $required=$db->prepare('SELECT must_change_password FROM system_users WHERE id=?');$required->execute([(int)($_SESSION['user_id']??0)]);
        if((int)$required->fetchColumn()===1)respond(['error'=>'You must create a new password before continuing','must_change_password'=>true],428);
    }
    if ($method === 'POST' && $resource === 'mobile-login') {
        $data = body();
        $studentId = strtoupper(trim((string)($data['student_id'] ?? '')));
        $password = (string)($data['password'] ?? '');
        if ($studentId === '' || $password === '') respond(['error'=>'Student ID and password are required'],422);
        $stmt = $db->prepare('SELECT a.*,s.status FROM mobile_accounts a JOIN students s ON s.id=a.student_id WHERE a.student_id=? AND a.active=1');
        $stmt->execute([$studentId]); $account = $stmt->fetch();
        if (!$account || $account['status'] !== 'Active' || !password_verify($password, $account['password_hash'])) {
            usleep(250000); respond(['error'=>'Incorrect Student ID or password'],401);
        }
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30);
        $db->prepare('DELETE FROM student_mobile_tokens WHERE expires_at<=CURRENT_TIMESTAMP')->execute();
        $stmt = $db->prepare('INSERT INTO student_mobile_tokens(student_id,token_hash,expires_at) VALUES(?,?,?)');
        $stmt->execute([$studentId,hash('sha256',$plainToken),$expiresAt]);
        respond(['ok'=>true,'token'=>$plainToken,'expires_at'=>$expiresAt,'must_change_password'=>(bool)$account['must_change_password']]);
    }
    if ($method === 'GET' && $resource === 'mobile-dashboard') {
        $student = requireMobileStudent($db); $studentId = (string)$student['id'];
        $stmt = $db->prepare('SELECT receipt,category,amount,method,paid_at,currency,note FROM payments WHERE student_id=? ORDER BY paid_at DESC,id DESC');
        $stmt->execute([$studentId]); $payments = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT attendance_date,session,state FROM attendance WHERE student_id=? ORDER BY attendance_date DESC,id DESC');
        $stmt->execute([$studentId]); $attendance = $stmt->fetchAll();
        $mobileWorkflows = ['academic-class-schedule','academic-teaching-schedule','academic-monthly','academic-quarterly','academic-semester','academic-khmer-schedule','academic-khmer-monthly','academic-khmer-semester','academic-master-input','setting-announcement','setting-activities','setting-content','setting-school','setting-fee','setting-general','setting-bank-account','setting-currency','payment-invoice','pre-invoices','certificate-form-card'];
        $workflowData = [];
        $workflowStmt = $db->prepare('SELECT id,workflow,payload,created_at,updated_at FROM workflow_records WHERE workflow IN (' . implode(',',array_fill(0,count($mobileWorkflows),'?')) . ') ORDER BY id DESC');
        $workflowStmt->execute($mobileWorkflows);
        foreach ($workflowStmt->fetchAll() as $record) {
            $payload = json_decode($record['payload'],true);
            if (!is_array($payload)) continue;
            $status = strtolower(trim((string)($payload['Status'] ?? 'active')));
            if (in_array($record['workflow'],['setting-announcement','setting-activities'],true) && $status === 'inactive') continue;
            if ($record['workflow'] === 'pre-invoices' && (($payload['published_mobile'] ?? 'No') !== 'Yes' || ($payload['archived'] ?? 'No') === 'Yes')) continue;
            $targetStudent = trim((string)($payload['Student ID'] ?? $payload['Student'] ?? $payload['student_id'] ?? ''));
            if ($targetStudent !== '' && !in_array($targetStudent,[$studentId,(string)$student['name'],(string)$student['name_english']],true)) continue;
            $targetClass = trim((string)($payload['Class'] ?? $payload['class'] ?? ''));
            if ($targetClass !== '' && trim((string)$student['class']) !== '' && strcasecmp($targetClass,trim((string)$student['class'])) !== 0) continue;
            $workflowData[$record['workflow']][] = ['id'=>(string)$record['id'],'created_at'=>$record['created_at'],'updated_at'=>$record['updated_at']] + $payload;
        }
        unset($student['father_photo']);
        $generalSettings=$workflowData['setting-general'][0]??[];
        respond(['student'=>$student,'payments'=>$payments,'attendance'=>$attendance,'records'=>$workflowData,'id_card_svg'=>studentIdCardSvg($student,$generalSettings)]);
    }
    if ($method === 'POST' && $resource === 'mobile-change-password') {
        $student = requireMobileStudent($db); $data=body();
        $current=(string)($data['current_password']??''); $next=(string)($data['new_password']??'');
        if (strlen($next)<8) respond(['error'=>'New password must be at least 8 characters'],422);
        $stmt=$db->prepare('SELECT password_hash FROM mobile_accounts WHERE student_id=?'); $stmt->execute([$student['id']]);
        if (!password_verify($current,(string)$stmt->fetchColumn())) respond(['error'=>'Current password is incorrect'],401);
        $stmt=$db->prepare('UPDATE mobile_accounts SET password_hash=?,must_change_password=0,updated_at=CURRENT_TIMESTAMP WHERE student_id=?');
        $stmt->execute([password_hash($next,PASSWORD_DEFAULT),$student['id']]); respond(['ok'=>true]);
    }
    if ($method === 'POST' && $resource === 'mobile-attendance-scan') {
        $student=requireMobileStudent($db); $data=body(); $payload=trim((string)($data['payload']??''));
        if (!preg_match('#^wgattendance://checkin\?token=([a-f0-9]{64})$#i',$payload,$match)) respond(['error'=>'This is not a valid school attendance QR code'],422);
        $stmt=$db->prepare('SELECT audience FROM attendance_stations WHERE token_hash=? AND active=1 AND datetime(expires_at)>datetime(CURRENT_TIMESTAMP)');
        $stmt->execute([hash('sha256',strtolower($match[1]))]); $station=$stmt->fetch();
        if (!$station) respond(['error'=>'Attendance QR code is invalid or expired'],422);
        if (!in_array($station['audience'],['all','student'],true)) respond(['error'=>'This QR code is not for student attendance'],403);
        $date=date('Y-m-d'); $hour=(int)date('G'); $session=$hour<12?'Morning':($hour<17?'Afternoon':'Evening');
        $stmt=$db->prepare('INSERT INTO attendance(student_id,attendance_date,session,state) VALUES(?,?,?,?) ON CONFLICT(student_id,attendance_date,session) DO UPDATE SET state=excluded.state');
        $stmt->execute([$student['id'],$date,$session,'Present']);
        respond(['ok'=>true,'student_id'=>$student['id'],'date'=>$date,'session'=>$session,'state'=>'Present']);
    }
    if ($method === 'POST' && $resource === 'mobile-pay-invoice') {
        $student=requireMobileStudent($db); $data=body();
        $invoiceId=(int)($data['invoice_id']??0); $operation=trim((string)($data['operation']??'prepare'));
        if ($invoiceId<=0) respond(['error'=>'Invoice is required'],422);
        if (!in_array($operation,['prepare','complete'],true)) respond(['error'=>'Invalid payment operation'],422);
        $stmt=$db->prepare("SELECT id,payload FROM workflow_records WHERE id=? AND workflow='pre-invoices' LIMIT 1");
        $stmt->execute([$invoiceId]); $invoiceRow=$stmt->fetch();
        if (!$invoiceRow) respond(['error'=>'Invoice not found'],404);
        $invoice=json_decode((string)$invoiceRow['payload'],true);
        if (!is_array($invoice)) respond(['error'=>'Invoice data is invalid'],500);
        $invoiceStudent=trim((string)($invoice['student_id']??$invoice['Student ID']??''));
        if ($invoiceStudent!==(string)$student['id']) respond(['error'=>'This invoice does not belong to your account'],403);
        if (($invoice['published_mobile']??'No')!=='Yes'||($invoice['archived']??'No')==='Yes') respond(['error'=>'This invoice is not available for mobile payment'],409);
        $total=round((float)($invoice['total']??0),2); $paid=round((float)($invoice['paid']??0),2); $due=max(0,round($total-$paid,2));
        $currency=strtoupper(trim((string)($invoice['currency']??'USD')))?:'USD';
        $bankStmt=$db->query("SELECT id,payload FROM workflow_records WHERE workflow='setting-bank-account' ORDER BY id DESC");
        $bank=null;
        foreach ($bankStmt->fetchAll() as $bankRow) {
            $candidate=json_decode((string)$bankRow['payload'],true);
            if (!is_array($candidate)||strcasecmp(trim((string)($candidate['Status']??'Active')),'Inactive')===0) continue;
            if (strtoupper(trim((string)($candidate['Currency']??$currency)))===$currency) { $bank=['id'=>(string)$bankRow['id']]+$candidate; break; }
            if ($bank===null) $bank=['id'=>(string)$bankRow['id']]+$candidate;
        }
        if ($operation==='prepare') {
            if ($due<=0) respond(['ok'=>true,'status'=>'Paid','invoice_id'=>(string)$invoiceId,'invoice_no'=>(string)($invoice['invoice_no']??''),'amount'=>0,'currency'=>$currency]);
            if ($bank===null) respond(['error'=>'No active bank account is configured by the school'],409);
            $reference='PAY-'.str_pad((string)$invoiceId,6,'0',STR_PAD_LEFT).'-'.strtoupper(substr(hash('sha256',(string)$student['id'].'|'.$invoiceId),0,6));
            respond(['ok'=>true,'status'=>'Unpaid','invoice_id'=>(string)$invoiceId,'invoice_no'=>(string)($invoice['invoice_no']??''),'description'=>(string)($invoice['description']??'School fees'),'amount'=>$due,'currency'=>$currency,'reference'=>$reference,'payment_link'=>'wgpay://bank?invoice='.rawurlencode((string)($invoice['invoice_no']??$invoiceId)).'&reference='.rawurlencode($reference).'&amount='.rawurlencode(number_format($due,2,'.','')).'&currency='.rawurlencode($currency),'bank'=>$bank]);
        }
        if ($due<=0) respond(['ok'=>true,'status'=>'Paid','invoice_id'=>(string)$invoiceId,'receipt'=>(string)($invoice['payment_receipt']??'')]);
        if ($bank===null) respond(['error'=>'No active bank account is configured by the school'],409);
        $db->beginTransaction();
        try {
            $lock=$db->prepare("SELECT payload FROM workflow_records WHERE id=? AND workflow='pre-invoices'"); $lock->execute([$invoiceId]);
            $latest=json_decode((string)$lock->fetchColumn(),true); $latestPaid=round((float)($latest['paid']??0),2); $latestTotal=round((float)($latest['total']??0),2); $amount=max(0,round($latestTotal-$latestPaid,2));
            if ($amount<=0) { $db->commit(); respond(['ok'=>true,'status'=>'Paid','invoice_id'=>(string)$invoiceId,'receipt'=>(string)($latest['payment_receipt']??'')]); }
            $receipt='MOB-'.date('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))); $paidAt=date('Y-m-d H:i:s');
            $payment=$db->prepare('INSERT INTO payments(receipt,student_id,category,amount,method,paid_at,currency,branch,note) VALUES(?,?,?,?,?,?,?,?,?)');
            $payment->execute([$receipt,(string)$student['id'],(string)($latest['description']??'School fees'),$amount,'Bank Transfer',$paidAt,$currency,trim((string)($bank['Branch']??'SC1')),'Mobile payment for '.(string)($latest['invoice_no']??$invoiceId)]);
            $latest['paid']=$latestTotal; $latest['Status']='Paid'; $latest['payment_status']='Paid'; $latest['paid_at']=$paidAt; $latest['payment_receipt']=$receipt; $latest['payment_method']='Bank Transfer';
            $update=$db->prepare("UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND workflow='pre-invoices'"); $update->execute([json_encode($latest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$invoiceId]);
            $db->prepare('UPDATE students SET balance=MAX(0,balance-?) WHERE id=?')->execute([$amount,(string)$student['id']]);
            $db->commit(); respond(['ok'=>true,'status'=>'Paid','invoice_id'=>(string)$invoiceId,'receipt'=>$receipt,'paid_at'=>$paidAt,'amount'=>$amount,'currency'=>$currency]);
        } catch (Throwable $paymentError) {
            if ($db->inTransaction()) $db->rollBack();
            respond(['error'=>'Payment could not be completed','detail'=>$paymentError->getMessage()],500);
        }
    }
    if ($method === 'POST' && $resource === 'mobile-payment-proof') {
        $student=requireMobileStudent($db); $data=body(); $invoiceId=(int)($data['invoice_id']??0);
        $proof=trim((string)($data['receipt_image']??'')); $reference=trim((string)($data['reference']??''));
        if ($invoiceId<=0) respond(['error'=>'Invoice is required'],422);
        if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i',$proof)) respond(['error'=>'Upload a JPG, PNG or WEBP receipt image'],422);
        if (strlen($proof)>7*1024*1024) respond(['error'=>'Receipt image must be 5 MB or smaller'],413);
        $stmt=$db->prepare("SELECT payload FROM workflow_records WHERE id=? AND workflow='pre-invoices'"); $stmt->execute([$invoiceId]);
        $invoice=json_decode((string)$stmt->fetchColumn(),true);
        if (!is_array($invoice)) respond(['error'=>'Invoice not found'],404);
        if (trim((string)($invoice['student_id']??''))!==(string)$student['id']) respond(['error'=>'This invoice does not belong to your account'],403);
        if ((float)($invoice['paid']??0)>=(float)($invoice['total']??0)) respond(['ok'=>true,'status'=>'Paid','receipt'=>(string)($invoice['payment_receipt']??'')]);
        $existing=$db->prepare("SELECT id FROM workflow_records WHERE workflow='mobile-payment-proof' AND json_extract(payload,'$.invoice_id')=? AND json_extract(payload,'$.status')='Pending Verification' ORDER BY id DESC LIMIT 1");
        $existing->execute([(string)$invoiceId]); $existingId=$existing->fetchColumn();
        $payload=['invoice_id'=>(string)$invoiceId,'invoice_no'=>(string)($invoice['invoice_no']??''),'student_id'=>(string)$student['id'],'student'=>(string)($student['name_english']??$student['name']??''),'amount'=>max(0,(float)($invoice['total']??0)-(float)($invoice['paid']??0)),'currency'=>(string)($invoice['currency']??'USD'),'reference'=>$reference,'receipt_image'=>$proof,'status'=>'Pending Verification','submitted_at'=>date('Y-m-d H:i:s'),'reviewed_at'=>'','reviewed_by'=>'','note'=>''];
        if ($existingId) $db->prepare("UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND workflow='mobile-payment-proof'")->execute([json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$existingId]);
        else {$insert=$db->prepare("INSERT INTO workflow_records(workflow,payload) VALUES('mobile-payment-proof',?)");$insert->execute([json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$existingId=$db->lastInsertId();}
        $invoice['payment_status']='Pending Verification'; $invoice['payment_reference']=$reference;
        $db->prepare("UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND workflow='pre-invoices'")->execute([json_encode($invoice,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$invoiceId]);
        respond(['ok'=>true,'status'=>'Pending Verification','proof_id'=>(string)$existingId,'reference'=>$reference]);
    }
    if ($method === 'POST' && $resource === 'mobile-logout') {
        $token=bearerToken();
        if ($token!=='') $db->prepare('DELETE FROM student_mobile_tokens WHERE token_hash=?')->execute([hash('sha256',$token)]);
        respond(['ok'=>true]);
    }
    if (empty($_SESSION['authenticated'])) respond(['error'=>'Authentication required'],401);
    $resourceModules=['student'=>'student','delete-student'=>'student','admission'=>'front','admission-status'=>'front','admission-review'=>'front','approve-admission'=>'front','payment'=>'payment','mobile-payment-review'=>'payment','attendance'=>'attendance','attendance-station'=>'attendance','mobile-account'=>'student','auto-pre-invoices'=>'payment'];
    if(isset($resourceModules[$resource])){
        $action=$method==='GET'?'view':(str_starts_with($resource,'delete-')?'delete':(in_array($resource,['admission-status','admission-review','attendance'],true)?'edit':'create'));
        requireSystemAccess($db,$resourceModules[$resource],$action);
    }
    if ($method === 'GET' && $resource === 'database-health') {
        $quickCheck = $db->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN);
        $foreignKeyIssues = $db->query('PRAGMA foreign_key_check')->fetchAll();
        $counts = [];
        foreach (['students','admissions','payments','attendance','workflow_records'] as $table) {
            $counts[$table] = (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        respond([
            'ok'=>$quickCheck === ['ok'] && count($foreignKeyIssues) === 0,
            'integrity'=>$quickCheck,
            'foreign_key_issues'=>$foreignKeyIssues,
            'journal_mode'=>(string)$db->query('PRAGMA journal_mode')->fetchColumn(),
            'schema_version'=>(int)$db->query('PRAGMA user_version')->fetchColumn(),
            'counts'=>$counts
        ]);
    }
    if ($method === 'GET' && $resource === 'attendance-station') {
        $db->prepare('UPDATE attendance_stations SET active=0 WHERE datetime(expires_at)<=datetime(CURRENT_TIMESTAMP)')->execute();
        $plain=strtolower(bin2hex(random_bytes(32))); $expires=date('Y-m-d H:i:s',time()+12*60*60);
        $stmt=$db->prepare('INSERT INTO attendance_stations(token_hash,audience,expires_at) VALUES(?,?,?)');
        $stmt->execute([hash('sha256',$plain),'all',$expires]);
        respond(['ok'=>true,'payload'=>'wgattendance://checkin?token='.$plain,'expires_at'=>$expires,'audience'=>'all']);
    }
    if ($method === 'GET' && $resource === 'bootstrap') {
        $access=systemUserAccess($db);$may=static fn(string $module):bool=>$access['allow_full']||in_array('*',$access['modules'],true)||in_array($module,$access['modules'],true);
        $workflowRecords = [];
        foreach ($db->query('SELECT * FROM workflow_records ORDER BY id DESC')->fetchAll() as $record) {
            $publicWorkflow=in_array($record['workflow'],['setting-general','setting-content','setting-currency'],true);
            if(!$publicWorkflow&&!$may(workflowModule((string)$record['workflow'])))continue;
            $payload = json_decode($record['payload'], true) ?: [];
            $workflowRecords[$record['workflow']][] = ['id' => (string)$record['id']] + $payload;
        }
        respond([
            'students' => ($may('student')||$may('attendance')||$may('payment'))?$db->query('SELECT * FROM students ORDER BY created_at DESC, id')->fetchAll():[],
            'admissions' => $may('front')?$db->query('SELECT * FROM admissions ORDER BY id DESC')->fetchAll():[],
            'payments' => ($may('payment')||$may('accounting'))?$db->query('SELECT p.*, s.name student_name FROM payments p JOIN students s ON s.id=p.student_id ORDER BY p.id DESC')->fetchAll():[],
            'attendance' => $may('attendance')?$db->query('SELECT * FROM attendance ORDER BY attendance_date DESC,id DESC')->fetchAll():[],
            'records' => $workflowRecords,
            'finance_summary' => ($may('payment')||$may('accounting')||$may('report'))?financeSummary($db, $workflowRecords):null,
            'current_user' => $access
        ]);
    }
    if ($method === 'POST') requireCsrf();

    if ($method === 'POST' && $resource === 'mobile-account') {
        $data=body(); $studentId=strtoupper(trim((string)($data['student_id']??''))); $password=(string)($data['password']??'');
        if ($studentId==='' || strlen($password)<8) respond(['error'=>'Student ID and a password of at least 8 characters are required'],422);
        $stmt=$db->prepare('SELECT 1 FROM students WHERE id=?'); $stmt->execute([$studentId]);
        if (!$stmt->fetchColumn()) respond(['error'=>'Student not found'],404);
        $active=($data['active']??true)?1:0; $mustChange=($data['must_change_password']??true)?1:0;
        $stmt=$db->prepare('INSERT INTO mobile_accounts(student_id,password_hash,active,must_change_password) VALUES(?,?,?,?) ON CONFLICT(student_id) DO UPDATE SET password_hash=excluded.password_hash,active=excluded.active,must_change_password=excluded.must_change_password,updated_at=CURRENT_TIMESTAMP');
        $stmt->execute([$studentId,password_hash($password,PASSWORD_DEFAULT),$active,$mustChange]);
        $db->prepare('DELETE FROM student_mobile_tokens WHERE student_id=?')->execute([$studentId]);
        respond(['ok'=>true,'student_id'=>$studentId]);
    }

    if ($method === 'POST' && $resource === 'student') {
        $data = body();
        foreach (['id','name','class','guardian'] as $field) if (trim((string)($data[$field] ?? '')) === '') respond(['error' => "$field is required"], 422);
        $stmt = $db->prepare('INSERT INTO students(id,name,class,guardian,phone,status,balance,attendance,photo,name_khmer,name_english,name_chinese,program,time,room,gender,birth_date,admission_date,father_name,father_phone,father_occupation,mother_name,mother_phone,mother_occupation,guardian_relation,guardian_phone,guardian_occupation,guardian_name,guardian_email,guardian_address,father_photo)
            VALUES(:id,:name,:class,:guardian,:phone,:status,:balance,:attendance,:photo,:name_khmer,:name_english,:name_chinese,:program,:time,:room,:gender,:birth_date,:admission_date,:father_name,:father_phone,:father_occupation,:mother_name,:mother_phone,:mother_occupation,:guardian_relation,:guardian_phone,:guardian_occupation,:guardian_name,:guardian_email,:guardian_address,:father_photo)
            ON CONFLICT(id) DO UPDATE SET name=excluded.name,class=excluded.class,guardian=excluded.guardian,phone=excluded.phone,status=excluded.status,photo=excluded.photo,name_khmer=excluded.name_khmer,name_english=excluded.name_english,name_chinese=excluded.name_chinese,program=excluded.program,time=excluded.time,room=excluded.room,gender=excluded.gender,birth_date=excluded.birth_date,admission_date=excluded.admission_date,father_name=excluded.father_name,father_phone=excluded.father_phone,father_occupation=excluded.father_occupation,mother_name=excluded.mother_name,mother_phone=excluded.mother_phone,mother_occupation=excluded.mother_occupation,guardian_relation=excluded.guardian_relation,guardian_phone=excluded.guardian_phone,guardian_occupation=excluded.guardian_occupation,guardian_name=excluded.guardian_name,guardian_email=excluded.guardian_email,guardian_address=excluded.guardian_address,father_photo=excluded.father_photo');
        $stmt->execute([':id'=>$data['id'],':name'=>$data['name'],':class'=>$data['class'],':guardian'=>$data['guardian'],':phone'=>$data['phone']??'',':status'=>$data['status']??'Active',':balance'=>(float)($data['balance']??0),':attendance'=>(float)($data['attendance']??100),':photo'=>$data['photo']??'',':name_khmer'=>$data['name_khmer']??'',':name_english'=>$data['name_english']??'',':name_chinese'=>$data['name_chinese']??'',':program'=>$data['program']??'',':time'=>$data['time']??'',':room'=>$data['room']??'',':gender'=>$data['gender']??'',':birth_date'=>$data['birth_date']??'',':admission_date'=>$data['admission_date']??'',':father_name'=>$data['father_name']??'',':father_phone'=>$data['father_phone']??'',':father_occupation'=>$data['father_occupation']??'',':mother_name'=>$data['mother_name']??'',':mother_phone'=>$data['mother_phone']??'',':mother_occupation'=>$data['mother_occupation']??'',':guardian_relation'=>$data['guardian_relation']??'',':guardian_phone'=>$data['guardian_phone']??'',':guardian_occupation'=>$data['guardian_occupation']??'',':guardian_name'=>$data['guardian_name']??($data['guardian']??''),':guardian_email'=>$data['guardian_email']??'',':guardian_address'=>$data['guardian_address']??'',':father_photo'=>$data['father_photo']??'']);
        createStudentMobileAccount($db,strtoupper(trim((string)$data['id'])),(string)($data['phone']??''));
        respond(['ok' => true]);
    }
    if ($method === 'POST' && $resource === 'delete-student') {
        $id = trim((string)(body()['id'] ?? ''));
        if ($id === '') respond(['error' => 'Student ID is required'], 422);
        $stmt = $db->prepare('DELETE FROM students WHERE id=?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) respond(['error' => 'Student not found'], 404);
        respond(['ok' => true]);
    }
    if ($method === 'POST' && $resource === 'payment') {
        $data = body(); $amount=(float)($data['amount']??0);
        $studentId = trim((string)($data['student'] ?? ''));
        if ($amount <= 0) respond(['error'=>'Amount must be positive'],422);
        if ($studentId === '') respond(['error'=>'Student is required'],422);
        $stmt=$db->prepare('SELECT 1 FROM students WHERE id=?'); $stmt->execute([$studentId]);
        if (!$stmt->fetchColumn()) respond(['error'=>'Student not found'],404);
        $category=trim((string)($data['category']??'Tuition')) ?: 'Tuition';
        $methodName=trim((string)($data['method']??'Cash')) ?: 'Cash';
        if (!in_array($methodName,['Cash','Bank','Bank transfer','Bank Transfer','Card','KHQR'],true)) respond(['error'=>'Invalid payment method'],422);
        $configuredCurrencies=currencySettings($db); $defaultCurrency=array_key_first(array_filter($configuredCurrencies,static fn(array $row):bool=>$row['default']));
        $currency=validCurrency($db,(string)($data['currency']??$defaultCurrency));
        $paidInput=trim((string)($data['paid_at']??$data['date']??date('Y-m-d')));
        $paidDate=DateTimeImmutable::createFromFormat('!Y-m-d',substr($paidInput,0,10));
        if (!$paidDate||$paidDate->format('Y-m-d')!==substr($paidInput,0,10)) respond(['error'=>'Invalid payment date'],422);
        $paidAt=$paidDate->format('Y-m-d').' '.(strlen($paidInput)>10?substr($paidInput,11,8):date('H:i:s'));
        $db->beginTransaction();
        $receipt='RC-'.date('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));
        $stmt=$db->prepare('INSERT INTO payments(receipt,student_id,category,amount,method,paid_at,currency,branch,note) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$receipt,$studentId,$category,$amount,$methodName,$paidAt,$currency,trim((string)($data['branch']??'SC1')),trim((string)($data['note']??''))]);
        $stmt=$db->prepare('UPDATE students SET balance=MAX(0,balance-?) WHERE id=?');
        $stmt->execute([$amount,$studentId]);
        $db->commit(); respond(['ok'=>true,'receipt'=>$receipt]);
    }
    if ($method === 'POST' && $resource === 'mobile-payment-review') {
        $data=body(); $proofId=(int)($data['proof_id']??0); $decision=trim((string)($data['decision']??'')); $note=trim((string)($data['note']??''));
        if ($proofId<=0||!in_array($decision,['approve','reject'],true)) respond(['error'=>'Valid payment proof and decision are required'],422);
        $stmt=$db->prepare("SELECT payload FROM workflow_records WHERE id=? AND workflow='mobile-payment-proof'"); $stmt->execute([$proofId]); $proof=json_decode((string)$stmt->fetchColumn(),true);
        if (!is_array($proof)) respond(['error'=>'Payment proof not found'],404);
        if (($proof['status']??'')!=='Pending Verification') respond(['error'=>'This payment proof has already been reviewed'],409);
        $invoiceId=(int)($proof['invoice_id']??0); $stmt=$db->prepare("SELECT payload FROM workflow_records WHERE id=? AND workflow='pre-invoices'"); $stmt->execute([$invoiceId]); $invoice=json_decode((string)$stmt->fetchColumn(),true);
        if (!is_array($invoice)) respond(['error'=>'Linked invoice not found'],404);
        $user=systemUserAccess($db); $reviewer=(string)($user['display_name']??$user['username']??'Administrator');
        if ($decision==='reject') {
            $proof['status']='Rejected';$proof['reviewed_at']=date('Y-m-d H:i:s');$proof['reviewed_by']=$reviewer;$proof['note']=$note;
            $invoice['payment_status']='Rejected';
            $db->beginTransaction();$db->prepare("UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND workflow='mobile-payment-proof'")->execute([json_encode($proof,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$proofId]);$db->prepare("UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND workflow='pre-invoices'")->execute([json_encode($invoice,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$invoiceId]);$db->commit();respond(['ok'=>true,'status'=>'Rejected']);
        }
        $total=round((float)($invoice['total']??0),2);$alreadyPaid=round((float)($invoice['paid']??0),2);$amount=max(0,round($total-$alreadyPaid,2));
        $db->beginTransaction();
        try {
            $receipt=(string)($invoice['payment_receipt']??'');
            if ($amount>0) {$receipt='MOB-'.date('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2)));$paidAt=date('Y-m-d H:i:s');$db->prepare('INSERT INTO payments(receipt,student_id,category,amount,method,paid_at,currency,branch,note) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$receipt,(string)$proof['student_id'],(string)($invoice['description']??'School fees'),$amount,'Bank Transfer',$paidAt,(string)($invoice['currency']??'USD'),'SC1','Verified receipt '.(string)($proof['reference']??'')]);$db->prepare('UPDATE students SET balance=MAX(0,balance-?) WHERE id=?')->execute([$amount,(string)$proof['student_id']]);$invoice['paid']=$total;$invoice['Status']='Paid';$invoice['payment_status']='Paid';$invoice['paid_at']=$paidAt;$invoice['payment_receipt']=$receipt;$invoice['payment_method']='Bank Transfer';}
            $proof['status']='Approved';$proof['reviewed_at']=date('Y-m-d H:i:s');$proof['reviewed_by']=$reviewer;$proof['note']=$note;$proof['receipt']=$receipt;
            $db->prepare("UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND workflow='mobile-payment-proof'")->execute([json_encode($proof,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$proofId]);$db->prepare("UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND workflow='pre-invoices'")->execute([json_encode($invoice,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$invoiceId]);$db->commit();respond(['ok'=>true,'status'=>'Paid','receipt'=>$receipt]);
        } catch (Throwable $reviewError) {if($db->inTransaction())$db->rollBack();respond(['error'=>'Payment review could not be saved','detail'=>$reviewError->getMessage()],500);}
    }
    if ($method === 'POST' && $resource === 'attendance') {
        $data=body(); $date=(string)($data['date']??date('Y-m-d')); $session=(string)($data['session']??'Morning');
        $validDate=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$validDate||$validDate->format('Y-m-d')!==$date) respond(['error'=>'Invalid attendance date'],422);
        if (!in_array($session,['Morning','Afternoon','Evening'],true)) respond(['error'=>'Invalid attendance session'],422);
        if (!is_array($data['records']??null)) respond(['error'=>'Attendance records are required'],422);
        $stmt=$db->prepare('INSERT INTO attendance(student_id,attendance_date,session,state) VALUES(?,?,?,?) ON CONFLICT(student_id,attendance_date,session) DO UPDATE SET state=excluded.state');
        $db->beginTransaction();
        foreach ($data['records'] as $studentId=>$value) {
            $value=(string)$value;
            if (!in_array($value,['P','L','A','F','Present','Late','Absent','Excused'],true)) { $db->rollBack(); respond(['error'=>'Invalid attendance state'],422); }
            $stmt->execute([(string)$studentId,$date,$session,$value]);
        }
        $db->commit();
        respond(['ok'=>true]);
    }
    if ($method === 'POST' && $resource === 'admission') {
        $data=body();
        foreach (['student_name','guardian'] as $field) if (trim((string)($data[$field] ?? '')) === '') respond(['error' => "$field is required"], 422);
        $stmt=$db->prepare('INSERT INTO admissions(student_name,guardian,phone,requested_class,source,status,name_english,name_khmer,gender,birth_date,program,section) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$data['student_name'],$data['guardian'],$data['phone']??'',$data['requested_class']??'Unassigned',$data['source']??'Walk-in','Inquiry',$data['name_english']??$data['student_name'],$data['name_khmer']??'',$data['gender']??'',$data['birth_date']??'',$data['program']??'',$data['section']??'']);
        respond(['ok'=>true,'id'=>(string)$db->lastInsertId()]);
    }
    if ($method === 'POST' && $resource === 'admission-status') {
        $data=body(); $allowed=['Inquiry','Assessment','Approved','Update Requested','Rejected'];
        if (!in_array($data['status']??'', $allowed, true)) respond(['error'=>'Invalid admission status'],422);
        $stmt=$db->prepare('UPDATE admissions SET status=? WHERE id=?');
        $stmt->execute([$data['status'],(int)($data['id']??0)]);
        if ($stmt->rowCount()===0) respond(['error'=>'Admission not found'],404);
        respond(['ok'=>true]);
    }
    if ($method === 'POST' && $resource === 'admission-review') {
        $data=body(); $allowed=['Approved','Update Requested','Rejected'];
        $status=(string)($data['status']??''); $remark=trim((string)($data['remark']??''));
        if (!in_array($status,$allowed,true)) respond(['error'=>'Invalid admission review status'],422);
        if (($status==='Rejected'||$status==='Update Requested') && $remark==='') respond(['error'=>'Remark is required for Reject or Request Update'],422);
        $reviewedBy=trim((string)($data['reviewed_by']??'Super Admin')) ?: 'Super Admin';
        $stmt=$db->prepare('UPDATE admissions SET status=?, remark=?, reviewed_by=?, reviewed_date=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$status,$remark,$reviewedBy,(int)($data['id']??0)]);
        if ($stmt->rowCount()===0) respond(['error'=>'Admission not found'],404);
        respond(['ok'=>true,'status'=>$status,'reviewed_by'=>$reviewedBy]);
    }
    if ($method === 'POST' && $resource === 'workflow') {
        $data = body(); $workflow = trim((string)($data['workflow'] ?? ''));
        $payload = $data['payload'] ?? []; $id = (int)($data['id'] ?? 0);
        if ($workflow === '' || !is_array($payload)) respond(['error'=>'Invalid workflow record'],422);
        requireSystemAccess($db,workflowModule($workflow),$id>0?'edit':'create');
        $systemUserPassword=null;$generatedTemporaryPassword=null;$newSystemUser=$workflow==='users'&&$id===0;
        if($workflow==='users'){
            foreach(['First Name','Last Name','User Name','Email','Phone','Role'] as $field){$payload[$field]=trim((string)($payload[$field]??''));if($payload[$field]==='')respond(['error'=>"$field is required"],422);}
            if(!filter_var($payload['Email'],FILTER_VALIDATE_EMAIL))respond(['error'=>'Enter a valid email address'],422);
            if(!preg_match('/^[A-Za-z0-9._-]{3,50}$/',$payload['User Name']))respond(['error'=>'Username must be 3-50 characters and use only letters, numbers, dot, dash or underscore'],422);
            $password=(string)($payload['Password']??'');$confirmation=(string)($payload['Password Confirmation']??'');
            if($password!==''&&$password!==$confirmation)respond(['error'=>'Password confirmation does not match'],422);
            if($password!==''&&strlen($password)<6)respond(['error'=>'Password must be at least 6 characters'],422);
            if($newSystemUser){$alphabet='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';$generatedTemporaryPassword='';for($i=0;$i<10;$i++)$generatedTemporaryPassword.=$alphabet[random_int(0,strlen($alphabet)-1)];$generatedTemporaryPassword.='!'.random_int(10,99);$systemUserPassword=$generatedTemporaryPassword;}elseif($password!=='')$systemUserPassword=$password;
            unset($payload['Password'],$payload['Password Confirmation']);
            $payload['Status']=in_array(($payload['Status']??'Active'),['Active','Inactive'],true)?$payload['Status']:'Active';
            $payload['Allow Full']=($payload['Allow Full']??'No')==='Yes'?'Yes':'No';
            $duplicate=$db->prepare('SELECT workflow_id FROM system_users WHERE (email=? OR username=?) AND COALESCE(workflow_id,0)<>? LIMIT 1');
            $duplicate->execute([$payload['Email'],$payload['User Name'],$id]);
            if($duplicate->fetchColumn())respond(['error'=>'Username or email already exists'],409);
        }
        $payload = accountingPayload($db, $workflow, $payload);
        if ($workflow === 'setting-currency') {
            foreach (['Currency','Symbol','Code','Rate'] as $field) {
                $payload[$field]=trim((string)($payload[$field]??''));
                if ($payload[$field]==='') respond(['error'=>"$field is required"],422);
            }
            $payload['Code']=strtoupper($payload['Code']);
            if (!preg_match('/^[A-Z0-9]{3,8}$/',$payload['Code'])) respond(['error'=>'Currency code must contain 3 to 8 letters or numbers'],422);
            $submittedRate=(float)str_replace(',','',$payload['Rate']);
            if (!is_finite($submittedRate) || $submittedRate<=0) respond(['error'=>'Currency rate must be greater than zero'],422);
            $payload['Is Default']=($payload['Is Default']??'No')==='Yes'?'Yes':'No';
            $records=$db->query("SELECT id,payload FROM workflow_records WHERE workflow='setting-currency'")->fetchAll();
            foreach($records as $record){$saved=json_decode((string)$record['payload'],true);if((int)$record['id']!==$id&&is_array($saved)&&strcasecmp((string)($saved['Code']??''),$payload['Code'])===0)respond(['error'=>'Currency code already exists'],409);}
            if ($payload['Is Default']==='Yes') {
                $factor=$submittedRate; $updateCurrency=$db->prepare('UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                foreach($records as $record){if((int)$record['id']===$id)continue;$saved=json_decode((string)$record['payload'],true);if(!is_array($saved))continue;$oldRate=(float)str_replace(',','',(string)($saved['Rate']??1));$saved['Rate']=max(0.000001,$oldRate/$factor);$saved['Is Default']='No';$updateCurrency->execute([json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(int)$record['id']]);}
                $payload['Rate']=1;
            } else {
                $payload['Rate']=$submittedRate;
                $hasOtherDefault=false; foreach($records as $record){if((int)$record['id']===$id)continue;$saved=json_decode((string)$record['payload'],true);if(is_array($saved)&&($saved['Is Default']??'No')==='Yes'){$hasOtherDefault=true;break;}}
                if(!$hasOtherDefault){$payload['Is Default']='Yes';$payload['Rate']=1;}
            }
        }
        if ($workflow === 'setting-announcement') {
            $payload['Title']=trim((string)($payload['Title']??''));
            $payload['Description']=trim((string)($payload['Description']??''));
            if ($payload['Title']==='' || $payload['Description']==='') respond(['error'=>'Announcement title and description are required'],422);
            $payload['Status']=trim((string)($payload['Status']??'Active'));
            if (!in_array($payload['Status'],['Active','Inactive'],true)) respond(['error'=>'Invalid announcement status'],422);
            $payload['Image']=trim((string)($payload['Image']??''));
            $payload['UpdatedBy']='Super Admin';
            $payload['UpdatedAt']=date('d/m/Y H:i');
        }
        if ($workflow === 'setting-activities') {
            $payload['Title']=trim((string)($payload['Title']??''));
            if ($payload['Title']==='') respond(['error'=>'Activity title is required'],422);
            $payload['Status']=trim((string)($payload['Status']??'Active'));
            if (!in_array($payload['Status'],['Active','Inactive'],true)) respond(['error'=>'Invalid activity status'],422);
            $payload['Image']=trim((string)($payload['Image']??''));
            $payload['UpdatedBy']='Super Admin';
            $payload['UpdatedAt']=date('d/m/Y H:i');
        }
        if ($workflow === 'pre-invoices') {
            foreach (['student_id','student','description','invoice_date','due_date'] as $field) {
                $payload[$field] = trim((string)($payload[$field] ?? ''));
                if ($payload[$field] === '') respond(['error'=>"$field is required"],422);
            }
            $studentStmt=$db->prepare('SELECT name,class FROM students WHERE id=?');
            $studentStmt->execute([$payload['student_id']]); $invoiceStudent=$studentStmt->fetch();
            if (!$invoiceStudent) respond(['error'=>'Student not found'],404);
            $payload['student']=(string)$invoiceStudent['name']; $payload['class']=(string)$invoiceStudent['class'];
            foreach (['invoice_date','due_date'] as $field) {
                $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$payload[$field]);
                if (!$parsed || $parsed->format('Y-m-d') !== $payload[$field]) respond(['error'=>"Invalid $field"],422);
            }
            if ($payload['due_date'] < $payload['invoice_date']) respond(['error'=>'Due date cannot be before invoice date'],422);
            $payload['total']=round((float)($payload['total']??0),2);
            $payload['discount']=round((float)($payload['discount']??0),2);
            $payload['paid']=round((float)($payload['paid']??0),2);
            $configuredCurrencies=currencySettings($db);$invoiceDefault=array_key_first(array_filter($configuredCurrencies,static fn(array $row):bool=>$row['default']));
            $payload['currency']=validCurrency($db,(string)($payload['currency']??$invoiceDefault));
            if ($payload['total'] <= 0 || $payload['discount'] < 0 || $payload['paid'] < 0) respond(['error'=>'Invalid pre-invoice amounts'],422);
            $payload['published_mobile']=($payload['published_mobile']??'No')==='Yes'?'Yes':'No';
            $payload['archived']=($payload['archived']??'No')==='Yes'?'Yes':'No';
            $payload['confirmed']=($payload['confirmed']??'No')==='Yes'?'Yes':'No';
            if ($payload['published_mobile']==='Yes' && trim((string)($payload['released_at']??''))==='') {
                $existingReleasedAt='';
                if ($id>0) {
                    $releasedStmt=$db->prepare("SELECT json_extract(payload,'$.released_at') FROM workflow_records WHERE id=? AND workflow='pre-invoices'");
                    $releasedStmt->execute([$id]);
                    $existingReleasedAt=trim((string)$releasedStmt->fetchColumn());
                }
                $payload['released_at']=$existingReleasedAt!==''?$existingReleasedAt:date('Y-m-d H:i:s');
            }
        }
        if ($workflow === 'setting-bank-account') {
            foreach (['Bank Name','Account Name','Account Number','Currency'] as $field) {
                $payload[$field] = trim((string)($payload[$field] ?? ''));
                if ($payload[$field] === '') respond(['error'=>"$field is required"],422);
            }
            $payload['Currency'] = strtoupper($payload['Currency']);
            $payload['Currency']=validCurrency($db,$payload['Currency']);
            $payload['Status'] = trim((string)($payload['Status'] ?? 'Active'));
            if (!in_array($payload['Status'],['Active','Inactive'],true)) respond(['error'=>'Invalid bank account status'],422);
            foreach (['Branch','SWIFT','Note','QR Image'] as $field) $payload[$field] = trim((string)($payload[$field] ?? ''));
            $bankRecords = $db->query("SELECT id,payload FROM workflow_records WHERE workflow='setting-bank-account'")->fetchAll();
            foreach ($bankRecords as $bankRecord) {
                $savedBank = json_decode($bankRecord['payload'],true);
                if ((int)$bankRecord['id'] !== $id && is_array($savedBank) && trim((string)($savedBank['Account Number'] ?? '')) === $payload['Account Number']) respond(['error'=>'Bank account number already exists'],409);
            }
        }
        if ($workflow === 'accounting-chart') {
            $existingAccounts = $db->query("SELECT id,payload FROM workflow_records WHERE workflow='accounting-chart'")->fetchAll();
            foreach ($existingAccounts as $existingAccount) {
                $existingPayload = json_decode($existingAccount['payload'], true);
                if ((int)$existingAccount['id'] !== $id && is_array($existingPayload) && strcasecmp(trim((string)($existingPayload['Code'] ?? '')), $payload['Code']) === 0) {
                    respond(['error'=>'Account code already exists'],409);
                }
            }
        }
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($id > 0) {
            $stmt=$db->prepare('UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND workflow=?');
            $stmt->execute([$encodedPayload,$id,$workflow]);
            if ($stmt->rowCount() === 0) respond(['error'=>'Record not found'],404);
        } else {
            $stmt=$db->prepare('INSERT INTO workflow_records(workflow,payload) VALUES(?,?)');
            $stmt->execute([$workflow,$encodedPayload]); $id=(int)$db->lastInsertId();
        }
        if($workflow==='users'){
            $existing=$db->prepare('SELECT password_hash FROM system_users WHERE workflow_id=?');$existing->execute([$id]);$existingHash=$existing->fetchColumn();
            $hash=$systemUserPassword!==null?password_hash($systemUserPassword,PASSWORD_DEFAULT):(is_string($existingHash)&&$existingHash!==''?$existingHash:'');
            if($hash==='')respond(['error'=>'Password is required for a new user'],422);
            $upsert=$db->prepare('INSERT INTO system_users(workflow_id,username,email,password_hash,role,status,allow_full) VALUES(?,?,?,?,?,?,?) ON CONFLICT(workflow_id) DO UPDATE SET username=excluded.username,email=excluded.email,password_hash=excluded.password_hash,role=excluded.role,status=excluded.status,allow_full=excluded.allow_full,updated_at=CURRENT_TIMESTAMP');
            $upsert->execute([$id,$payload['User Name'],$payload['Email'],$hash,$payload['Role'],$payload['Status'],$payload['Allow Full']==='Yes'?1:0]);
            if($newSystemUser)$db->prepare('UPDATE system_users SET must_change_password=1 WHERE workflow_id=?')->execute([$id]);
        }
        respond(['ok'=>true,'id'=>(string)$id,'temporary_password'=>$generatedTemporaryPassword,'must_change_password'=>$newSystemUser]);
    }
    if ($method === 'POST' && $resource === 'auto-pre-invoices') {
        $data=body(); $operation=trim((string)($data['operation']??'generate'));
        if ($operation === 'confirm') {
            $ids=array_values(array_unique(array_filter(array_map('intval',(array)($data['ids']??[])),static fn(int $id):bool=>$id>0)));
            if (!$ids) respond(['error'=>'Select at least one pre-invoice'],422);
            $db->beginTransaction(); $select=$db->prepare("SELECT payload FROM workflow_records WHERE id=? AND workflow='pre-invoices'");
            $update=$db->prepare("UPDATE workflow_records SET payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND workflow='pre-invoices'");
            foreach ($ids as $id) {
                $select->execute([$id]); $payload=json_decode((string)$select->fetchColumn(),true);
                if (!is_array($payload) || ($payload['auto_generated']??'No')!=='Yes') { $db->rollBack(); respond(['error'=>'Auto pre-invoice not found'],404); }
                $payload['confirmed']='Yes'; $payload['published_mobile']='Yes';
                if (trim((string)($payload['released_at']??''))==='') $payload['released_at']=date('Y-m-d H:i:s');
                $update->execute([json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$id]);
            }
            $db->commit(); respond(['ok'=>true,'confirmed'=>count($ids)]);
        }
        if ($operation !== 'generate') respond(['error'=>'Invalid auto pre-invoice operation'],422);
        $studentIds=array_values(array_unique(array_filter(array_map(static fn(mixed $id):string=>strtoupper(trim((string)$id)),(array)($data['student_ids']??[])))));
        $description=trim((string)($data['description']??'')); $year=trim((string)($data['year']??''));
        $program=trim((string)($data['program']??'All Programs')); $dueDate=trim((string)($data['due_date']??''));
        $configuredCurrencies=currencySettings($db);$invoiceDefault=array_key_first(array_filter($configuredCurrencies,static fn(array $row):bool=>$row['default']));$currency=validCurrency($db,(string)($data['currency']??$invoiceDefault));
        $amount=round((float)($data['amount']??0),2); $invoiceDate=date('Y-m-d');
        if (!$studentIds) respond(['error'=>'Select at least one student'],422);
        if ($description==='' || $amount<=0) respond(['error'=>'Description and a positive amount are required'],422);
        if (!preg_match('/^\d{4}-\d{4}$/',$year)) respond(['error'=>'Invalid academic year'],422);
        $due=DateTimeImmutable::createFromFormat('!Y-m-d',$dueDate);
        if (!$due || $due->format('Y-m-d')!==$dueDate || $dueDate<$invoiceDate) respond(['error'=>'Due date must be today or later'],422);
        $studentStmt=$db->prepare('SELECT id,name,class,program,status FROM students WHERE id=?');
        $duplicateStmt=$db->prepare("SELECT 1 FROM workflow_records WHERE workflow='pre-invoices' AND json_extract(payload,'$.student_id')=? AND json_extract(payload,'$.description')=? AND json_extract(payload,'$.due_date')=? AND COALESCE(json_extract(payload,'$.archived'),'No')!='Yes' LIMIT 1");
        $insert=$db->prepare("INSERT INTO workflow_records(workflow,payload) VALUES('pre-invoices',?)");
        $updateNumber=$db->prepare('UPDATE workflow_records SET payload=? WHERE id=?'); $created=[];
        $db->beginTransaction();
        foreach ($studentIds as $studentId) {
            $studentStmt->execute([$studentId]); $student=$studentStmt->fetch();
            if (!$student || $student['status']!=='Active') { $db->rollBack(); respond(['error'=>"Active student $studentId was not found"],404); }
            if ($program!=='All Programs' && strcasecmp(trim((string)$student['program']),$program)!==0) { $db->rollBack(); respond(['error'=>"$studentId is not enrolled in $program"],422); }
            $duplicateStmt->execute([$studentId,$description,$dueDate]);
            if ($duplicateStmt->fetchColumn()) { $db->rollBack(); respond(['error'=>"A matching active pre-invoice already exists for $studentId"],409); }
            $payload=['student_id'=>$studentId,'student'=>$student['name'],'class'=>$student['class'],'description'=>$description,'total'=>$amount,'discount'=>0,'paid'=>0,'invoice_date'=>$invoiceDate,'due_date'=>$dueDate,'note'=>"Auto generated · $year · $program",'academic_year'=>$year,'program'=>$program,'auto_generated'=>'Yes','confirmed'=>'No','published_mobile'=>'No','archived'=>'No'];
            $payload['currency']=$currency;
            $insert->execute([json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]); $id=(int)$db->lastInsertId();
            $payload['invoice_no']='PI-'.date('ymd').'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);
            $updateNumber->execute([json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$id]);
            $created[]=['id'=>(string)$id]+$payload;
        }
        $db->commit(); respond(['ok'=>true,'created'=>$created,'count'=>count($created)]);
    }
    if ($method === 'POST' && $resource === 'delete-workflow') {
        $data=body(); $id=(int)($data['id']??0); $workflow=trim((string)($data['workflow']??''));
        if ($id <= 0 || $workflow === '') respond(['error'=>'Workflow and record ID are required'],422);
        requireSystemAccess($db,workflowModule($workflow),'delete');
        if($workflow==='setting-currency'){$check=$db->prepare("SELECT payload FROM workflow_records WHERE id=? AND workflow='setting-currency'");$check->execute([$id]);$currency=json_decode((string)$check->fetchColumn(),true);if(is_array($currency)&&($currency['Is Default']??'No')==='Yes')respond(['error'=>'Choose another default currency before deleting this one'],409);}
        if($workflow==='users'){
            $current=$db->prepare('SELECT workflow_id,role FROM system_users WHERE id=?');
            $current->execute([(int)($_SESSION['user_id']??0)]);
            $actor=$current->fetch()?:[];
            if((int)($actor['workflow_id']??0)===$id)respond(['error'=>'You cannot delete the account currently signed in'],409);
            $target=$db->prepare('SELECT role FROM system_users WHERE workflow_id=?');
            $target->execute([$id]);
            $actorRole=strtolower(trim((string)($actor['role']??'')));
            $targetRole=strtolower(trim((string)$target->fetchColumn()));
            if($actorRole==='vice school director'&&in_array($targetRole,['super administrator','super admin'],true))respond(['error'=>'Vice School Director cannot delete a Super Administrator account'],403);
            $db->prepare('DELETE FROM system_users WHERE workflow_id=?')->execute([$id]);
        }
        $stmt=$db->prepare('DELETE FROM workflow_records WHERE id=? AND workflow=?');
        $stmt->execute([$id,$workflow]);
        if ($stmt->rowCount() === 0) respond(['error'=>'Record not found'],404);
        respond(['ok'=>true]);
    }
    if ($method === 'POST' && $resource === 'approve-admission') {
        $id=(int)(body()['id']??0); $db->beginTransaction();
        $stmt=$db->prepare('SELECT * FROM admissions WHERE id=?'); $stmt->execute([$id]); $admission=$stmt->fetch();
        if (!$admission) { $db->rollBack(); respond(['error'=>'Admission not found'],404); }
        if ($admission['status'] === 'Enrolled') { $db->rollBack(); respond(['error'=>'Admission is already enrolled'],409); }
        if ($admission['status'] !== 'Approved') { $db->rollBack(); respond(['error'=>'Admission must be approved before enrollment'],409); }
        $maxStudentNumber = 2040;
        foreach ($db->query("SELECT id FROM students WHERE id LIKE 'ST-%'")->fetchAll(PDO::FETCH_COLUMN) as $existingId) {
            if (preg_match('/^ST-(\d+)$/', (string)$existingId, $match)) $maxStudentNumber = max($maxStudentNumber, (int)$match[1]);
        }
        $studentId='ST-'.($maxStudentNumber+1);
        $stmt=$db->prepare('INSERT INTO students(id,name,class,guardian,phone,status,balance,attendance,name_english,name_khmer,gender,birth_date,program,time,admission_date) VALUES(?,?,?,?,?,"Active",0,100,?,?,?,?,?,?,?)');
        $stmt->execute([$studentId,$admission['student_name'],$admission['requested_class'],$admission['guardian'],$admission['phone'],$admission['name_english']?:$admission['student_name'],$admission['name_khmer'],$admission['gender'],$admission['birth_date'],$admission['program'],$admission['section'],date('Y-m-d')]);
        createStudentMobileAccount($db,$studentId,(string)$admission['phone']);
        $stmt=$db->prepare('UPDATE admissions SET status="Enrolled" WHERE id=?'); $stmt->execute([$id]);
        $db->commit(); respond(['ok'=>true,'student_id'=>$studentId]);
    }
    respond(['error' => 'Route not found'], 404);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('WG API request failed: '.$e->getMessage());
    respond(['error' => 'Request failed'], 500);
}
