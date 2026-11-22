<?php
declare(strict_types=1);

// ═════════════ Summer deduction (port of the old GHIS SalaryService.summer*) ═════════════

const SUMMER_REFUNDABLE = ['ربع', 'ربعين'];

/** Old summerCalculate: idempotent per (employee, month, year); own field > department rule; first-year exception = 50% non-refundable. */
function summer_calculate(int $year): array
{
    $res = ['calculated' => 0, 'already' => 0, 'unclassified' => 0, 'errors' => [], 'total' => 0.0];
    $logged = [];
    foreach (q_all('SELECT employee_id, month_no FROM summer_deduction_log WHERE year_no = ?', [$year]) as $r) $logged[$r['employee_id'] . '|' . $r['month_no']] = true;
    $by = user()['username'];
    foreach (q_all('SELECT e.*, d.summer_rule FROM employees e LEFT JOIN departments d ON d.id = e.department_id ORDER BY e.id') as $emp) {
        $own = trim((string)$emp['summer_deduction']);
        $rule = trim((string)$emp['summer_rule']);
        $exOpt = trim((string)$emp['first_year_exception']);
        if (in_array($exOpt, ['1', '2'], true) && within_first_year($emp['hire_date'], "$year-07")) {
            $months = $exOpt === '2' ? [7, 8] : [7]; $factor = 0.5; $type = $exOpt === '2' ? 'نصفين' : 'نصف'; $status = 'غير قابل للرد';
        } elseif ($own === '' && $rule === '') {
            $res['unclassified']++; continue;
        } else {
            $two = $own !== '' ? $own === 'ربعين' : $rule === 'Rule 1';
            $months = $two ? [7, 8] : [7]; $factor = 0.25; $type = $two ? 'ربعين' : 'ربع'; $status = 'لسه';
        }
        foreach ($months as $mn) {
            if (isset($logged[$emp['id'] . '|' . $mn])) { $res['already']++; continue; }
            $salary = basic_salary_at((int)$emp['id'], sprintf('%04d-%02d-01', $year, $mn));
            if ($salary === null) { $res['errors'][] = (int)$emp['id']; continue; }
            $amount = round2($salary * $factor);
            q('INSERT INTO summer_deduction_log (employee_id, month_no, year_no, basic_salary, amount, refund_status, ded_type, created_by) VALUES (?,?,?,?,?,?,?,?)',
                [$emp['id'], $mn, $year, $salary, $amount, $status, $type, $by]);
            $res['calculated']++; $res['total'] += $amount;
        }
    }
    $res['errors'] = array_values(array_unique($res['errors']));
    return $res;
}

/** Old summerRefund / summerRefundByType: only «ربع» / «ربعين» rows still marked «لسه» are refundable. */
function summer_refund(int $year, ?int $empId, string $type): array
{
    $sql = "SELECT id, amount FROM summer_deduction_log WHERE year_no = ? AND refund_status = 'لسه' AND ded_type IN ('ربع','ربعين')";
    $p = [$year];
    if ($empId !== null) { $sql .= ' AND employee_id = ?'; $p[] = $empId; }
    if ($type !== '') { $sql .= ' AND ded_type = ?'; $p[] = $type; }
    $rows = q_all($sql, $p);
    $total = 0.0;
    foreach ($rows as $r) {
        q("UPDATE summer_deduction_log SET refund_status = 'تم', refunded_at = CURDATE() WHERE id = ? AND refund_status = 'لسه'", [$r['id']]);
        $total += (float)$r['amount'];
    }
    return ['rows' => count($rows), 'total' => round2($total)];
}

