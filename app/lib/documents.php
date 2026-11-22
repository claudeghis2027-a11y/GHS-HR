<?php
declare(strict_types=1);

/*
 * Employee documents — a port of the old GHIS DocumentService: type rules on add (active type, number when the
 * type has one, original/copy, duplicate confirmation), PDF/image files kept on disk outside the web root,
 * soft delete that keeps the file, and the status of every (employee, type) pair from its latest record.
 */

const DOC_MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const DOC_MAX_BYTES = 10 * 1024 * 1024;
const DOC_STATUS = ['ساري', 'قريب الانتهاء', 'منتهي', 'ناقص', 'غير مطلوب'];

function doc_dir(): string
{
    $d = config('storage_dir') . '/uploads/documents';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

/** Checks an uploaded file field; returns [error|null, mime|null]. No file is fine (null, null). */
function doc_upload_check(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return [__('رفع الملف فشل، جرّب تاني.'), null];
    if ($f['size'] > DOC_MAX_BYTES) return [__('الملف أكبر من 10 ميجا.'), null];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!isset(DOC_MIME[$mime])) return [__('الملف لازم يكون صورة أو PDF.'), null];
    return [null, $mime];
}

/** Moves a checked upload into storage; returns the stored relative name. */
function doc_upload_store(string $field, string $mime, string $prefix): string
{
    $name = $prefix . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . DOC_MIME[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], doc_dir() . '/' . $name)) throw new RuntimeException('upload move failed');
    return $name;
}

/** Sends a stored file inline (never as HTML). */
function doc_send(string $rel, string $downloadName): never
{
    $path = doc_dir() . '/' . basename($rel);
    if ($rel === '' || !is_file($path)) http_error(404, __('مفيش ملف مرفق.'));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    if (!isset(DOC_MIME[$mime])) http_error(404);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($downloadName . '.' . DOC_MIME[$mime]));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/** Old computeStatus: no expiry = valid; past = expired; within the alert days = near expiry. */
function doc_compute_status(?string $expiry, int $alertDays, string $today): string
{
    if (!$expiry) return 'ساري';
    if ($expiry < $today) return 'منتهي';
    $left = (int)round((strtotime($expiry) - strtotime($today)) / 86400);
    return $left <= $alertDays ? 'قريب الانتهاء' : 'ساري';
}

/**
 * Old statusFor: for each employee × active type, the latest record decides (a deleted latest record = missing).
 * $empIds null = every employee in the user's scope.
 */
function doc_status_rows(?array $empIds = null, ?int $typeId = null, string $today = ''): array
{
    $today = $today ?: date('Y-m-d');
    $types = q_all('SELECT * FROM document_types WHERE active = 1' . ($typeId ? ' AND id = ' . (int)$typeId : '') . ' ORDER BY name');
    [$w, $p] = scope_sql('e');
    $sql = "SELECT e.id, e.name_ar, e.end_date, d.name dept_name, j.name job_name, e.department_id, e.job_title_id FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id WHERE $w";
    if ($empIds !== null) {
        if (!$empIds) return [];
        $sql .= ' AND e.id IN (' . implode(',', array_map('intval', $empIds)) . ')';
    }
    $emps = q_all($sql . ' ORDER BY e.id', $p);
    $latest = [];
    foreach (q_all('SELECT * FROM employee_documents ORDER BY created_at, id') as $r) $latest[$r['employee_id'] . '|' . $r['type_id']] = $r;
    $out = [];
    foreach ($emps as $e) foreach ($types as $t) {
        $r = $latest[$e['id'] . '|' . $t['id']] ?? null;
        $row = ['emp' => $e, 'type' => $t, 'doc' => null, 'days_left' => null];
        if (!$r || $r['is_deleted']) $row['status'] = $t['required'] ? 'ناقص' : 'غير مطلوب';
        else {
            $row['doc'] = $r;
            $row['status'] = $t['has_expiry'] ? doc_compute_status($r['expiry_date'], (int)($t['alert_days'] ?? 30) ?: 30, $today) : 'ساري';
            if ($r['expiry_date']) $row['days_left'] = (int)round((strtotime($r['expiry_date']) - strtotime($today)) / 86400);
        }
        $out[] = $row;
    }
    return $out;
}

