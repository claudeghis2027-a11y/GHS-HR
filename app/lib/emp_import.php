<?php
declare(strict_types=1);

/*
 * Employee bulk upload: download an Excel template (empty or pre-filled), fill it, upload it,
 * review a preview, confirm. Every change goes through the audit log / employee change log.
 */
const EMP_SHEET = 'الموظفين';
const EMP_COLS = [
    'id'            => 'كود الموظف',
    'name_ar'       => 'الاسم بالعربي',
    'name_en'       => 'الاسم بالإنجليزي',
    'department'    => 'القسم',
    'job_title'     => 'المسمى الوظيفي',
    'hire_date'     => 'تاريخ التعيين',
    'end_date'      => 'تاريخ نهاية الخدمة',
    'exempt'        => 'معفى من البصمة (نعم/لا)',
    'nationality'   => 'الجنسية',
    'phone'         => 'رقم التليفون',
    'pay_method'    => 'طريقة القبض (كاش/فيزا)',
    'basic_salary'  => 'الراتب الأساسي',
    'salary_from'   => 'تاريخ بدء الراتب',
    'role'          => 'الصلاحية',
];

function emp_import_dir(): string
{
    $d = config('storage_dir') . '/tmp';
    if (!is_dir($d)) mkdir($d, 0770, true);
    return $d;
}