function page_summer(): void
{
    require_perm('salary');
    $year = in_int('year', $_GET) ?? (int)date('Y');
    $type = in_array($_GET['type'] ?? '', ['ربع', 'ربعين', 'نصف', 'نصفين'], true) ? $_GET['type'] : '';
    $dept = in_int('dept', $_GET);
    $job = in_int('job', $_GET);
    [$w, $p] = scope_sql('e');
    $sql = "SELECT s.*, e.name_ar, e.department_id, d.name dept_name, j.name job_name FROM summer_deduction_log s JOIN employees e ON e.id = s.employee_id
            LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id WHERE $w AND s.year_no = ?";
    $p[] = $year;
    if ($type !== '') { $sql .= ' AND s.ded_type = ?'; $p[] = $type; }
    if ($dept) { $sql .= ' AND e.department_id = ?'; $p[] = $dept; }
    if ($job) { $sql .= ' AND e.job_title_id = ?'; $p[] = $job; }
    $rows = q_all($sql . ' ORDER BY e.id, s.month_no', $p);
    $total = round2(array_sum(array_map(fn($r) => (float)$r['amount'], $rows)));
    $refunded = round2(array_sum(array_map(fn($r) => $r['refund_status'] === 'تم' ? (float)$r['amount'] : 0.0, $rows)));
    if (export_format()) {
        require_perm('salary', 'export');
        audit('export.' . export_format(), 'summer', $year);
        send_export(__('سجل خصم الصيف %s', $year), "summer-$year", [__('الكود'), __('الاسم'), __('القسم'), __('المسمى'), __('الشهر'), __('السنة'), __('الأساسي وقتها'), __('المبلغ'), __('النوع'), __('حالة الرد'), __('تاريخ الرد')],
            array_map(fn($r) => [$r['employee_id'], $r['name_ar'], $r['dept_name'], $r['job_name'], month_name((int)$r['month_no']), $r['year_no'], money($r['basic_salary']), money($r['amount']), $r['ded_type'], $r['refund_status'], $r['refunded_at']], $rows));
    }
    render('summer', ['title' => __('خصم الصيف'), 'summerDepts' => q_all('SELECT id, name, summer_rule, active FROM departments ORDER BY name'), 'year' => $year, 'type' => $type, 'dept' => $dept, 'job' => $job, 'rows' => $rows, 'total' => $total, 'refunded' => $refunded,
        'depts' => fk_options(['fk' => ['departments', 'name']]), 'jobs' => fk_options(['fk' => ['job_titles', 'name']])]);
}

function page_summer_post(): void
{
    require_perm('salary', 'update');
    if (dept_scope() !== null) http_error(403, __('خصم الصيف متاح بس لمستخدم صلاحيته على كل الأقسام.'));
    if (($_POST['action'] ?? '') === 'dept_rule') { // old summer.departmentSet
        $id = in_int('dept_id'); $rule = $_POST['rule'] ?? '';
        $d = $id !== null ? q_one('SELECT id, name, summer_rule FROM departments WHERE id = ?', [$id]) : null;
        if (!$d || !in_array($rule, ['', 'Rule 1', 'Rule 2'], true)) { flash('err', __('اختار القسم والقاعدة.')); redirect(url('summer') . '#summer_depts'); }
        q('UPDATE departments SET summer_rule = ? WHERE id = ?', [$rule === '' ? null : $rule, $id]);
        audit('summer.departmentSet', $d['name'], ['rule' => [$d['summer_rule'], $rule]]);
        flash('ok', __('تم الحفظ.'));
        redirect(url('summer') . '#summer_depts');
    }
    $year = in_int('year');
    if ($year === null || $year < 2000 || $year > 2100) { flash('err', __('السنة غير صحيحة.')); redirect(url('summer')); }
    $action = $_POST['action'] ?? '';
    if ($action === 'calculate') {
        db()->beginTransaction();
        $r = summer_calculate($year);
        audit('summer.calculate', $year, ['rows' => $r['calculated'], 'total' => $r['total']]);
        db()->commit();
        flash('ok', __('اتحسب %d صف بإجمالي %s جنيه. متسجل قبل كده: %d. موظفين من غير تصنيف: %d.', $r['calculated'], money($r['total']), $r['already'], $r['unclassified']));
        if ($r['errors']) flash('err', __('موظفين من غير راتب أساسي في يوليو/أغسطس (اتسابوا): %s', implode('، ', $r['errors'])));
    } elseif ($action === 'refund') {
        $emp = in_int('employee_id');
        $type = in_array($_POST['type'] ?? '', SUMMER_REFUNDABLE, true) ? $_POST['type'] : '';
        if ($emp === null && $type === '') { flash('err', __('النوع لازم يكون «ربع» أو «ربعين» لما ترد لكل الموظفين.')); redirect(url('summer', ['year' => $year])); }
        if ($emp !== null && !can_see_employee($emp)) http_error(403);
        db()->beginTransaction();
        $r = summer_refund($year, $emp, $type);
        audit($emp === null ? 'summer.refundByType' : 'summer.refund', $emp ?? $type, ['year' => $year, 'type' => $type, 'rows' => $r['rows'], 'total' => $r['total']]);
        db()->commit();
        if ($r['rows'] === 0) flash('err', __('مفيش خصم صيفي معلّق يترد بالاختيار ده — يمكن اتردّ قبل كده.'));
        else flash('ok', __('اترد %d صف بإجمالي %s جنيه.', $r['rows'], money($r['total'])));
    } else {
        http_error(400);
    }
    redirect(url('summer', ['year' => $year]));
}

