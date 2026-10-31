<?php
declare(strict_types=1);
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

