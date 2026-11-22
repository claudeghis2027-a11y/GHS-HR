<?php
declare(strict_types=1);

// ═════════════ Document files and reports (old DocumentService.file / report*) ═════════════

function page_doc_file(): void
{
    $id = in_int('id', $_GET);
    $d = $id ? q_one('SELECT d.*, t.name type_name FROM employee_documents d JOIN document_types t ON t.id = d.type_id WHERE d.id = ?', [$id]) : null;
    if (!$d) http_error(404);
    $own = my_employee_id() === (int)$d['employee_id'];
    if ($own && $d['is_deleted']) http_error(404);
    if (!$own && !(can('documents') && can_see_employee((int)$d['employee_id']))) http_error(403);
    doc_send((string)$d['file_ref'], $d['type_name'] . '-' . (int)$d['employee_id']);
}

const DOC_REPORTS = ['missing' => 'الموظفين الناقص لهم مستندات', 'near' => 'مستندات قربت تنتهي أو انتهت', 'type' => 'حسب نوع المستند', 'employee' => 'حالة مستندات موظف'];

/** The rows of one documents report, already filtered by department / job / ended employees. */
function doc_report_rows(string $tab, array $f, string $today = ''): array
{
    $today = $today ?: date('Y-m-d');
    $keep = function (array $e) use ($f, $today): bool {
        if ($f['dept'] && (int)$e['department_id'] !== $f['dept']) return false;
        if ($f['job'] && (int)$e['job_title_id'] !== $f['job']) return false;
        if (!$f['ended'] && $e['end_date'] && $e['end_date'] < $today) return false;
        return true;
    };
    if ($tab === 'missing') {
        $by = [];
        foreach (doc_status_rows(null, null, $today) as $r) {
            if ($r['status'] !== 'ناقص' || !$keep($r['emp'])) continue;
            $by[$r['emp']['id']] ??= ['emp' => $r['emp'], 'types' => []];
            $by[$r['emp']['id']]['types'][] = $r['type']['name'];
        }
        return array_values($by);
    }
    if ($tab === 'near') {
        $rows = array_values(array_filter(doc_status_rows(null, null, $today), fn($r) => in_array($r['status'], ['قريب الانتهاء', 'منتهي'], true) && $keep($r['emp'])));
        usort($rows, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
        return $rows;
    }
    if ($tab === 'type') {
        if (!$f['type']) return [];
        return array_values(array_filter(doc_status_rows(null, $f['type'], $today), fn($r) => $keep($r['emp'])));
    }
    if ($tab === 'employee') {
        if (!$f['emp'] || !can_see_employee($f['emp'])) return [];
        return doc_status_rows([$f['emp']], null, $today);
    }
    return [];
}

function page_doc_reports(): void
{
    require_perm('docs_reports');
    $tab = isset(DOC_REPORTS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'missing';
    $f = ['dept' => in_int('dept', $_GET), 'job' => in_int('job', $_GET), 'type' => in_int('type', $_GET), 'emp' => in_int('emp', $_GET), 'ended' => ($_GET['ended'] ?? '') === '1'];
    $rows = doc_report_rows($tab, $f);
    if (export_format()) {
        require_perm('docs_reports', 'export');
        audit('export.' . export_format(), 'doc_reports', $tab);
        $copy = fn($d) => $d ? __(['original' => 'أصل', 'copy' => 'صورة'][$d['is_original']] ?? '') : '';
        if ($tab === 'missing') send_export(__('المستندات الناقصة'), "documents-missing", [__('الكود'), __('الاسم'), __('القسم'), __('المسمى'), __('عدد الناقص'), __('المستندات الناقصة')],
            array_map(fn($r) => [$r['emp']['id'], $r['emp']['name_ar'], $r['emp']['dept_name'], $r['emp']['job_name'], count($r['types']), implode('، ', $r['types'])], $rows));
        send_export(__('تقارير المستندات'), "documents-$tab", [__('الكود'), __('الاسم'), __('القسم'), __('المسمى'), __('نوع المستند'), __('الحالة'), __('رقم المستند'), __('أصل أم صورة'), __('تاريخ الإصدار'), __('تاريخ الانتهاء'), __('الأيام الباقية')],
            array_map(fn($r) => [$r['emp']['id'], $r['emp']['name_ar'], $r['emp']['dept_name'], $r['emp']['job_name'], $r['type']['name'], __($r['status']), $r['doc']['doc_number'] ?? '', $copy($r['doc']), $r['doc']['issue_date'] ?? '', $r['doc']['expiry_date'] ?? '', $r['days_left']], $rows));
    }
    render('doc_reports', ['title' => __('تقارير المستندات'), 'tab' => $tab, 'f' => $f, 'rows' => $rows,
        'depts' => fk_options(['fk' => ['departments', 'name']]), 'jobs' => fk_options(['fk' => ['job_titles', 'name']]),
        'types' => fk_options(['fk' => ['document_types', 'name']]), 'emps' => employee_options()]);
}

// ═════════════ Investigations (old InvestigationService) ═════════════

/** Old _nextId: the highest INV-n number + 1, six digits. */
function inv_next_no(): string
{
    $max = 0;
    foreach (q_all("SELECT inv_no FROM investigations WHERE inv_no REGEXP '^INV-[0-9]+$'") as $r) $max = max($max, (int)substr($r['inv_no'], 4));
    return 'INV-' . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}

/** Investigations where the user can see at least one of the employees (everything for an unscoped user). */
function inv_visible_sql(string $alias = 'i'): array
{
    if (dept_scope() === null) return ['1=1', []];
    [$w, $p] = scope_sql('e');
    return ["EXISTS (SELECT 1 FROM investigation_employees ie JOIN employees e ON e.id = ie.employee_id WHERE ie.investigation_id = $alias.id AND $w)", $p];
}

function inv_load(int $id): array
{
    [$w, $p] = inv_visible_sql();
    $inv = q_one("SELECT i.* FROM investigations i WHERE i.id = ? AND $w", array_merge([$id], $p));
    if (!$inv) http_error(404);
    return $inv;
}

/**
 * Old saveInvestigation_: a new one takes the next INV number; an edit cancels the current version and its
 * employee links and saves a new version with the same number (history kept). Attachments move to the new version.
 * Returns [error|null, new id].
 */
function inv_save(?int $editId, string $date, string $subject, string $decision, string $notes, array $empIds, string $by): array
{
    if (!valid_date($date)) return [__('تاريخ التحقيق غير صحيح.'), null];
    if (trim($subject) === '') return [__('عنوان/موضوع التحقيق مطلوب.'), null];
    $empIds = array_values(array_unique(array_filter(array_map('intval', $empIds))));
    if (!$empIds) return [__('لازم تختار موظف واحد على الأقل.'), null];
    foreach ($empIds as $e) {
        if (!q_val('SELECT 1 FROM employees WHERE id = ?', [$e])) return [__('كود الموظف مش موجود: %s', $e), null];
        if (!can_see_employee($e)) return [__('الموظف %s خارج الأقسام المسموحة ليك.', $e), null];
    }
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    q("SELECT GET_LOCK('hr_inv_no', 10)");
    try {
        $old = null;
        if ($editId !== null) {
            $old = q_one('SELECT * FROM investigations WHERE id = ? AND cancelled = 0 FOR UPDATE', [$editId]);
            if (!$old) { if ($own) $pdo->rollBack(); return [__('التحقيق مش موجود أو اتلغى أو اتعدّل في نسخة أحدث.'), null]; }
        }
        $no = ($old['inv_no'] ?? '') !== '' ? $old['inv_no'] : inv_next_no();
        q('INSERT INTO investigations (inv_no, inv_date, subject, decision, notes, cancelled, created_by) VALUES (?,?,?,?,?,0,?)',
            [$no, $date, trim($subject), $decision === '' ? null : $decision, $notes === '' ? null : $notes, $by]);
        $newId = (int)$pdo->lastInsertId();
        foreach ($empIds as $e) q('INSERT INTO investigation_employees (investigation_id, employee_id) VALUES (?,?)', [$newId, $e]);
        if ($old) {
            q('UPDATE investigations SET cancelled = 1, superseded_by = ? WHERE id = ?', [$newId, $old['id']]);
            q('UPDATE investigation_employees SET cancelled = 1 WHERE investigation_id = ? AND cancelled = 0', [$old['id']]);
            q('UPDATE investigation_attachments SET investigation_id = ? WHERE investigation_id = ? AND cancelled = 0', [$newId, $old['id']]);
        }
        audit($old ? 'investigation.update' : 'investigation.create', $no, ['id' => $newId, 'employees' => count($empIds)]);
        if ($own) $pdo->commit();
        return [null, $newId];
    } catch (Throwable $ex) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    } finally {
        q("SELECT RELEASE_LOCK('hr_inv_no')");
    }
}

/** Old cancel: the investigation, its employee links and attachments are marked cancelled; nothing is deleted. */
function inv_cancel(int $id, string $reason, string $by): ?string
{
    if (trim($reason) === '') return __('لازم تكتب سبب الإلغاء.');
    $n = q('UPDATE investigations SET cancelled = 1, cancel_reason = ?, cancelled_by = ?, cancelled_at = NOW() WHERE id = ? AND cancelled = 0', [trim($reason), $by, $id])->rowCount();
    if ($n === 0) return __('التحقيق مش موجود أو ملغى بالفعل.');
    q('UPDATE investigation_employees SET cancelled = 1 WHERE investigation_id = ? AND cancelled = 0', [$id]);
    q('UPDATE investigation_attachments SET cancelled = 1 WHERE investigation_id = ? AND cancelled = 0', [$id]);
    audit('investigation.cancel', $id, ['reason' => trim($reason)]);
    return null;
}

/** Old attachmentAdd, once per file of a multi-file field (images / PDF). Returns [stored count, error|null]. */
function inv_attach_uploads(int $id, string $invNo, string $by, string $field = 'files'): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f['name'])) return [0, null];
    $n = 0;
    foreach (array_keys($f['name']) as $i) {
        $_FILES['_one'] = ['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        [$err, $mime] = doc_upload_check('_one');
        if ($err) return [$n, $err . ' (' . $f['name'][$i] . ')'];
        if ($mime === null) continue;
        $ref = doc_upload_store('_one', $mime, 'inv' . $id);
        $label = pathinfo((string)$f['name'][$i], PATHINFO_FILENAME) ?: __('مرفق');
        q('INSERT INTO investigation_attachments (investigation_id, file_ref, file_name, uploaded_by) VALUES (?,?,?,?)', [$id, $ref, mb_substr($label, 0, 200), $by]);
        audit('investigation.attachment.add', $invNo, ['file' => $ref]);
        $n++;
    }
    unset($_FILES['_one']);
    return [$n, null];
}

