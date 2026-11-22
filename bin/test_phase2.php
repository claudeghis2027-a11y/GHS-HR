<?php
declare(strict_types=1);
/*
 * Phase 2 tests: rule overlap and archive, basic salary rules, raise approval, advances, monthly closing
 * (snapshot + attendance grid + gaps), reopen, and the old admin password format. Expected values come
 * from the old GHIS code (RulesService, SalaryService, ClosingService, U.hash).
 *
 * DESTRUCTIVE: wipes the tables it uses. Runs only against a database whose name contains "test".
 *   HR_CONFIG=/path/to/test-config.php php bin/test_phase2.php
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/pages_salary.php';

if (!str_contains((string)q_val('SELECT DATABASE()'), 'test')) { fwrite(STDERR, "Refusing: not a test database.\n"); exit(1); }
$GLOBALS['USER'] = ['id' => null, 'username' => 'phase2-test', 'is_super' => 1, 'is_staff' => 1, 'role_slug' => 'super_admin', 'dept_scope' => null, 'employee_id' => null];

$fails = 0; $n = 0;
function check(string $what, $got, $want): void
{
    global $fails, $n;
    $n++;
    $ok = is_float($want) || is_float($got) ? abs((float)$got - (float)$want) < 0.0001 : $got === $want;
    if (!$ok) { $fails++; echo "FAIL  $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"; }
    else echo "ok    $what\n";
}
function throws(callable $f): ?string { try { $f(); return null; } catch (RuntimeException $e) { return $e->getMessage(); } }

foreach (['attendance', 'attendance_adjustments', 'salary_tx', 'advances', 'payroll_snapshot_rows', 'payroll_snapshots', 'payroll_period_log', 'payroll_periods',
          'schedule_rules', 'schedule_rules_archive', 'deduction_rules', 'payroll_year_settings', 'summer_deduction_log'] as $t) q("DELETE FROM `$t`");
q('DELETE FROM employees');
q("DELETE FROM salary_tx_types WHERE name LIKE 'T-%'");
q("DELETE FROM departments WHERE name LIKE 'T-%'");
q("DELETE FROM academic_years WHERE name LIKE 'T-%'");
$wasActive = array_column(q_all("SELECT id FROM salary_tx_types WHERE category = 'basic' AND active = 1"), 'id');
q("UPDATE salary_tx_types SET active = 0 WHERE category = 'basic'");
q("INSERT INTO salary_tx_types (name, direction, category) VALUES ('T-basic', 'add', 'basic'), ('T-bonus', 'add', 'earning')");
$tBasic = (int)q_val("SELECT id FROM salary_tx_types WHERE name = 'T-basic'");
q("INSERT INTO departments (name, allowance_rate) VALUES ('T-D', 10)");
$dep = (int)q_val("SELECT id FROM departments WHERE name = 'T-D'");
foreach ([9101, 9102] as $id) q("INSERT INTO employees (id, name_ar, department_id, hire_date) VALUES (?, ?, ?, '2020-01-01')", [$id, "T$id", $dep]);

// ── 1. Schedule rules never overlap; editing needs a reason and archives the old version
$sr = fn(array $x) => $x + ['code' => '', 'start_time' => '08:00', 'end_time' => '15:00'];
$d = $sr(['date_from' => '2026-01-01', 'date_to' => '2026-06-30']); $e = [];
hook_schedule_rules_validate($d, $e, null);
check('schedule: first rule ok', $e, []);
check('schedule: auto code', $d['code'], 'SR-0001');
q('INSERT INTO schedule_rules (code, date_from, date_to, start_time, end_time) VALUES (?,?,?,?,?)', [$d['code'], $d['date_from'], $d['date_to'], $d['start_time'], $d['end_time']]);
$r1 = q_one("SELECT * FROM schedule_rules WHERE code = 'SR-0001'");
$d = $sr(['date_from' => '2026-06-30', 'date_to' => '2026-12-31']); $e = [];
hook_schedule_rules_validate($d, $e, null);
check('schedule: overlapping by one day → error', isset($e['date_from']), true);
$d = $sr(['date_from' => '2026-07-01', 'date_to' => '2026-12-31']); $e = [];
hook_schedule_rules_validate($d, $e, null);
check('schedule: adjacent rule ok', $e, []);
check('schedule: next code', $d['code'], 'SR-0002');
$d = $sr(['date_from' => '2026-01-01', 'date_to' => '2026-06-30', 'code' => 'SR-0001', 'change_reason' => '']); $e = [];
hook_schedule_rules_validate($d, $e, $r1);
check('schedule: edit without reason → error', isset($e['change_reason']), true);
check('schedule: edit itself is not an overlap', isset($e['date_from']), false);
hook_schedule_rules_after((int)$r1['id'], ['change_reason' => 'تغيير الميعاد'], $r1, []);
$a = q_one('SELECT * FROM schedule_rules_archive ORDER BY id DESC LIMIT 1');
check('archive: old version kept', [$a['rule_code'], $a['operation'], $a['date_to'], $a['reason']], ['SR-0001', 'تعديل', '2026-06-30', 'تغيير الميعاد']);

// ── 2. Deduction rules never overlap
q("INSERT INTO deduction_rules (date_from, date_to) VALUES ('2026-01-01', '2026-12-31')");
$d = ['date_from' => '2026-12-31', 'date_to' => '2027-06-30']; $e = [];
hook_deduction_rules_validate($d, $e, null);
check('deduction rule overlap → error', isset($e['date_from']), true);

// ── 3. Basic salary: one per employee and effective date; value > 0
q("INSERT INTO salary_tx (employee_id, type_id, date_from, amount) VALUES (9101, ?, '2020-01-01', 4000), (9102, ?, '2020-01-01', 5000)", [$tBasic, $tBasic]);
$d = ['employee_id' => 9101, 'type_id' => $tBasic, 'date_from' => '2020-01-01', 'amount' => '4500']; $e = [];
hook_salary_tx_validate($d, $e, null);
check('basic: same date twice → error', isset($e['date_from']), true);
$d['date_from'] = '2026-01-01'; $e = [];
hook_salary_tx_validate($d, $e, null);
check('basic: another date ok', $e, []);
$d['amount'] = '0'; $e = [];
hook_salary_tx_validate($d, $e, null);
check('value 0 → error', isset($e['amount']), true);
$old = q_one('SELECT * FROM salary_tx WHERE employee_id = 9101');
$d = ['employee_id' => 9101, 'type_id' => $tBasic, 'date_from' => '2020-01-01', 'amount' => '4100']; $e = [];
hook_salary_tx_validate($d, $e, $old);
check('basic: editing the same row is not a duplicate', $e, []);
check('add_basic_salary: duplicate date refused', add_basic_salary(9101, '2020-01-01', 4200, 't') !== null, true);
check('add_basic_salary: zero refused', add_basic_salary(9101, '2026-02-01', 0, 't') !== null, true);

// ── 4. Annual raise: department rate × basic at the period start; approval records a new basic from the next period
q("INSERT INTO academic_years (name, date_from, date_to, active) VALUES ('T-Y', '2025-09-01', '2026-08-31', 1)");
q('INSERT INTO payroll_year_settings (academic_year_id, raise_month) VALUES (?, ?)', [(int)q_val("SELECT id FROM academic_years WHERE name = 'T-Y'"), '2026-07']);
$due = raises_due('2026-07', null);
check('raise: both employees due', array_keys($due), [9101, 9102]);
check('raise: 10% of 4000', $due[9101]['allowance'], 400.0);
check('raise: not due in June', raises_due('2026-06', null), []);
check('raise approve → new basic', add_basic_salary(9101, '2026-08-01', round2(4000 + $due[9101]['allowance']), 'raise'), null);
check('raise month itself keeps the old basic + allowance', [payroll_compute(9101, '2026-07-01', '2026-07-31', '2026-07')['basic'], payroll_compute(9101, '2026-07-01', '2026-07-31', '2026-07')['allowance']], [4000.0, 400.0]);
check('next month uses the new basic, no allowance', [payroll_compute(9101, '2026-08-01', '2026-08-31', '2026-08')['basic'], payroll_compute(9101, '2026-08-01', '2026-08-31', '2026-08')['allowance']], [4400.0, 0.0]);

// ── 5. Advances: installment value from the cents split; collected = installments due by today; cancelled collects nothing
$d = ['employee_id' => 9101, 'advance_date' => '2026-04-01', 'total' => '1000', 'installments' => 3, 'deduct_from' => '2026-04-15']; $e = [];
hook_advances_validate($d, $e, null);
check('advance: installment value', [$d['installment_value'], $d['status']], [333.33, 'active']);
$adv = ['total' => 1000, 'installments' => 3, 'deduct_from' => '2026-04-15', 'status' => 'active'];
$p = advance_progress($adv, '2026-05-20');
check('advance report: schedule of 3 due dates', array_column($p['schedule'], 'due'), ['2026-04-15', '2026-05-15', '2026-06-15']);
check('advance report: 2 of 3 collected', [$p['collected'], $p['collected_count'], $p['remaining']], [666.66, 2, 333.34]);
$p = advance_progress(['status' => 'cancelled'] + $adv, '2026-05-20');
check('advance report: cancelled', [$p['collected'], $p['collected_count'], $p['remaining']], [0.0, 0, 0.0]);
$e = [];
hook_advances_validate($d, $e, ['status' => 'cancelled']);
check('advance: cancelled can not be edited', isset($e['_']), true);
check('advance: delete refused', hook_advances_delete([]) !== null, true);

// ── 6. Payroll periods: no overlap; gaps are reported
q("INSERT INTO payroll_periods (id, name, date_from, date_to) VALUES (1, 'T-P1', '2026-01-26', '2026-02-25'), (2, 'T-P2', '2026-02-26', '2026-03-25'), (3, 'T-P3', '2026-04-01', '2026-04-25')");
$d = ['date_from' => '2026-03-20', 'date_to' => '2026-03-31']; $e = [];
hook_payroll_periods_validate($d, $e, null);
check('period overlap → error', isset($e['date_from']), true);
check('gap between P2 and P3', period_gaps(), [['from' => '2026-03-26', 'to' => '2026-03-31', 'days' => 6]]);

// ── 7. Closing: needs the gap OK, saves pay + grid, verifies, marks the period closed
$r = period_close(2, 'phase2-test', 'note', false);
check('close without gap OK → asks', [$r['ok'], $r['needs_gap_ack'] ?? false], [false, true]);
check('period still open', q_val('SELECT status FROM payroll_periods WHERE id = 2'), 'open');
$r = period_close(2, 'phase2-test', 'note', true);
check('close with gap OK', [$r['ok'], $r['employees']], [true, 2]);
$snap = period_snapshot(2);
check('snapshot ready', $snap['state'] ?? null, 'ready');
check('period closed', q_val('SELECT status FROM payroll_periods WHERE id = 2'), 'closed');
check('log has the gap warning', (int)q_val("SELECT gap_warning FROM payroll_period_log WHERE period_id = 2 AND action = 'close'"), 1);
$rows = snapshot_rows((int)$snap['id']);
check('grid: one cell per day (28 days)', count($rows[9101]['cells']), 28);
check('grid: first cell date', $rows[9101]['cells'][0]['d'], '2026-02-26');
$live = payroll_compute(9102, '2026-02-26', '2026-03-25', '2026-03');
check('snapshot net = live net at close', $rows[9102]['net_final'], $live['net_final']);
q("INSERT INTO salary_tx (employee_id, type_id, date_from, amount) VALUES (9102, ?, '2026-03-01', 777)", [(int)q_val("SELECT id FROM salary_tx_types WHERE name = 'T-bonus'")]);
check('later changes do not touch the snapshot', snapshot_rows((int)$snap['id'])[9102]['net_final'], $live['net_final']);
check('closing twice refused', throws(fn() => period_close(2, 'x', '', true)) !== null, true);
$d = ['date_from' => '2026-02-27', 'date_to' => '2026-03-25']; $e = [];
hook_payroll_periods_validate($d, $e, q_one('SELECT * FROM payroll_periods WHERE id = 2'));
check('closed period dates locked', isset($e['_']), true);
check('closed period can not be deleted', hook_payroll_periods_delete(q_one('SELECT * FROM payroll_periods WHERE id = 2')) !== null, true);
check('grid text: present shows late minutes', grid_text(['s' => 'present', 'late' => 12]), '12');
check('grid text: absent', grid_text(['s' => 'absent', 'late' => 0]), 'غائب');

// ── 8. Reopen: reason required; the snapshot is superseded; closing again makes a new one
check('reopen without reason refused', throws(fn() => period_reopen(2, 'x', ' ')) !== null, true);
check('reopen supersedes 1 snapshot', period_reopen(2, 'phase2-test', 'تصحيح'), 1);
check('period open again', q_val('SELECT status FROM payroll_periods WHERE id = 2'), 'open');
check('no ready snapshot after reopen', period_snapshot(2), null);
check('reopen logged with reason', q_val("SELECT reason FROM payroll_period_log WHERE period_id = 2 AND action = 'reopen'"), 'تصحيح');
period_close(2, 'phase2-test', '', true);
check('re-close: 2 snapshots, 1 ready', [(int)q_val('SELECT COUNT(*) FROM payroll_snapshots WHERE period_id = 2'), (int)q_val("SELECT COUNT(*) FROM payroll_snapshots WHERE period_id = 2 AND state = 'ready'")], [2, 1]);
check('re-close picks up the new bonus', snapshot_rows((int)period_snapshot(2)['id'])[9102]['earnings'], 777.0);
check('reopening an open period refused', throws(fn() => period_reopen(1, 'x', 'r')) !== null, true);

// ── 9. Old admin passwords: SHA-256(salt + '::' + password)
check('legacy salt::password', verify_legacy('secret1', hash('sha256', 'abc::secret1'), 'abc'), true);
check('legacy wrong password', verify_legacy('secret2', hash('sha256', 'abc::secret1'), 'abc'), false);

foreach ($wasActive as $id) q('UPDATE salary_tx_types SET active = 1 WHERE id = ?', [$id]);
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