function current_basic(int $empId): ?array
{
    return q_one("SELECT t.* FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id WHERE t.employee_id = ? AND ty.category = 'basic'
                  AND t.date_from <= CURDATE() AND (t.date_to IS NULL OR t.date_to >= CURDATE()) ORDER BY t.date_from DESC, t.id DESC LIMIT 1", [$empId]);
}

/** Builds the template. $filled = include the employees the user may see. */
function emp_template_file(bool $filled): string
{
    $withSalary = can('salary', 'view') && can('salary', 'export');
    $head = array_values(EMP_COLS);
    $rows = [$head];
    if ($filled) {
        [$w, $p] = scope_sql('e');
        $roles = [];
        foreach (q_all('SELECT u.employee_id, r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.employee_id IS NOT NULL') as $r) $roles[$r['employee_id']] = $r['name'];
        foreach (q_all("SELECT e.*, d.name dept, j.name job FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id WHERE $w ORDER BY e.id", $p) as $e) {
            $b = $withSalary ? current_basic((int)$e['id']) : null;
            $rows[] = [(string)$e['id'], $e['name_ar'], $e['name_en'], $e['dept'], $e['job'], $e['hire_date'], $e['end_date'], $e['attendance_exempt'] ? 'نعم' : 'لا',
                       $e['nationality'], $e['phone'], ['cash' => 'كاش', 'visa' => 'فيزا'][$e['pay_method'] ?? ''] ?? '',
                       $b ? (float)$b['amount'] : '', $b ? $b['date_from'] : '', is_manager() ? ($roles[$e['id']] ?? '') : ''];
        }
    }
    $lists = [['الأقسام', 'المسميات الوظيفية', 'الصلاحيات', 'نعم/لا', 'طريقة القبض']];
    $depts = array_values(fk_options(['fk' => ['departments', 'name']]));
    $jobs = array_values(fk_options(['fk' => ['job_titles', 'name']]));
    $roleNames = array_column(q_all('SELECT name FROM roles WHERE active = 1 AND is_super = 0 ORDER BY is_staff, name'), 'name');
    for ($i = 0; $i < max(count($depts), count($jobs), count($roleNames), 2); $i++) {
        $lists[] = [$depts[$i] ?? '', $jobs[$i] ?? '', $roleNames[$i] ?? '', ['نعم', 'لا'][$i] ?? '', ['كاش', 'فيزا'][$i] ?? ''];
    }
    $help = [['تعليمات'],
        ['كل صف = موظف. "الاسم بالعربي" إلزامي.'],
        ['كود الموظف: سيبه فاضي لموظف جديد عشان يتولد تلقائيًا. لو الكود موجود، بيانات الموظف بتتحدث.'],
        ['القسم والمسمى والصلاحية لازم يكونوا مكتوبين زي شيت "القوائم" بالظبط.'],
        ['التواريخ بصيغة 2026-10-01 (أو تاريخ Excel عادي).'],
        ['الراتب الأساسي: لو اتغير، القيمة القديمة بتتقفل يوم قبل "تاريخ بدء الراتب" (الافتراضي أول الشهر الحالي) وبتتسجل الجديدة.'],
        ['الصلاحية: لو مكتوبة، بيتعمل للموظف حساب دخول (اسم المستخدم = الكود) أو بتتغير صلاحية حسابه. للسوبر أدمن بس.'],
        ['خلية فاضية = سيب القيمة الحالية زي ما هي.'],
    ];
    $x = new XlsxWriter();
    $x->addSheet(EMP_SHEET, $rows, [12, 26, 26, 18, 18, 14, 16, 18, 14, 16, 18, 14, 16, 18]);
    $x->addSheet('القوائم', $lists, [22, 22, 22, 10, 14]);
    $x->addSheet('تعليمات', $help, [110]);
    $file = tempnam(emp_import_dir(), 'tpl');
    $x->save($file);
    return $file;
}

/** @return string|null|false  date, empty, or invalid */
function xl_date($v): string|null|false
{
    if ($v === '' || $v === null) return null;
    if (is_int($v) || is_float($v)) return ($v > 20000 && $v < 80000) ? gmdate('Y-m-d', (int)round(($v - 25569) * 86400)) : false;
    $v = trim((string)$v);
    if (valid_date($v)) return $v;
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return false;
}

/** Parses + validates an uploaded workbook. Returns ['rows' => normalized changes, 'errors' => [rowNo => msgs], 'stats' => ...]. */
function emp_import_parse(string $file): array
{
    $xr = new XlsxReader($file);
    if (!in_array(EMP_SHEET, $xr->sheetNames(), true)) return ['fatal' => __('الملف لازم يكون فيه شيت اسمه "%s" (نزّل القالب واستخدمه).', EMP_SHEET)];
    $raw = $xr->rows(EMP_SHEET);
    if (!$raw) return ['fatal' => __('الشيت فاضي.')];
    $head = array_map(fn($h) => trim((string)$h), array_shift($raw));
    $idx = [];
    foreach (EMP_COLS as $k => $label) { $i = array_search($label, $head, true); if ($i !== false) $idx[$k] = $i; }
    if (!isset($idx['name_ar'])) return ['fatal' => __('عمود "الاسم بالعربي" مش موجود. استخدم القالب من غير ما تغيّر العناوين.')];
    if (count($raw) > 5000) return ['fatal' => __('أقصى عدد 5000 صف في المرة.')];

    $depts = array_change_key_case(array_flip(fk_options(['fk' => ['departments', 'name']])));
    $jobs = array_change_key_case(array_flip(fk_options(['fk' => ['job_titles', 'name']])));
    $roles = [];
    foreach (q_all('SELECT id, name, is_staff FROM roles WHERE active = 1 AND is_super = 0') as $r) $roles[mb_strtolower($r['name'])] = $r;
    $scope = dept_scope();
    $canSalary = can('salary', 'create');
    $out = []; $errors = []; $warnings = []; $seen = [];
    $stats = ['new' => 0, 'update' => 0, 'same' => 0, 'salary' => 0, 'accounts' => 0];

    foreach ($raw as $n => $r) {
        $rowNo = $n + 2;
        $get = function (string $k) use ($r, $idx) { $v = isset($idx[$k]) ? ($r[$idx[$k]] ?? '') : ''; return is_string($v) ? trim($v) : $v; };
        if (count(array_filter(array_map(fn($k) => $get($k), array_keys(EMP_COLS)), fn($v) => $v !== '' && $v !== null)) === 0) continue;
        $err = [];
        $idRaw = $get('id');
        $id = null;
        if ($idRaw !== '') {
            if (!preg_match('/^\d{1,9}(\.0+)?$/', (string)$idRaw) || (int)$idRaw < 1) $err[] = __('كود الموظف لازم يكون رقم صحيح.');
            else $id = (int)$idRaw;
        }
        if ($id !== null && isset($seen[$id])) $err[] = __('الكود %d متكرر في الملف (صف %d).', $id, $seen[$id]);
        if ($id !== null) $seen[$id] = $rowNo;
        $existing = $id !== null ? q_one('SELECT * FROM employees WHERE id = ?', [$id]) : null;
        if ($existing && !can_see_employee($id)) { $errors[$rowNo] = [__('الموظف ده خارج نطاق صلاحياتك.')]; continue; }
        if ($existing && !can('employees', 'update')) $err[] = __('معندكش صلاحية تعديل الموظفين.');
        if (!$existing && !can('employees', 'create')) $err[] = __('معندكش صلاحية إضافة موظفين.');

        $f = [];
        $name = (string)$get('name_ar');
        if ($name === '' && !$existing) $err[] = __('الاسم بالعربي إلزامي.');
        if ($name !== '') $f['name_ar'] = mb_substr($name, 0, 150);
        foreach (['name_en' => 150, 'nationality' => 80] as $k => $max) if ((string)$get($k) !== '') $f[$k] = mb_substr((string)$get($k), 0, $max);
        $phone = $get('phone');
        if ($phone !== '') {
            $phone = is_float($phone) || is_int($phone) ? '0' . (string)(int)$phone : (string)$phone;
            if (!preg_match('/^[0-9+\- ]{6,30}$/', $phone)) $err[] = __('رقم التليفون غير صحيح.'); else $f['phone'] = $phone;
        }
        foreach (['department' => [$depts, 'department_id', 'القسم'], 'job_title' => [$jobs, 'job_title_id', 'المسمى الوظيفي']] as $k => [$map, $col, $label]) {
            $v = (string)$get($k);
            if ($v === '') continue;
            if (!isset($map[mb_strtolower($v)])) $err[] = __('%s "%s" مش موجود في القوائم.', __($label), $v);
            else $f[$col] = (int)$map[mb_strtolower($v)];
        }
        $deptAfter = $f['department_id'] ?? ($existing['department_id'] ?? null);
        if ($scope !== null && !in_array((int)$deptAfter, $scope, true)) $err[] = __('لازم القسم يكون من الأقسام المسموحة لك.');
        foreach (['hire_date', 'end_date'] as $k) {
            $d = xl_date($get($k));
            if ($d === false) $err[] = __('%s تاريخ غير صحيح.', __(EMP_COLS[$k])); elseif ($d) $f[$k] = $d;
        }
        $ex = (string)$get('exempt');
        if ($ex !== '') { if (!in_array($ex, ['نعم', 'لا'], true)) $err[] = __('معفى من البصمة: اكتب نعم أو لا.'); else $f['attendance_exempt'] = $ex === 'نعم' ? 1 : 0; }
        $pm = (string)$get('pay_method');
        if ($pm !== '') { $pmv = ['كاش' => 'cash', 'فيزا' => 'visa'][$pm] ?? null; if (!$pmv) $err[] = __('طريقة القبض: كاش أو فيزا.'); else $f['pay_method'] = $pmv; }

        $salary = null;
        $sal = $get('basic_salary');
        if ($sal !== '') {
            if (!$canSalary) $warnings[$rowNo][] = __('الراتب اتجاهل: معندكش صلاحية إضافة حركات مرتب.');
            elseif (!is_numeric($sal) || (float)$sal < 0 || (float)$sal > 10000000) $err[] = __('الراتب الأساسي لازم يكون رقم موجب.');
            else {
                $from = xl_date($get('salary_from'));
                if ($from === false) $err[] = __('تاريخ بدء الراتب غير صحيح.');
                else {
                    $from = $from ?: date('Y-m-01');
                    $cur = $existing ? current_basic($id) : null;
                    if (!$cur || abs((float)$cur['amount'] - (float)$sal) > 0.004) {
                        if ($cur && $from <= $cur['date_from']) $err[] = __('تاريخ بدء الراتب الجديد لازم يكون بعد بداية الراتب الحالي.');
                        elseif ($p = closed_period_for($from)) $err[] = __('تاريخ بدء الراتب جوه فترة مقفولة (%s).', $p);
                        else $salary = ['amount' => round((float)$sal, 2), 'from' => $from];
                    }
                }
            }
        }
        $role = null;
        $roleName = (string)$get('role');
        if ($roleName !== '') {
            if (!is_manager()) $warnings[$rowNo][] = __('الصلاحية اتجاهلت: تحديدها للسوبر أدمن والأدمن بس.');
            elseif (!isset($roles[mb_strtolower($roleName)])) $err[] = __('الصلاحية "%s" مش موجودة.', $roleName);
            else $role = (int)$roles[mb_strtolower($roleName)]['id'];
        }

        if ($err) { $errors[$rowNo] = $err; continue; }
        $changes = [];
        if ($existing) foreach ($f as $k => $v) { if ((string)($existing[$k] ?? '') !== (string)$v) $changes[$k] = $v; }
        else $changes = $f;
        if ($role !== null) {
            $u = $existing ? q_one('SELECT id, role_id FROM users WHERE employee_id = ?', [$id]) : null;
            if ($u && (int)$u['role_id'] === $role) $role = null;
        }
        if (!$changes && !$salary && $role === null && $existing) { $stats['same']++; continue; }
        $stats[$existing ? 'update' : 'new']++;
        if ($salary) $stats['salary']++;
        if ($role !== null) $stats['accounts']++;
        $out[] = ['row' => $rowNo, 'id' => $id, 'is_new' => !$existing, 'name' => $f['name_ar'] ?? $existing['name_ar'], 'changes' => $changes, 'salary' => $salary, 'role' => $role];
    }
    return ['rows' => $out, 'errors' => $errors, 'warnings' => $warnings, 'stats' => $stats];
}

/** Applies a validated plan inside one transaction. Returns counts. */
function emp_import_apply(array $plan, string $tempPassword): array
{
    $by = user()['username'];
    $op = bin2hex(random_bytes(8));
    $basicType = (int)(q_val("SELECT id FROM salary_tx_types WHERE category = 'basic' AND active = 1 ORDER BY id LIMIT 1")
        ?: q_val("SELECT id FROM salary_tx_types WHERE name = 'الراتب الأساسي'") ?: 0);
    $hash = $tempPassword !== '' ? hash_password($tempPassword) : null;
    $done = ['new' => 0, 'update' => 0, 'salary' => 0, 'accounts' => 0, 'roles' => 0];
    db()->beginTransaction();
    try {
        foreach ($plan as $r) {
            $id = $r['id'];
            if ($r['is_new']) {
                if ($id === null) $id = (int)q_val('SELECT COALESCE(MAX(id), 0) + 1 FROM employees');
                elseif (q_val('SELECT 1 FROM employees WHERE id = ?', [$id])) throw new RuntimeException(__('الكود %d اتضاف من وقت المعاينة؛ ارفع الملف تاني.', $id));
                $cols = array_merge(['id' => $id], $r['changes']);
                q('INSERT INTO employees (`' . implode('`,`', array_keys($cols)) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($cols));
                q('INSERT INTO employee_change_log (employee_id, field, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?)', [$id, 'إضافة موظف جديد', 'رفع ملف Excel', 'رفع ملف Excel', $by, $op]);
                $done['new']++;
            } elseif ($r['changes']) {
                $old = q_one('SELECT * FROM employees WHERE id = ? FOR UPDATE', [$id]);
                q('UPDATE employees SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($r['changes']))) . ' WHERE id = ?', array_merge(array_values($r['changes']), [$id]));
                foreach ($r['changes'] as $k => $v) {
                    q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?,?)', [$id, $k, $old[$k], $v, 'رفع ملف Excel', $by, $op]);
                }
                $done['update']++;
            }
            if ($r['salary']) {
                if (!$basicType) { q("INSERT INTO salary_tx_types (name, direction, category) VALUES ('الراتب الأساسي', 'add', 'basic')"); $basicType = (int)db()->lastInsertId(); }
                $cur = current_basic($id);
                if ($cur) {
                    $to = date('Y-m-d', strtotime($r['salary']['from'] . ' -1 day'));
                    q('UPDATE salary_tx SET date_to = ? WHERE id = ?', [$to, $cur['id']]);
                    audit('salary_tx.update', $cur['id'], ['date_to' => [$cur['date_to'], $to], 'via' => 'excel']);
                }
                q('INSERT INTO salary_tx (tx_code, employee_id, type_id, date_from, amount, reason, created_by) VALUES (?,?,?,?,?,?,?)',
                    ['TX-' . strtoupper(bin2hex(random_bytes(5))), $id, $basicType, $r['salary']['from'], $r['salary']['amount'], 'رفع ملف Excel', $by]);
                audit('salary_tx.create', (int)db()->lastInsertId(), ['employee_id' => $id, 'amount' => $r['salary']['amount'], 'date_from' => $r['salary']['from'], 'via' => 'excel']);
                $done['salary']++;
            }
            if ($r['role'] !== null) {
                $u = q_one('SELECT id, role_id FROM users WHERE employee_id = ?', [$id]);
                if ($u) {
                    q('UPDATE users SET role_id = ?, session_epoch = session_epoch + 1 WHERE id = ?', [$r['role'], $u['id']]);
                    audit('user.role', $id, ['from' => $u['role_id'], 'to' => $r['role'], 'via' => 'excel']);
                    $done['roles']++;
                } elseif ($hash !== null && !q_val('SELECT 1 FROM users WHERE username = ?', [(string)$id])) {
                    q('INSERT INTO users (username, password_hash, role_id, employee_id, must_change_password) VALUES (?,?,?,?,1)', [(string)$id, $hash, $r['role'], $id]);
                    audit('user.create', (string)$id, ['role' => $r['role'], 'via' => 'excel']);
                    $done['accounts']++;
                }
            }
        }
        face_purge_ended(); // employees whose service ended lose their face data right away
        audit('employees.excel_import', null, $done);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        throw $ex;
    }
    return $done;
}
