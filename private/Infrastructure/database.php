<?php
declare(strict_types=1);
$storage = STORAGE_PATH;
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