// ═════════════ Quarter deduction policy (old «سياسة خصم الربعين»: department × month grid) ═════════════

function page_quarter_policy(): void
{
    require_perm('salary');
    $years = q_all('SELECT * FROM academic_years ORDER BY date_from DESC');
    $yid = in_int('year', $_GET) ?? (int)($years[0]['id'] ?? 0);
    $year = null;
    foreach ($years as $y) if ((int)$y['id'] === $yid) $year = $y;
    $months = [];
    if ($year) for ($m = substr($year['date_from'], 0, 7); $m <= substr($year['date_to'], 0, 7); $m = substr(add_months_key("$m-01", 1), 0, 7)) $months[] = $m;
    $applied = [];
    foreach (q_all('SELECT department_id, month_key, applied FROM quarter_deduction_policy WHERE academic_year_id = ?', [$yid]) as $r) $applied[$r['department_id'] . '|' . $r['month_key']] = (bool)$r['applied'];
    render('quarter_policy', ['title' => __('سياسة خصم الربعين'), 'years' => $years, 'yid' => $yid, 'months' => $months, 'applied' => $applied,
        'depts' => q_all('SELECT id, name FROM departments WHERE active = 1 ORDER BY name')]);
}

function page_quarter_policy_post(): void
{
    require_perm('salary', 'update');
    $yid = in_int('year');
    $year = $yid ? q_one('SELECT * FROM academic_years WHERE id = ?', [$yid]) : null;
    if (!$year) { flash('err', __('السنة الدراسية مش موجودة.')); redirect(url('quarter_policy')); }
    $on = is_array($_POST['cell'] ?? null) ? $_POST['cell'] : [];
    $by = user()['username'];
    $n = 0;
    db()->beginTransaction();
    foreach (q_all('SELECT id FROM departments WHERE active = 1') as $d) {
        for ($m = substr($year['date_from'], 0, 7); $m <= substr($year['date_to'], 0, 7); $m = substr(add_months_key("$m-01", 1), 0, 7)) {
            $val = isset($on[$d['id'] . '|' . $m]) ? 1 : 0;
            $cur = q_val('SELECT applied FROM quarter_deduction_policy WHERE academic_year_id = ? AND department_id = ? AND month_key = ?', [$yid, $d['id'], $m]);
            if ($cur !== null && (int)$cur === $val) continue;
            if ($cur === null && $val === 0) continue;
            q('INSERT INTO quarter_deduction_policy (academic_year_id, department_id, month_key, month_no, applied, created_by) VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE applied = VALUES(applied), created_by = VALUES(created_by), created_at = NOW()', [$yid, $d['id'], $m, (int)substr($m, 5, 2), $val, $by]);
            $n++;
        }
    }
    audit('quarterPolicy.set', $yid, ['cells' => $n]);
    db()->commit();
    flash('ok', __('تم الحفظ (%d خانة اتغيرت).', $n));
    redirect(url('quarter_policy', ['year' => $yid]));
}

// ═════════════ Payroll periods: list, closing grid, reopen (old «اقفال شهري») ═════════════

