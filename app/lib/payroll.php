<?php
declare(strict_types=1);

/*
 * Payroll — a port of the old GHIS SalaryService (salaryTable / payrollForPeriod / summer deduction).
 * Money is rounded like the old sheets: to 2 decimals on every step, and the final net to the nearest 5 EGP.
 */

/** JS Math.round(n * 100) / 100 (halves go up), as the old sheets rounded money. */
function round2(float $n): float { return floor($n * 100 + 0.5) / 100; }
/** Old AT.round5: money rounded to the nearest 5 EGP (totals only). */
function round5(float $n): float { return floor($n / 5 + 0.5) * 5; }

/** Old addMonthsKey_: same day N months later, clamped to that month's last day. */
function add_months_key(string $key, int $months): string
{
    $y = (int)substr($key, 0, 4); $m = (int)substr($key, 5, 2) - 1; $d = (int)substr($key, 8, 2);
    $total = $m + $months;
    $ty = $y + intdiv($total - (($total % 12 + 12) % 12), 12);
    $tm = (($total % 12) + 12) % 12;
    $last = (int)date('t', mktime(0, 0, 0, $tm + 1, 1, $ty));
    return sprintf('%04d-%02d-%02d', $ty, $tm + 1, min($d, $last));
}

/** Old isWithinFirstYear_: the month falls in the employee's first 12 months. */
function within_first_year(?string $hire, string $ym): bool
{
    if (!$hire) return false;
    return $ym >= substr($hire, 0, 7) && $ym < substr(add_months_key($hire, 12), 0, 7);
}

/** The official payroll period whose end falls in $ym (latest start wins), like the old salary table. */
function payroll_period_for_month(string $ym): ?array
{
    return q_one('SELECT * FROM payroll_periods WHERE DATE_FORMAT(date_to, "%Y-%m") = ? ORDER BY date_from DESC, id DESC LIMIT 1', [$ym]);
}

/** [from, to, period] of the payroll month: the official period that ends in it, else the calendar month. */
function payroll_range(string $ym): array
{
    $per = payroll_period_for_month($ym);
    if ($per) return [$per['date_from'], $per['date_to'], $per];
    [$from, $to] = month_range($ym);
    return [$from, $to, null];
}