/** One employee's own documents per active type (self service: no department scope involved). */
function doc_status_self(int $empId, string $today = ''): array
{
    $today = $today ?: date('Y-m-d');
    $latest = [];
    foreach (q_all('SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY created_at, id', [$empId]) as $r) $latest[$r['type_id']] = $r;
    $out = [];
    foreach (q_all('SELECT * FROM document_types WHERE active = 1 ORDER BY name') as $t) {
        $r = $latest[$t['id']] ?? null;
        $row = ['type' => $t, 'doc' => null, 'days_left' => null];
        if (!$r || $r['is_deleted']) $row['status'] = $t['required'] ? 'ناقص' : 'غير مطلوب';
        else {
            $row['doc'] = $r;
            $row['status'] = $t['has_expiry'] ? doc_compute_status($r['expiry_date'], (int)($t['alert_days'] ?? 30) ?: 30, $today) : 'ساري';
            if ($r['expiry_date']) $row['days_left'] = (int)round((strtotime($r['expiry_date']) - strtotime($today)) / 86400);
        }
        $out[] = $row;
    }
    return $out;
}

// ───────────── CRUD hooks for employee_documents ─────────────

/** Old DocumentService.add rules. */
function hook_documents_validate(array &$data, array &$errors, ?array $old): void
{
    if ($errors) return;
    $t = q_one('SELECT * FROM document_types WHERE id = ?', [$data['type_id']]);
    if (!$t) return;
    if ($old === null && !$t['active']) $errors['type_id'] = __('نوع المستند ده متوقف حاليًا: %s', $t['name']);
    if ($t['has_number'] && trim((string)$data['doc_number']) === '') $errors['doc_number'] = __('رقم المستند إلزامي لنوع "%s".', $t['name']);
    if (!$t['has_expiry']) $data['expiry_date'] = null;
    if ($old === null && empty($data['confirm_duplicate'])
        && ($last = q_one('SELECT is_deleted FROM employee_documents WHERE employee_id = ? AND type_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [$data['employee_id'], $data['type_id']]))
        && !$last['is_deleted']) {
        $errors['confirm_duplicate'] = __('الموظف ده عنده بالفعل مستند من نوع "%s" مسجل قبل كده. علّم على التأكيد لو عايز تضيف نسخة جديدة (القديمة هتفضل في السجل).', $t['name']);
    }
    [$err] = doc_upload_check('file');
    if ($err) $errors['file'] = $err;
}

function hook_documents_after(int $id, array $data, ?array $old, array $diff): void
{
    [, $mime] = doc_upload_check('file');
    if ($mime === null) return;
    $name = doc_upload_store('file', $mime, (string)(int)$data['employee_id']);
    q('UPDATE employee_documents SET file_ref = ? WHERE id = ?', [$name, $id]); // a replaced file stays on disk (non-destructive)
}

/** Old deleteEmployeeDocument_: soft delete; the record and its file stay. */
function hook_documents_delete(array $old): ?string
{
    if ($old['is_deleted']) return __('المستند ده محذوف بالفعل.');
    q('UPDATE employee_documents SET is_deleted = 1, deleted_by = ?, deleted_at = NOW() WHERE id = ?', [user()['username'], $old['id']]);
    audit('employee_documents.delete', $old['id'], ['employee' => (int)$old['employee_id'], 'type' => (int)$old['type_id'], 'soft' => true]);
    flash('ok', __('المستند اتشال من الحالي، والسجل والملف فضلوا محفوظين.'));
    redirect(url('list', ['res' => 'employee_documents']));
}
