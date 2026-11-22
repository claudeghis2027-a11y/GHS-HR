<?php
declare(strict_types=1);

// ═════════════ Backups (old BackupService): create, list, download, upload, restore one table ═════════════

function page_backup(): void
{
    require_perm('backup');
    $rows = q_all('SELECT * FROM backups_log ORDER BY COALESCE(at, "1970-01-01") DESC, id DESC LIMIT 200');
    foreach ($rows as &$b) $b['local'] = backup_file((int)$b['id']) !== null;
    unset($b);
    render('backup', ['title' => __('النسخ الاحتياطي'), 'rows' => $rows, 'tables' => backup_tables()]);
}

function page_backup_post(): void
{
    $op = $_POST['op'] ?? '';
    $by = user()['username'];
    @set_time_limit(600);
    if ($op === 'create') {
        require_perm('backup', 'create');
        try {
            $id = backup_create(in_str('description', 255) ?: 'نسخة احتياطية يدوية', $by);
            flash('ok', __('النسخة الاحتياطية اتعملت (رقم %d).', $id));
        } catch (Throwable $ex) {
            error_log(safe_error($ex));
            flash('err', __('النسخة الاحتياطية فشلت. مفيش أي بيانات اتغيرت.'));
        }
    } elseif ($op === 'upload') {
        require_perm('backup', 'create');
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || !backup_header($f['tmp_name'])) { flash('err', __('ملف النسخة غير صالح.')); redirect(url('backup')); }
        $name = 'hr-backup-' . date('Ymd-His') . '-up' . bin2hex(random_bytes(3)) . '.jsonl.gz';
        if (!move_uploaded_file($f['tmp_name'], backup_dir() . '/' . $name)) { flash('err', __('رفع الملف فشل، جرّب تاني.')); redirect(url('backup')); }
        q('INSERT INTO backups_log (at, description, source, size_text, file_ref, done_by) VALUES (NOW(),?,?,?,?,?)', [mb_substr(in_str('description', 255) ?: 'نسخة مرفوعة', 0, 255), 'رفع', size_text((int)$f['size']), $name, $by]);
        audit('backup.upload', (int)db()->lastInsertId());
        flash('ok', __('النسخة اترفعت وبقت في القايمة.'));
    } elseif ($op === 'restore') {
        // Old rule: restore is for the admin only, confirmed by typing the word, one table at a time, add-only.
        if (!is_manager()) http_error(403);
        $b = backup_file(in_int('id') ?? 0);
        if (!$b) { flash('err', __('النسخة مش موجودة.')); redirect(url('backup')); }
        if (in_str('confirm', 20) !== 'استرجاع') { flash('err', __('لتأكيد الاسترجاع اكتب كلمة «استرجاع» بالضبط.')); redirect(url('backup')); }
        $table = in_str('table', 64);
        [$err, $added, $seen] = backup_restore($b['path'], $table);
        if ($err) { flash('err', $err); redirect(url('backup')); }
        audit('backup.restore', $table, ['backup' => (int)$b['id'], 'added' => $added]);
        flash('ok', $added ? __('اتضاف %d صف ناقص لجدول %s (من %d صف في النسخة). مفيش أي صف اتمسح أو اتعدّل.', $added, $table, $seen) : __('كل صفوف النسخة موجودة بالفعل — مفيش أي كتابة.'));
    } else {
        http_error(400);
    }
    redirect(url('backup'));
}

function page_backup_file(): void
{
    require_perm('backup', 'export');
    $b = backup_file(in_int('id', $_GET) ?? 0);
    if (!$b) http_error(404, __('النسخة مش موجودة.'));
    audit('backup.download', (int)$b['id']);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $b['file_ref'] . '"');
    header('Content-Length: ' . filesize($b['path']));
    header('Cache-Control: private, no-store');
    readfile($b['path']);
    exit;
}

// ═════════════ WhatsApp messages (old WhatsAppService): preview only, the browser opens wa.me ═════════════

const WA_TYPES = ['payroll' => 'كشف المرتب', 'attendance' => 'ملخص الحضور', 'attendanceBrief' => 'ملخص الحضور المختصر'];

/** Old normalizeEgyptianPhoneForWhatsApp_. */
function wa_phone(?string $raw): ?string
{
    $p = preg_replace('/[^\d+]/', '', (string)$raw);
    if ($p === '') return null;
    if (str_starts_with($p, '+')) $p = substr($p, 1);
    if (str_starts_with($p, '00')) $p = substr($p, 2);
    if (preg_match('/^20\d{10}$/', $p)) return $p;
    if (preg_match('/^0\d{10}$/', $p)) return '20' . substr($p, 1);
    if (preg_match('/^1\d{9}$/', $p)) return '20' . $p;
    return null;
}

