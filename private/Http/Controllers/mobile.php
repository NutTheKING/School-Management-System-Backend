<?php
declare(strict_types=1);
    if ($method === 'POST' && $resource === 'mobile-login') {
        $data = body();
        $studentId = strtoupper(trim((string)($data['student_id'] ?? '')));
        $password = (string)($data['password'] ?? '');
        if ($studentId === '' || $password === '') respond(['error'=>'Student ID and password are required'],422);
        $login = $studentAuth->login($studentId, $password);
        if ($login === null) {
            usleep(250000); respond(['error'=>'Incorrect Student ID or password'],401);
        }
        respond($login);
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
        $studentAuth->logout($token);
        respond(['ok'=>true]);
    }