function page_inv(): void
{
    require_perm('investigations');
    $id = in_int('id', $_GET);
    if ($id !== null) {
        $inv = inv_load($id);
        $emps = q_all('SELECT e.id, e.name_ar, d.name dept_name FROM investigation_employees ie JOIN employees e ON e.id = ie.employee_id LEFT JOIN departments d ON d.id = e.department_id
                       WHERE ie.investigation_id = ? AND (ie.cancelled = 0 OR ? = 1) ORDER BY e.id', [$id, $inv['cancelled'] ? 1 : 0]); // a cancelled version shows the links it had
        $atts = q_all('SELECT * FROM investigation_attachments WHERE investigation_id = ? AND cancelled = 0 ORDER BY id', [$id]);
        $versions = $inv['inv_no'] ? q_all('SELECT id, inv_date, subject, cancelled, superseded_by, cancel_reason, created_by, created_at FROM investigations WHERE inv_no = ? ORDER BY id DESC', [$inv['inv_no']]) : [];
        render('inv_view', ['title' => __('التحقيقات'), 'inv' => $inv, 'emps' => $emps, 'atts' => $atts, 'versions' => $versions]);
        return;
    }
    $f = ['from' => valid_date($_GET['from'] ?? null), 'to' => valid_date($_GET['to'] ?? null), 'emp' => in_int('emp', $_GET), 'q' => in_str('q', 100, $_GET), 'show' => in_array($_GET['show'] ?? '', ['cancelled', 'all'], true) ? $_GET['show'] : ''];
    [$w, $p] = inv_visible_sql();
    $sql = "SELECT i.* FROM investigations i WHERE $w AND i.superseded_by IS NULL";
    if ($f['show'] === '') $sql .= ' AND i.cancelled = 0';
    if ($f['show'] === 'cancelled') $sql .= ' AND i.cancelled = 1';
    if ($f['from']) { $sql .= ' AND i.inv_date >= ?'; $p[] = $f['from']; }
    if ($f['to']) { $sql .= ' AND i.inv_date <= ?'; $p[] = $f['to']; }
    if ($f['emp']) { $sql .= ' AND EXISTS (SELECT 1 FROM investigation_employees x WHERE x.investigation_id = i.id AND x.employee_id = ?)'; $p[] = $f['emp']; }
    $rows = q_all($sql . ' ORDER BY i.inv_no DESC, i.id DESC', $p);
    $ids = array_column($rows, 'id');
    $empsBy = []; $attBy = [];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (q_all("SELECT ie.investigation_id, e.id, e.name_ar FROM investigation_employees ie JOIN employees e ON e.id = ie.employee_id WHERE ie.investigation_id IN ($in) ORDER BY e.id") as $r) $empsBy[$r['investigation_id']][] = $r;
        foreach (q_all("SELECT investigation_id, COUNT(*) n FROM investigation_attachments WHERE investigation_id IN ($in) AND cancelled = 0 GROUP BY investigation_id") as $r) $attBy[$r['investigation_id']] = (int)$r['n'];
    }
    if ($f['q'] !== '') {
        $needle = mb_strtolower($f['q']);
        $rows = array_values(array_filter($rows, function ($r) use ($needle, $empsBy) {
            $hay = $r['inv_no'] . ' ' . $r['subject'] . ' ' . $r['decision'] . ' ' . $r['notes'] . ' ' . implode(' ', array_map(fn($e) => $e['id'] . ' ' . $e['name_ar'], $empsBy[$r['id']] ?? []));
            return str_contains(mb_strtolower($hay), $needle);
        }));
    }
    render('inv_list', ['title' => __('التحقيقات'), 'rows' => $rows, 'empsBy' => $empsBy, 'attBy' => $attBy, 'f' => $f, 'emps' => employee_options()]);
}

