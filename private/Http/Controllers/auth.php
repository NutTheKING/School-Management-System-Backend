<?php
declare(strict_types=1);
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