function page_periods(): void
{
    require_perm('period_close');
    $rows = q_all("SELECT p.*, (SELECT COUNT(*) FROM payroll_snapshots s WHERE s.period_id = p.id AND s.state = 'ready') ready FROM payroll_periods p ORDER BY p.date_from DESC");
    render('periods', ['title' => __('فترات المرتبات'), 'rows' => $rows, 'gaps' => period_gaps()]);
}

function page_period(): void
{
    require_perm('period_close');
    $per = q_one('SELECT * FROM payroll_periods WHERE id = ?', [in_int('id', $_GET) ?? 0]);
    if (!$per) http_error(404);
    $snaps = q_all('SELECT * FROM payroll_snapshots WHERE period_id = ? ORDER BY id DESC', [$per['id']]);
    $sid = in_int('snap', $_GET);
    $snap = null;
    foreach ($snaps as $s) if ($sid ? (int)$s['id'] === $sid : $s['state'] === 'ready') { $snap = $s; break; }
    $dept = in_int('dept', $_GET);
    $rows = $snap ? array_values(snapshot_rows((int)$snap['id'], $dept)) : [];
    $days = [];
    if ($snap) for ($d = $snap['date_from']; $d <= $snap['date_to']; $d = date('Y-m-d', strtotime("$d +1 day"))) $days[] = $d;
    if ($snap && export_format()) {
        require_perm('period_close', 'export');
        audit('export.' . export_format(), 'closing_grid', $per['id']);
        $head = array_merge([__('الكود'), __('الاسم'), __('القسم')], array_map(fn($d) => substr($d, 8) . '/' . substr($d, 5, 2), $days), att_breakdown_headers(), [__('الصافي النهائي')]);
        send_export(period_label($per), 'closing-' . $per['id'], $head, array_map(function ($r) use ($days) {
            $by = []; foreach ($r['cells'] as $c) $by[$c['d']] = grid_text($c);
            return array_merge([$r['emp']['id'], $r['emp']['name_ar'], $r['emp']['dept_name']], array_map(fn($d) => $by[$d] ?? '-', $days), array_map(fn($v) => is_float($v) ? num($v) : $v, att_breakdown_values($r['att'] ?: ['total_days' => $r['att_days']])), [money($r['net_final'])]);
        }, $rows));
    }
    render('period', ['title' => period_label($per), 'per' => $per, 'snaps' => $snaps, 'snap' => $snap, 'rows' => $rows, 'days' => $days, 'dept' => $dept,
        'log' => q_all('SELECT * FROM payroll_period_log WHERE period_id = ? ORDER BY id DESC', [$per['id']]), 'gaps' => period_gaps(),
        'depts' => fk_options(['fk' => ['departments', 'name']])]);
}

/** Old ClosingService.reopen: separate permission, mandatory reason, logged. */
function page_period_post(): void
{
    $pid = in_int('id');
    if ($pid === null) http_error(400);
    if (($_POST['action'] ?? '') !== 'reopen') http_error(400);
    require_perm('period_reopen', 'update');
    if (dept_scope() !== null) http_error(403);
    try {
        $n = period_reopen($pid, user()['username'], in_str('reason', 500));
        flash('ok', __('الفترة اتفتحت تاني. نسخ الإقفال اللي اتلغت: %d.', $n));
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
    }
    redirect(url('period', ['id' => $pid]));
}

// ═════════════ Annual raise approval (old approveSalaryAllowance_) ═════════════

/** Employees whose annual raise is due in $ym, with the allowance computed from the basic at the period start. */
function raises_due(string $ym, ?int $dept): array
{
    [$from, $to] = payroll_range($ym);
    [$w, $p] = scope_sql('e');
    $sql = "SELECT e.*, d.name dept_name, d.allowance_rate FROM employees e LEFT JOIN departments d ON d.id = e.department_id
            WHERE $w AND (e.hire_date IS NULL OR e.hire_date <= ?) AND (e.end_date IS NULL OR e.end_date >= ?)";
    $p = array_merge($p, [$to, $from]);
    if ($dept) { $sql .= ' AND e.department_id = ?'; $p[] = $dept; }
    $out = [];
    foreach (q_all($sql . ' ORDER BY e.id', $p) as $e) {
        $basic = basic_salary_at((int)$e['id'], $from);
        if ($basic === null) continue;
        [$due, $rate, $allowance] = raise_for($e, ['allowance_rate' => $e['allowance_rate']], round2($basic), $ym);
        if (!$due || !($allowance > 0)) continue;
        $out[(int)$e['id']] = ['id' => (int)$e['id'], 'name_ar' => $e['name_ar'], 'dept_name' => $e['dept_name'], 'basic' => round2($basic), 'rate' => $rate, 'allowance' => $allowance];
    }
    return $out;
}