/** Old getBasicSalaryAtPeriodStart_: the latest basic-salary transaction effective on $start (ties: last recorded). */
function basic_salary_at(int $empId, string $start): ?float
{
    $v = q_val("SELECT t.amount FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id
                WHERE t.employee_id = ? AND ty.category = 'basic' AND t.date_from <= ?
                ORDER BY t.date_from DESC, t.created_at DESC, t.id DESC LIMIT 1", [$empId, $start]);
    return $v === null ? null : (float)$v;
}

/** Old splitInstallments: cents split evenly, the remainder on the last installment. */
/** The basic salary in force on $day (old «تقرير الرواتب الأساسية»), and how many dates carry two basics. */
function basic_current(int $empId, string $day): array
{
    $b = q_one("SELECT t.amount, t.date_from FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id WHERE t.employee_id = ? AND ty.category = 'basic' AND t.date_from <= ?
                ORDER BY t.date_from DESC, t.created_at DESC, t.id DESC LIMIT 1", [$empId, $day]);
    $dups = (int)q_val("SELECT COUNT(*) FROM (SELECT t.date_from FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id WHERE t.employee_id = ? AND ty.category = 'basic' GROUP BY t.date_from HAVING COUNT(*) > 1) x", [$empId]);
    return ['basic' => $b ? round2((float)$b['amount']) : null, 'effective_from' => $b['date_from'] ?? null, 'dups' => $dups];
}

function split_installments(float $total, int $count): array
{
    $cents = (int)round($total * 100);
    $count = max(1, $count);
    $base = intdiv($cents, $count);
    $parts = array_fill(0, $count, $base);
    $parts[$count - 1] += $cents - $base * $count;
    return array_map(fn($c) => $c / 100, $parts);
}

/** Installments of one advance with their due dates (start date + i months). */
function advance_installments(array $a): array
{
    $out = [];
    foreach (split_installments((float)$a['total'], (int)$a['installments']) as $i => $v) {
        $out[] = ['no' => $i + 1, 'due' => add_months_key($a['deduct_from'], $i), 'value' => $v];
    }
    return $out;
}

/** Months (07 / 08) in which the summer quarter is deducted for this employee in $ym, and whether the 50% first-year rule applies. */
function summer_months(array $emp, string $ym): array
{
    $firstYear = within_first_year($emp['hire_date'] ?? null, $ym);
    $fyRule = $firstYear ? trim((string)($emp['first_year_deduction'] ?? '')) : '';
    $exOpt = $firstYear ? trim((string)($emp['first_year_exception'] ?? '')) : '';
    $own = trim((string)($emp['summer_deduction'] ?? ''));
    $rule = trim((string)($emp['summer_rule'] ?? ''));
    $half = in_array($exOpt, ['1', '2'], true);
    if ($half) { $months = $exOpt === '2' ? ['07', '08'] : ['07']; $label = ($exOpt === '2' ? 'نص يوليو + نص أغسطس' : 'نص يوليو') . ' بلا رد (استثناء سنة أولى)'; }
    elseif (in_array($exOpt, ['3', '4'], true)) { $months = $exOpt === '4' ? ['07', '08'] : ['07']; $label = ($exOpt === '4' ? 'ربعين' : 'ربع') . ' (استثناء سنة أولى)'; }
    elseif ($fyRule !== '') { $months = $fyRule === 'شهرين' ? ['07', '08'] : ($fyRule === 'شهر واحد' ? ['07'] : []); $label = $fyRule . ' (سنة أولى)'; }
    elseif ($own !== '') { $months = $own === 'ربعين' ? ['07', '08'] : ['07']; $label = $own; }
    elseif ($rule !== '') { $months = $rule === 'Rule 1' ? ['07', '08'] : ['07']; $label = $rule === 'Rule 1' ? 'ربعين (قسم)' : 'ربع (قسم)'; }
    else { $months = []; $label = ''; }
    return ['months' => $months, 'half' => $half, 'label' => $label];
}

/** Raise month (YYYY-MM) of the active academic year, if set. */
function active_raise_month(): string
{
    return (string)q_val('SELECT s.raise_month FROM payroll_year_settings s JOIN academic_years y ON y.id = s.academic_year_id WHERE y.active = 1 ORDER BY y.date_from DESC LIMIT 1');
}

/** Old raiseDue: [due, department rate %, allowance] of the annual raise in payroll month $ym. */
function raise_for(array $emp, ?array $dept, float $basic, string $ym): array
{
    $rate = (float)($dept['allowance_rate'] ?? 0);
    $raiseMonth = active_raise_month();
    $isRaiseMonth = $raiseMonth !== '' && $raiseMonth === $ym;
    $fr = !empty($emp['first_raise_month']) ? substr((string)$emp['first_raise_month'], 0, 7) : '';
    $due = $fr === '' ? $isRaiseMonth : ($ym < $fr ? false : ($ym === $fr ? true : $isRaiseMonth));
    return [$due, $rate, $due ? round2($basic * $rate / 100) : 0.0];
}

/** Id of the active basic-salary transaction type (old SalaryService.BASIC). */
function basic_type_id(): ?int
{
    $v = q_val("SELECT id FROM salary_tx_types WHERE category = 'basic' AND active = 1 ORDER BY id LIMIT 1");
    return $v === null ? null : (int)$v;
}

/** Old SalaryService.add for the basic salary: one basic per employee and effective date. Returns an error message or null. */
function add_basic_salary(int $empId, string $from, float $amount, string $reason): ?string
{
    if (!($amount > 0)) return __('القيمة غير صحيحة.');
    $type = basic_type_id();
    if ($type === null) return __('مفيش نوع حركة مصنَّف «راتب أساسي» ونشط. ضيفه من أنواع الحركات الأول.');
    if (q_val("SELECT 1 FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id WHERE t.employee_id = ? AND ty.category = 'basic' AND t.date_from = ?", [$empId, $from])) {
        return __('فيه راتب أساسي مسجَّل بالفعل بنفس تاريخ السريان (%s). عدّل الحركة الحالية أو ألغِها الأول.', $from);
    }
    q('INSERT INTO salary_tx (employee_id, type_id, date_from, amount, reason, created_by) VALUES (?,?,?,?,?,?)', [$empId, $type, $from, round2($amount), $reason, user()['username'] ?? null]);
    return null;
}

/** Old getAdvancesReport_: collected = installments already due (on or before $today); a cancelled advance collects nothing. */
function advance_progress(array $a, string $today): array
{
    $inst = advance_installments($a);
    $cents = 0; $n = 0;
    foreach ($inst as $i) if ($i['due'] <= $today) { $cents += (int)round($i['value'] * 100); $n++; }
    $cancelled = $a['status'] === 'cancelled';
    $collected = $cancelled ? 0.0 : $cents / 100;
    return ['schedule' => $inst, 'collected' => round2($collected), 'collected_count' => $cancelled ? 0 : $n, 'remaining' => round2($cancelled ? 0 : (float)$a['total'] - $collected)];
}

/**
 * Pay of one employee over [from, to] — the old salary-table row. $ym is the payroll month
 * (defaults to the month of $to); it decides the raise month and the summer quarter months.
 */
function payroll_compute(int $empId, string $from, string $to, ?string $ym = null): array
{
    $ym ??= substr($to, 0, 7);
    $att = att_evaluate($empId, $from, $to);
    $emp = $att['emp'];
    $dept = $emp && $emp['department_id'] ? q_one('SELECT summer_rule, allowance_rate, quarter_rate FROM departments WHERE id = ?', [$emp['department_id']]) : null;
    $errors = [];
    $lines = [];

    $basicV = basic_salary_at($empId, $from);
    if ($basicV === null) $errors[] = __('مفيش راتب أساسي مسجَّل للموظف ده وقت بداية الفترة (%s) — لازم تسجّله الأول.', $from);
    $basic = round2($basicV ?? 0.0);
    $lines[] = ['name' => __('الراتب الأساسي'), 'dir' => 'add', 'amount' => $basic, 'reason' => null];
    $dailyValue = round2($basic / max(1, (int)setting('day_divisor', '30')));

    $earnings = 0.0; $cash = 0.0;
    $tx = q_all('SELECT t.*, ty.name type_name, ty.category FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id
                 WHERE t.employee_id = ? AND t.date_from <= ? AND (t.date_to IS NULL OR t.date_to >= ?) ORDER BY ty.name, t.date_from', [$empId, $to, $from]);
    foreach ($tx as $t) {
        $cat = (string)$t['category'];
        if (in_array($cat, ['basic', 'summer', 'excluded'], true)) continue;
        $amt = (float)$t['amount'];
        if ($cat === 'earning' || $cat === 'allowance') {
            $earnings += $amt;
            $lines[] = ['name' => $t['type_name'], 'dir' => 'add', 'amount' => $amt, 'reason' => $t['reason']];
        } elseif ($cat === 'deduction') {
            $cash += abs($amt);
            $lines[] = ['name' => $t['type_name'], 'dir' => 'deduct', 'amount' => abs($amt), 'reason' => $t['reason']];
        } else {
            $errors[] = __('نوع الحركة "%s" لسه مش مصنَّف بشكل صحيح — لازم تحدد تصنيفه الأول.', $t['type_name']);
        }
    }

    $adv = 0;
    foreach (q_all("SELECT * FROM advances WHERE employee_id = ? AND status <> 'cancelled' AND deduct_from <= ?", [$empId, $to]) as $a) {
        foreach (advance_installments($a) as $i) {
            if ($i['due'] < $from || $i['due'] > $to) continue;
            $adv += (int)round($i['value'] * 100);
            $lines[] = ['name' => __('قسط سلفة (%d/%d)', $i['no'], (int)$a['installments']), 'dir' => 'deduct', 'amount' => $i['value'], 'reason' => $a['notes']];
        }
    }
    $adv /= 100;

    // Annual raise: due in the raise month, or from the employee's own first-raise month.
    [$raiseDue, $aRate, $allowance] = raise_for($emp ?? [], $dept, $basic, $ym);
    if ($allowance > 0) $lines[] = ['name' => __('العلاوة السنوية (%s%%)', num($aRate)), 'dir' => 'add', 'amount' => $allowance, 'reason' => null];

    // Summer quarter: deducted in July (and August) by the employee's / department's rule.
    $sm = summer_months(($emp ?? []) + ['summer_rule' => $dept['summer_rule'] ?? ''], $ym);
    $qRate = $dept === null || $dept['quarter_rate'] === null ? 25.0 : (float)$dept['quarter_rate'];
    $quarter = in_array(substr($ym, 5, 2), $sm['months'], true) ? round2($sm['half'] ? $basic * 0.5 : $basic * $qRate / 100) : 0.0;
    if ($quarter > 0) $lines[] = ['name' => __('خصم الصيف (%s)', $sm['label']), 'dir' => 'deduct', 'amount' => $quarter, 'reason' => null];

    $days = (float)($att['totals']['total_days'] ?? 0);
    $attAmt = round2($days * $dailyValue);
    if ($attAmt > 0) $lines[] = ['name' => __('خصم الحضور (%s يوم)', num($days)), 'dir' => 'deduct', 'amount' => $attAmt, 'reason' => null];

    $earnings = round2($earnings); $cash = round2($cash); $adv = round2($adv);
    $gross = round2($basic + $allowance + $earnings);
    $totalDed = $attAmt + $cash + $adv + $quarter;
    return [
        'emp' => $emp, 'from' => $from, 'to' => $to, 'ym' => $ym, 'errors' => $errors,
        'basic' => $basic, 'daily_value' => $dailyValue, 'allowance_rate' => $aRate, 'allowance' => $allowance, 'raise_due' => $raiseDue,
        'earnings' => $earnings, 'additions' => round2($allowance + $earnings), 'deductions' => $cash, 'advances' => $adv,
        'quarter_rate' => $qRate, 'quarter' => $quarter, 'summer_rule' => $sm['label'],
        'att_days' => $days, 'att_amount' => $attAmt, 'gross' => $gross,
        'total_deductions' => round2($totalDed), 'total_deductions_final' => round5($totalDed),
        'net' => round2($basic + $allowance + $earnings - $totalDed), 'net_final' => round5($basic + $allowance + $earnings - $totalDed),
        'lines' => $lines, 'att' => $att['totals'], 'days' => $att['days'],
    ];
}

/** Old notes column of the salary table: data errors, the summer rule and a due raise. */
function payroll_notes(array $r): array
{
    $n = $r['errors'] ?? [];
    if (!empty($r['quarter']) && !empty($r['summer_rule'])) $n[] = __('خصم الصيف: %s', $r['summer_rule']);
    if (!empty($r['raise_due'])) $n[] = __('علاوة مستحقة');
    return $n;
}

/** The old salary table columns, shared by the CSV and the monthly closing report (Excel / print). */
function payroll_table_headers(): array
{
    return [__('الكود'), __('الاسم'), __('القسم'), __('الأساسي'), __('قيمة اليوم'), __('العلاوة'), __('الاستحقاقات'), __('إجمالي المستحق'), __('أيام خصم الحضور'), __('قيمة خصم الحضور'),
        __('الخصومات'), __('السلف'), __('خصم الصيف'), __('إجمالي الخصومات'), __('الصافي'), __('الصافي النهائي (مقرّب لأقرب 5)'), __('ملاحظات')];
}

function payroll_table_row(array $r): array
{
    return [(int)$r['emp']['id'], $r['emp']['name_ar'], (string)$r['emp']['dept_name'], (float)$r['basic'], (float)$r['daily_value'], (float)$r['allowance'], (float)$r['earnings'], (float)$r['gross'],
        (float)$r['att_days'], (float)$r['att_amount'], (float)$r['deductions'], (float)$r['advances'], (float)$r['quarter'], (float)$r['total_deductions'], (float)$r['net'], (float)$r['net_final'], implode(' · ', payroll_notes($r))];
}
