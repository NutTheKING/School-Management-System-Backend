<?php
declare(strict_types=1);
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

