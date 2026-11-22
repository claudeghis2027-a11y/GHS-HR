<?php
declare(strict_types=1);
/*
 * Parity tests: attendance and payroll results must equal what the old GHIS Apps Script code gives
 * for the same inputs. Every expected value below is worked out by hand from the old code
 * (AttendanceCalc.employeeReport / employeeReportWithDeductions, SalaryService.salaryTable / summerCalculate).
 *
 * DESTRUCTIVE: wipes the tables it uses. Runs only against a database whose name contains "test".
 *   HR_CONFIG=/path/to/test-config.php php bin/test_parity.php
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/pages_salary.php';

if (!str_contains((string)q_val('SELECT DATABASE()'), 'test')) { fwrite(STDERR, "Refusing: not a test database.\n"); exit(1); }
$GLOBALS['USER'] = ['id' => null, 'username' => 'parity-test'];

$fails = 0; $n = 0;
function check(string $what, $got, $want): void
{
    global $fails, $n;
    $n++;
    $ok = is_float($want) || is_float($got) ? abs((float)$got - (float)$want) < 0.0001 : $got === $want;
    if (!$ok) { $fails++; echo "FAIL  $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"; }
    else echo "ok    $what\n";
}

foreach (['attendance', 'attendance_adjustments', 'late_exemptions', 'permission_requests', 'forgot_punch_log', 'extra_deductions', 'leaves', 'holidays',
          'checkout_exemptions', 'employee_schedules', 'schedule_rules', 'deduction_rules', 'salary_tx', 'advances', 'summer_deduction_log',
          'payroll_year_settings', 'payroll_periods'] as $t) q("DELETE FROM `$t`");
q('DELETE FROM employees WHERE id >= 9000');
q("DELETE FROM salary_tx_types WHERE name LIKE 'T-%'");
q("DELETE FROM departments WHERE name LIKE 'T-%'");
q("DELETE FROM academic_years WHERE name LIKE 'T-%'");

q("INSERT INTO departments (name, summer_rule, allowance_rate, quarter_rate) VALUES ('T-R1', 'Rule 1', 10, NULL), ('T-R2', 'Rule 2', 0, 20), ('T-none', NULL, NULL, NULL)");
$d1 = (int)q_val("SELECT id FROM departments WHERE name = 'T-R1'");
$d2 = (int)q_val("SELECT id FROM departments WHERE name = 'T-R2'");
$d0 = (int)q_val("SELECT id FROM departments WHERE name = 'T-none'");
$emp = function (int $id, int $dept, string $hire = '2020-01-01', array $x = []) {
    $row = array_merge(['id' => $id, 'name_ar' => "T$id", 'department_id' => $dept, 'hire_date' => $hire], $x);
    q('INSERT INTO employees (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
};
// The real rules from the old sheet: 08:00–15:00; late unit 25 min = 0.25 day; absence 1; missing checkout 0.25; early leave off.
q("INSERT INTO schedule_rules (date_from, date_to, start_time, end_time) VALUES ('2026-01-01', '2026-12-31', '08:00', '15:00')");
q("INSERT INTO deduction_rules (date_from, date_to, late_unit_min, late_unit_days, absence_days, missing_checkout_days, early_leave_enabled, early_unit_min, early_unit_days)
   VALUES ('2026-01-01', '2026-12-31', 25, 0.25, 1, 0.25, 0, 25, 0.25)");
$punch = fn(int $e, string $d, ?string $in, ?string $out) => q('INSERT INTO attendance (employee_id, work_date, check_in_at, check_out_at) VALUES (?,?,?,?)', [$e, $d, $in ? "$d $in:00" : null, $out ? "$d $out:00" : null]);
$from = '2026-09-01'; $to = '2026-09-10'; // work days: 1,2,3,6,7,8,9,10 (4 and 5 are Fri/Sat)
$work = ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-06', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10'];

// ── 1. Late minutes are summed over the period, then floored: 3 × 10 min = 30 → 1 unit (not 3)
$emp(9001, $d0);
foreach ($work as $i => $d) $punch(9001, $d, $i < 3 ? '08:10' : '07:55', '15:05');
$t = att_evaluate(9001, $from, $to)['totals'];
check('late: cumulative minutes', $t['late_min'], 30);
check('late: floor(30/25) × 0.25', $t['late_days'], 0.25);
check('late: total', $t['total_days'], 0.25);
check('late: work days', $t['work_days'], 8);

// ── 2. A late waiver comes off the same cumulative total: 30 − 10 = 20 → 0 units
q("INSERT INTO late_exemptions (employee_id, ex_date, minutes, reason, approver) VALUES (9001, '2026-09-01', 10, 'x', 'y')");
$t = att_evaluate(9001, $from, $to)['totals'];
check('waiver: final minutes', $t['late_min_final'], 20);
check('waiver: late days after waiver', $t['late_days'], 0.0);
check('waiver: original late days kept', $t['late_days_original'], 0.25);

// ── 3. No daily cap: 300 min late in one day = floor(300/25)=12 units = 3 days (more than an absence)
$emp(9002, $d0);
foreach ($work as $i => $d) $punch(9002, $d, $i === 0 ? '13:00' : '08:00', '15:00');
check('no daily cap', att_evaluate(9002, $from, $to)['totals']['late_days'], 3.0);

// ── 4. Permissions: 3 in the period → 0.5 day on the fixed ladder; the day's lateness still counts
$emp(9003, $d0);
foreach ($work as $i => $d) $punch(9003, $d, $i === 0 ? '08:30' : '08:00', '15:00');
foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $d) q('INSERT INTO permission_requests (employee_id, perm_date) VALUES (9003, ?)', [$d]);
$t = att_evaluate(9003, $from, $to)['totals'];
check('permissions: count', $t['perms'], 3);
check('permissions: ladder 3 → 0.5', $t['perm_days'], 0.5);
check('permissions: lateness kept (30 min → 1 unit)', $t['late_days'], 0.25);
check('permissions: total', $t['total_days'], 0.75);
check('ladder 1 → 0', permission_deduction(1), 0.0);
check('ladder 2 → 0.25', permission_deduction(2), 0.25);
check('ladder 9 → 1', permission_deduction(9), 1.0);

// ── 5. Forgotten punches: first free, then 0.25 each; the day stays absent
$emp(9004, $d0);
foreach ($work as $i => $d) if ($i !== 0) $punch(9004, $d, '08:00', '15:00');
foreach (['2026-09-01', '2026-09-02'] as $d) q('INSERT INTO forgot_punch_log (employee_id, punch_date) VALUES (9004, ?)', [$d]);
$t = att_evaluate(9004, $from, $to)['totals'];
check('forgot: 2 → 0.25', $t['forgot_days'], 0.25);
check('forgot: unpunched day is still absent', $t['absent'], 1);
check('forgot: total = 1 absence + 0.25', $t['total_days'], 1.25);

// ── 6. Missing check-out = 0.25; weekend punch has no effect; holiday wins; waive_day is neutral
$emp(9005, $d0);
foreach ($work as $i => $d) $punch(9005, $d, '08:00', $i === 1 ? null : '15:00');
$punch(9005, '2026-09-04', '09:00', '10:00'); // Friday
q("INSERT INTO holidays (holiday_date, title) VALUES ('2026-09-03', 'T')");
q("INSERT INTO attendance_adjustments (employee_id, adj_date, adj_type, reason) VALUES (9005, '2026-09-06', 'waive_day', 'x')");
$r = att_evaluate(9005, $from, $to);
$st = array_column($r['days'], 'status', 'date');
check('missing checkout: 0.25', $r['totals']['missing_out_days'], 0.25);
check('weekend with a punch stays weekend', $st['2026-09-04'], 'weekend');
check('holiday status', $st['2026-09-03'], 'holiday');
check('waive_day status', $st['2026-09-06'], 'waived');
check('work days exclude holiday and waived day', $r['totals']['work_days'], 6);
check('missing checkout total', $r['totals']['total_days'], 0.25);

// ── 7. Manual absent / manual present / time edit; latest adjustment of a kind wins
$emp(9006, $d0);
foreach ($work as $d) $punch(9006, $d, '08:00', '15:00');
q("INSERT INTO attendance_adjustments (employee_id, adj_date, adj_type, value, reason, created_at) VALUES
   (9006, '2026-09-01', 'force_absent', NULL, 'x', '2026-09-11 10:00'),
   (9006, '2026-09-02', 'set_check_in', '08:40', 'x', '2026-09-11 10:00'),
   (9006, '2026-09-02', 'set_check_in', '08:20', 'x', '2026-09-11 11:00')");
$emp(9007, $d0);
q("INSERT INTO attendance_adjustments (employee_id, adj_date, adj_type, value, reason) VALUES (9007, '2026-09-01', 'force_present', '08:00', 'x')");
$t = att_evaluate(9006, $from, $to)['totals'];
check('force_absent → 1 absence', $t['absent'], 1);
check('latest set_check_in wins (20 min, under one unit)', $t['late_min'], 20);
$t7 = att_evaluate(9007, $from, $to);
check('force_present without punch → present', array_column($t7['days'], 'status', 'date')['2026-09-01'], 'present');
check('force_present day has no check-out', $t7['totals']['missing_out'], 1);

// ── 8. Basic salary = latest effective at the period start (two open-ended basics never add up)
q("INSERT INTO salary_tx_types (name, direction, category) VALUES ('T-basic', 'add', 'basic'), ('T-bonus', 'add', 'earning'), ('T-fine', 'deduct', 'deduction'), ('T-summer', 'deduct', 'summer'), ('T-other', 'add', 'other')");
$ty = fn(string $n) => (int)q_val('SELECT id FROM salary_tx_types WHERE name = ?', [$n]);
$tx = fn(int $e, string $type, string $f, ?string $t, float $a) => q('INSERT INTO salary_tx (employee_id, type_id, date_from, date_to, amount) VALUES (?,?,?,?,?)', [$e, $ty($type), $f, $t, $a]);
$emp(9010, $d0);
$tx(9010, 'T-basic', '2026-01-01', null, 5000);
$tx(9010, 'T-basic', '2026-03-01', null, 6000);
$tx(9010, 'T-bonus', '2026-03-10', '2026-03-31', 250);
$tx(9010, 'T-fine', '2026-03-05', '2026-03-05', 100);
$tx(9010, 'T-summer', '2026-03-01', null, 999);
q("INSERT INTO payroll_periods (name, date_from, date_to) VALUES ('T-Mar', '2026-02-26', '2026-03-25')");
$p = payroll_compute(9010, '2026-03-01', '2026-03-25', '2026-03');
check('basic: latest effective, not the sum', $p['basic'], 6000.0);
check('daily value = round2(6000/30)', $p['daily_value'], 200.0);
check('earnings', $p['earnings'], 250.0);
check('cash deductions', $p['deductions'], 100.0);
check('summer-category tx is excluded', $p['net'], round2(6000 + 250 - 100 - $p['att_amount']));
$p0 = payroll_compute(9010, '2025-12-01', '2025-12-31', '2025-12');
check('no basic before the start → error, zero basic', [count($p0['errors']), $p0['basic']], [1, 0.0]);
[$pf, $pt] = payroll_range('2026-03');
check('payroll month uses the official period', [$pf, $pt], ['2026-02-26', '2026-03-25']);
$tx(9010, 'T-other', '2026-03-01', null, 50);
check('unclassified tx type → error', count(payroll_compute(9010, '2026-03-01', '2026-03-25', '2026-03')['errors']), 1);

// ── 9. Summer quarter in the salary table: Rule 1 dept = July + August at 25%; Rule 2 = July at the dept rate
$emp(9011, $d1); $tx(9011, 'T-basic', '2020-01-01', null, 4000);
$emp(9012, $d2); $tx(9012, 'T-basic', '2020-01-01', null, 4000);
$emp(9013, $d0, '2026-03-01', ['first_year_exception' => '1']); $tx(9013, 'T-basic', '2026-03-01', null, 4000);
$emp(9014, $d2, '2020-01-01', ['summer_deduction' => 'ربعين']); $tx(9014, 'T-basic', '2020-01-01', null, 4000);
$q = fn(int $e, string $ym) => payroll_compute($e, "$ym-01", date('Y-m-t', strtotime("$ym-01")), $ym)['quarter'];
check('Rule 1: July 25%', $q(9011, '2026-07'), 1000.0);
check('Rule 1: August 25%', $q(9011, '2026-08'), 1000.0);
check('Rule 1: September none', $q(9011, '2026-09'), 0.0);
check('Rule 2: July at the department rate (20%)', $q(9012, '2026-07'), 800.0);
check('Rule 2: no August', $q(9012, '2026-08'), 0.0);
check('first-year exception 1: half of July', $q(9013, '2026-07'), 2000.0);
check('first-year exception 1: no August', $q(9013, '2026-08'), 0.0);
check('own «ربعين» beats the department rule', $q(9014, '2026-08'), 800.0);

// ── 10. Annual raise: department rate in the active year's raise month; first-raise month overrides
q("INSERT INTO academic_years (name, date_from, date_to, active) VALUES ('T-2026', '2025-09-01', '2026-08-31', 1)");
q('INSERT INTO payroll_year_settings (academic_year_id, raise_month) VALUES (?, ?)', [(int)q_val("SELECT id FROM academic_years WHERE name = 'T-2026'"), '2026-07']);
check('raise month: 10% of 4000', payroll_compute(9011, '2026-07-01', '2026-07-31', '2026-07')['allowance'], 400.0);
check('not the raise month', payroll_compute(9011, '2026-06-01', '2026-06-30', '2026-06')['allowance'], 0.0);
q("UPDATE employees SET first_raise_month = '2026-09-01' WHERE id = 9011");
check('first raise month later → no raise in July', payroll_compute(9011, '2026-07-01', '2026-07-31', '2026-07')['allowance'], 0.0);
check('first raise month itself → raise', payroll_compute(9011, '2026-09-01', '2026-09-30', '2026-09')['allowance'], 400.0);

// ── 11. Advances: cents split, remainder on the last installment, due each month from the start date
check('split 1000/3', split_installments(1000, 3), [333.33, 333.33, 333.34]);
q("INSERT INTO advances (employee_id, advance_date, total, installments, deduct_from, installment_value) VALUES (9012, '2026-04-01', 1000, 3, '2026-04-15', 333.33)");
$adv = fn(string $ym) => payroll_compute(9012, "$ym-01", date('Y-m-t', strtotime("$ym-01")), $ym)['advances'];
check('advance April', $adv('2026-04'), 333.33);
check('advance June (last)', $adv('2026-06'), 333.34);
check('advance July (done)', $adv('2026-07'), 0.0);
check('add_months_key clamps to month end', add_months_key('2026-01-31', 1), '2026-02-28');

// ── 12. Rounding: net to 2 decimals, final net to the nearest 5
check('round5(1234.5) = 1235', round5(1234.5), 1235.0);
check('round5(1232.49) = 1230', round5(1232.49), 1230.0);
check('round5(1237.5) = 1240', round5(1237.5), 1240.0);

// ── 13. Summer calculation and refund (idempotent per employee / month / year)
q('DELETE FROM employees WHERE id >= 9000 AND id NOT IN (9011, 9012, 9013, 9014)');
q("UPDATE employees SET first_raise_month = NULL");
q('DELETE FROM employees WHERE id < 9000'); // keep the test fixture alone in the test DB
$r1 = summer_calculate(2026);
check('summer: rows (9011 ×2, 9012 ×1, 9013 ×1 half, 9014 ×2)', $r1['calculated'], 6);
check('summer: total = 1000+1000+1000+2000+1000+1000', $r1['total'], 7000.0);
check('summer: second run adds nothing', summer_calculate(2026)['calculated'], 0);
check('summer: half rows are non-refundable', q_val("SELECT refund_status FROM summer_deduction_log WHERE employee_id = 9013"), 'غير قابل للرد');
$rf = summer_refund(2026, null, 'ربعين');
check('refund by type ربعين: 4 rows', $rf['rows'], 4);
check('refund by type ربعين: 4000', $rf['total'], 4000.0);
check('refund again: nothing pending', summer_refund(2026, null, 'ربعين')['rows'], 0);
check('refund one employee (ربع)', summer_refund(2026, 9012, '')['total'], 1000.0);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
