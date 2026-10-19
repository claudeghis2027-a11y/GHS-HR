<?php
declare(strict_types=1);

function resources(): array
{
    static $r = null;
    return $r ??= require __DIR__ . '/../resources.php';
}

function resource(string $key): array
{
    $r = resources()[$key] ?? null;
    if (!$r) http_error(404);
    $r['key'] = $key;
    $r['pk'] ??= 'id';
    return $r;
}

/** id => label map for a foreign-key field (small lookup tables only). */
function fk_options(array $f): array
{
    static $cache = [];
    [$table, $label] = $f['fk'];
    $k = "$table.$label";
    if (!isset($cache[$k])) {
        $cache[$k] = [];
        foreach (q_all("SELECT id, `$label` AS l FROM `$table` ORDER BY `$label`") as $r) $cache[$k][$r['id']] = $r['l'];
    }
    return $cache[$k];
}

function employee_options(): array
{
    [$w, $p] = scope_sql('e');
    $out = [];
    foreach (q_all("SELECT e.id, e.name_ar FROM employees e WHERE $w ORDER BY e.name_ar", $p) as $r) $out[$r['id']] = $r['id'] . ' - ' . $r['name_ar'];
    return $out;
}

function display_value(array $f, $v, array $row = []): string
{
    if ($v === null || $v === '') return '';
    switch ($f['type']) {
        case 'bool': return $v ? __('نعم') : __('لا');
        case 'enum': return __((string)($f['options'][$v] ?? $v));
        case 'fk': return (string)(fk_options($f)[$v] ?? $v);
        case 'employee': return $v . (isset($row['_emp_name']) ? ' - ' . $row['_emp_name'] : '');
        case 'datetime': return substr((string)$v, 0, 16);
        case 'time': return substr((string)$v, 0, 5);
        case 'decimal': return num($v);
        default: return (string)$v;
    }
}

/** Builds the WHERE clause shared by list + export. */
function crud_where(array $res, bool $selfOnly): array
{
    $where = ['1=1'];
    $params = [];
    $emp = $res['emp'] ?? null;
    if ($selfOnly) {
        $where[] = "t.`$emp` = ?";
        $params[] = require_employee_self();
    } elseif ($emp) {
        [$w, $p] = scope_sql('e');
        $where[] = $w;
        $params = array_merge($params, $p);
    }
    $empFilter = in_int('emp', $_GET);
    if ($emp && $empFilter !== null && !$selfOnly) { $where[] = "t.`$emp` = ?"; $params[] = $empFilter; }
    if (!empty($res['date_filter'])) {
        $df = $res['date_filter'];
        if ($d = valid_date($_GET['from'] ?? null)) { $where[] = "t.`$df` >= ?"; $params[] = $d; }
        if ($d = valid_date($_GET['to'] ?? null)) { $where[] = "t.`$df` <= ?"; $params[] = $d; }
    }
    if (!empty($res['current_filter']) && ($_GET['current'] ?? '') === '1') {
        $where[] = "(t.`{$res['current_filter']}` IS NULL OR t.`{$res['current_filter']}` >= CURDATE())";
    }
    if (!empty($res['present_filter']) && ($_GET['present'] ?? '') === '1') {
        $where[] = "t.`{$res['present_filter']}` IS NOT NULL";
    }
    $s = in_str('q', 100, $_GET);
    if ($s !== '' && !empty($res['search'])) {
        $or = [];
        foreach ($res['search'] as $col) { $or[] = "t.`$col` LIKE ?"; $params[] = '%' . addcslashes($s, '%_\\') . '%'; }
        if (ctype_digit($s)) { $or[] = 't.`' . $res['pk'] . '` = ?'; $params[] = (int)$s; }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    return [implode(' AND ', $where), $params];
}

function crud_from(array $res): string
{
    $emp = $res['emp'] ?? null;
    $sql = "FROM `{$res['table']}` t";
    if ($emp) $sql .= " LEFT JOIN employees e ON e.id = t.`$emp`";
    return $sql;
}

function crud_list(string $key, bool $selfOnly = false): void
{
    $res = resource($key);
    if ($selfOnly) {
        if (empty($res['self'])) http_error(404);
        require_employee_self();
    } else {
        require_perm($res['module'], 'view');
    }
    [$where, $params] = crud_where($res, $selfOnly);
    $from = crud_from($res);
    $nameSel = !empty($res['emp']) ? ', e.name_ar AS _emp_name' : '';

    if (($_GET['export'] ?? '') === 'csv' && !$selfOnly) {
        require_perm($res['module'], 'export');
        $rows = q_all("SELECT t.* $nameSel $from WHERE $where ORDER BY {$res['order']} LIMIT 50000", $params);
        $cols = array_keys($res['fields']);
        $out = [];
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) $line[] = display_value($res['fields'][$c] + ['type' => 'text'], $r[$c] ?? null, $r);
            $out[] = $line;
        }
        audit('export.csv', $key, ['rows' => count($out)]);
        send_csv($key . '-' . date('Ymd') . '.csv', array_map(fn($c) => $res['fields'][$c]['label'], $cols), $out);
    }

    $per = 50;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)q_val("SELECT COUNT(*) $from WHERE $where", $params);
    $rows = q_all("SELECT t.* $nameSel $from WHERE $where ORDER BY {$res['order']} LIMIT $per OFFSET " . (($page - 1) * $per), $params);

    render('crud_list', [
        'res' => $res, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'selfOnly' => $selfOnly,
        'title' => $res['title'],
    ]);
}

