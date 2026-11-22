<?php
declare(strict_types=1);
/*
 * Phase 4 tests: fine permissions (a larger permission grants the smaller one), the reports centre,
 * WhatsApp message helpers, backup + add-only restore, data reset, and matching old Drive files.
 * Expected values come from the old GHIS code (AuthService.can, ReportService, WhatsAppService,
 * BackupService, SystemService.resetData, DocumentService._safe).
 *
 * DESTRUCTIVE: wipes the tables it uses. Runs only against a database whose name contains "test".
 *   HR_CONFIG=/path/to/test-config.php php bin/test_phase4.php
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/pages.php';
require APP_ROOT . '/app/pages_hr.php';
require APP_ROOT . '/app/pages_reports.php';
require APP_ROOT . '/app/pages_admin.php';
require APP_ROOT . '/app/pages_salary.php';

if (!str_contains((string)q_val('SELECT DATABASE()'), 'test')) { fwrite(STDERR, "Refusing: not a test database.\n"); exit(1); }
$super = ['id' => null, 'username' => 'phase4-test', 'is_super' => 1, 'is_staff' => 1, 'role_slug' => 'super_admin', 'dept_scope' => null, 'employee_id' => null, 'perms' => []];
$GLOBALS['USER'] = $super;

$fails = 0; $n = 0;
function check(string $what, $got, $want): void
{
    global $fails, $n;
    $n++;
    if ($got !== $want) { $fails++; echo "FAIL  $what: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"; }
    else echo "ok    $what\n";
}
$as = function (array $perms) use ($super) { $GLOBALS['USER'] = ['is_super' => 0, 'role_slug' => 'x', 'is_staff' => 1, 'dept_scope' => null, 'employee_id' => null, 'username' => 'p4', 'id' => null, 'perms' => $perms]; };
$row = fn(array $a) => array_fill_keys(array_map(fn($x) => "can_$x", $a), 1);

// ── 1. Fine permissions: the larger module grants the fine one with the same action, never the other way
$as(['attendance' => $row(['view', 'update'])]);
check('attendance grants attendance_adjust view', can('attendance_adjust'), true);
check('attendance grants permissions update', can('permissions', 'update'), true);
check('attendance does not grant create on adjust', can('attendance_adjust', 'create'), false);
$as(['attendance_adjust' => $row(['view'])]);
check('fine permission alone', can('attendance_adjust'), true);
check('fine does not grant the larger', can('attendance'), false);
$as(['documents' => $row(['view', 'export'])]);
check('documents grants docs_reports export', can('docs_reports', 'export'), true);
check('documents does not grant whatsapp', can('whatsapp'), false);
$as(['salary' => $row(['view'])]);
check('salary grants payroll + period close view', [can('payroll'), can('period_close')], [true, true]);
check('salary does not grant period reopen', can('period_reopen', 'update'), false);
$as(['reports' => $row(['view'])]);
check('reports: attendance reports only', array_keys(reports_catalog()), ['attendanceSingle', 'attendanceAll', 'attendanceGrid']);
check('reports: no export without export right', report_allowed('attendanceAll', 'export'), false);
$as([]);
check('no rights: empty catalog', reports_catalog(), []);
$GLOBALS['USER'] = $super;
check('every module in the matrix has a label', count(array_diff(array_keys(MODULE_PARENT), array_keys(MODULES))), 0);
check('15 reports (14 old + grid)', count(REPORTS), 15);

// ── 2. Reports centre
foreach (['attendance', 'salary_tx', 'advances', 'summer_deduction_log', 'backups_log'] as $t) q("DELETE FROM `$t`");
q('DELETE FROM employees');
q("DELETE FROM departments WHERE name LIKE 'T4-%'");
q("INSERT INTO departments (name) VALUES ('T4-A'), ('T4-B')");
$da = (int)q_val("SELECT id FROM departments WHERE name = 'T4-A'"); $db = (int)q_val("SELECT id FROM departments WHERE name = 'T4-B'");
q("INSERT INTO employees (id, name_ar, department_id, hire_date, end_date, phone) VALUES (9401, 'T1', ?, '2020-01-01', NULL, '01012345678'), (9402, 'T2', ?, '2026-03-10', NULL, ''), (9403, 'T3', ?, '2019-01-01', '2026-03-15', '')", [$da, $db, $db]);
$f = report_filters(['from' => '2026-03-01', 'to' => '2026-03-31']);
check('filters: defaults and arrays', [$f['from'], $f['dept'], report_filters(['dept' => ['3', 'x', '5']])['dept']], ['2026-03-01', [], [3, 5]]);
check('bad period refused', report_build('newHires', report_filters(['from' => '2026-04-01', 'to' => '2026-03-01']))['error'] !== null, true);
check('new hires in March', array_column(report_build('newHires', $f)['rows'], 0), [9402]);
check('end of service in March', array_column(report_build('endOfService', $f)['rows'], 0), [9403]);
check('multi department filter', array_column(report_build('newHires', ['dept' => [$da]] + $f)['rows'], 0), []);
check('employees: active only by default', array_column(report_build('employees', report_filters([]))['rows'], 0), [9401, 9402]);
check('employees: with former', count(report_build('employees', report_filters(['ended' => '1']))['rows']), 3);
$g = report_build('attendanceGrid', $f);
check('grid: 3 + 31 days + breakdown columns', count($g['headers']), 34 + count(ATT_BREAKDOWN));
check('grid: weekend cell text', $g['rows'][0][3 + 5], __('عطلة أسبوعية')); // 2026-03-06 is a Friday
check('grid: more than 62 days refused', report_build('attendanceGrid', report_filters(['from' => '2026-01-01', 'to' => '2026-03-31']))['error'] !== null, true);
check('single attendance needs an employee', report_build('attendanceSingle', $f)['error'] !== null, true);
check('single attendance days', count(report_build('attendanceSingle', ['emp' => 9401] + $f)['rows']), 31);
check('paper by width', [report_paper(6), report_paper(12), report_paper(35)], ['a4p', 'a4l', 'a3l']);
check('cell display', [report_cell(1500.5), report_cell(3), report_cell('x')], ['1,500.50', '3', 'x']);

// ── 3. WhatsApp helpers (old normalizeEgyptianPhoneForWhatsApp_ and texts)
check('phone 01x', wa_phone('010 1234 5678'), '201012345678');
check('phone +20', wa_phone('+201012345678'), '201012345678');
check('phone 0020', wa_phone('00201012345678'), '201012345678');
check('phone without zero', wa_phone('1012345678'), '201012345678');
check('phone invalid', [wa_phone('12345'), wa_phone('')], [null, null]);
check('minutes text', [wa_minutes(45), wa_minutes(60), wa_minutes(135)], ['45 دقيقة', '1 ساعة', '2 ساعة و15 دقيقة']);
check('days text', [wa_days_fraction(0.25), wa_days_fraction(1.5), wa_days_fraction(2), wa_days_fraction(0.75)], ['ربع يوم', '1 يوم ونص يوم', '2 يوم', 'ثلاثة أرباع يوم']);
[$text] = wa_message('attendanceBrief', ['id' => 9401, 'name_ar' => 'T1'], '2026-03-01', '2026-03-31', '2026-03');
check('brief message names the period', str_contains($text, 'من يوم 2026-03-01'), true);
[$text, $err] = wa_message('payroll', ['id' => 9401, 'name_ar' => 'T1'], '', '', '2026-03');
check('payroll message without a basic salary = error', [$text, $err !== null], [null, true]);

// ── 4. Backup: full file, restore adds only the missing rows
$bid = backup_create('phase4', 'phase4-test');
$b = backup_file($bid);
check('backup file registered', $b !== null && backup_header($b['path'])['magic'] === BACKUP_MAGIC, true);
q("DELETE FROM employees WHERE id = 9402");
q("UPDATE employees SET name_ar = 'changed' WHERE id = 9401");
[$err, $added, $seen] = backup_restore($b['path'], 'employees');
check('restore: one missing row added', [$err, $added, $seen], [null, 1, 3]);
check('restore: existing rows not overwritten', q_val('SELECT name_ar FROM employees WHERE id = 9401'), 'changed');
check('restore again adds nothing', backup_restore($b['path'], 'employees')[1], 0);
check('restore: unknown table refused', backup_restore($b['path'], 'nope')[0] !== null, true);
q("UPDATE employees SET name_ar = 'T1' WHERE id = 9401");

// ── 5. Data reset: whitelist, backup first
check('reset: nothing chosen', data_reset([], 'x')[0] !== null, true);
check('reset: unknown group refused', data_reset(['users'], 'x')[0] !== null, true);
q("INSERT INTO advances (employee_id, advance_date, total, installments, deduct_from, installment_value, status) VALUES (9401, '2026-03-01', 300, 3, '2026-03-01', 100, 'active')");
$before = (int)q_val('SELECT COUNT(*) FROM backups_log');
[$err, $rows, $rb] = data_reset(['advances'], 'phase4-test');
check('reset: rows cleared', [$err, $rows, (int)q_val('SELECT COUNT(*) FROM advances')], [null, 1, 0]);
check('reset: a backup was made first', (int)q_val('SELECT COUNT(*) FROM backups_log') === $before + 1 && backup_file($rb) !== null, true);
check('reset: employees untouched', (int)q_val('SELECT COUNT(*) FROM employees'), 3);
check('reset never lists security tables', array_intersect(array_merge(...array_column(RESET_GROUPS, 1)), ['users', 'roles', 'role_permissions', 'settings', 'audit_log', 'attendance', 'face_profiles']), []);

// ── 6. Old Drive files: matched by the old name pattern / stored name
check('drive safe name', [drive_safe('a/b:c'), drive_safe(str_repeat('ب', 50)) === str_repeat('ب', 40), drive_safe(' ')], ['a_b_c', true, 'x']);
q('DELETE FROM employee_documents'); q('DELETE FROM investigation_attachments'); q('DELETE FROM investigation_employees'); q('DELETE FROM investigations');
q("DELETE FROM document_types WHERE name LIKE 'T4-%'");
q("INSERT INTO document_types (name, active) VALUES ('T4-بطاقة', 1)");
$tp = (int)q_val("SELECT id FROM document_types WHERE name = 'T4-بطاقة'");
q("INSERT INTO employee_documents (employee_id, type_id, file_ref, created_at) VALUES (9401, ?, 'drive:AAA', '2025-05-04 10:00:00'), (9402, ?, 'drive:BBB', '2025-05-04 10:00:00'), (9403, ?, 'drive:CCC', '2025-06-01 10:00:00')", [$tp, $tp, $tp]);
q("INSERT INTO investigations (inv_no, inv_date, subject) VALUES ('INV-000001', '2025-01-01', 's')");
$iid = (int)db()->lastInsertId();
q("INSERT INTO investigation_attachments (investigation_id, file_ref, file_name) VALUES (?, 'DRIVEID', 'INV-000001_محضر_2025-01-02.pdf')", [$iid]);
$dir = sys_get_temp_dir() . '/p4drive-' . bin2hex(random_bytes(3));
mkdir("$dir/sub", 0700, true);
$pdf = "%PDF-1.4\n%%EOF\n";
file_put_contents("$dir/9401_T1_T4-بطاقة_2025-05-04.pdf", $pdf);
file_put_contents("$dir/9402_T2_T4-بطاقة_2025-05-04.pdf", $pdf);
file_put_contents("$dir/sub/9402_T2_T4-بطاقة_2025-05-04.pdf", $pdf);
file_put_contents("$dir/sub/INV-000001_محضر_2025-01-02.pdf", $pdf);
file_put_contents("$dir/9403_T3_T4-بطاقة_2024-01-01.pdf", $pdf);
$plan = drive_plan($dir);
check('drive plan: matched', [count($plan['docs']), count($plan['atts'])], [1, 1]);
check('drive plan: two files for one record = ambiguous, wrong date = missing', [$plan['ambiguous'], $plan['missing'], $plan['pending']], [1, 1, 4]);
[$copied, $refused] = drive_apply($plan);
check('drive apply copies and links', [$copied, $refused], [2, 0]);
$ref = (string)q_val('SELECT file_ref FROM employee_documents WHERE employee_id = 9401');
check('document now points at a local file', is_file(doc_dir() . '/' . $ref), true);
check('nothing left pending for the linked ones', drive_plan($dir)['pending'], 2);
array_map('unlink', array_merge(glob("$dir/sub/*"), glob("$dir/*.pdf"))); rmdir("$dir/sub"); rmdir($dir);
@unlink(doc_dir() . '/' . $ref);

// ── Gaps found in the screen-by-screen review ──
check('employee status: ended yesterday = ended, no end date = active', [employee_status(['end_date' => date('Y-m-d', strtotime('-1 day'))]), employee_status(['end_date' => null])], ['منتهي الخدمة', 'نشط']);
q("INSERT INTO departments (name, active, attendance_exempt) VALUES ('T-EX', 1, 1)"); $exd = (int)db()->lastInsertId();
check('final exemption: by department or manual', [employee_exempt_final(['attendance_exempt' => 0, 'department_id' => $exd]), employee_exempt_final(['attendance_exempt' => 1, 'department_id' => 0])], [true, true]);
q('DELETE FROM departments WHERE id = ?', [$exd]);
$_GET = ['dept' => ['3', 'x', '-1', '3', '7']];
check('multi filter keeps positive unique ids', crud_multi_ids('dept'), [3, 7]);
[$w] = crud_where(resource('employees'), false);
check('employees list filters by several departments', str_contains($w, 't.`department_id` IN (?,?)'), true);
$_GET = [];
check('payroll notes show errors, summer rule and a due raise', payroll_notes(['errors' => ['x'], 'quarter' => 10, 'summer_rule' => 'ربع', 'raise_due' => true]), ['x', 'خصم الصيف: ربع', 'علاوة مستحقة']);
check('breakdown: 16 columns, total last', [count(att_breakdown_headers()), ATT_BREAKDOWN[count(ATT_BREAKDOWN) - 1][1]], [16, 'total_days']);
check('breakdown values keep the totals', att_breakdown_values(['late_min' => 30, 'late_waiver_min' => 10, 'late_min_final' => 20, 'total_days' => 1.25])[0] + att_breakdown_values(['late_min_final' => 20])[2], 50);
check('payroll table row matches its headers', count(payroll_table_row(SNAPSHOT_DEFAULTS + ['emp' => ['id' => 1, 'name_ar' => 'x', 'dept_name' => 'd'], 'net_final' => 0.0])), count(payroll_table_headers()));

// ── Schedule rules like the old «القواعد» screen ──
q("DELETE FROM schedule_rules WHERE date_from >= '2090-01-01'");
[$e1, $c1] = sched_rule_save(null, '2090-01-01', '2090-06-30', 'xx', '', '');
$r1 = q_one('SELECT * FROM schedule_rules WHERE code = ?', [$c1]);
check('sched add: SR code, bad/missing times fall back to 08:00/15:00', [$e1, (bool)preg_match('/^SR-\d{4}$/', $c1), substr($r1['start_time'], 0, 5), substr($r1['end_time'], 0, 5)], [null, true, '08:00', '15:00']);
check('sched add: overlapping period refused', sched_rule_save(null, '2090-06-01', '2090-12-31', '08:00', '15:00', '')[0] !== null, true);
check('sched add: from after to refused', sched_rule_save(null, '2091-02-01', '2091-01-01', '08:00', '15:00', '')[0], 'تاريخ البداية لازم يكون قبل تاريخ النهاية.');
[$e2] = sched_rule_save((int)$r1['id'], '2090-01-01', '2090-05-31', '07:30', '14:00', '');
$arch = q_all('SELECT * FROM schedule_rules_archive WHERE rule_code = ?', [$c1]);
check('sched edit: reason optional, old version archived first', [$e2, count($arch), $arch[0]['operation'] ?? '', $arch[0]['date_to'] ?? '', substr((string)($arch[0]['start_time'] ?? ''), 0, 5)], [null, 1, 'تعديل', '2090-06-30', '08:00']);
check('sched edit: new values saved', substr((string)q_val('SELECT start_time FROM schedule_rules WHERE id = ?', [$r1['id']]), 0, 5), '07:30');
check('sched edit: overlapping another rule refused', (function () use ($r1) { sched_rule_save(null, '2090-07-01', '2090-12-31', '08:00', '15:00', ''); return sched_rule_save((int)$r1['id'], '2090-01-01', '2090-08-31', '08:00', '15:00', '')[0]; })(), 'النطاق بيتداخل مع قاعدة تانية.');
check('sched rules: no delete or edit through the generic list', !empty(resource('schedule_rules')['readonly']), true);
q("DELETE FROM schedule_rules_archive WHERE rule_code IN (SELECT code FROM schedule_rules WHERE date_from >= '2090-01-01')"); q("DELETE FROM schedule_rules WHERE date_from >= '2090-01-01'");

// ── Settings like the old «الإعدادات» screen ──
if (!function_exists('settings_year')) require_once APP_ROOT . '/app/pages.php';
$post = function (array $p, callable $fn) { $_POST = $p; $r = $fn(); $_POST = []; return $r; };
q("DELETE FROM academic_years WHERE name LIKE 'T4Y-%'");
$post(['name' => 'T4Y-1', 'date_from' => '2090-09-01', 'date_to' => '2091-06-30'], fn() => settings_year('year_add'));
$post(['name' => 'T4Y-2', 'date_from' => '2091-09-01', 'date_to' => '2092-06-30'], fn() => settings_year('year_add'));
$y1 = (int)q_val("SELECT id FROM academic_years WHERE name = 'T4Y-1'"); $y2 = (int)q_val("SELECT id FROM academic_years WHERE name = 'T4Y-2'");
check('year add: new years start inactive', (int)q_val("SELECT SUM(active) FROM academic_years WHERE name LIKE 'T4Y-%'"), 0);
check('year add: duplicate name refused', $post(['name' => 'T4Y-1', 'date_from' => '2093-09-01', 'date_to' => '2094-06-30'], fn() => settings_year('year_add')), 'السنة موجودة بالفعل.');
check('year add: from after to refused', $post(['name' => 'T4Y-3', 'date_from' => '2094-09-01', 'date_to' => '2094-01-01'], fn() => settings_year('year_add')), 'من تاريخ / لحد تاريخ غير صالحين.');
$post(['id' => (string)$y1], fn() => settings_year('year_activate'));
$post(['id' => (string)$y2], fn() => settings_year('year_activate'));
check('year activate: only one active year', [(int)q_val('SELECT SUM(active) FROM academic_years'), (int)q_val('SELECT active FROM academic_years WHERE id = ?', [$y2])], [1, 1]);
q('UPDATE academic_years SET active = 1 WHERE id = ?', [$y1]); hook_academic_years_after($y1, ['active' => 1], null, []);
check('year via generic form: saving active deactivates the others', [(int)q_val('SELECT SUM(active) FROM academic_years'), (int)q_val('SELECT active FROM academic_years WHERE id = ?', [$y1])], [1, 1]);
check('year update: rename + dates', [$post(['id' => (string)$y2, 'name' => 'T4Y-2b', 'date_from' => '2091-09-01', 'date_to' => '2092-07-31'], fn() => settings_year('year_update')), q_val('SELECT date_to FROM academic_years WHERE id = ?', [$y2])], [null, '2092-07-31']);
q("DELETE FROM academic_years WHERE name LIKE 'T4Y-%'");

q("DELETE FROM departments WHERE name LIKE 'T4D-%'");
check('name list add', $post(['kind' => 'dept', 'name' => '  T4D-a   b '], fn() => settings_name_list('name_add')), null);
$d = q_one("SELECT * FROM departments WHERE name = 'T4D-a b'");
check('name list add: spaces normalised, active', [(bool)$d, (int)($d['active'] ?? 0)], [true, 1]);
check('name list add: duplicate refused', $post(['kind' => 'dept', 'name' => 'T4D-a b'], fn() => settings_name_list('name_add')), 'القسم «T4D-a b» موجود بالفعل.');
$post(['kind' => 'dept', 'id' => (string)$d['id'], 'name' => 'T4D-c'], fn() => settings_name_list('name_rename'));
$post(['kind' => 'dept', 'id' => (string)$d['id']], fn() => settings_name_list('name_toggle'));
check('name list rename + deactivate (no delete)', q_one('SELECT name, active FROM departments WHERE id = ?', [$d['id']]), ['name' => 'T4D-c', 'active' => 0]);

$post(['id' => (string)$d['id']], fn() => settings_exempt('exempt_dept_add'));
check('exempt department add', (int)q_val('SELECT attendance_exempt FROM departments WHERE id = ?', [$d['id']]), 1);
$post(['id' => (string)$d['id']], fn() => settings_exempt('exempt_dept_remove'));
check('exempt department remove', (int)q_val('SELECT attendance_exempt FROM departments WHERE id = ?', [$d['id']]), 0);
q("DELETE FROM departments WHERE name LIKE 'T4D-%'");
$eid = (int)q_val('SELECT id FROM employees WHERE attendance_exempt = 0 ORDER BY id LIMIT 1');
if ($eid) {
    $before = (int)q_val('SELECT COUNT(*) FROM employee_change_log WHERE employee_id = ?', [$eid]);
    $post(['id' => (string)$eid], fn() => settings_exempt('exempt_emp_add'));
    $log = q_one("SELECT * FROM employee_change_log WHERE employee_id = ? ORDER BY id DESC LIMIT 1", [$eid]);
    check('exempt employee add: flag + change log with reason', [(int)q_val('SELECT attendance_exempt FROM employees WHERE id = ?', [$eid]), $log['field'], $log['new_value'], $log['reason']], [1, 'attendance_exempt', '1', 'إعفاء يدوي من البصمة']);
    $post(['id' => (string)$eid], fn() => settings_exempt('exempt_emp_remove'));
    check('exempt employee remove: logged too', [(int)q_val('SELECT attendance_exempt FROM employees WHERE id = ?', [$eid]), (int)q_val('SELECT COUNT(*) FROM employee_change_log WHERE employee_id = ?', [$eid]) - $before], [0, 2]);
    q("DELETE FROM employee_change_log WHERE employee_id = ? AND field = 'attendance_exempt'", [$eid]);
}

$oldAuto = q_val("SELECT v FROM settings WHERE k = 'auto_employee_code'");
$post(['auto_code' => '0'], fn() => settings_save_autocode());
check('auto code: yes/no saved as 0/1', q_val("SELECT v FROM settings WHERE k = 'auto_employee_code'"), '0');
q('UPDATE settings SET v = ? WHERE k = ?', [$oldAuto, 'auto_employee_code']);

$keep = q_all("SELECT k, v FROM settings WHERE k IN ('weekend_days','geofence_enforce','day_divisor')");
$base = ['s' => ['day_divisor' => '30'], 'weekend' => ['5', '6']];
check('general: weekdays boxes saved as ISO list, missing bool = 0', [$post($base, fn() => settings_save_general()), q_val("SELECT v FROM settings WHERE k = 'weekend_days'"), q_val("SELECT v FROM settings WHERE k = 'geofence_enforce'")], [null, '5,6', '0']);
check('general: bad number refused', $post(['s' => ['day_divisor' => 'abc'], 'weekend' => ['5']], fn() => settings_save_general()) !== null, true);
check('general: refused save changes nothing', q_val("SELECT v FROM settings WHERE k = 'weekend_days'"), '5,6');
foreach ($keep as $k) q('UPDATE settings SET v = ? WHERE k = ?', [$k['v'], $k['k']]);
$chk = array_column(system_checks(), 'ok', 'name');
check('environment check: database and tables pass', [$chk['قاعدة البيانات'] ?? null, $chk['جداول النظام'] ?? null], [true, true]);

// ── The rest of the old «القواعد» screen ──
if (!function_exists('deduction_rule_add')) require_once APP_ROOT . '/app/pages_hr.php';
q("DELETE FROM deduction_rules WHERE date_from >= '2095-01-01'");
check('deduction add: empty fields take the old defaults', deduction_rule_add(['date_from' => '2095-01-01', 'date_to' => '2095-06-30', 'perm1_days' => '', 'late_unit_min' => '']), null);
$dr = q_one("SELECT * FROM deduction_rules WHERE date_from = '2095-01-01'");
check('deduction add: stored defaults (25 min = 0.25, ladder 0/0.25/0.5/0.75/1, early leave off)',
    [(int)$dr['late_unit_min'], (float)$dr['late_unit_days'], (float)$dr['absence_days'], (float)$dr['perm2_days'], (float)$dr['perm5_days'], (int)$dr['early_leave_enabled']], [25, 0.25, 1.0, 0.25, 1.0, 0]);
check('deduction add: values given are kept', [deduction_rule_add(['date_from' => '2095-07-01', 'date_to' => '2095-12-31', 'early_leave_enabled' => '1', 'early_unit_min' => '15', 'perm2_days' => '0']),
    q_val("SELECT CONCAT(early_leave_enabled, '/', early_unit_min, '/', perm2_days) FROM deduction_rules WHERE date_from = '2095-07-01'")], [null, '1/15/0.00']);
check('deduction add: overlap refused', deduction_rule_add(['date_from' => '2095-06-01', 'date_to' => '2095-07-15']), 'الفترة الجديدة (2095-06-01 إلى 2095-07-15) بتتداخل مع قاعدة موجودة بالفعل (2095-01-01 إلى 2095-06-30).');
check('deduction add: from after to refused', deduction_rule_add(['date_from' => '2096-02-01', 'date_to' => '2096-01-01']), 'تاريخ البداية لازم يكون قبل تاريخ النهاية.');
check('deduction add: bad number refused', deduction_rule_add(['date_from' => '2096-01-01', 'date_to' => '2096-02-01', 'absence_days' => 'x']) !== null, true);
q("DELETE FROM deduction_rules WHERE date_from >= '2095-01-01'");

q("DELETE FROM holidays WHERE holiday_date BETWEEN '2095-01-01' AND '2095-12-31' OR original_date BETWEEN '2095-01-01' AND '2095-12-31'");
$wd = fn(int $iso) => (function ($iso) { for ($d = '2095-03-01'; ; $d = date('Y-m-d', strtotime("$d +1 day"))) if ((int)date('N', strtotime($d)) === $iso) return $d; })($iso);
$mon = $wd(1); $fri = $wd(5); $tue = date('Y-m-d', strtotime("$mon +1 day")); $wed = date('Y-m-d', strtotime("$mon +2 day")); $thu = date('Y-m-d', strtotime("$mon +3 day"));
check('manual holiday add', manual_holiday_add($mon, ''), null);
$mh = q_one('SELECT * FROM holidays WHERE holiday_date = ?', [$mon]);
check('manual holiday add: default name, source «إدخال يدوي», seen as manual', [$mh['title'], $mh['source'], holiday_is_manual($mh)], ['إجازة رسمية', 'إدخال يدوي', true]);
check('manual holiday add: same date refused', manual_holiday_add($mon, 'x'), 'التاريخ ده مسجّل كإجازة رسمية بالفعل.');
q("INSERT INTO holidays (holiday_date, title, htype, source, status) VALUES (?, 'T4H-gov', 'OFFICIAL_HOLIDAY', 'رئاسة الجمهورية', 'ACTIVE'), (?, 'T4H-fri', 'OFFICIAL_HOLIDAY', 'رئاسة الجمهورية', 'ACTIVE'), (?, 'T4H-gov2', 'OFFICIAL_HOLIDAY', 'رئاسة الجمهورية', 'ACTIVE')", [$mon, $fri, $thu]);
$eff = holidays_effective('2095-03-01', '2095-03-31');
check('effective holidays: hand-typed wins the same date; a state holiday on the weekend stays a weekend', [$eff[$mon] ?? null, isset($eff[$fri]), $eff[$thu] ?? null], ['إجازة رسمية', false, 'T4H-gov2']);
manual_holiday_add($tue, 'T4H-manual-tue');
check('observed day: a hand-typed holiday on that date is not overwritten', [holiday_set_observed($wed, $thu, 'T4H-moved', '', ''), q_val('SELECT source FROM holidays WHERE holiday_date = ? AND title = ?', [$tue, 'T4H-manual-tue'])], [null, 'إدخال يدوي']);
holiday_set_observed($tue, $thu, 'T4H-moved2', '', '');
$eff = holidays_effective('2095-03-01', '2095-03-31');
check('effective holidays: a hand-typed date the occasion moved away from is not a holiday', [isset($eff[$tue]), $eff[$thu] ?? null], [false, 'T4H-moved2']);
$cal = array_column(holiday_calendar('2095'), null, 'title');
check('calendar: hand-typed rows are their own, suppressed when the occasion moved', [$cal['T4H-manual-tue']['origin'] ?? null, $cal['T4H-manual-tue']['status'] ?? null, $cal['إجازة رسمية']['status'] ?? null, $cal['T4H-gov']['origin'] ?? null],
    ['manual', 'SUPPRESSED', 'ACTIVE', 'synced']);
q("DELETE FROM holidays WHERE holiday_date BETWEEN '2095-01-01' AND '2095-12-31' OR original_date BETWEEN '2095-01-01' AND '2095-12-31'");

if ($eid) {
    q("DELETE FROM employee_schedules WHERE employee_id = ? AND date_from >= '2095-01-01'", [$eid]);
    schedule_override_add($eid, '2095-01-01', '2095-01-31', '09:00', '14:00');
    schedule_override_add($eid, '2095-02-10', '2095-02-12', '09:00', '14:00');
    [$err, $segN] = schedule_override_add($eid, '2095-01-20', '2095-02-20', '10:00', '13:00');
    check('personal schedule add: overlapping periods trimmed / removed, counts reported', [$err, $segN['trimmed'], $segN['removed'],
        q_val("SELECT GROUP_CONCAT(CONCAT(date_from, '>', date_to) ORDER BY date_from) FROM employee_schedules WHERE employee_id = ? AND date_from >= '2095-01-01'", [$eid])],
        [null, 1, 1, '2095-01-01>2095-01-19,2095-01-20>2095-02-20']);
    check('personal schedule add: bad times refused', schedule_override_add($eid, '2095-03-01', '2095-03-02', '', '14:00')[0], 'لازم تحدد موعد الحضور والانصراف.');
    q("DELETE FROM employee_schedules WHERE employee_id = ? AND date_from >= '2095-01-01'", [$eid]);
}

// ── Payroll rates per department (old payroll settings): validated in full before any write, only changed columns saved
q("DELETE FROM departments WHERE name LIKE 'T4R-%'");
q("INSERT INTO departments (name, allowance_rate, quarter_rate) VALUES ('T4R-a', 10, 25), ('T4R-b', NULL, NULL)");
$ra = (int)q_val("SELECT id FROM departments WHERE name = 'T4R-a'"); $rb = (int)q_val("SELECT id FROM departments WHERE name = 'T4R-b'");
check('rates: a bad value saves nothing', [payroll_rates_save([$ra => '15', $rb => '101'], [])[1] !== null, (float)q_val('SELECT allowance_rate FROM departments WHERE id = ?', [$ra])], [true, 10.0]);
check('rates: non-number refused', payroll_rates_save([$ra => 'x'], [])[1] !== null, true);
check('rates: only changed values counted (10 → 10 unchanged, 25 → empty = cleared, b set)', payroll_rates_save([$ra => '10', $rb => '12.5'], [$ra => '', $rb => '30'])[0], 3);
check('rates: stored', [q_val('SELECT quarter_rate FROM departments WHERE id = ?', [$ra]), (float)q_val('SELECT allowance_rate FROM departments WHERE id = ?', [$rb]), (float)q_val('SELECT quarter_rate FROM departments WHERE id = ?', [$rb])], [null, 12.5, 30.0]);
check('rates: same again changes nothing', payroll_rates_save([$ra => '10', $rb => '12.50'], [$ra => '', $rb => '30']), [0, null]);
q("DELETE FROM departments WHERE name LIKE 'T4R-%'");

// ── Save-and-return: a CRUD form posted from a combined screen goes back there, only to a plain page name
$_POST = ['_back' => 'att_adjust#perms', '_back_from' => '2095-01-01', '_back_to' => 'bad'];
$bu = crud_back_url();
check('back url: page + anchor + valid dates only', [str_contains($bu, 'att_adjust'), str_ends_with($bu, '#perms'), str_contains($bu, '2095-01-01'), str_contains($bu, 'bad')], [true, true, true, false]);
foreach (['https://evil.example', '//x', 'att_adjust?x=1', 'a', 'list#x y', ''] as $bad) { $_POST = ['_back' => $bad]; check('back url refused: ' . var_export($bad, true), crud_back_url(), null); }
$_POST = [];

// ── Notifications for several departments / employees (old scope قسم / موظف, now more than one)
$GLOBALS['USER'] = $super;
q("DELETE FROM departments WHERE name LIKE 'T4N-%'");
q("INSERT INTO departments (name) VALUES ('T4N-a'), ('T4N-b'), ('T4N-c')");
$nd = array_map('intval', array_column(q_all("SELECT id FROM departments WHERE name LIKE 'T4N-%' ORDER BY name"), 'id'));
q("INSERT INTO employees (id, name_ar, department_id) VALUES (99101, 'T4N-1', ?), (99102, 'T4N-2', ?), (99103, 'T4N-3', ?), (99104, 'T4N-4', ?)", [$nd[0], $nd[1], $nd[2], $nd[0]]);
$_POST = ['scope_dept' => [(string)$nd[1], (string)$nd[0], (string)$nd[0], 'x']];
[$nData, $nErr] = crud_validate(['fields' => ['scope_dept' => resource('notifications')['fields']['scope_dept']]], true);
check('multi pick: ids cleaned, sorted, unique', [$nData['scope_dept'], $nErr], [min($nd[0], $nd[1]) . ',' . max($nd[0], $nd[1]), []]);
$_POST = [];
q("INSERT INTO notifications (ntype, title, show_at, duration_min, scope, scope_value, status, created_by) VALUES ('عام', 'T4N', NOW(), 60, 'department', ?, 'scheduled', 't')", [$nd[0] . ',' . $nd[1]]);
$nid = (int)db()->lastInsertId();
hook_notifications_after($nid, [], null, []);
check('several departments reach all their employees', array_map('intval', array_column(q_all('SELECT employee_id FROM notification_targets WHERE notification_id = ? ORDER BY employee_id', [$nid]), 'employee_id')), [99101, 99102, 99104]);
q("UPDATE notifications SET scope = 'employee', scope_value = '99103,99102' WHERE id = ?", [$nid]);
hook_notifications_after($nid, [], null, []);
check('several employees', array_map('intval', array_column(q_all('SELECT employee_id FROM notification_targets WHERE notification_id = ? ORDER BY employee_id', [$nid]), 'employee_id')), [99102, 99103]);
check('scope shown by names', display_value(['type' => 'scope'], '99102,99103', ['scope' => 'employee']), '99102 - T4N-2، 99103 - T4N-3');
check('one department still works', (function () use ($nid, $nd) { q("UPDATE notifications SET scope = 'department', scope_value = ? WHERE id = ?", [(string)$nd[2], $nid]); hook_notifications_after($nid, [], null, []);
    return array_map('intval', array_column(q_all('SELECT employee_id FROM notification_targets WHERE notification_id = ?', [$nid]), 'employee_id')); })(), [99103]);
q('DELETE FROM notification_targets WHERE notification_id = ?', [$nid]); q('DELETE FROM notifications WHERE id = ?', [$nid]);
q('DELETE FROM employees WHERE id BETWEEN 99101 AND 99104'); q("DELETE FROM departments WHERE name LIKE 'T4N-%'");

// ── Old employee app parity
// Notification content items: several options kept in their own order; report items need the period
$cf = resource('notifications')['fields']['contents'];
$_POST = ['contents' => ['late', 'message', 'absence', 'late']];
[$cd, $ce] = crud_validate(['fields' => ['contents' => $cf]], true);
check('content items: known keys in option order', [$cd['contents'], $ce], ['message,absence,late', []]);
$_POST = ['contents' => ['message', 'evil']];
[$cd, $ce] = crud_validate(['fields' => ['contents' => $cf]], true);
check('content items: unknown key refused', isset($ce['contents']), true);
check('content items shown by label', display_value($cf, 'message,deductions'), 'نص الرسالة، الخصومات');
$_POST = [];
$nv = ['ntype' => 'عام', 'contents' => 'message,late', 'period_month' => null, 'report_from' => null, 'report_to' => null, 'scope' => 'all'];
$ne = []; hook_notifications_validate($nv, $ne, null);
check('late item needs the report period', [isset($ne['report_from']), isset($ne['report_to'])], [true, true]);
$nv = ['ntype' => 'عام', 'contents' => 'message,profile', 'period_month' => null, 'report_from' => '2095-01-01', 'report_to' => '2095-01-31', 'scope' => 'all'];
$ne = []; hook_notifications_validate($nv, $ne, null);
check('message + profile: no period kept', [$ne, $nv['report_from']], [[], null]);
$nv = ['ntype' => 'عام', 'contents' => 'deductions', 'period_month' => null, 'report_from' => '2095-01-01', 'report_to' => '2095-01-31', 'scope' => 'all'];
$ne = []; hook_notifications_validate($nv, $ne, null);
check('deductions keeps its period', [$ne, $nv['report_from'], $nv['report_to']], [[], '2095-01-01', '2095-01-31']);

// Relative time (old formatRelative)
$now = strtotime('2095-03-10 12:00:00');
check('relative time', array_map(fn($d) => rel_time($d, $now), ['2095-03-10 11:59:30', '2095-03-10 11:15:00', '2095-03-10 10:00:00', '2095-03-10 07:00:00', '2095-03-09 11:00:00', '2095-03-08 10:00:00', '2095-03-06 12:00:00', '2095-02-01 08:00:00']),
    ['الآن', 'منذ 45 دقيقة', 'منذ ساعتين', 'منذ 5 ساعات', 'منذ يوم', 'منذ يومين', 'منذ 4 أيام', '2095-02-01']);

// My documents: missing required types next to the ones on file (self service, no department scope)
q("INSERT INTO document_types (name, active, required, has_number, has_expiry) VALUES ('T4S-req1', 1, 1, 0, 0), ('T4S-req2', 1, 1, 0, 0), ('T4S-opt', 1, 0, 0, 0), ('T4S-off', 0, 1, 0, 0)");
q("INSERT INTO employees (id, name_ar) VALUES (99201, 'T4S-1')");
$tr1 = (int)q_val("SELECT id FROM document_types WHERE name = 'T4S-req1'");
q("INSERT INTO employee_documents (employee_id, type_id, is_original) VALUES (99201, ?, 'original')", [$tr1]);
$mine = array_column(array_map(fn($r) => [$r['type']['name'], $r['status']], array_filter(doc_status_self(99201), fn($r) => str_starts_with($r['type']['name'], 'T4S-'))), 1, 0);
check('my documents: on file / missing / not required, stopped types left out', $mine, ['T4S-opt' => 'غير مطلوب', 'T4S-req1' => 'ساري', 'T4S-req2' => 'ناقص']);

// Unread count next to «الإشعارات»
q("INSERT INTO notifications (ntype, title, show_at, duration_min, scope, status, created_by) VALUES ('عام', 'T4U', NOW() - INTERVAL 1 MINUTE, 60, 'employee', 'scheduled', 't')");
$un = (int)db()->lastInsertId();
q('INSERT INTO notification_targets (notification_id, employee_id, show_at, hide_at) VALUES (?, 99201, NOW() - INTERVAL 1 MINUTE, NOW() + INTERVAL 1 HOUR)', [$un]);
check('unread count', inbox_unread(99201), 1);
q('DELETE FROM notification_targets WHERE notification_id = ?', [$un]); q('DELETE FROM notifications WHERE id = ?', [$un]);

// Location gate: nearest point with the same rule as check-in; only for employee accounts; one check lasts 10 minutes
q("DELETE FROM attendance_points WHERE name LIKE 'T4G-%'");
q("INSERT INTO attendance_points (name, lat, lng, radius_m, active) VALUES ('T4G-school', 30.0, 31.0, 100, 1)");
check('inside the point', geo_nearest(30.0005, 31.0, 20)[1], true);
check('outside the point (GPS error counts up to 30 m)', [geo_nearest(30.0012, 31.0, 20)[1], geo_nearest(30.0012, 31.0, 500)[1]], [false, false]);
$GLOBALS['USER'] = ['id' => 1, 'username' => 'e', 'is_super' => 0, 'is_staff' => 0, 'role_slug' => 'employee', 'employee_id' => 99201, 'perms' => [], 'dept_scope' => null];
$_SESSION = [];
q("UPDATE settings SET v = '1' WHERE k = 'self_geo_gate'"); setting('self_geo_gate', null, true); // the settings tests above saved it off
check('employee outside: gated', geo_gate_ok(), false);
$_SESSION['geo_ok_until'] = time() + 60;
check('employee after a good check: open', geo_gate_ok(), true);
$_SESSION['geo_ok_until'] = time() - 1;
check('check expired: gated again', geo_gate_ok(), false);
q("UPDATE settings SET v = '0' WHERE k = 'self_geo_gate'"); setting('self_geo_gate', null, true);
check('gate switched off in settings', geo_gate_ok(), true);
q("UPDATE settings SET v = '1' WHERE k = 'self_geo_gate'"); setting('self_geo_gate', null, true);
$GLOBALS['USER'] = $super + ['employee_id' => 99201];
check('staff accounts are never gated', geo_gate_ok(), true);
$GLOBALS['USER'] = $super;
q("DELETE FROM attendance_points WHERE name LIKE 'T4G-%'");

// Check-in wait: 30 seconds after any attempt
$_SESSION = ['checkin_last' => time() - 10];
check('check-in wait left', checkin_wait() >= 19 && checkin_wait() <= 20, true);
$_SESSION = ['checkin_last' => time() - 31];
check('check-in wait over', checkin_wait(), 0);
$_SESSION = [];

q('DELETE FROM employee_documents WHERE employee_id = 99201'); q('DELETE FROM employees WHERE id = 99201'); q("DELETE FROM document_types WHERE name LIKE 'T4S-%'");

q('DELETE FROM employee_documents'); q("DELETE FROM document_types WHERE name LIKE 'T4-%'");
foreach (q_all("SELECT file_ref FROM backups_log WHERE file_ref LIKE 'hr-backup-%'") as $x) @unlink(backup_dir() . '/' . $x['file_ref']);
q('DELETE FROM backups_log');
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