function page_inv_post(): void
{
    $op = $_POST['op'] ?? '';
    $id = in_int('id');
    if ($id === null) http_error(400);
    $by = user()['username'];
    if ($op === 'cancel') {
        require_perm('investigations', 'delete');
        inv_load($id);
        $err = inv_cancel($id, in_str('reason', 500), $by);
        flash($err ? 'err' : 'ok', $err ?? __('التحقيق اتلغى، وسجله فضل محفوظ.'));
        redirect(url('inv', ['id' => $id]));
    }
    require_perm('investigations', 'update');
    $inv = inv_load($id);
    if ($op === 'att_add') {
        if ($inv['cancelled']) { flash('err', __('التحقيق ده ملغى أو ليه نسخة أحدث.')); redirect(url('inv', ['id' => $id])); }
        [$n, $err] = inv_attach_uploads($id, (string)($inv['inv_no'] ?? $id), $by);
        if (!$err && $n === 0) $err = __('الملف مطلوب.');
        flash($err ? 'err' : 'ok', $err ?? __('اتضاف %d مرفق.', $n));
    } elseif ($op === 'att_remove') {
        $aid = in_int('att');
        $n = $aid ? q('UPDATE investigation_attachments SET cancelled = 1 WHERE id = ? AND investigation_id = ? AND cancelled = 0', [$aid, $id])->rowCount() : 0;
        if ($n) { audit('investigation.attachment.remove', $inv['inv_no'] ?? $id, ['attachment' => $aid]); flash('ok', __('المرفق اتشال (الملف فضل محفوظ).')); }
        else flash('err', __('المرفق مش موجود.'));
    } else {
        http_error(400);
    }
    redirect(url('inv', ['id' => $id]));
}

function page_inv_edit(array $vars = []): void
{
    $id = in_int('id', $_GET) ?? in_int('id');
    require_perm('investigations', $id ? 'update' : 'create');
    $inv = $id ? inv_load($id) : null;
    if ($inv && $inv['cancelled']) { flash('err', __('التحقيق ده ملغى أو ليه نسخة أحدث.')); redirect(url('inv', ['id' => $id])); }
    $sel = $inv ? array_map('intval', array_column(q_all('SELECT employee_id FROM investigation_employees WHERE investigation_id = ? AND cancelled = 0', [$id]), 'employee_id')) : [];
    render('inv_form', $vars + ['title' => __('التحقيقات'), 'inv' => $inv, 'sel' => $sel, 'emps' => employee_options(), 'err' => null]);
}

function page_inv_edit_post(): void
{
    $id = in_int('id');
    require_perm('investigations', $id ? 'update' : 'create');
    if ($id) inv_load($id);
    $emps = is_array($_POST['employees'] ?? null) ? $_POST['employees'] : [];
    $in = ['inv_date' => in_str('inv_date', 10), 'subject' => in_str('subject', 255), 'decision' => in_str('decision', 1000), 'notes' => in_str('notes', 5000)];
    [$err, $newId] = inv_save($id, $in['inv_date'], $in['subject'], $in['decision'], $in['notes'], $emps, user()['username']);
    if ($err) {
        render('inv_form', ['title' => __('التحقيقات'), 'inv' => $in + ['id' => $id, 'inv_no' => $id ? q_val('SELECT inv_no FROM investigations WHERE id = ?', [$id]) : null],
            'sel' => array_map('intval', $emps), 'emps' => employee_options(), 'err' => $err]);
        return;
    }
    [$n, $upErr] = inv_attach_uploads($newId, (string)q_val('SELECT inv_no FROM investigations WHERE id = ?', [$newId]), user()['username']);
    flash('ok', ($id ? __('اتحفظت نسخة جديدة من التحقيق، والنسخة القديمة فضلت في السجل.') : __('التحقيق اتسجل.')) . ($n ? ' ' . __('اتضاف %d مرفق.', $n) : ''));
    if ($upErr) flash('err', $upErr);
    redirect(url('inv', ['id' => $newId]));
}