function crud_form(string $key): void
{
    $res = resource($key);
    $id = in_int('id', $_GET);
    $row = [];
    if ($id !== null) {
        require_perm($res['module'], 'view');
        $row = crud_load($res, $id);
    } else {
        require_perm($res['module'], 'create');
        foreach ($res['fields'] as $name => $f) if (isset($f['default'])) $row[$name] = $f['default'];
        if (!empty($res['emp']) && ($e = in_int('emp', $_GET)) !== null) $row[$res['emp']] = $e;
    }
    render('crud_form', ['res' => $res, 'row' => $row, 'id' => $id, 'errors' => [], 'title' => $res['title']]);
}

function crud_load(array $res, int $id): array
{
    $row = q_one('SELECT t.* ' . crud_from($res) . " WHERE t.`{$res['pk']}` = ?", [$id]);
    if (!$row) http_error(404);
    if (!empty($res['emp']) && !can_see_employee((int)$row[$res['emp']])) http_error(403);
    return $row;
}

/** Validates POSTed values for a resource. Returns [data, errors]. */
function crud_validate(array $res, bool $isNew): array
{
    $data = [];
    $errors = [];
    foreach ($res['fields'] as $name => $f) {
        if (!empty($f['auto']) || !empty($f['readonly'])) continue;
        if (!empty($f['create_only']) && !$isNew) continue;
        $raw = $_POST[$name] ?? null;
        $raw = is_string($raw) ? trim($raw) : null;
        $label = __($f['label']);
        if ($f['type'] === 'bool') { $data[$name] = $raw === '1' ? 1 : 0; continue; }
        if ($raw === null || $raw === '') {
            if (!empty($f['required'])) $errors[$name] = __('%s مطلوب.', $label);
            $data[$name] = $f['type'] === 'enum' && isset($f['default']) ? $f['default'] : null; // NOT NULL enums fall back to their default
            continue;
        }
        $v = null;
        switch ($f['type']) {
            case 'text': case 'textarea':
                $max = $f['max'] ?? 500;
                if (mb_strlen($raw) > $max) $errors[$name] = __('%s أطول من %d حرف.', $label, $max);
                elseif (!empty($f['pattern']) && !preg_match($f['pattern'], $raw)) $errors[$name] = __('%s بصيغة غير صحيحة.', $label);
                $v = $raw; break;
            case 'int': case 'fk': case 'employee':
                if (!preg_match('/^-?\d{1,10}$/', $raw)) { $errors[$name] = __('%s لازم يكون رقم صحيح.', $label); break; }
                $v = (int)$raw; break;
            case 'decimal':
                if (!preg_match('/^-?\d{1,12}(\.\d{1,7})?$/', $raw)) { $errors[$name] = __('%s لازم يكون رقم.', $label); break; }
                $v = $raw; break;
            case 'date':
                if (!($v = valid_date($raw))) $errors[$name] = __('%s تاريخ غير صحيح.', $label); break;
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $raw)) $errors[$name] = __('%s وقت غير صحيح.', $label);
                $v = $raw; break;
            case 'datetime':
                $raw = str_replace('T', ' ', $raw);
                $dt = DateTime::createFromFormat('Y-m-d H:i', substr($raw, 0, 16));
                if (!$dt) $errors[$name] = __('%s غير صحيح.', $label); else $v = $dt->format('Y-m-d H:i:00');
                break;
            case 'enum':
                if (!array_key_exists($raw, $f['options'])) $errors[$name] = __('%s قيمة غير مسموحة.', $label);
                $v = $raw; break;
        }
        if (in_array($f['type'], ['int', 'decimal'], true) && $v !== null && !isset($errors[$name])) {
            if (isset($f['min']) && (float)$v < $f['min']) $errors[$name] = __('%s أقل من المسموح.', $label);
            if (isset($f['max_val']) && (float)$v > $f['max_val']) $errors[$name] = __('%s أكبر من المسموح.', $label);
        }
        if ($f['type'] === 'fk' && $v !== null && !isset(fk_options($f)[$v])) $errors[$name] = __('%s غير موجود.', $label);
        if ($f['type'] === 'employee' && $v !== null && !isset($errors[$name])) {
            if (!q_val('SELECT 1 FROM employees WHERE id = ?', [$v])) $errors[$name] = __('الموظف غير موجود.');
            elseif (!can_see_employee($v)) $errors[$name] = __('الموظف ده خارج نطاق صلاحياتك.');
        }
        $data[$name] = $v;
    }
    foreach ([['date_from', 'date_to']] as [$a, $b]) {
        if (!empty($data[$a]) && !empty($data[$b]) && $data[$b] < $data[$a]) $errors[$b] = __('تاريخ النهاية قبل تاريخ البداية.');
    }
    return [$data, $errors];
}

