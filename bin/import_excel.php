<?php
declare(strict_types=1);
/*
 * Migrates the two Google-Sheets workbooks into the database.
 *
 *   php bin/import_excel.php --db="GHIS V2 Database.xlsx" --att="GHIS Attendance V2.xlsx" [--reset] [--dry-run]
 *
 * --reset   wipes previously imported HR data first (keeps roles, settings and staff accounts
 *           that are not linked to employees, e.g. the super admin).
 * --dry-run runs everything inside a transaction and rolls it back.
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';

$opt = getopt('', ['db:', 'att:', 'reset', 'dry-run']);
if (empty($opt['db']) || empty($opt['att'])) {
    fwrite(STDERR, "Usage: php bin/import_excel.php --db=<HR database.xlsx> --att=<attendance.xlsx> [--reset] [--dry-run]\n");
    exit(2);
}
$DB = new XlsxReader($opt['db']);
$AT = new XlsxReader($opt['att']);
$TZ = new DateTimeZone(config('timezone', 'Africa/Cairo'));
$report = [];

// ───────── value converters ─────────
function v($rec, string $k) { $x = $rec[$k] ?? ''; return is_string($x) ? trim($x) : $x; }
function blank($x): bool { return $x === '' || $x === null; }
function yes($x): int { return in_array(is_string($x) ? mb_strtolower(trim($x)) : $x, ['نعم', 'yes', 'true', '1', 1, true], true) ? 1 : 0; }
function dt($x): ?string
{
    global $TZ;
    if (blank($x)) return null;
    if (is_int($x) || is_float($x)) {  // Excel serial (already local time)
        $secs = (int)round(($x - 25569) * 86400);
        return gmdate('Y-m-d H:i:s', $secs);
    }
    try {
        $d = new DateTimeImmutable((string)$x, $TZ); // ISO strings ending in Z are UTC
        return $d->setTimezone($TZ)->format('Y-m-d H:i:s');
    } catch (Exception) { return null; }
}
function d($x): ?string { $v = dt($x); return $v ? substr($v, 0, 10) : null; }
function tm($x): ?string
{
    if (blank($x)) return null;
    if (is_int($x) || is_float($x)) { $s = (int)round(fmod((float)$x, 1) * 86400); return sprintf('%02d:%02d:%02d', intdiv($s, 3600) % 24, intdiv($s % 3600, 60), $s % 60); }
    return preg_match('/^\d{1,2}:\d{2}/', (string)$x) ? substr((string)$x . ':00', 0, 8) : null;
}
function nn($x) { return blank($x) ? null : $x; }
function ins(string $table, array $row): int
{
    $cols = array_keys($row);
    q("INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
    return (int)db()->lastInsertId();
}
function count_rep(string $what, int $n): void { global $report; $report[$what] = ($report[$what] ?? 0) + $n; }
function lookup_id(string $table, ?string $name, array $extra = []): ?int
{
    if ($name === null || trim($name) === '') return null;
    $id = q_val("SELECT id FROM `$table` WHERE name = ?", [trim($name)]);
    if ($id) return (int)$id;
    return ins($table, ['name' => trim($name)] + $extra);
}

db()->beginTransaction();
try {
    if (isset($opt['reset'])) {
        q('SET foreign_key_checks = 0');
        foreach (['attendance', 'face_match_log', 'identity_log', 'schedule_rules', 'schedule_rules_archive', 'employee_schedules', 'deduction_rules', 'holidays', 'leaves',
                  'attendance_adjustments', 'permission_requests', 'forgot_punch_log', 'extra_deductions', 'late_exemptions', 'checkout_exemptions', 'salary_tx', 'salary_tx_types',
                  'advances', 'payroll_periods', 'payroll_period_log', 'payroll_snapshot_rows', 'payroll_snapshots', 'payroll_year_settings', 'quarter_deduction_policy',
                  'summer_deduction_log', 'document_types', 'employee_documents', 'investigation_attachments', 'investigation_employees', 'investigations', 'notification_targets',
                  'notifications', 'backups_log', 'employee_change_log', 'profile_update_requests', 'employee_review_state', 'academic_years', 'attendance_points'] as $t) {
            q("DELETE FROM `$t`");
        }
        q("DELETE FROM users WHERE employee_id IS NOT NULL OR legacy_hash IS NOT NULL");
        q('DELETE FROM employees'); q('DELETE FROM departments'); q('DELETE FROM job_titles');
        q('SET foreign_key_checks = 1');
    } elseif (q_val('SELECT COUNT(*) FROM employees')) {
        fwrite(STDERR, "Database already has employees. Re-run with --reset to replace them.\n");
        db()->rollBack();
        exit(1);
    }

    // ───── departments / job titles ─────
    foreach ($DB->records('قوائم الأقسام والمسميات') as $r) {
        $table = v($r, 'النوع') === 'قسم' ? 'departments' : 'job_titles';
        if (blank(v($r, 'الاسم'))) continue;
        ins($table, ['name' => v($r, 'الاسم'), 'active' => yes(v($r, 'نشط؟ (نعم/لا)')), 'created_at' => dt(v($r, 'تاريخ الإضافة')) ?? date('Y-m-d H:i:s')]);
        count_rep($table, 1);
    }
    foreach ($DB->records('الأقسام المعفاة من البصمة') as $r) {
        if (blank(v($r, 'القسم')) || !yes(v($r, 'نشط؟ (نعم/لا)'))) continue;
        $id = lookup_id('departments', v($r, 'القسم'));
        q('UPDATE departments SET attendance_exempt = 1 WHERE id = ?', [$id]);
    }
    foreach ($DB->records('تصنيف أقسام خصم الصيف') as $r) {
        if (blank(v($r, 'اسم القسم'))) continue;
        $id = lookup_id('departments', v($r, 'اسم القسم'));
        q('UPDATE departments SET summer_rule = ? WHERE id = ?', [nn(v($r, 'القاعدة (Rule 1 / Rule 2)')), $id]);
    }

    // ───── employees ─────
    $empIds = [];
    foreach ($DB->records('بيانات الموظفين') as $r) {
        $id = (int)v($r, 'كود الموظف');
        if ($id < 1) continue;
        $pay = v($r, 'طريقة القبض (كاش/فيزا)');
        ins('employees', [
            'id' => $id,
            'name_ar' => v($r, 'الاسم بالعربي') ?: v($r, 'الاسم بالإنجليزي (من برنامج البصمة)') ?: "موظف $id",
            'name_en' => nn(v($r, 'الاسم بالإنجليزي (من برنامج البصمة)')),
            'department_id' => lookup_id('departments', (string)v($r, 'القسم')),
            'job_title_id' => lookup_id('job_titles', (string)v($r, 'المسمى الوظيفي')),
            'hire_date' => d(v($r, 'تاريخ التعيين')),
            'end_date' => d(v($r, 'تاريخ نهاية الخدمة')),
            'attendance_exempt' => yes(v($r, 'معفى يدويًا من البصمة؟ (نعم/لا)')),
            'nationality' => nn(v($r, 'الجنسية')),
            'phone' => blank(v($r, 'رقم التليفون')) ? null : (is_float(v($r, 'رقم التليفون')) || is_int(v($r, 'رقم التليفون')) ? '0' . (string)(int)v($r, 'رقم التليفون') : (string)v($r, 'رقم التليفون')),
            'pay_method' => $pay === 'كاش' ? 'cash' : ($pay === 'فيزا' ? 'visa' : null),
            'papers_received' => yes(v($r, 'استلم أوراقه بعد ترك الخدمة؟ (نعم/لا)')),
            'photo_ref' => nn(v($r, 'صورة الموظف')) ? 'drive:' . v($r, 'صورة الموظف') : null,
            'summer_deduction' => nn((string)v($r, 'خصم الصيف')),
            'first_year_deduction' => nn((string)v($r, 'خصم السنة الأولى')),
            'first_raise_month' => d(v($r, 'شهر أول علاوة مستحقة')),
            'first_year_exception' => nn((string)v($r, 'استثناء خصم السنة الأولى')),
        ]);
        $empIds[$id] = true;
        count_rep('employees', 1);
    }
    // Employees that exist only in the attendance workbook
    foreach ($AT->records('بيانات الموظفين') as $r) {
        $id = (int)v($r, 'كود الموظف');
        if ($id < 1 || isset($empIds[$id])) continue;
        ins('employees', ['id' => $id, 'name_ar' => v($r, 'اسم الموظف') ?: "موظف $id"]);
        $empIds[$id] = true;
        count_rep('employees (attendance only)', 1);
    }
    $empOk = fn($x) => isset($empIds[(int)$x]);

    foreach ($DB->records('سجل تعديلات بيانات الموظفين') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        ins('employee_change_log', ['employee_id' => (int)v($r, 'كود الموظف'), 'field' => (string)v($r, 'الحقل المعدل'), 'old_value' => nn((string)v($r, 'القيمة القديمة')),
            'new_value' => nn((string)v($r, 'القيمة الجديدة')), 'reason' => nn((string)v($r, 'السبب')), 'changed_by' => nn((string)v($r, 'المستخدم')),
            'changed_at' => dt(v($r, 'تاريخ ووقت التعديل')) ?? date('Y-m-d H:i:s'), 'op_id' => nn((string)v($r, 'معرّف العملية'))]);
        count_rep('employee_change_log', 1);
    }
    foreach ($DB->records('صلاحيات حقول الموظف') as $r) {
        $map = ['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'phone' => 'phone', 'nationality' => 'nationality', 'dept' => 'department_id', 'jobTitle' => 'job_title_id', 'hireDate' => 'hire_date'];
        $f = $map[v($r, 'الحقل')] ?? null;
        if (!$f) continue;
        q('UPDATE employee_field_permissions SET visible = ?, editable = ?, required = ?, sort_order = ? WHERE field = ?',
            [yes(v($r, 'مرئي للموظف')), yes(v($r, 'قابل للتعديل')), yes(v($r, 'إلزامي')), (int)v($r, 'الترتيب'), $f]);
    }
    foreach ($DB->records('مراجعة صور الموظفين') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        q('INSERT INTO employee_review_state (employee_id, photo_pending_ref, photo_review_status, photo_upload_used, updated_at) VALUES (?,?,?,?,?)
           ON DUPLICATE KEY UPDATE photo_pending_ref = VALUES(photo_pending_ref), photo_review_status = VALUES(photo_review_status), photo_upload_used = VALUES(photo_upload_used)',
            [(int)v($r, 'كود الموظف'), nn((string)v($r, 'معرّف الصورة المعلّقة')), nn((string)v($r, 'حالة المراجعة')), yes(v($r, 'استُهلكت فرصة الرفع؟')), dt(v($r, 'آخر تحديث'))]);
    }
    foreach ($DB->records('حالة مراجعة مطابقة الوجه') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        q('INSERT INTO employee_review_state (employee_id, face_fail_streak, face_review_status, updated_at) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE face_fail_streak = VALUES(face_fail_streak), face_review_status = VALUES(face_review_status)',
            [(int)v($r, 'كود الموظف'), (int)v($r, 'عدد الفشل المتتالي'), nn((string)v($r, 'حالة المراجعة')), dt(v($r, 'آخر تحديث'))]);
    }
    foreach ($DB->records('حالة استكمال بيانات الموظفين') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        q('INSERT INTO employee_review_state (employee_id, profile_status, profile_submitted_at, profile_approved_at, profile_reviewer, profile_reject_reason) VALUES (?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE profile_status = VALUES(profile_status), profile_submitted_at = VALUES(profile_submitted_at), profile_approved_at = VALUES(profile_approved_at),
           profile_reviewer = VALUES(profile_reviewer), profile_reject_reason = VALUES(profile_reject_reason)',
            [(int)v($r, 'كود الموظف'), nn((string)v($r, 'الحالة')), dt(v($r, 'تاريخ التقديم')), dt(v($r, 'تاريخ الاعتماد')), nn((string)v($r, 'المراجِع')), nn((string)v($r, 'سبب الرفض'))]);
    }
    foreach ($DB->records('طلبات تعديل بيانات الموظفين') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        $st = ['pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected'][mb_strtolower((string)v($r, 'الحالة'))] ?? 'rejected';
        ins('profile_update_requests', ['legacy_id' => nn((string)v($r, 'معرّف الطلب')), 'employee_id' => (int)v($r, 'كود الموظف'), 'fields_json' => (string)v($r, 'الحقول (JSON)') ?: '{}',
            'photo_ref' => nn((string)v($r, 'معرّف ملف الصورة')), 'submitted_at' => dt(v($r, 'تاريخ التقديم')) ?? date('Y-m-d H:i:s'), 'status' => $st,
            'reviewer' => nn((string)v($r, 'المراجِع')), 'reviewed_at' => dt(v($r, 'تاريخ المراجعة')), 'reject_reason' => nn((string)v($r, 'سبب الرفض'))]);
        count_rep('profile_update_requests', 1);
    }

    // ───── accounts ─────
    $roleIds = [];
    foreach (q_all('SELECT id, name, slug FROM roles') as $r) { $roleIds[$r['name']] = (int)$r['id']; $roleIds[$r['slug']] = (int)$r['id']; }
    foreach ($DB->records('مستويات الصلاحيات') as $r) {
        $name = (string)v($r, 'اسم الصلاحية');
        if ($name === '' || isset($roleIds[$name])) continue;
        q('INSERT INTO roles (slug, name, is_staff, active) VALUES (?,?,1,?)', ['r' . bin2hex(random_bytes(4)), $name, yes(v($r, 'نشط؟'))]);
        $roleIds[$name] = (int)db()->lastInsertId();
        count_rep('roles', 1);
    }
    foreach ($DB->records('مستخدمو النظام') as $r) {
        $u = mb_strtolower((string)v($r, 'اسم المستخدم'));
        if ($u === '' || q_val('SELECT 1 FROM users WHERE username = ?', [$u])) { count_rep('skipped users (username exists)', 1); continue; }
        ins('users', ['username' => $u, 'legacy_hash' => nn((string)v($r, 'كلمة المرور (مشفّرة)')), 'legacy_salt' => nn((string)v($r, 'Salt')),
            'role_id' => $roleIds[(string)v($r, 'الصلاحية')] ?? $roleIds['admin'], 'active' => yes(v($r, 'نشط؟')),
            'created_at' => dt(v($r, 'تاريخ الإنشاء')) ?? date('Y-m-d H:i:s'), 'last_login_at' => dt(v($r, 'آخر دخول'))]);
        count_rep('staff users', 1);
    }
    foreach ($AT->records('كلمات المرور') as $r) {
        $id = (int)v($r, 'كود الموظف');
        if (!$empOk($id) || q_val('SELECT 1 FROM users WHERE employee_id = ? OR username = ?', [$id, (string)$id])) continue;
        $h = (string)v($r, 'كلمة المرور');
        [$salt, $hash] = str_contains($h, ':') ? explode(':', $h, 2) : [null, $h];
        ins('users', ['username' => (string)$id, 'legacy_hash' => $hash, 'legacy_salt' => $salt, 'role_id' => $roleIds['employee'], 'employee_id' => $id,
            'password_changed_at' => dt(v($r, 'آخر تعديل'))]);
        count_rep('employee users', 1);
    }

    // ───── attendance ─────
    $points = [];
    foreach ($AT->sheetNames() as $sheet) {
        if (!preg_match('/^\d{4}-\d{2}$/', $sheet)) continue;
        foreach ($AT->records($sheet) as $r) {
            $id = (int)v($r, 'كود الموظف');
            if (!$empOk($id)) { count_rep('attendance skipped (unknown employee)', 1); continue; }
            $c = dt(v($r, 'التاريخ'));
            $in = dt(v($r, 'وقت الحضور')) ?? $c;
            $out = dt(v($r, 'وقت الانصراف'));
            $row = ['employee_id' => $id, 'work_date' => substr($c ?? $in, 0, 10), 'source' => 'import',
                    'check_in_at' => $in, 'check_in_point' => nn((string)v($r, 'نقطة الحضور')), 'check_in_distance' => nn(v($r, 'مسافة الحضور (م)')), 'check_in_photo' => null,
                    'check_out_at' => $out, 'check_out_point' => nn((string)v($r, 'نقطة الانصراف')), 'check_out_distance' => nn(v($r, 'مسافة الانصراف (م)')), 'check_out_photo' => null];
            // Old app quirk: the row is created at the first punch (column "التاريخ") and a later punch
            // overwrote "وقت الحضور". If they differ, keep the first as check-in and the later as check-out.
            if ($c && $in && !$out && strtotime($in) - strtotime($c) > 60) {
                $row['check_out_at'] = $in; $row['check_out_point'] = $row['check_in_point']; $row['check_out_distance'] = $row['check_in_distance'];
                $row['check_in_at'] = $c; $row['check_in_point'] = null; $row['check_in_distance'] = null;
                count_rep('attendance rows split (first punch → in, later → out)', 1);
            }
            foreach (['check_in_point', 'check_out_point'] as $pk) if ($row[$pk]) $points[$row[$pk]] = true;
            q('INSERT INTO attendance (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')
               ON DUPLICATE KEY UPDATE check_out_at = COALESCE(VALUES(check_out_at), check_out_at)', array_values($row));
            count_rep('attendance', 1);
        }
    }
    foreach (array_keys($points) as $p) { // coordinates were not stored in the sheets; admin must fill them in
        ins('attendance_points', ['name' => $p, 'active' => 0]);
        count_rep('attendance_points (need coordinates)', 1);
    }
    foreach ($AT->records('سجل فشل مطابقة الوجه') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        ins('face_match_log', ['employee_id' => (int)v($r, 'كود الموظف'), 'at' => dt(v($r, 'التاريخ والوقت')), 'result' => (string)v($r, 'النتيجة'),
            'distance' => nn(v($r, 'المسافة')), 'streak' => nn(v($r, 'العداد المتتالي')), 'counted' => yes(v($r, 'محتسب؟'))]);
        count_rep('face_match_log', 1);
    }
    foreach ($AT->records('سجل التحقق من الهوية') as $r) {
        ins('identity_log', ['employee_id' => nn(v($r, 'كود الموظف')), 'op_type' => (string)v($r, 'نوع العملية'), 'at' => dt(v($r, 'التاريخ والوقت')) ?? date('Y-m-d H:i:s'),
            'result' => nn((string)v($r, 'النتيجة')), 'details' => nn((string)v($r, 'تفاصيل/سبب'))]);
        count_rep('identity_log', 1);
    }

    // ───── rules ─────
    foreach ($DB->records('قواعد المواعيد') as $r) {
        ins('schedule_rules', ['code' => nn((string)v($r, 'معرّف القاعدة')), 'date_from' => d(v($r, 'من تاريخ')), 'date_to' => d(v($r, 'لحد تاريخ')),
            'start_time' => tm(v($r, 'موعد الحضور')), 'end_time' => tm(v($r, 'موعد الانصراف'))]);
        count_rep('schedule_rules', 1);
    }
    foreach ($DB->records('أرشيف قواعد المواعيد') as $r) {
        ins('schedule_rules_archive', ['archive_code' => nn((string)v($r, 'معرّف الأرشيف')), 'rule_code' => nn((string)v($r, 'معرّف القاعدة')), 'operation' => nn((string)v($r, 'نوع العملية')),
            'date_from' => d(v($r, 'من تاريخ')), 'date_to' => d(v($r, 'لحد تاريخ')), 'start_time' => tm(v($r, 'موعد الحضور')), 'end_time' => tm(v($r, 'موعد الانصراف')),
            'done_by' => nn((string)v($r, 'المستخدم')), 'done_at' => dt(v($r, 'تاريخ ووقت العملية')), 'reason' => nn((string)v($r, 'السبب'))]);
    }
    foreach ($DB->records('مواعيد خاصة لموظفين') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        ins('employee_schedules', ['employee_id' => (int)v($r, 'كود الموظف'), 'date_from' => d(v($r, 'من تاريخ')), 'date_to' => d(v($r, 'لحد تاريخ')),
            'start_time' => tm(v($r, 'موعد الحضور')), 'end_time' => tm(v($r, 'موعد الانصراف')), 'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]);
    }
    foreach ($DB->records('قواعد الخصم') as $r) {
        ins('deduction_rules', ['date_from' => d(v($r, 'من تاريخ')), 'date_to' => d(v($r, 'لحد تاريخ')),
            'late_unit_min' => (int)v($r, 'وحدة التأخير (دقائق)'), 'late_unit_days' => (float)v($r, 'قيمة وحدة التأخير (يوم)'), 'absence_days' => (float)v($r, 'خصم الغياب (يوم)'),
            'missing_checkout_days' => (float)v($r, 'خصم عدم بصمة الانصراف (يوم)'), 'early_leave_enabled' => yes(v($r, 'تفعيل خصم الانصراف المبكر؟ (نعم/لا)')),
            'early_unit_min' => (int)v($r, 'وحدة الانصراف المبكر (دقائق)') ?: 25, 'early_unit_days' => (float)v($r, 'قيمة وحدة الانصراف المبكر (يوم)'),
            'perm1_days' => (float)v($r, 'الإذن الأول'), 'perm2_days' => (float)v($r, 'الإذن الثاني'), 'perm3_days' => (float)v($r, 'الإذن الثالث'),
            'perm4_days' => (float)v($r, 'الإذن الرابع'), 'perm5_days' => (float)v($r, 'الإذن الخامس فأكثر')]);
        count_rep('deduction_rules', 1);
    }
    $statusMap = ['ACTIVE' => 'ACTIVE', 'SUPERSEDED' => 'SUPERSEDED', 'CANCELLED' => 'CANCELLED'];
    foreach ($DB->records('العطلات الرسمية') as $r) {
        if (!d(v($r, 'التاريخ'))) continue;
        ins('holidays', ['holiday_date' => d(v($r, 'التاريخ')), 'title' => (string)v($r, 'المناسبة'), 'htype' => (string)v($r, 'النوع') ?: 'OFFICIAL_HOLIDAY',
            'source' => nn((string)v($r, 'المصدر')), 'source_date' => d(v($r, 'تاريخ المصدر')), 'original_date' => d(v($r, 'التاريخ الأصلي')),
            'actual_date' => d(v($r, 'يوم الإجازة الفعلي')), 'status' => $statusMap[(string)v($r, 'الحالة')] ?? 'ACTIVE', 'synced_at' => dt(v($r, 'آخر مزامنة')), 'note' => nn((string)v($r, 'ملاحظة'))]);
        count_rep('holidays', 1);
    }
    // Legacy duplicate list; "المناسبة" was mistakenly stored as a date there, so import only dates not already known.
    foreach ($DB->records('الإجازات الرسمية') as $r) {
        $hd = d(v($r, 'التاريخ'));
        if (!$hd || q_val('SELECT 1 FROM holidays WHERE holiday_date = ?', [$hd])) continue;
        $title = v($r, 'المناسبة');
        ins('holidays', ['holiday_date' => $hd, 'title' => is_string($title) && $title !== '' ? $title : 'إجازة رسمية (من الشيت القديم)', 'status' => 'ACTIVE',
            'note' => 'مستورد من شيت "الإجازات الرسمية" — راجع اسم المناسبة']);
        count_rep('holidays (legacy list — review)', 1);
    }
    $empRows = [
        ['الإجازات الشخصية المعتمدة', 'leaves', fn($r) => ['date_from' => d(v($r, 'من تاريخ')), 'date_to' => d(v($r, 'لحد تاريخ')), 'reason' => nn((string)v($r, 'السبب'))]],
        ['تعديلات الحضور', 'attendance_adjustments', fn($r) => ['adj_date' => d(v($r, 'التاريخ')), 'adj_type' => 'other', 'value' => mb_substr(trim(v($r, 'نوع التعديل') . ': ' . v($r, 'القيمة')), 0, 100),
            'reason' => nn((string)v($r, 'السبب')), 'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]],
        ['سجل الأذونات', 'permission_requests', fn($r) => ['perm_date' => d(v($r, 'التاريخ')), 'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]],
        ['سجل نسيان تسجيل الحضورالانصراف', 'forgot_punch_log', fn($r) => ['punch_date' => d(v($r, 'التاريخ')), 'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]],
        ['سجل الخصومات الإضافية والجزاءات', 'extra_deductions', fn($r) => ['ded_date' => d(v($r, 'التاريخ')), 'reason' => nn((string)v($r, 'السبب/النوع')), 'days' => (float)v($r, 'عدد الأيام المخصومة'),
            'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]],
        ['إعفاءات التأخير المعتمدة', 'late_exemptions', fn($r) => ['ex_date' => d(v($r, 'التاريخ')), 'minutes' => (int)v($r, 'دقائق الإعفاء المعتمدة'), 'reason' => nn((string)v($r, 'سبب الإعفاء')),
            'approver' => nn((string)v($r, 'جهة الموافقة')), 'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل')), 'legacy_id' => nn((string)v($r, 'معرّف الإعفاء'))]],
    ];
    foreach ($empRows as [$sheet, $table, $fn]) {
        foreach ($DB->records($sheet) as $r) {
            if (!$empOk(v($r, 'كود الموظف'))) continue;
            ins($table, ['employee_id' => (int)v($r, 'كود الموظف')] + array_filter($fn($r), fn($x) => $x !== null));
            count_rep($table, 1);
        }
    }
    foreach ($DB->records('استثناءات إلزام الانصراف') as $r) {
        $scope = (string)v($r, 'النطاق');
        $scope = str_contains($scope, 'قسم') ? 'department' : (str_contains($scope, 'موظف') ? 'employee' : 'all');
        $val = (string)v($r, 'القيمة');
        if ($scope === 'department' && !ctype_digit($val)) $val = (string)lookup_id('departments', $val);
        ins('checkout_exemptions', ['scope' => $scope, 'scope_value' => nn($val), 'date_from' => d(v($r, 'من تاريخ')), 'date_to' => d(v($r, 'لحد تاريخ')),
            'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]);
        count_rep('checkout_exemptions', 1);
    }

    // ───── salary ─────
    $catMap = ['basic' => 'basic'];
    foreach ($DB->records('أنواع حركات المرتب') as $r) {
        if (blank(v($r, 'اسم النوع'))) continue;
        ins('salary_tx_types', ['name' => (string)v($r, 'اسم النوع'), 'direction' => v($r, 'إضافة أم خصم؟') === 'خصم' ? 'deduct' : 'add',
            'category' => nn((string)v($r, 'التصنيف')), 'active' => yes(v($r, 'نشط؟ (نعم/لا)'))]);
        count_rep('salary_tx_types', 1);
    }
    foreach ($DB->records('حركات المرتب') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        $type = q_val('SELECT id FROM salary_tx_types WHERE name = ?', [(string)v($r, 'نوع الحركة')]) ?: ins('salary_tx_types', ['name' => (string)v($r, 'نوع الحركة'), 'direction' => 'add']);
        ins('salary_tx', ['tx_code' => nn((string)v($r, 'Tx ID')), 'employee_id' => (int)v($r, 'كود الموظف'), 'type_id' => $type, 'date_from' => d(v($r, 'من تاريخ')),
            'date_to' => d(v($r, 'لحد تاريخ (فاضي = مستمرة)')), 'amount' => (float)v($r, 'القيمة'), 'reason' => nn((string)v($r, 'السبب')),
            'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]);
        count_rep('salary_tx', 1);
    }
    foreach ($DB->records('سلف الموظفين') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        $st = (string)v($r, 'الحالة');
        ins('advances', ['legacy_id' => nn((string)v($r, 'Advance ID')), 'employee_id' => (int)v($r, 'كود الموظف'), 'advance_date' => d(v($r, 'تاريخ السلفة')),
            'total' => (float)v($r, 'الإجمالي'), 'installments' => max(1, (int)v($r, 'عدد الأقساط')), 'deduct_from' => d(v($r, 'تاريخ بدء الخصم')) ?? d(v($r, 'تاريخ السلفة')),
            'installment_value' => (float)v($r, 'قيمة القسط'), 'status' => str_contains($st, 'ملغ') ? 'cancelled' : (str_contains($st, 'سدد') ? 'paid' : 'active'),
            'notes' => nn((string)v($r, 'ملاحظات')), 'created_by' => nn((string)v($r, 'سجّله')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]);
        count_rep('advances', 1);
    }
    foreach ($DB->records('فترات المرتبات') as $r) {
        if (!d(v($r, 'من تاريخ'))) continue;
        ins('payroll_periods', ['id' => (int)v($r, 'Period ID') ?: null, 'name' => nn((string)v($r, 'اسم الفترة')), 'date_from' => d(v($r, 'من تاريخ')), 'date_to' => d(v($r, 'لحد تاريخ')),
            'status' => str_contains((string)v($r, 'الحالة'), 'مقفول') ? 'closed' : 'open', 'created_by' => nn((string)v($r, 'أنشأها')), 'created_at' => dt(v($r, 'تاريخ الإنشاء')),
            'closed_by' => nn((string)v($r, 'أقفلها')), 'closed_at' => dt(v($r, 'تاريخ الإقفال'))]);
        count_rep('payroll_periods', 1);
    }
    foreach ($DB->records('سجل خصم واسترداد الصيف') as $r) {
        if (!$empOk(v($r, 'كود الموظف'))) continue;
        ins('summer_deduction_log', ['employee_id' => (int)v($r, 'كود الموظف'), 'month_no' => (int)v($r, 'الشهر'), 'year_no' => (int)v($r, 'السنة'),
            'basic_salary' => nn(v($r, 'الراتب الأساسي وقتها')), 'amount' => (float)v($r, 'المبلغ المخصوم فعليًا'), 'refund_status' => nn((string)v($r, 'حالة الرد')),
            'refunded_at' => d(v($r, 'تاريخ الرد الفعلي')), 'ded_type' => nn((string)v($r, 'نوع الخصم')), 'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]);
    }
    foreach ($DB->records('السنوات الدراسية') as $r) {
        if (!d(v($r, 'من تاريخ'))) continue;
        ins('academic_years', ['name' => (string)v($r, 'اسم السنة'), 'date_from' => d(v($r, 'من تاريخ')), 'date_to' => d(v($r, 'لحد تاريخ')), 'active' => yes(v($r, 'نشطة؟')),
            'created_by' => nn((string)v($r, 'أنشأها')), 'created_at' => dt(v($r, 'تاريخ الإنشاء'))]);
    }

    // ───── documents / investigations ─────
    foreach ($DB->records('أنواع المستندات') as $r) {
        if (blank(v($r, 'اسم النوع'))) continue;
        ins('document_types', ['name' => (string)v($r, 'اسم النوع'), 'has_number' => yes(v($r, 'له رقم؟ (نعم/لا)')), 'required' => yes(v($r, 'مطلوب؟ (نعم/لا)')),
            'has_expiry' => yes(v($r, 'له تاريخ انتهاء؟ (نعم/لا)')), 'alert_days' => nn(v($r, 'مدة التنبيه بالأيام')), 'active' => yes(v($r, 'نشط؟ (نعم/لا)'))]);
    }
    foreach ($DB->records('مستندات الموظفين') as $r) {
        if (!$empOk(v($r, 'كود الموظف')) || yes(v($r, 'محذوف؟ (نعم/لا)'))) continue;
        $type = q_val('SELECT id FROM document_types WHERE name = ?', [(string)v($r, 'نوع المستند')]) ?: ins('document_types', ['name' => (string)v($r, 'نوع المستند')]);
        $orig = (string)v($r, 'أصل أم صورة؟');
        ins('employee_documents', ['employee_id' => (int)v($r, 'كود الموظف'), 'type_id' => $type, 'doc_number' => nn((string)v($r, 'رقم المستند')),
            'is_original' => str_contains($orig, 'أصل') ? 'original' : ($orig !== '' ? 'copy' : null), 'issue_date' => d(v($r, 'تاريخ الإصدار')), 'expiry_date' => d(v($r, 'تاريخ الانتهاء')),
            'file_ref' => nn((string)v($r, 'معرّف ملف Drive')) ? 'drive:' . v($r, 'معرّف ملف Drive') : null, 'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]);
        count_rep('employee_documents', 1);
    }
    $invIds = [];
    foreach ($DB->records('التحقيقات') as $r) {
        $invIds[(string)v($r, 'معرّف التحقيق')] = ins('investigations', ['legacy_id' => nn((string)v($r, 'معرّف التحقيق')), 'inv_date' => d(v($r, 'تاريخ التحقيق')) ?? date('Y-m-d'),
            'subject' => (string)v($r, 'عنوان/موضوع التحقيق'), 'decision' => nn((string)v($r, 'القرار/النتيجة')), 'notes' => nn((string)v($r, 'ملاحظات')),
            'cancelled' => yes(v($r, 'ملغى؟ (نعم/لا)')), 'created_by' => nn((string)v($r, 'المستخدم')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]);
        count_rep('investigations', 1);
    }
    foreach ($DB->records('موظفو التحقيقات') as $r) {
        $inv = $invIds[(string)v($r, 'معرّف التحقيق')] ?? null;
        if (!$inv || !$empOk(v($r, 'كود الموظف'))) continue;
        ins('investigation_employees', ['investigation_id' => $inv, 'employee_id' => (int)v($r, 'كود الموظف'), 'cancelled' => yes(v($r, 'ملغى؟ (نعم/لا)')), 'created_at' => dt(v($r, 'تاريخ التسجيل'))]);
    }
    foreach ($DB->records('مرفقات التحقيقات') as $r) {
        $inv = $invIds[(string)v($r, 'معرّف التحقيق')] ?? null;
        if (!$inv) continue;
        ins('investigation_attachments', ['investigation_id' => $inv, 'file_ref' => nn((string)v($r, 'معرّف الملف')), 'file_name' => nn((string)v($r, 'اسم الملف')),
            'file_url' => nn((string)v($r, 'رابط الملف')), 'cancelled' => yes(v($r, 'ملغى؟ (نعم/لا)')), 'uploaded_at' => dt(v($r, 'تاريخ الرفع')), 'uploaded_by' => nn((string)v($r, 'رفعه'))]);
    }

    // ───── notifications ─────
    $nIds = [];
    foreach ($DB->records('التنبيهات والإشعارات') as $r) {
        $show = d(v($r, 'تاريخ الإظهار'));
        if (!$show) continue;
        $scope = (string)v($r, 'النطاق');
        $nIds[(string)v($r, 'معرّف الإشعار')] = ins('notifications', [
            'uuid' => nn((string)v($r, 'معرّف الإشعار')), 'ntype' => (string)v($r, 'النوع') ?: 'عام', 'period_month' => d(v($r, 'الشهر/الفترة')),
            'title' => (string)v($r, 'العنوان') ?: '(بدون عنوان)', 'body' => nn((string)v($r, 'النص')),
            'show_at' => $show . ' ' . (tm(v($r, 'وقت الإظهار')) ?? '00:00:00'), 'duration_min' => (int)v($r, 'مدة الظهور (دقائق)') ?: 120,
            'scope' => str_contains($scope, 'قسم') ? 'department' : (str_contains($scope, 'موظف') && !str_contains($scope, 'كل') ? 'employee' : 'all'),
            'scope_value' => nn((string)v($r, 'قيمة النطاق')), 'status' => str_contains((string)v($r, 'الحالة'), 'ملغ') ? 'cancelled' : 'scheduled',
            'report_from' => d(v($r, 'فترة التقرير من')), 'report_to' => d(v($r, 'فترة التقرير إلى')), 'created_by' => nn((string)v($r, 'أنشأه')),
            'created_at' => dt(v($r, 'تاريخ الإنشاء')), 'updated_at' => dt(v($r, 'آخر تحديث'))]);
        count_rep('notifications', 1);
    }
    foreach ($DB->records('مستهدفو الإشعارات') as $r) {
        $n = $nIds[(string)v($r, 'معرّف الإشعار')] ?? null;
        if (!$n || !$empOk(v($r, 'كود الموظف'))) continue;
        q('INSERT IGNORE INTO notification_targets (notification_id, employee_id, show_at, read_at, hide_at) VALUES (?,?,?,?,?)',
            [$n, (int)v($r, 'كود الموظف'), dt(v($r, 'تاريخ ووقت الإظهار')), dt(v($r, 'تاريخ القراءة')), dt(v($r, 'تاريخ الإخفاء'))]);
        count_rep('notification_targets', 1);
    }

    // ───── settings, backups log, legacy audit log ─────
    foreach ($DB->records('الإعدادات العامة') as $r) {
        $k = (string)v($r, 'الإعداد');
        if (str_contains($k, 'توليد كود')) q("UPDATE settings SET v = ? WHERE k = 'auto_employee_code'", [yes(v($r, 'القيمة')) ? '1' : '0']);
        if (str_contains($k, 'قريب الانتهاء')) q("UPDATE settings SET v = ? WHERE k = 'doc_expiry_alert_days'", [(string)(int)v($r, 'القيمة')]);
    }
    foreach ($DB->records('سجل النسخ الاحتياطية') as $r) {
        ins('backups_log', ['at' => dt(v($r, 'التاريخ والوقت')), 'description' => nn((string)v($r, 'الوصف')), 'source' => nn((string)v($r, 'المصدر')),
            'size_text' => nn((string)v($r, 'الحجم التقريبي')), 'file_ref' => nn((string)v($r, 'Drive File ID')), 'done_by' => nn((string)v($r, 'المستخدم'))]);
    }
    // audit_log is append-only (DB triggers), so legacy rows are imported once and never re-inserted.
    $legacyDone = (bool)q_val("SELECT 1 FROM audit_log WHERE action LIKE 'legacy.%' LIMIT 1");
    foreach ($legacyDone ? [] : $DB->records('سجل النظام') as $r) {
        $target = v($r, 'الهدف');
        $details = v($r, 'التفاصيل');
        q('INSERT INTO audit_log (created_at, username, action, target, details, ip) VALUES (?,?,?,?,?,?)', [
            dt(v($r, 'التاريخ والوقت')) ?? date('Y-m-d H:i:s'), nn((string)v($r, 'المستخدم')), 'legacy.' . v($r, 'الإجراء'),
            blank($target) ? null : (is_float($target) && $target > 20000 && $target < 80000 ? d($target) : (string)$target),
            blank($details) ? null : (is_float($details) && $details > 20000 && $details < 80000 ? d($details) : (string)$details), 'import']);
        count_rep('audit_log (legacy)', 1);
    }

    audit('import.excel', basename($opt['db']) . ' + ' . basename($opt['att']), $report);
    if (isset($opt['dry-run'])) { db()->rollBack(); echo "DRY RUN — nothing saved.\n"; }
    else db()->commit();
} catch (Throwable $ex) {
    if (db()->inTransaction()) db()->rollBack();
    // No exception message: DB errors can echo row values (names, salaries). Location + code only.
    fwrite(STDERR, 'IMPORT FAILED: ' . get_class($ex) . ' code=' . $ex->getCode() . ' at ' . basename($ex->getFile()) . ':' . $ex->getLine() . "\n");
    exit(1);
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