function wa_minutes(int $min): string
{
    $h = intdiv($min, 60); $m = $min % 60;
    if (!$h) return $m . ' دقيقة';
    return $h . ' ساعة' . ($m ? ' و' . $m . ' دقيقة' : '');
}

function wa_days_fraction(float $v): string
{
    $whole = (int)floor($v); $frac = round($v - $whole, 2);
    $fr = $frac == 0.25 ? 'ربع يوم' : ($frac == 0.5 ? 'نص يوم' : ($frac == 0.75 ? 'ثلاثة أرباع يوم' : ''));
    if (!$whole && $fr) return $fr;
    if ($whole && $fr) return $whole . ' يوم و' . $fr;
    return $whole . ' يوم';
}

/** The message text of one employee (old preview), in Arabic as the old system sent it. Returns [text|null, error|null]. */
function wa_message(string $type, array $emp, string $from, string $to, string $ym): array
{
    $name = $emp['name_ar'];
    if ($type === 'payroll') {
        [$pf, $pt, $per] = payroll_range($ym);
        $snap = $per && $per['status'] === 'closed' ? period_snapshot((int)$per['id']) : null;
        $p = $snap ? (snapshot_rows((int)$snap['id'])[(int)$emp['id']] ?? null) : payroll_compute((int)$emp['id'], $pf, $pt, $ym);
        if ($p === null) return [null, __('الموظف ده مش موجود في نسخة الإقفال.')];
        if (!empty($p['errors'])) return [null, implode(' / ', $p['errors'])];
        return ["مرحبًا أستاذ $name،\n\nكشف مرتبك للفترة من $pf إلى $pt:\n\nالراتب الأساسي: " . money($p['basic']) . " جنيه\nالاستحقاقات: " . money($p['additions'] ?? ($p['allowance'] + $p['earnings'])) . " جنيه\nإجمالي الخصومات: " . money($p['total_deductions']) . " جنيه\nصافي المرتب: " . money($p['net_final']) . " جنيه\n\nهذه رسالة آلية من نظام شئون العاملين — لا ترد على هذه الرسالة.", null];
    }
    $t = att_evaluate((int)$emp['id'], $from, $to)['totals'];
    if ($type === 'attendanceBrief') {
        return ["الأستاذ/ $name\n\nمن يوم $from وحتى $to:\n\nإجمالي التأخيرات: {$t['late_min']} دقيقة\nإجمالي الغيابات: {$t['absent']} يوم\nإجمالي الخصومات: " . num($t['total_days']) . ' يوم', null];
    }
    return ["أستاذ $name،\n\nمن يوم $from وحتى $to:\n\n• إجمالي التأخير: " . wa_minutes((int)$t['late_min']) . "\n• أيام الغياب: {$t['absent']} يوم\n• نسيان تسجيل الحضور/الانصراف: {$t['forgot']} يوم\n• إجمالي الخصم: " . wa_days_fraction((float)$t['total_days']) . "\n\nهذه رسالة آلية من نظام شئون العاملين — لا ترد على هذه الرسالة.", null];
}