function crud_save(string $key): void
{
    $res = resource($key);
    if (!empty($res['readonly'])) http_error(403);
    $id = in_int('id');
    $isNew = $id === null;
    require_perm($res['module'], $isNew ? 'create' : 'update');
    $old = $isNew ? null : crud_load($res, $id);

    [$data, $errors] = crud_validate($res, $isNew);
    foreach ([$old[$res['lock_date'] ?? ''] ?? null, $data[$res['lock_date'] ?? ''] ?? null] as $d) {
        if ($d && ($p = closed_period_for($d))) $errors['_'] = __('الفترة "%s" مقفولة؛ مينفعش تضيف أو تعدل حاجة بتاريخ جواها.', $p);
    }
    $hook = $res['hooks'] ?? null;
    if ($hook && function_exists("hook_{$hook}_validate")) {
        ("hook_{$hook}_validate")($data, $errors, $old);
    }
    if ($errors) {
        render('crud_form', ['res' => $res, 'row' => array_merge($old ?? [], $data), 'id' => $id, 'errors' => $errors, 'title' => $res['title']]);
        return;
    }
    if ($isNew) {
        foreach ($res['fields'] as $name => $f) {
            if (($f['auto'] ?? '') === 'username') $data[$name] = user()['username'];
            if (($f['auto'] ?? '') === 'now') $data[$name] = date('Y-m-d H:i:s');
        }
    }

    try {
        db()->beginTransaction();
        if ($isNew) {
            $cols = array_keys($data);
            q("INSERT INTO `{$res['table']}` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
            $id = (int)($data[$res['pk']] ?? db()->lastInsertId());
        } else {
            $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
            if ($sets !== '') q("UPDATE `{$res['table']}` SET $sets WHERE `{$res['pk']}` = ?", array_merge(array_values($data), [$id]));
        }
        $diff = [];
        foreach ($data as $k => $v) if ($isNew || (string)($old[$k] ?? '') !== (string)($v ?? '')) $diff[$k] = $isNew ? $v : [$old[$k] ?? null, $v];
        if ($hook && function_exists("hook_{$hook}_after")) ("hook_{$hook}_after")($id, $data, $old, $diff);
        audit("$key." . ($isNew ? 'create' : 'update'), $id, $diff);
        db()->commit();
    } catch (PDOException $ex) {
        db()->rollBack();
        error_log(safe_error($ex));
        $msg = $ex->getCode() === '23000' ? __('القيمة دي متسجلة قبل كده أو مرتبطة ببيانات تانية.') : __('حصل خطأ أثناء الحفظ.');
        render('crud_form', ['res' => $res, 'row' => array_merge($old ?? [], $data), 'id' => $isNew ? null : $id, 'errors' => ['_' => $msg], 'title' => $res['title']]);
        return;
    }
    flash('ok', __('تم الحفظ.'));
    redirect(url('list', ['res' => $key]));
}

function crud_delete(string $key): void
{
    $res = resource($key);
    if (!empty($res['readonly'])) http_error(403);
    require_perm($res['module'], 'delete');
    $id = in_int('id');
    if ($id === null) http_error(400);
    $old = crud_load($res, $id);
    if (!empty($res['lock_date']) && ($p = closed_period_for((string)$old[$res['lock_date']]))) {
        flash('err', __('الفترة "%s" مقفولة؛ مينفعش تحذف حاجة بتاريخ جواها.', $p));
        redirect(url('list', ['res' => $key]));
    }
    try {
        q("DELETE FROM `{$res['table']}` WHERE `{$res['pk']}` = ?", [$id]);
        audit("$key.delete", $id, $old);
        flash('ok', __('تم الحذف.'));
    } catch (PDOException $ex) {
        flash('err', __('مش ممكن الحذف لأن السجل مرتبط ببيانات تانية.'));
    }
    redirect(url('list', ['res' => $key]));
}

/** Name of a closed payroll period containing $date (super admin may still edit; it is audited). */
function closed_period_for(?string $date): ?string
{
    if (!$date || is_manager()) return null;
    $p = q_one("SELECT id, name FROM payroll_periods WHERE status = 'closed' AND ? BETWEEN date_from AND date_to LIMIT 1", [substr($date, 0, 10)]);
    return $p ? ($p['name'] ?: __('رقم %d', $p['id'])) : null;
}

// ───────────── Resource hooks ─────────────
function hook_employees_validate(array &$data, array &$errors, ?array $old): void
{
    if ($old === null) {
        if (($data['id'] ?? null) === null) {
            if (setting('auto_employee_code', '1') !== '1') { $errors['id'] = __('كود الموظف مطلوب.'); return; }
            $data['id'] = (int)q_val('SELECT COALESCE(MAX(id), 0) + 1 FROM employees');
        } elseif ($data['id'] < 1) {
            $errors['id'] = __('كود غير صالح.');
        } elseif (q_val('SELECT 1 FROM employees WHERE id = ?', [$data['id']])) {
            $errors['id'] = __('الكود ده مستخدم.');
        }
    }
    $scope = dept_scope();
    if ($scope !== null && !in_array((int)($data['department_id'] ?? 0), $scope, true)) {
        $errors['department_id'] = __('لازم تختار قسم من الأقسام المسموحة لك.');
    }
}

function hook_employees_after(int $id, array $data, ?array $old, array $diff): void
{
    $by = user()['username'];
    $op = bin2hex(random_bytes(8));
    if ($old === null) {
        q('INSERT INTO employee_change_log (employee_id, field, new_value, changed_by, op_id) VALUES (?,?,?,?,?)', [$id, 'إضافة موظف جديد', 'تم الإنشاء', $by, $op]);
        return;
    }
    foreach ($diff as $field => [$from, $to]) {
        q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, changed_by, op_id) VALUES (?,?,?,?,?,?)', [$id, $field, $from, $to, $by, $op]);
    }
    if (!empty($data['end_date']) && $data['end_date'] <= date('Y-m-d')) face_purge_employee($id, 'end_of_service');
}

