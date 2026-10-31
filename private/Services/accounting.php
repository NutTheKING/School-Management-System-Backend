<?php
declare(strict_types=1);
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

