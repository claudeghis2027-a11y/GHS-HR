<?php
declare(strict_types=1);

/** Monthly pay for one employee over [from, to]: salary transactions, advances, attendance deductions. */
function payroll_compute(int $empId, string $from, string $to): array
{
    $tx = q_all('SELECT t.*, ty.name type_name, ty.direction, ty.category FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id
                 WHERE t.employee_id = ? AND t.date_from <= ? AND (t.date_to IS NULL OR t.date_to >= ?) ORDER BY ty.direction, ty.name', [$empId, $to, $from]);
    $basic = 0.0; $add = 0.0; $ded = 0.0; $lines = [];
    foreach ($tx as $t) {
        $amt = (float)$t['amount'];
        if ($t['category'] === 'basic') $basic += $amt;
        if ($t['direction'] === 'add') $add += $amt; else $ded += $amt;
        $lines[] = ['name' => $t['type_name'], 'dir' => $t['direction'], 'amount' => $amt, 'reason' => $t['reason']];
    }
    $adv = 0.0;
    $pm = (int)substr($from, 0, 4) * 12 + (int)substr($from, 5, 2);
    foreach (q_all("SELECT * FROM advances WHERE employee_id = ? AND status = 'active' AND deduct_from <= ?", [$empId, $to]) as $a) {
        $start = (int)substr($a['deduct_from'], 0, 4) * 12 + (int)substr($a['deduct_from'], 5, 2);
        $k = $pm - $start; // 0-based installment index for this month
        if ($k >= 0 && $k < (int)$a['installments']) {
            $adv += (float)$a['installment_value'];
            $lines[] = ['name' => __('قسط سلفة (%d/%d)', $k + 1, $a['installments']), 'dir' => 'deduct', 'amount' => (float)$a['installment_value'], 'reason' => $a['notes']];
        }
    }
    $att = att_evaluate($empId, $from, $to);
    $days = (float)($att['totals']['total_days'] ?? 0);
    $dayRate = $basic / max(1, (int)setting('day_divisor', '30'));
    $attAmt = round($days * $dayRate, 2);
    if ($attAmt > 0) $lines[] = ['name' => __('خصم الحضور (%s يوم)', num($days)), 'dir' => 'deduct', 'amount' => $attAmt, 'reason' => null];
    return [
        'emp' => $att['emp'], 'basic' => $basic, 'additions' => $add, 'deductions' => $ded, 'advances' => $adv,
        'att_days' => $days, 'att_amount' => $attAmt, 'net' => round($add - $ded - $adv - $attAmt, 2),
        'lines' => $lines, 'att' => $att['totals'],
    ];
}