function hook_notifications_validate(array &$data, array &$errors, ?array $old): void
{
    if (($data['scope'] ?? 'all') !== 'all' && !preg_match('/^\d+$/', (string)($data['scope_value'] ?? ''))) {
        $errors['scope_value'] = __('اكتب رقم القسم أو كود الموظف.');
    }
}

/** (Re)builds the recipients of a notification, limited to the author's department scope. */
function hook_notifications_after(int $id, array $data, ?array $old, array $diff): void
{
    $n = q_one('SELECT * FROM notifications WHERE id = ?', [$id]);
    [$w, $p] = scope_sql('e');
    $sql = "SELECT e.id FROM employees e WHERE $w AND (e.end_date IS NULL OR e.end_date >= CURDATE())";
    if ($n['scope'] === 'department') { $sql .= ' AND e.department_id = ?'; $p[] = (int)$n['scope_value']; }
    if ($n['scope'] === 'employee')   { $sql .= ' AND e.id = ?'; $p[] = (int)$n['scope_value']; }
    $hide = date('Y-m-d H:i:s', strtotime($n['show_at']) + 60 * (int)$n['duration_min']);
    q('DELETE FROM notification_targets WHERE notification_id = ? AND read_at IS NULL', [$id]);
    foreach (q_all($sql, $p) as $r) {
        q('INSERT INTO notification_targets (notification_id, employee_id, show_at, hide_at) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE show_at = VALUES(show_at), hide_at = VALUES(hide_at)', [$id, $r['id'], $n['show_at'], $hide]);
    }
    q('UPDATE notifications SET updated_at = NOW() WHERE id = ?', [$id]);
}