function page_raises(): void
{
    require_perm('salary');
    $ym = valid_month($_GET['m'] ?? null) ?? (active_raise_month() ?: date('Y-m'));
    [, $to] = payroll_range($ym);
    $eff = date('Y-m-d', strtotime("$to +1 day"));
    $dept = in_int('dept', $_GET);
    $rows = raises_due($ym, $dept);
    foreach ($rows as &$r) {
        $r['approved'] = (bool)q_val("SELECT 1 FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id WHERE t.employee_id = ? AND ty.category = 'basic' AND t.date_from = ?", [$r['id'], $eff]);
    }
    unset($r);
    render('raises', ['title' => __('اعتماد العلاوة السنوية'), 'ym' => $ym, 'eff' => $eff, 'rows' => $rows, 'dept' => $dept, 'raiseMonth' => active_raise_month(),
        'depts' => fk_options(['fk' => ['departments', 'name']])]);
}

function page_raises_post(): void
{
    require_perm('salary', 'create');
    $ym = valid_month($_POST['m'] ?? null);
    $eff = valid_date($_POST['effective_from'] ?? null);
    if (!$ym || !$eff) { flash('err', __('الشهر أو تاريخ السريان غير صحيح.')); redirect(url('raises')); }
    $pick = array_map('intval', is_array($_POST['emp'] ?? null) ? $_POST['emp'] : []);
    $due = raises_due($ym, null);
    $reason = in_str('reason', 500);
    $ok = 0; $skip = [];
    db()->beginTransaction();
    foreach ($pick as $id) {
        if (!isset($due[$id])) { $skip[] = $id; continue; }
        $current = basic_salary_at($id, $eff);
        if ($current === null) { $skip[] = $id; continue; }
        $new = round2($current + $due[$id]['allowance']);
        $err = add_basic_salary($id, $eff, $new, $reason !== '' ? $reason : __('اعتماد العلاوة السنوية (+%s)', money($due[$id]['allowance'])));
        if ($err !== null) { $skip[] = $id; continue; }
        audit('salary.allowanceApprove', $id, ['from' => $current, 'to' => $new, 'effective' => $eff]);
        $ok++;
    }
    db()->commit();
    flash($ok ? 'ok' : 'err', __('اتعتمدت العلاوة لـ %d موظف (أساسي جديد من %s).', $ok, $eff));
    if ($skip) flash('err', __('اتسابوا (مش مستحقين أو ليهم أساسي بنفس التاريخ): %s', implode('، ', $skip)));
    redirect(url('raises', ['m' => $ym]));
}

// ═════════════ Basic salaries report + bulk save (old basicSalariesReport / basicSalariesSave) ═════════════

