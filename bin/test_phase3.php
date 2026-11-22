<?php
declare(strict_types=1);
/*
 * Phase 3 tests: personal schedule segmentation, document rules and status, name lists never deleted,
 * the employee change reason, investigations (numbers, versions, cancel), notification types and cancel,
 * holidays (cancel / reactivate / substitute day) and the scope picker. Expected values come from the old
 * GHIS code (ScheduleOverrideService, DocumentService, InvestigationService, HolidayService).
 *
 * DESTRUCTIVE: wipes the tables it uses. Runs only against a database whose name contains "test".
 *   HR_CONFIG=/path/to/test-config.php php bin/test_phase3.php
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/pages_hr.php';

if (!str_contains((string)q_val('SELECT DATABASE()'), 'test')) { fwrite(STDERR, "Refusing: not a test database.\n"); exit(1); }
$GLOBALS['USER'] = ['id' => null, 'username' => 'phase3-test', 'is_super' => 1, 'is_staff' => 1, 'role_slug' => 'super_admin', 'dept_scope' => null, 'employee_id' => null];

$fails = 0; $n = 0;
function check(string $what, $got, $want): void
{
    global $fails, $n;
    $n++;
    if ($got !== $want) { $fails++; echo "FAIL  $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"; }
    else echo "ok    $what\n";
}

foreach (['employee_schedules', 'employee_documents', 'investigation_attachments', 'investigation_employees', 'investigations', 'notification_targets', 'notifications', 'holidays', 'employee_change_log'] as $t) q("DELETE FROM `$t`");
q('DELETE FROM employees');
q("DELETE FROM employee_documents WHERE employee_id BETWEEN 9201 AND 9203");
q("DELETE FROM document_types WHERE name LIKE 'T-%'");
q("DELETE FROM departments WHERE name LIKE 'T-%'");
q("INSERT INTO departments (name) VALUES ('T-D1'), ('T-D2')");
$d1 = (int)q_val("SELECT id FROM departments WHERE name = 'T-D1'");
$d2 = (int)q_val("SELECT id FROM departments WHERE name = 'T-D2'");
foreach ([9201 => $d1, 9202 => $d1, 9203 => $d2] as $id => $dep) q("INSERT INTO employees (id, name_ar, department_id, hire_date) VALUES (?, ?, ?, '2020-01-01')", [$id, "T$id", $dep]);
$sched = function (string $from, string $to): int { q("INSERT INTO employee_schedules (employee_id, date_from, date_to, start_time, end_time) VALUES (9201, ?, ?, '09:00', '14:00')", [$from, $to]); return (int)db()->lastInsertId(); };
$ranges = fn() => array_map(fn($r) => $r['date_from'] . '..' . $r['date_to'], q_all('SELECT date_from, date_to FROM employee_schedules WHERE employee_id = 9201 ORDER BY date_from'));

// ── 1. Personal schedules: a new one trims, splits or replaces the overlapping ones
$sched('2026-01-01', '2026-01-31');
$id = $sched('2026-01-10', '2026-01-20');
check('schedule split: counts', schedule_segment(9201, '2026-01-10', '2026-01-20', $id), ['trimmed' => 1, 'removed' => 0, 'split' => 1]);
check('schedule split: ranges', $ranges(), ['2026-01-01..2026-01-09', '2026-01-10..2026-01-20', '2026-01-21..2026-01-31']);
$id = $sched('2026-01-05', '2026-01-25');
check('schedule trim both + remove inner', schedule_segment(9201, '2026-01-05', '2026-01-25', $id), ['trimmed' => 2, 'removed' => 1, 'split' => 0]);
check('schedule trim: ranges', $ranges(), ['2026-01-01..2026-01-04', '2026-01-05..2026-01-25', '2026-01-26..2026-01-31']);
$id = $sched('2026-01-01', '2026-01-31');
schedule_segment(9201, '2026-01-01', '2026-01-31', $id);
check('schedule cover all: one left', $ranges(), ['2026-01-01..2026-01-31']);
check('other employees untouched', (int)q_val('SELECT COUNT(*) FROM employee_schedules WHERE employee_id <> 9201'), 0);

// ── 2. Departments and job titles are deactivated, never deleted
check('name list delete refused', hook_name_lists_delete(['id' => $d1]) !== null, true);

// ── 3. Employee edits log the reason with every changed field
hook_employees_after(9201, ['change_reason' => 'تصحيح'], ['id' => 9201], ['name_ar' => ['a', 'b'], 'phone' => ['1', '2']]);
check('change log: 2 rows with reason', q_all("SELECT field, reason FROM employee_change_log WHERE employee_id = 9201 ORDER BY id"), [['field' => 'name_ar', 'reason' => 'تصحيح'], ['field' => 'phone', 'reason' => 'تصحيح']]);
check('change log: one op id per save', (int)q_val('SELECT COUNT(DISTINCT op_id) FROM employee_change_log WHERE employee_id = 9201'), 1);

// ── 4. Document rules and status
q("INSERT INTO document_types (name, has_number, required, has_expiry, alert_days, active) VALUES ('T-ID', 1, 1, 1, 30, 1), ('T-CV', 0, 0, 0, NULL, 1), ('T-OLD', 0, 1, 0, NULL, 0)");
$tId = (int)q_val("SELECT id FROM document_types WHERE name = 'T-ID'");
$tCv = (int)q_val("SELECT id FROM document_types WHERE name = 'T-CV'");
$tOld = (int)q_val("SELECT id FROM document_types WHERE name = 'T-OLD'");
$doc = fn(array $x) => $x + ['employee_id' => 9201, 'type_id' => $tId, 'doc_number' => '', 'is_original' => 'copy', 'issue_date' => null, 'expiry_date' => null, 'confirm_duplicate' => 0];
$v = function (array $d, ?array $old = null) { $e = []; hook_documents_validate($d, $e, $old); return [$d, $e]; };
[, $e] = $v($doc([]));
check('doc: number required for a numbered type', isset($e['doc_number']), true);
[, $e] = $v($doc(['type_id' => $tOld]));
check('doc: stopped type refused', isset($e['type_id']), true);
[$d, $e] = $v($doc(['type_id' => $tCv, 'expiry_date' => '2030-01-01']));
check('doc: expiry cleared for a type without expiry', [$e, $d['expiry_date']], [[], null]);
q("INSERT INTO employee_documents (employee_id, type_id, doc_number, is_original, expiry_date, created_at) VALUES (9201, ?, 'N1', 'original', '2026-11-10', '2026-01-01 10:00:00')", [$tId]);
$first = (int)db()->lastInsertId();
[, $e] = $v($doc(['doc_number' => 'N2']));
check('doc: duplicate needs confirmation', isset($e['confirm_duplicate']), true);
[, $e] = $v($doc(['doc_number' => 'N2', 'confirm_duplicate' => 1]));
check('doc: duplicate confirmed ok', $e, []);
$status = function (int $emp, int $type, string $today) { foreach (doc_status_rows([$emp], $type, $today) as $r) return [$r['status'], $r['days_left']]; return null; };
check('status: valid', $status(9201, $tId, '2026-09-01'), ['ساري', 70]);
check('status: near expiry (30 days)', $status(9201, $tId, '2026-10-20'), ['قريب الانتهاء', 21]);
check('status: expired', $status(9201, $tId, '2026-11-11'), ['منتهي', -1]);
check('status: required + none = missing', $status(9202, $tId, '2026-09-01'), ['ناقص', null]);
check('status: optional + none = not required', $status(9202, $tCv, '2026-09-01'), ['غير مطلوب', null]);
check('status: stopped types are left out', $status(9201, $tOld, '2026-09-01'), null);
q("INSERT INTO employee_documents (employee_id, type_id, doc_number, is_original, expiry_date, created_at) VALUES (9201, ?, 'N2', 'copy', '2031-01-01', '2026-02-01 10:00:00')", [$tId]);
check('status: the latest record decides', $status(9201, $tId, '2026-11-11'), ['ساري', 1512]);
$last = (int)q_val("SELECT id FROM employee_documents WHERE doc_number = 'N2'");
q('UPDATE employee_documents SET is_deleted = 1 WHERE id = ?', [$last]);
check('status: deleted latest = missing', $status(9201, $tId, '2026-09-01'), ['ناقص', null]);
[, $e] = $v($doc(['doc_number' => 'N3']));
check('doc: after a delete no duplicate warning', $e, []);
$f = ['dept' => null, 'job' => null, 'type' => null, 'emp' => null, 'ended' => false];
$miss = doc_report_rows('missing', $f, '2026-09-01');
check('report missing: 3 employees missing T-ID', count(array_filter($miss, fn($r) => in_array('T-ID', $r['types'], true))), 3);
check('report missing: department filter', count(doc_report_rows('missing', ['dept' => $d2] + $f, '2026-09-01')), 1);
q('UPDATE employee_documents SET is_deleted = 0 WHERE id = ?', [$last]);
q("UPDATE employee_documents SET expiry_date = '2026-09-20' WHERE id = ?", [$last]);
check('report near: one row', array_map(fn($r) => [$r['emp']['id'], $r['status']], doc_report_rows('near', $f, '2026-09-01')), [[9201, 'قريب الانتهاء']]);
check('report by type: all 3 employees', count(doc_report_rows('type', ['type' => $tId] + $f, '2026-09-01')), 3);
q("UPDATE employees SET end_date = '2026-01-01' WHERE id = 9203");
check('report: ended employees hidden by default', count(doc_report_rows('type', ['type' => $tId] + $f, '2026-09-01')), 2);
check('report: ended employees on request', count(doc_report_rows('type', ['type' => $tId, 'ended' => true] + $f, '2026-09-01')), 3);
q('UPDATE employees SET end_date = NULL WHERE id = 9203');

// ── 5. Investigations: INV numbers, edit = new version, cancel with a reason
q("INSERT INTO investigations (legacy_id, inv_no, inv_date, subject) VALUES ('INV-000041', 'INV-000041', '2025-01-01', 'old')");
check('inv: next number after the legacy max', inv_next_no(), 'INV-000042');
[$err] = inv_save(null, '2026-03-01', 'x', '', '', [], 'phase3-test');
check('inv: at least one employee', $err !== null, true);
[$err] = inv_save(null, '2026-03-01', ' ', '', '', [9201], 'phase3-test');
check('inv: subject required', $err !== null, true);
[$err] = inv_save(null, '2026-03-01', 'x', '', '', [99999], 'phase3-test');
check('inv: unknown employee refused', $err !== null, true);
[$err, $v1] = inv_save(null, '2026-03-01', 'موضوع', 'قرار', '', [9201, 9202, 9201], 'phase3-test');
check('inv: created', [$err, q_val('SELECT inv_no FROM investigations WHERE id = ?', [$v1])], [null, 'INV-000042']);
check('inv: duplicates removed from employees', (int)q_val('SELECT COUNT(*) FROM investigation_employees WHERE investigation_id = ?', [$v1]), 2);
q("INSERT INTO investigation_attachments (investigation_id, file_ref, file_name) VALUES (?, 'f.pdf', 'محضر')", [$v1]);
[$err, $v2] = inv_save($v1, '2026-03-02', 'موضوع معدل', 'قرار', '', [9203], 'phase3-test');
check('inv edit: same number', q_val('SELECT inv_no FROM investigations WHERE id = ?', [$v2]), 'INV-000042');
check('inv edit: old version kept, cancelled, linked', q_one('SELECT cancelled, superseded_by FROM investigations WHERE id = ?', [$v1]), ['cancelled' => 1, 'superseded_by' => $v2]);
check('inv edit: old links cancelled', (int)q_val('SELECT COUNT(*) FROM investigation_employees WHERE investigation_id = ? AND cancelled = 0', [$v1]), 0);
check('inv edit: new employees', array_map('intval', array_column(q_all('SELECT employee_id FROM investigation_employees WHERE investigation_id = ? AND cancelled = 0', [$v2]), 'employee_id')), [9203]);
check('inv edit: attachment moves to the new version', (int)q_val('SELECT investigation_id FROM investigation_attachments WHERE file_name = ?', ['محضر']), $v2);
[$err] = inv_save($v1, '2026-03-03', 'y', '', '', [9201], 'phase3-test');
check('inv edit of an old version refused', $err !== null, true);
check('inv cancel: reason required', inv_cancel($v2, ' ', 'phase3-test') !== null, true);
check('inv cancel ok', inv_cancel($v2, 'اتكرر', 'phase3-test'), null);
check('inv cancel: reason saved, attachments cancelled', [q_val('SELECT cancel_reason FROM investigations WHERE id = ?', [$v2]), (int)q_val('SELECT cancelled FROM investigation_attachments WHERE file_name = ?', ['محضر'])], ['اتكرر', 1]);
check('inv cancel twice refused', inv_cancel($v2, 'x', 'phase3-test') !== null, true);
check('inv: nothing deleted', (int)q_val('SELECT COUNT(*) FROM investigations'), 3);

// ── 6. Notifications: type rules, scope picker, cancel with a reason
$nv = function (array $d, ?array $old = null) { $e = []; hook_notifications_validate($d, $e, $old); return [$d, $e]; };
$nd = fn(array $x) => $x + ['ntype' => 'عام', 'scope' => 'all', 'scope_dept' => null, 'scope_emp' => null, 'period_month' => null, 'report_from' => null, 'report_to' => null];
[, $e] = $nv($nd(['ntype' => 'كشف مرتب']));
check('notif: payslip needs a month', isset($e['period_month']), true);
[, $e] = $nv($nd(['ntype' => 'تقرير حضور', 'report_from' => '2026-03-10', 'report_to' => '2026-03-01']));
check('notif: report period order', isset($e['report_to']), true);
[$d, $e] = $nv($nd(['ntype' => 'عام', 'period_month' => '2026-03-01']));
check('notif: general clears the period', [$e, $d['period_month']], [[], null]);
[$d, $e] = $nv($nd(['scope' => 'department', 'scope_dept' => $d2]));
check('scope pick: department id stored', [$e, $d['scope_value']], [[], (string)$d2]);
[, $e] = $nv($nd(['scope' => 'employee']));
check('scope pick: employee required', isset($e['scope_emp']), true);
$row = ['scope' => 'employee', 'scope_value' => '9202']; scope_pick_load($row);
check('scope load: employee prefilled', $row['scope_emp'], '9202');
q("INSERT INTO notifications (ntype, title, show_at, duration_min, scope, scope_value) VALUES ('عام', 't', NOW(), 60, 'department', ?)", [(string)$d1]);
$nid = (int)db()->lastInsertId();
hook_notifications_after($nid, [], null, []);
check('notif targets: department only', (int)q_val('SELECT COUNT(*) FROM notification_targets WHERE notification_id = ?', [$nid]), 2);
q('UPDATE notification_targets SET read_at = NOW() WHERE notification_id = ? AND employee_id = 9201', [$nid]);
check('notif cancel: reason required', notif_cancel($nid, '', 'phase3-test') !== null, true);
check('notif cancel ok', notif_cancel($nid, 'غلط', 'phase3-test'), null);
check('notif cancel: unread targets removed, read kept', (int)q_val('SELECT COUNT(*) FROM notification_targets WHERE notification_id = ?', [$nid]), 1);
[, $e] = $nv($nd([]), ['status' => 'cancelled']);
check('notif: cancelled cannot be edited', isset($e['_']), true);

// ── 7. Holidays: substitute day, newest decision wins, cancel / reactivate
check('observed: dates required', holiday_set_observed('', '2026-07-24', 'x', '', '') !== null, true);
check('observed: set', holiday_set_observed('2026-07-23', '2026-07-26', 'ثورة يوليو', '', ''), null);
$h = fn(string $d) => q_one('SELECT htype, status, original_date, actual_date FROM holidays WHERE holiday_date = ?', [$d]);
check('observed: actual day active', $h('2026-07-26'), ['htype' => 'OFFICIAL_HOLIDAY', 'status' => 'ACTIVE', 'original_date' => '2026-07-23', 'actual_date' => '2026-07-26']);
check('observed: original superseded', $h('2026-07-23')['status'] . '|' . $h('2026-07-23')['htype'], 'SUPERSEDED|ORIGINAL_DATE_SUPERSEDED');
holiday_set_observed('2026-07-23', '2026-07-27', 'ثورة يوليو', '', '');
check('observed again: older substitute superseded', $h('2026-07-26')['status'] . '|' . $h('2026-07-26')['htype'], 'SUPERSEDED|OBSERVED_DATE_SUPERSEDED');
check('observed again: new substitute active', $h('2026-07-27')['status'], 'ACTIVE');
$cal = holiday_calendar('2026');
check('calendar: one occasion, effective = newest', array_map(fn($r) => [$r['official'], $r['effective'], $r['status'], $r['records']], $cal), [['2026-07-23', '2026-07-27', 'ACTIVE', 3]]);
check('engine sees only the new day', array_column(q_all("SELECT holiday_date FROM holidays WHERE status = 'ACTIVE'"), 'holiday_date'), ['2026-07-27']);
$orig = (int)q_val("SELECT id FROM holidays WHERE holiday_date = '2026-07-23'");
$act = (int)q_val("SELECT id FROM holidays WHERE holiday_date = '2026-07-27'");
check('cancel historical row refused', holiday_set_status($orig, true, 'x') !== null, true);
check('cancel ok', holiday_set_status($act, true, 'قرار جديد'), null);
check('cancel: reason in note', q_one('SELECT status, note FROM holidays WHERE id = ?', [$act]), ['status' => 'CANCELLED', 'note' => 'قرار جديد']);
check('cancel twice refused', holiday_set_status($act, true, 'x') !== null, true);
check('reactivate ok', holiday_set_status($act, false, ''), null);
check('reactivate: default note', q_val('SELECT note FROM holidays WHERE id = ?', [$act]), 'أُعيد تفعيلها يدويًا');

// ── 8. Late helper source: the day's late minutes from att_evaluate
check('late exemption: reason and approver required', (function () { $d = ['reason' => '', 'approver' => '']; $e = []; hook_late_ex_validate($d, $e, null); return array_keys($e); })(), ['reason', 'approver']);
check('late exemption: max a full day', resource('late_exemptions')['fields']['minutes']['max_val'], 1440);

q("DELETE FROM employee_documents WHERE employee_id BETWEEN 9201 AND 9203");
q("DELETE FROM document_types WHERE name LIKE 'T-%'");
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