function page_inv_file(): void
{
    require_perm('investigations');
    $aid = in_int('id', $_GET);
    $a = $aid ? q_one('SELECT * FROM investigation_attachments WHERE id = ? AND cancelled = 0', [$aid]) : null;
    if (!$a) http_error(404, __('المرفق مش موجود.'));
    inv_load((int)$a['investigation_id']);
    doc_send((string)$a['file_ref'], (string)($a['file_name'] ?: 'attachment'));
}

// ═════════════ Notifications: cancel with a reason (old notifications.cancel) ═════════════

function notif_cancel(int $id, string $reason, string $by): ?string
{
    if (trim($reason) === '') return __('لازم تكتب سبب الإلغاء.');
    $n = q("UPDATE notifications SET status = 'cancelled', cancel_reason = ?, cancelled_by = ?, cancelled_at = NOW() WHERE id = ? AND status <> 'cancelled'", [trim($reason), $by, $id])->rowCount();
    if ($n === 0) return __('الإشعار مش موجود أو ملغي بالفعل.');
    q('DELETE FROM notification_targets WHERE notification_id = ? AND read_at IS NULL', [$id]);
    audit('notifications.cancel', $id, ['reason' => trim($reason)]);
    return null;
}

function page_notif_cancel_post(): void
{
    require_perm('notifications', 'update');
    $id = in_int('id');
    if ($id === null) http_error(400);
    $err = notif_cancel($id, in_str('reason', 500), user()['username']);
    flash($err ? 'err' : 'ok', $err ?? __('الإشعار اتلغى ومش هيظهر للموظفين.'));
    redirect(url('form', ['res' => 'notifications', 'id' => $id]));
}

// ═════════════ Holidays calendar (old HolidayService.calendar / cancel / reactivate / setObserved) ═════════════

const HOLIDAY_OFFICIAL = 'OFFICIAL_HOLIDAY';

/**
 * One row per occasion of the year: an occasion is one official date, however many records it has.
 * Hand-typed holidays are their own rows (origin manual), suppressed when the occasion moved away from that date.
 */
function holiday_calendar(string $year): array
{
    $by = []; $manual = [];
    foreach (q_all('SELECT * FROM holidays ORDER BY id') as $r) {
        if (holiday_is_manual($r)) { if (substr($r['holiday_date'], 0, 4) === $year) $manual[] = $r; continue; }
        $official = $r['original_date'] ?: $r['holiday_date'];
        $effective = $r['actual_date'] ?: $r['holiday_date'];
        if (substr($official, 0, 4) !== $year && substr($effective, 0, 4) !== $year) continue;
        $name = str_replace([' — التاريخ الأصلي', ' — يوم الإجازة الفعلي'], '', $r['title']);
        $e = &$by[$official];
        $e ??= ['title' => $name, 'official' => $official, 'effective' => $effective, 'status' => '', 'source' => '', 'note' => '', 'synced_at' => '', 'id' => null, 'records' => 0, 'origin' => 'synced'];
        $e['records']++;
        if ($r['htype'] === HOLIDAY_OFFICIAL) {
            $e = array_merge($e, ['title' => $name, 'effective' => $effective, 'status' => $r['status'], 'source' => (string)$r['source'], 'note' => (string)$r['note'], 'synced_at' => (string)$r['synced_at'], 'id' => (int)$r['id']]);
        } elseif ($e['status'] === '') {
            $e['status'] = $r['status']; $e['source'] = $e['source'] ?: (string)$r['source'];
        }
        unset($e);
    }
    $out = array_values($by);
    $win = $manual ? holiday_transfer_window() : [];
    foreach ($manual as $r) {
        $w = $win[$r['holiday_date']] ?? null;
        $out[] = ['title' => $r['title'], 'official' => $r['holiday_date'], 'effective' => $r['holiday_date'], 'origin' => 'manual', 'id' => (int)$r['id'], 'records' => 1,
            'status' => $r['status'] === 'ACTIVE' && $w ? 'SUPPRESSED' : $r['status'], 'source' => 'إدخال يدوي', 'synced_at' => '',
            'note' => $w ? __('المناسبة اتنقلت رسميًا إلى %s', $w['observed']) : (string)$r['note']];
    }
    usort($out, fn($a, $b) => strcmp($a['effective'], $b['effective']));
    return $out;
}

/** Old cancel / reactivate: only the status (and the reason in the note) change; historical rows are refused. */
function holiday_set_status(int $id, bool $cancel, string $reason): ?string
{
    $r = q_one('SELECT * FROM holidays WHERE id = ?', [$id]);
    if (!$r) return __('المناسبة مش موجودة في التقويم.');
    if ($r['htype'] !== HOLIDAY_OFFICIAL) return __('ده سجل تاريخي (تاريخ أصلي اتنقلت منه المناسبة) — التغيير بيتم على يوم الإجازة الفعلي بس.');
    if ($cancel && $r['status'] !== 'ACTIVE') return __('المناسبة مش نشطة أصلاً.');
    if (!$cancel && $r['status'] !== 'CANCELLED') return __('المناسبة مش ملغاة.');
    $note = trim($reason) !== '' ? trim($reason) : ($cancel ? 'ملغاة يدويًا' : 'أُعيد تفعيلها يدويًا');
    q('UPDATE holidays SET status = ?, note = ?, synced_at = NOW() WHERE id = ?', [$cancel ? 'CANCELLED' : 'ACTIVE', mb_substr($note, 0, 500), $id]);
    audit($cancel ? 'holiday.cancel' : 'holiday.reactivate', $r['holiday_date'], ['reason' => trim($reason)]);
    return null;
}