function page_basic_salaries(): void
{
    require_perm('salary');
    $today = date('Y-m-d');
    $dept = in_int('dept', $_GET);
    $job = in_int('job', $_GET);
    [$w, $p] = scope_sql('e');
    $sql = "SELECT e.id, e.name_ar, d.name dept_name, j.name job_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id
            WHERE $w AND (e.hire_date IS NULL OR e.hire_date <= ?) AND (e.end_date IS NULL OR e.end_date >= ?)";
    $p = array_merge($p, [$today, $today]);
    if ($dept) { $sql .= ' AND e.department_id = ?'; $p[] = $dept; }
    if ($job) { $sql .= ' AND e.job_title_id = ?'; $p[] = $job; }
    $rows = [];
    foreach (q_all($sql . ' ORDER BY e.id', $p) as $e) $rows[] = $e + basic_current((int)$e['id'], $today);
    if (export_format()) {
        require_perm('salary', 'export');
        audit('export.' . export_format(), 'basic_salaries', $today);
        send_export(__('تقرير الرواتب الأساسية'), "basic-salaries-$today", [__('الكود'), __('الاسم'), __('القسم'), __('المسمى'), __('الأساسي الحالي'), __('ساري من'), __('ملاحظات')],
            array_map(fn($r) => [$r['id'], $r['name_ar'], $r['dept_name'], $r['job_name'], $r['basic'] === null ? '' : money($r['basic']), $r['effective_from'],
                trim(($r['basic'] === null ? __('مفيش راتب أساسي مسجَّل') : '') . ($r['dups'] ? ' ' . __('أساسيين بنفس التاريخ') : ''))], $rows));
    }
    render('basic_salaries', ['title' => __('الرواتب الأساسية'), 'rows' => $rows, 'dept' => $dept, 'job' => $job,
        'depts' => fk_options(['fk' => ['departments', 'name']]), 'jobs' => fk_options(['fk' => ['job_titles', 'name']]),
        'rates' => q_all('SELECT id, name, allowance_rate, quarter_rate FROM departments WHERE active = 1 ORDER BY name')]);
}

/** Old payroll.ratesSet: the allowance and quarter-deduction rates of every department in one table. Returns [saved, error|null]. */
function payroll_rates_save(array $allow, array $quarter): array
{
    // Validate everything first, so a bad value saves nothing
    $norm = fn($v) => ($v = trim((string)$v)) === '' ? null : $v;
    $changes = [];
    foreach (q_all('SELECT id, name, allowance_rate, quarter_rate FROM departments') as $d) {
        $id = (int)$d['id'];
        $new = [];
        foreach (['allowance_rate' => $allow, 'quarter_rate' => $quarter] as $col => $src) {
            if (!array_key_exists($id, $src)) continue;
            $v = $norm($src[$id]);
            if ($v !== null && (!is_numeric($v) || (float)$v < 0 || (float)$v > 100)) return [0, __('نسبة غير صحيحة للقسم «%s»: لازم من 0 لـ 100.', $d['name'])];
            $v = $v === null ? null : round((float)$v, 2);
            if (($d[$col] === null ? null : (float)$d[$col]) !== $v) $new[$col] = $v;
        }
        if ($new) $changes[] = [$id, $d['name'], $new];
    }
    $saved = 0;
    foreach ($changes as [$id, $name, $new]) {
        q('UPDATE departments SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($new))) . ' WHERE id = ?', array_merge(array_values($new), [$id]));
        audit('payroll.ratesSet', $name, $new);
        $saved += count($new);
    }
    return [$saved, null];
}


function page_basic_salaries_post(): void
{
    if (($_POST['op'] ?? '') === 'rates') {
        require_perm('salary', 'update');
        [$n, $err] = payroll_rates_save(is_array($_POST['allowance'] ?? null) ? $_POST['allowance'] : [], is_array($_POST['quarter'] ?? null) ? $_POST['quarter'] : []);
        flash($err ? 'err' : 'ok', $err ?? ($n ? __('تم حفظ %d قيمة.', $n) : __('لا توجد تغييرات للحفظ.')));
        redirect(url('basic_salaries') . '#rates');
    }
    require_perm('salary', 'create');
    $from = valid_date($_POST['effective_from'] ?? null);
    if (!$from) { flash('err', __('تاريخ السريان مطلوب.')); redirect(url('basic_salaries')); }
    $reason = in_str('reason', 500) ?: __('حفظ جماعي للرواتب الأساسية');
    $vals = is_array($_POST['basic'] ?? null) ? $_POST['basic'] : [];
    $saved = 0; $skipped = [];
    db()->beginTransaction();
    foreach ($vals as $id => $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        $id = (int)$id;
        if (!can_see_employee($id) || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v)) { $skipped[] = $id; continue; }
        if (add_basic_salary($id, $from, (float)$v, $reason) !== null) { $skipped[] = $id; continue; }
        $saved++;
    }
    audit('salary.basicBulk', $from, ['saved' => $saved, 'skipped' => count($skipped)]);
    db()->commit();
    flash($saved ? 'ok' : 'err', __('اتحفظ %d راتب أساسي جديد من %s.', $saved, $from));
    if ($skipped) flash('err', __('اتسابوا (قيمة غلط أو ليهم أساسي بنفس التاريخ): %s', implode('، ', $skipped)));
    redirect(url('basic_salaries'));
}

