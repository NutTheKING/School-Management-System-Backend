<?php
declare(strict_types=1);
    if (empty($_SESSION['authenticated'])) respond(['error'=>'Authentication required'],401);
    if ($method === 'POST') {
        $required = $db->prepare('SELECT must_change_password FROM system_users WHERE id=?');
        $required->execute([(int)($_SESSION['user_id'] ?? 0)]);
        if ((int)$required->fetchColumn() === 1) respond(['error'=>'You must create a new password before continuing','must_change_password'=>true],428);
    }
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
        $plain=strtolower(bin2hex(random_bytes(32))); $expires=gmdate('Y-m-d H:i:s',time()+12*60*60);
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