function page_whatsapp(): void
{
    require_perm('whatsapp');
    $type = isset(WA_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : 'attendance';
    $f = report_filters($_GET);
    $sel = array_values(array_filter(array_map('intval', is_array($_GET['emps'] ?? null) ? $_GET['emps'] : [])));
    $messages = [];
    if (isset($_GET['go'])) {
        $list = $sel ? array_values(array_filter(report_emps(['dept' => [], 'job' => []] + $f), fn($e) => in_array((int)$e['id'], $sel, true)))
                     : ($f['dept'] ? report_emps($f, '(e.end_date IS NULL OR e.end_date >= CURDATE())') : []);
        $period = $type === 'payroll' ? $f['m'] : $f['from'] . '..' . $f['to'];
        $sent = [];
        foreach (q_all("SELECT target FROM audit_log WHERE action = 'whatsapp.sent' AND details = ?", [json_encode(['type' => $type, 'period' => $period], JSON_UNESCAPED_UNICODE)]) as $a) $sent[(int)$a['target']] = true;
        foreach (array_slice($list, 0, 300) as $e) {
            [$text, $err] = wa_message($type, $e, $f['from'], $f['to'], $f['m']);
            $phone = wa_phone($e['phone']);
            $messages[] = ['emp' => $e, 'text' => $text, 'error' => $err ?? ($phone ? null : __('رقم التليفون مش صحيح أو مش موجود.')), 'phone' => $phone, 'sent' => isset($sent[(int)$e['id']]), 'period' => $period];
        }
    }
    render('whatsapp', ['title' => __('رسائل واتساب'), 'type' => $type, 'f' => $f, 'sel' => $sel, 'messages' => $messages, 'emps' => employee_options(), 'depts' => fk_options(['fk' => ['departments', 'name']])]);
}

/** Old logSent: the user marks a message as sent from their browser; the server itself never sends anything. */
function page_whatsapp_post(): void
{
    require_perm('whatsapp', 'create');
    $emp = in_int('emp');
    $type = $_POST['type'] ?? '';
    if ($emp === null || !isset(WA_TYPES[$type]) || !can_see_employee($emp)) http_error(400);
    audit('whatsapp.sent', $emp, ['type' => $type, 'period' => in_str('period', 30)]);
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}

// ═════════════ Old Drive files: import from a folder the admin downloaded from Drive (no connection to Google) ═════════════

function drive_import_dir(): string
{
    $d = config('storage_dir') . '/import/drive';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

/** Old DocumentService._safe: the way file names were built in Drive. */
function drive_safe(string $s): string
{
    $s = mb_substr(preg_replace('#[\\\\/:*?"<>|]+#u', '_', trim($s)), 0, 40);
    return $s === '' ? 'x' : $s;
}

/** Arabic names from a ZIP may come decomposed; compare them in one form when intl is available. */
function drive_nfc(string $s): string
{
    return class_exists('Normalizer') ? (Normalizer::normalize($s, Normalizer::FORM_C) ?: $s) : $s;
}

/** All files under the import folder: basename => [paths]. */
function drive_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile()) $out[drive_nfc($file->getFilename())][] = $file->getPathname();
    return $out;
}

/**
 * Matches every record that still points at Drive to one downloaded file:
 *  - documents by the old name pattern «code_name_type_date.ext» (code, type and registration date must match);
 *  - investigation attachments by their stored Drive file name.
 * Returns ['docs' => [[id, path]], 'atts' => [[id, path]], 'missing' => n, 'ambiguous' => n, 'pending' => n].
 */
function drive_plan(string $dir): array
{
    $files = drive_files($dir);
    $plan = ['docs' => [], 'atts' => [], 'missing' => 0, 'ambiguous' => 0, 'pending' => 0];
    $docs = q_all("SELECT d.id, d.employee_id, d.created_at, t.name type_name FROM employee_documents d JOIN document_types t ON t.id = d.type_id WHERE d.file_ref LIKE 'drive:%'");
    foreach ($docs as $d) {
        $plan['pending']++;
        $prefix = drive_safe((string)$d['employee_id']) . '_';
        $type = '_' . drive_safe($d['type_name']) . '_';
        $date = $d['created_at'] ? substr($d['created_at'], 0, 10) : null;
        $cand = [];
        foreach ($files as $base => $paths) {
            if (!str_starts_with($base, $prefix) || !str_contains($base, $type) || !preg_match('/\.(pdf|jpe?g|png|webp)$/i', $base)) continue;
            if ($date && !preg_match('/_' . preg_quote($date, '/') . '\.\w+$/', $base)) continue;
            foreach ($paths as $p) $cand[] = $p;
        }
        if (count($cand) === 1) $plan['docs'][] = [(int)$d['id'], $cand[0]];
        elseif ($cand) $plan['ambiguous']++;
        else $plan['missing']++;
    }
    $atts = q_all("SELECT id, file_ref, file_name FROM investigation_attachments WHERE file_ref IS NOT NULL AND file_ref <> '' AND file_ref NOT LIKE 'inv%'");
    foreach ($atts as $a) {
        if (is_file(doc_dir() . '/' . basename((string)$a['file_ref']))) continue;
        $plan['pending']++;
        $name = drive_nfc((string)$a['file_name']);
        $cand = $files[$name] ?? [];
        if (count($cand) === 1) $plan['atts'][] = [(int)$a['id'], $cand[0]];
        elseif ($cand) $plan['ambiguous']++;
        else $plan['missing']++;
    }
    return $plan;
}