/** Old setObserved: the newest decision wins — earlier observed days of the same occasion are superseded. */
function holiday_set_observed(string $original, string $observed, string $name, string $note, string $source): ?string
{
    if (!valid_date($original) || !valid_date($observed)) return __('لازم تحدد التاريخ الأصلي ويوم الإجازة الفعلي.');
    $name = trim($name) !== '' ? trim($name) : 'إجازة رسمية';
    $source = trim($source) !== '' ? trim($source) : 'وزارة العمل / مجلس الوزراء';
    $types = "('OFFICIAL_HOLIDAY','ORIGINAL_DATE_SUPERSEDED','OBSERVED_DATE_SUPERSEDED')";
    $put = function (string $date, array $data) use ($types): void {
        $id = q_val("SELECT id FROM holidays WHERE holiday_date = ? AND htype IN $types
                     AND NOT (htype = 'OFFICIAL_HOLIDAY' AND original_date IS NULL AND COALESCE(source, '') IN ('', 'إدخال يدوي')) ORDER BY id LIMIT 1", [$date]); // hand-typed rows stay their own
        $cols = array_keys($data);
        if ($id) q('UPDATE holidays SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", $cols)) . ' WHERE id = ?', array_merge(array_values($data), [$id]));
        else q('INSERT INTO holidays (holiday_date, ' . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ') VALUES (?' . str_repeat(',?', count($cols)) . ')', array_merge([$date], array_values($data)));
    };
    q("UPDATE holidays SET htype = 'OBSERVED_DATE_SUPERSEDED', status = 'SUPERSEDED', synced_at = NOW(), note = ?
       WHERE original_date = ? AND holiday_date <> ? AND holiday_date <> ? AND status = 'ACTIVE' AND htype IN $types", ['يوم إجازة سابق — اتنقلت إلى ' . $observed, $original, $observed, $original]);
    $put($observed, ['title' => $name, 'htype' => HOLIDAY_OFFICIAL, 'source' => $source, 'source_date' => date('Y-m-d'), 'original_date' => $original, 'actual_date' => $observed, 'status' => 'ACTIVE', 'synced_at' => date('Y-m-d H:i:s'), 'note' => trim($note) === '' ? null : trim($note)]);
    if ($original !== $observed) $put($original, ['title' => $name . ' — التاريخ الأصلي', 'htype' => 'ORIGINAL_DATE_SUPERSEDED', 'source' => $source, 'source_date' => date('Y-m-d'), 'original_date' => $original, 'actual_date' => $observed, 'status' => 'SUPERSEDED', 'synced_at' => date('Y-m-d H:i:s'), 'note' => 'اتنقلت إلى ' . $observed]);
    audit('holiday.setObserved', $original . '→' . $observed, ['name' => $name]);
    return null;
}

function page_holiday_calendar(): void
{
    require_perm('rules');
    $year = preg_match('/^\d{4}$/', $_GET['year'] ?? '') ? $_GET['year'] : date('Y');
    $years = array_column(q_all('SELECT DISTINCT YEAR(holiday_date) y FROM holidays ORDER BY y'), 'y');
    $years = array_values(array_unique(array_merge(array_map('strval', $years), [date('Y'), (string)((int)date('Y') + 1), $year])));
    sort($years);
    $rows = holiday_calendar($year);
    $synced = count(array_filter($rows, fn($r) => $r['origin'] === 'synced'));
    render('holiday_calendar', ['title' => __('تقويم العطلات الرسمية'), 'year' => $year, 'years' => $years, 'rows' => $rows,
        'coverage' => ['synced' => $synced, 'manual' => count($rows) - $synced], 'lastSync' => (string)q_val('SELECT MAX(synced_at) FROM holidays')]);
}

function page_holiday_calendar_post(): void
{
    require_perm('rules', 'update');
    $op = $_POST['op'] ?? '';
    $year = preg_match('/^\d{4}$/', $_POST['year'] ?? '') ? $_POST['year'] : date('Y');
    if ($op === 'cancel' || $op === 'reactivate') {
        $id = in_int('id');
        if ($id === null) http_error(400);
        $err = holiday_set_status($id, $op === 'cancel', in_str('reason', 500));
        flash($err ? 'err' : 'ok', $err ?? ($op === 'cancel' ? __('المناسبة اتلغت.') : __('المناسبة اتفعّلت تاني.')));
    } elseif ($op === 'observed') {
        $err = holiday_set_observed(in_str('original_date', 10), in_str('observed_date', 10), in_str('title', 255), in_str('note', 500), in_str('source', 255));
        flash($err ? 'err' : 'ok', $err ?? __('يوم الإجازة الفعلي اتسجل.'));
    } else {
        http_error(400);
    }
    redirect(url('holiday_calendar', ['year' => $year]));
}

// ═════════════ Late waiver helper (old «احسب تأخير اليوم») ═════════════

function page_late_of_day(): void
{
    require_perm('attendance_adjust');
    $emp = in_int('emp', $_GET);
    $d = valid_date($_GET['d'] ?? null);
    header('Content-Type: application/json; charset=utf-8');
    if ($emp === null || !$d || !can_see_employee($emp)) { echo json_encode(['ok' => false, 'msg' => __('اختار الموظف والتاريخ الأول.')], JSON_UNESCAPED_UNICODE); exit; }
    $day = att_evaluate($emp, $d, $d)['days'][0] ?? null;
    $waived = (int)q_val('SELECT COALESCE(SUM(minutes), 0) FROM late_exemptions WHERE employee_id = ? AND ex_date = ?', [$emp, $d]);
    $late = (int)($day['late'] ?? 0);
    echo json_encode(['ok' => true, 'late' => $late, 'waived' => $waived, 'remaining' => max(0, $late - $waived), 'in' => $day && $day['in'] ? substr($day['in'], 11, 5) : null,
        'msg' => $day && $day['in'] ? __('الحالة: %s · حضور فعلي %s مقابل رسمي %s · تأخير أصلي %d د · إعفاء مسجَّل %d د · نهائي %d د', __(DAY_STATUS[$day['status']] ?? $day['status']), substr($day['in'], 11, 5),
            $day['sched_in'] ? substr((string)$day['sched_in'], 0, 5) : '—', $late, $waived, max(0, $late - $waived)) : __('مفيش بصمة حضور في اليوم ده.')], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═════════════ Employee profile (old openEmployeeProfile) ═════════════

function page_employee(): void
{
    require_perm('employees');
    $id = in_int('id', $_GET);
    $emp = $id ? q_one('SELECT * FROM employees WHERE id = ?', [$id]) : null;
    if (!$emp) http_error(404);
    if (!can_see_employee($id)) http_error(403);
    $fields = array_filter(resource('employees')['fields'], fn($f) => empty($f['virtual']));
    $history = q_all('SELECT field, old_value, new_value, reason, changed_by, changed_at FROM employee_change_log WHERE employee_id = ? ORDER BY id DESC LIMIT 30', [$id]);
    $schedules = q_all('SELECT date_from, date_to, start_time, end_time, created_by FROM employee_schedules WHERE employee_id = ? ORDER BY date_from DESC', [$id]);
    $basic = null; $basicRows = [];
    if (can('salary')) {
        $basic = basic_current($id, date('Y-m-d'));
        $basicRows = q_all("SELECT t.date_from, t.amount, t.reason FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id
                            WHERE t.employee_id = ? AND ty.category = 'basic' ORDER BY t.date_from DESC, t.id DESC", [$id]);
    }
    $face = q_val("SELECT status FROM face_profiles WHERE employee_id = ? AND status IN ('approved','pending') ORDER BY id DESC LIMIT 1", [$id]);
    render('employee', [
        'title' => __('ملف الموظف'), 'emp' => $emp, 'fields' => $fields, 'history' => $history, 'schedules' => $schedules,
        'basic' => $basic, 'basicRows' => $basicRows, 'face' => $face,
    ]);
}

// ═════════════ Schedule rules (old «القواعد» screen: historical rules, edit with archive, no delete) ═════════════

function page_sched_rules(): void
{
    require_perm('rules');
    $rules = q_all('SELECT * FROM schedule_rules ORDER BY date_from, id');
    $edit = null;
    if (($id = in_int('edit', $_GET)) !== null && can('rules', 'update')) $edit = q_one('SELECT * FROM schedule_rules WHERE id = ?', [$id]);
    $archCode = in_str('archive', 30, $_GET);
    $archive = $archCode !== '' ? q_all('SELECT * FROM schedule_rules_archive WHERE rule_code = ? ORDER BY id DESC', [$archCode]) : [];
    // The rest of the old «القواعد» screen: deduction rules, hand-typed holidays, checkout exceptions, personal schedules.
    $manual = array_values(array_filter(q_all('SELECT * FROM holidays ORDER BY holiday_date'), 'holiday_is_manual'));
    $gov = [];
    foreach (q_all("SELECT holiday_date, status, htype, original_date, source FROM holidays ORDER BY id") as $h) if (!holiday_is_manual($h)) $gov[$h['holiday_date']][] = $h['status'];
    $win = $manual ? holiday_transfer_window() : [];
    foreach ($manual as &$h) {
        $d = $h['holiday_date']; $g = $gov[$d] ?? [];
        $h['match'] = isset($win[$d]) ? __('🚫 ملغاة — المناسبة اتنقلت رسميًا إلى %s (مش محسوبة كإجازة)', $win[$d]['observed'])
            : (in_array('ACTIVE', $g, true) ? __('✅ مطابقة ليوم إجازة فعلي في التقويم') : ($g ? __('⚠️ التقويم مسجّل اليوم ده كـ%s (مش يوم الإجازة الفعلي)', $g[0]) : __('ℹ️ قرار محلي — بتتحسب إجازة رسمية')));
    }
    unset($h);
    $coEx = q_all("SELECT x.*, d.name dept_name, e.name_ar emp_name FROM checkout_exemptions x
                   LEFT JOIN departments d ON x.scope = 'department' AND d.id = x.scope_value LEFT JOIN employees e ON x.scope = 'employee' AND e.id = x.scope_value ORDER BY x.date_from");
    $emps = employee_options();
    $ovEmp = in_int('emp', $_GET);
    if ($ovEmp !== null && !isset($emps[$ovEmp])) $ovEmp = null;
    $overrides = $ovEmp !== null ? q_all('SELECT * FROM employee_schedules WHERE employee_id = ? ORDER BY date_from', [$ovEmp]) : [];
    render('sched_rules', ['title' => __('القواعد'), 'rules' => $rules, 'edit' => $edit, 'archCode' => $archCode, 'archive' => $archive,
        'form' => $_SESSION['sched_form'] ?? null, 'ded' => q_all('SELECT * FROM deduction_rules ORDER BY date_from, id'), 'dedForm' => $_SESSION['ded_form'] ?? null,
        'manual' => $manual, 'coEx' => $coEx, 'emps' => $emps, 'ovEmp' => $ovEmp, 'overrides' => $overrides]);
    unset($_SESSION['sched_form'], $_SESSION['ded_form']);
}

/** Old scheduleRuleAdd / scheduleRuleUpdate. Returns [error|null, rule code]. */
function sched_rule_save(?int $id, string $from, string $to, string $in, string $out, string $reason): array
{
    if (!valid_date($from) || !valid_date($to)) return [__('لازم تحدد من تاريخ ولحد تاريخ صحيحين.'), ''];
    if ($from > $to) return [__('تاريخ البداية لازم يكون قبل تاريخ النهاية.'), ''];
    $time = fn(string $t, string $dflt) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : $dflt;
    $in = $time($in, '08:00'); $out = $time($out, '15:00');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('SELECT id FROM schedule_rules FOR UPDATE'); // old U.withLock
        $old = $id !== null ? q_one('SELECT * FROM schedule_rules WHERE id = ?', [$id]) : null;
        if ($id !== null && !$old) { $pdo->rollBack(); return [__('القاعدة غير موجودة.'), '']; }
        if ($o = range_overlap('schedule_rules', $from, $to, $id)) {
            $pdo->rollBack();
            return [$old ? __('النطاق بيتداخل مع قاعدة تانية.') : __('الفترة الجديدة (%s إلى %s) بتتداخل مع قاعدة موجودة بالفعل (%s إلى %s).', $from, $to, $o['date_from'], $o['date_to']), ''];
        }
        if ($old) {
            schedule_rule_archive($old, 'تعديل', $reason);
            q('UPDATE schedule_rules SET date_from = ?, date_to = ?, start_time = ?, end_time = ? WHERE id = ?', [$from, $to, $in, $out, $id]);
            $code = $old['code'] ?: (string)$id;
            audit('scheduleRule.update', $code, ['reason' => $reason]);
        } else {
            $max = 0;
            foreach (q_all("SELECT code FROM schedule_rules WHERE code LIKE 'SR-%'") as $r) if (preg_match('/^SR-(\d+)$/', (string)$r['code'], $m)) $max = max($max, (int)$m[1]);
            $code = sprintf('SR-%04d', $max + 1);
            q('INSERT INTO schedule_rules (code, date_from, date_to, start_time, end_time) VALUES (?,?,?,?,?)', [$code, $from, $to, $in, $out]);
            audit('scheduleRule.add', $code, ['range' => $from . '→' . $to]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    return [null, $code];
}

/** Old RulesService.deductionRuleAdd: an empty field takes the old default; periods never overlap. */
function deduction_rule_add(array $in): ?string
{
    $from = (string)($in['date_from'] ?? ''); $to = (string)($in['date_to'] ?? '');
    if (!valid_date($from) || !valid_date($to)) return __('لازم تحدد من تاريخ ولحد تاريخ صحيحين.');
    if ($from > $to) return __('تاريخ البداية لازم يكون قبل تاريخ النهاية.');
    $row = ['date_from' => $from, 'date_to' => $to, 'early_leave_enabled' => !empty($in['early_leave_enabled']) ? 1 : 0];
    foreach (DEDUCTION_DEFAULTS as $k => $dflt) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v === '') { $row[$k] = $dflt; continue; }
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 999) return __('قيمة غير صحيحة: %s', __(DEDUCTION_LABELS[$k]));
        $row[$k] = is_int($dflt) ? (int)$v : round((float)$v, 2);
    }
    if ($row['late_unit_min'] < 1 || $row['early_unit_min'] < 1) return __('وحدة الدقائق لازم تكون 1 أو أكتر.');
    $pdo = db();
    $pdo->beginTransaction();
    q('SELECT id FROM deduction_rules FOR UPDATE');
    if ($o = range_overlap('deduction_rules', $from, $to)) {
        $pdo->rollBack();
        return __('الفترة الجديدة (%s إلى %s) بتتداخل مع قاعدة موجودة بالفعل (%s إلى %s).', $from, $to, $o['date_from'], $o['date_to']);
    }
    $cols = array_keys($row);
    q('INSERT INTO deduction_rules (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(',?', count($cols) - 1) . ')', array_values($row));
    $pdo->commit();
    audit('deductionRule.add', $from . '→' . $to, []);
    return null;
}

const DEDUCTION_DEFAULTS = ['late_unit_min' => 25, 'late_unit_days' => 0.25, 'absence_days' => 1.0, 'missing_checkout_days' => 0.25, 'early_unit_min' => 25, 'early_unit_days' => 0.25,
    'perm1_days' => 0.0, 'perm2_days' => 0.25, 'perm3_days' => 0.5, 'perm4_days' => 0.75, 'perm5_days' => 1.0];
const DEDUCTION_LABELS = ['late_unit_min' => 'وحدة التأخير (دقائق)', 'late_unit_days' => 'قيمة وحدة التأخير (يوم)', 'absence_days' => 'خصم الغياب (يوم)', 'missing_checkout_days' => 'خصم عدم بصمة الانصراف (يوم)',
    'early_unit_min' => 'وحدة الانصراف المبكر (دقائق)', 'early_unit_days' => 'قيمة وحدة الانصراف المبكر (يوم)', 'perm1_days' => 'الإذن الأول (يوم)', 'perm2_days' => 'الإذن الثاني (يوم)',
    'perm3_days' => 'الإذن الثالث (يوم)', 'perm4_days' => 'الإذن الرابع (يوم)', 'perm5_days' => 'الإذن الخامس فأكثر (يوم)'];

/** Old RulesService.holidayAdd: one hand-typed holiday per date. */
function manual_holiday_add(string $date, string $title): ?string
{
    if (!valid_date($date)) return __('التاريخ غير صحيح.');
    foreach (q_all('SELECT * FROM holidays WHERE holiday_date = ?', [$date]) as $h) if (holiday_is_manual($h)) return __('التاريخ ده مسجّل كإجازة رسمية بالفعل.');
    $title = trim($title) !== '' ? trim($title) : 'إجازة رسمية';
    q("INSERT INTO holidays (holiday_date, title, htype, source, status) VALUES (?, ?, 'OFFICIAL_HOLIDAY', 'إدخال يدوي', 'ACTIVE')", [$date, $title]);
    audit('holiday.add', $date, ['reason' => $title]);
    return null;
}

/** Old ScheduleOverrideService.add: the new period trims, splits or replaces the employee's overlapping ones. Returns [error|null, counts]. */
function schedule_override_add(int $empId, string $from, string $to, string $in, string $out): array
{
    if (!q_val('SELECT 1 FROM employees WHERE id = ?', [$empId])) return [__('الموظف غير موجود.'), []];
    if (!valid_date($from) || !valid_date($to)) return [__('لازم تحدد من تاريخ ولحد تاريخ صحيحين.'), []];
    if ($from > $to) return [__('«من تاريخ» لازم يكون قبل أو يساوي «لحد تاريخ».'), []];
    $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
    if (!preg_match($re, $in) || !preg_match($re, $out)) return [__('لازم تحدد موعد الحضور والانصراف.'), []];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('SELECT id FROM employee_schedules WHERE employee_id = ? FOR UPDATE', [$empId]);
        q('INSERT INTO employee_schedules (employee_id, date_from, date_to, start_time, end_time, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())',
            [$empId, $from, $to, $in, $out, user()['username'] ?? null]);
        $n = schedule_segment($empId, $from, $to, (int)$pdo->lastInsertId());
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    audit('scheduleOverride.add', $empId, ['range' => $from . '→' . $to]);
    return [null, $n];
}

function page_sched_rules_post(): void
{
    $op = $_POST['op'] ?? '';
    if ($op === 'deduction_add') {
        require_perm('rules', 'create');
        $in = ['date_from' => in_str('date_from', 10), 'date_to' => in_str('date_to', 10), 'early_leave_enabled' => in_str('early_leave_enabled', 1)];
        foreach (DEDUCTION_DEFAULTS as $k => $_) $in[$k] = in_str($k, 10);
        $err = deduction_rule_add($in);
        if ($err) $_SESSION['ded_form'] = $in;
        flash($err ? 'err' : 'ok', $err ?? __('تم الحفظ.'));
        redirect(url('sched_rules') . '#deduction');
    }
    if ($op === 'holiday_add') {
        require_perm('rules', 'create');
        $err = manual_holiday_add(in_str('holiday_date', 10), in_str('title', 255));
        flash($err ? 'err' : 'ok', $err ?? __('تمت الإضافة.'));
        redirect(url('sched_rules') . '#manual');
    }
    if ($op === 'override_add') {
        require_perm('rules', 'create');
        $emp = in_int('employee_id');
        if ($emp === null || !can_see_employee($emp)) { flash('err', __('كود الموظف مطلوب.')); redirect(url('sched_rules') . '#overrides'); }
        [$err, $n] = schedule_override_add($emp, in_str('date_from', 10), in_str('date_to', 10), in_str('start_time', 5), in_str('end_time', 5));
        flash($err ? 'err' : 'ok', $err ?? (__('تم الحفظ') . (($n['trimmed'] ?? 0) || ($n['removed'] ?? 0) ? ' ' . __('(تم تعديل %d وحذف %d فترة متداخلة)', $n['trimmed'], $n['removed']) : '')));
        redirect(url('sched_rules', ['emp' => $emp]) . '#overrides');
    }
    $id = in_int('id');
    require_perm('rules', $id === null ? 'create' : 'update');
    $f = ['from' => in_str('date_from', 10), 'to' => in_str('date_to', 10), 'in' => in_str('start_time', 5), 'out' => in_str('end_time', 5), 'reason' => in_str('reason', 500)];
    [$err, $code] = sched_rule_save($id, $f['from'], $f['to'], $f['in'], $f['out'], $f['reason']);
    if ($err) {
        $_SESSION['sched_form'] = $f + ['id' => $id];
        flash('err', $err);
        redirect(url('sched_rules', $id !== null ? ['edit' => $id] : []));
    }
    flash('ok', __('تم الحفظ (%s).', $code));
    redirect(url('sched_rules'));
}

// ═════════════ Old «إعفاءات التأخير والخصومات»: every attendance adjustment on one screen ═════════════

const ATT_ADJUST_PARTS = [
    // key => [resource, date column, title, note]
    'waivers'  => ['late_exemptions', 'ex_date', 'الإعفاءات المسجَّلة', 'الإعفاء طبقة مستقلة فوق البصمة — مابيعدّلش ولا بيحذف التأخير الأصلي. التقارير بتعرض الأصلي والإعفاء والنهائي.'],
    'perms'    => ['permission_requests', 'perm_date', 'الأذونات المسجَّلة', 'سلم الأذونات: الأول مجانًا ثم 0.25 / 0.5 / 0.75 / 1 يوم (بسقف يوم واحد).'],
    'forgot'   => ['forgot_punch_log', 'punch_date', 'نسيان تسجيل الحضور/الانصراف', 'أول مرة في فترة المرتب مجانًا، من المرة التانية بيتخصم ربع يوم إضافي كل مرة.'],
    'extra'    => ['extra_deductions', 'ded_date', 'خصومات إضافية / جزاءات', ''],
    'checkout' => ['checkout_exemptions', 'date_from', 'استثناءات إلزام الانصراف (مؤقتة)', 'بيأثر بس على خصم «عدم بصمة انصراف» — صفر تأثير على التأخير/الغياب/الأذونات/الجزاءات.'],
    'leaves'   => ['leaves', 'date_from', 'الإجازات الشخصية المعتمدة', ''],
    'adjust'   => ['attendance_adjustments', 'adj_date', 'تعديلات الحضور', 'آخر تعديل مسجَّل لنفس اليوم هو الساري — التسجيل إضافي ومفيش حذف.'],
];

function page_att_adjust(): void
{
    $parts = array_filter(ATT_ADJUST_PARTS, fn($p) => can(resource($p[0])['module']));
    if (!$parts) http_error(403);
    $from = valid_date($_GET['from'] ?? null) ?? date('Y-m-01');
    $to = valid_date($_GET['to'] ?? null) ?? date('Y-m-t');
    if ($from > $to) [$from, $to] = [$to, $from];
    [$w, $p] = scope_sql('e');
    $rows = [];
    foreach ($parts as $k => [$res, $col]) {
        $r = resource($res);
        $range = in_array($res, ['leaves', 'checkout_exemptions'], true) ? 't.date_from <= ? AND t.date_to >= ?' : "t.`$col` BETWEEN ? AND ?";
        $rp = in_array($res, ['leaves', 'checkout_exemptions'], true) ? [$to, $from] : [$from, $to];
        if ($res === 'checkout_exemptions') {
            $rows[$k] = q_all("SELECT t.*, d.name dept_name, e.name_ar emp_name FROM checkout_exemptions t LEFT JOIN departments d ON t.scope = 'department' AND d.id = t.scope_value
                               LEFT JOIN employees e ON t.scope = 'employee' AND e.id = t.scope_value WHERE $range ORDER BY t.date_from DESC", $rp);
            continue;
        }
        $rows[$k] = q_all("SELECT t.*, e.name_ar emp_name FROM `{$r['table']}` t JOIN employees e ON e.id = t.employee_id WHERE $range AND $w ORDER BY t.`$col` DESC, t.id DESC LIMIT 500", array_merge($rp, $p));
    }
    render('att_adjust', ['title' => __('إعفاءات التأخير والخصومات'), 'parts' => $parts, 'rows' => $rows, 'from' => $from, 'to' => $to, 'emps' => employee_options(),
        'depts' => fk_options(['fk' => ['departments', 'name'], 'fk_active' => true]), 'adjTypes' => resource('attendance_adjustments')['fields']['adj_type']['options']]);
}