// ═════════════ Advances report + cancel (old getAdvancesReport_ / advanceCancel) ═════════════

function page_advances_report(): void
{
    require_perm('salary');
    $from = valid_date($_GET['from'] ?? null);
    $to = valid_date($_GET['to'] ?? null);
    $today = date('Y-m-d');
    [$w, $p] = scope_sql('e');
    $sql = "SELECT a.*, e.name_ar, d.name dept_name FROM advances a JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE $w";
    if ($from) { $sql .= ' AND a.advance_date >= ?'; $p[] = $from; }
    if ($to) { $sql .= ' AND a.advance_date <= ?'; $p[] = $to; }
    $rows = [];
    $tot = ['total' => 0.0, 'collected' => 0.0, 'remaining' => 0.0];
    foreach (q_all($sql . ' ORDER BY a.advance_date DESC, a.id DESC', $p) as $a) {
        $r = $a + advance_progress($a, $today);
        if ($a['status'] !== 'cancelled') $tot['total'] += (float)$a['total'];
        $tot['collected'] += $r['collected']; $tot['remaining'] += $r['remaining'];
        $rows[] = $r;
    }
    $tot = array_map('round2', $tot);
    if (export_format()) {
        require_perm('salary', 'export');
        audit('export.' . export_format(), 'advances', $from . '..' . $to);
        send_export(__('تقرير السلف'), 'advances', [__('الكود'), __('الاسم'), __('القسم'), __('تاريخ السلفة'), __('الإجمالي'), __('عدد الأقساط'), __('بداية الخصم'), __('المحصّل'), __('أقساط محصّلة'), __('المتبقي'), __('الحالة'), __('سبب الإلغاء')],
            array_map(fn($r) => [$r['employee_id'], $r['name_ar'], $r['dept_name'], $r['advance_date'], money($r['total']), $r['installments'], $r['deduct_from'], money($r['collected']), $r['collected_count'], money($r['remaining']),
                __(['active' => 'سارية', 'paid' => 'مسددة', 'cancelled' => 'ملغاة'][$r['status']] ?? $r['status']), $r['cancel_reason']], $rows));
    }
    render('advances_report', ['title' => __('تقرير السلف'), 'rows' => $rows, 'tot' => $tot, 'from' => $from, 'to' => $to]);
}

function page_advances_report_post(): void
{
    require_perm('salary', 'update');
    $id = in_int('id');
    $reason = in_str('reason', 500);
    $a = $id ? q_one('SELECT * FROM advances WHERE id = ?', [$id]) : null;
    if (!$a || !can_see_employee((int)$a['employee_id'])) http_error(404);
    if ($reason === '') { flash('err', __('لازم تكتب سبب الإلغاء.')); redirect(url('advances_report')); }
    if ($a['status'] === 'cancelled') { flash('err', __('السلفة دي ملغاة بالفعل.')); redirect(url('advances_report')); }
    $by = user()['username'];
    db()->beginTransaction();
    q("UPDATE advances SET status = 'cancelled', cancel_reason = ?, cancelled_by = ?, cancelled_at = NOW() WHERE id = ?", [$reason, $by, $id]);
    q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?,?)',
        [$a['employee_id'], 'إلغاء سلفة', 'سارية', 'ملغاة', $reason, $by, bin2hex(random_bytes(8))]);
    audit('advance.cancel', $id, ['employee' => (int)$a['employee_id'], 'reason' => $reason]);
    db()->commit();
    flash('ok', __('السلفة اتلغت. الأقساط اللي لسه ماستحقتش مش هتتخصم.'));
    redirect(url('advances_report'));
}