/** Copies the matched files into document storage and points the records at them. Returns [copied, refused]. */
function drive_apply(array $plan): array
{
    $copied = 0; $refused = 0;
    foreach (['docs' => 'employee_documents', 'atts' => 'investigation_attachments'] as $k => $table) {
        foreach ($plan[$k] as [$id, $path]) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
            if (!isset(DOC_MIME[$mime]) || filesize($path) > DOC_MAX_BYTES * 3) { $refused++; continue; }
            $name = ($k === 'docs' ? 'drive' : 'invdrive') . '-' . $id . '-' . bin2hex(random_bytes(6)) . '.' . DOC_MIME[$mime];
            if (!copy($path, doc_dir() . '/' . $name)) { $refused++; continue; }
            q("UPDATE `$table` SET file_ref = ? WHERE id = ?", [$name, $id]);
            $copied++;
        }
    }
    return [$copied, $refused];
}

function page_drive_import(): void
{
    require_manager();
    render('drive_import', ['title' => __('استيراد ملفات Drive'), 'plan' => drive_plan(drive_import_dir()), 'dir' => drive_import_dir(), 'files' => array_sum(array_map('count', drive_files(drive_import_dir())))]);
}

function page_drive_import_post(): void
{
    require_manager();
    @set_time_limit(600);
    $op = $_POST['op'] ?? '';
    if ($op === 'zip') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { flash('err', __('رفع الملف فشل، جرّب تاني.')); redirect(url('drive_import')); }
        $z = new ZipArchive();
        if ($z->open($f['tmp_name']) !== true) { flash('err', __('الملف لازم يكون ZIP.')); redirect(url('drive_import')); }
        $dest = drive_import_dir() . '/' . date('Ymd-His');
        @mkdir($dest, 0750, true);
        $n = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $entry = $z->getNameIndex($i);
            $base = basename(str_replace('\\', '/', (string)$entry));
            if ($base === '' || str_ends_with((string)$entry, '/') || !preg_match('/\.(pdf|jpe?g|png|webp)$/i', $base)) continue; // no paths from the archive are trusted
            if (($z->statIndex($i)['size'] ?? 0) > DOC_MAX_BYTES * 3) continue;
            $data = $z->getFromIndex($i);
            if ($data === false) continue;
            $target = $dest . '/' . $base;
            for ($k = 1; file_exists($target); $k++) $target = $dest . '/' . $k . '__' . $base;
            file_put_contents($target, $data);
            $n++;
        }
        $z->close();
        audit('drive.import.zip', $n);
        flash('ok', __('اتفك %d ملف من الـ ZIP.', $n));
    } elseif ($op === 'apply') {
        [$copied, $refused] = drive_apply(drive_plan(drive_import_dir()));
        audit('drive.import.apply', $copied, ['refused' => $refused]);
        flash($copied ? 'ok' : 'err', __('اتربط %d ملف بالسجلات بتاعته. ملفات اترفضت (مش صورة أو PDF): %d.', $copied, $refused));
    } else {
        http_error(400);
    }
    redirect(url('drive_import'));
}

// ═════════════ Data reset (old «إعادة ضبط بيانات النظام»): super admin, password, typed confirmation, backup first ═════════════

function page_data_reset(): void
{
    require_super();
    $groups = [];
    foreach (RESET_GROUPS as $k => [$label, $tables]) $groups[$k] = [$label, array_sum(array_map(fn($t) => (int)q_val("SELECT COUNT(*) FROM `$t`"), $tables))];
    render('data_reset', ['title' => __('إعادة ضبط البيانات'), 'groups' => $groups]);
}

function page_data_reset_post(): void
{
    require_super();
    @set_time_limit(600);
    $u = q_one('SELECT password_hash FROM users WHERE id = ?', [user()['id']]);
    if (!$u || !password_verify((string)($_POST['password'] ?? ''), (string)$u['password_hash'])) {
        audit('system.resetData.denied', 'password');
        flash('err', __('كلمة المرور غير صحيحة — ماتمّ حذف أي شيء.'));
        redirect(url('data_reset'));
    }
    if (in_str('confirm', 30) !== 'إعادة ضبط') { flash('err', __('للتأكيد اكتب «إعادة ضبط» بالظبط — ماتمّ حذف أي شيء.')); redirect(url('data_reset')); }
    [$err, $rows, $bid] = data_reset(is_array($_POST['groups'] ?? null) ? array_map('strval', $_POST['groups']) : [], user()['username']);
    flash($err ? 'err' : 'ok', $err ?? __('اتمسح %d صف، وفيه نسخة احتياطية قبلها (رقم %d).', $rows, $bid));
    redirect(url('data_reset'));
}
