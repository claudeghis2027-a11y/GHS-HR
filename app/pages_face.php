<?php
declare(strict_types=1);

// ═════════════ Employee: consent + enrollment ═════════════
/** Consent text in the UI language (the exact text shown is what gets recorded). */
function face_consent_text(): string
{
    $en = (string)setting('face_consent_text_en', '');
    return lang() === 'en' && $en !== '' ? $en : (string)setting('face_consent_text', '');
}

function page_face_consent(array $vars = []): void
{
    $e = self_employee();
    render('face_consent', $vars + [
        'title' => __('الموافقة على بصمة الوجه'), 'emp' => $e,
        'text' => face_consent_text(), 'version' => (int)setting('face_consent_version', '1'),
        'done' => face_has_consent((int)$e['id']),
    ]);
}

function page_face_consent_post(): void
{
    $e = self_employee();
    $version = (int)setting('face_consent_version', '1');
    if (($_POST['agree'] ?? '') !== '1' || (int)($_POST['version'] ?? 0) !== $version) {
        page_face_consent(['error' => __('لازم تعلّم على "أوافق" عشان تكمل.')]);
        return;
    }
    if (!face_has_consent((int)$e['id'])) {
        q('INSERT INTO face_consents (employee_id, user_id, consent_version, consent_text, ip, user_agent) VALUES (?,?,?,?,?,?)',
            [$e['id'], user()['id'], $version, '[' . lang() . '] ' . face_consent_text(), client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        audit('face.consent', $e['id'], ['version' => $version]);
    }
    redirect(url(face_gate_route((int)$e['id']) ?? 'checkin'));
}

function page_face_enroll(array $vars = []): void
{
    $e = self_employee();
    if (!face_has_consent((int)$e['id'])) redirect(url('face_consent'));
    $active = face_active_profile((int)$e['id']);
    render('face_enroll', $vars + [
        'title' => __('صورتك المرجعية لبصمة الوجه'), 'emp' => $e,
        'pending' => face_pending_profile((int)$e['id']),
        'approved' => q_one("SELECT id, reviewed_at FROM face_profiles WHERE employee_id = ? AND status = 'approved' LIMIT 1", [$e['id']]),
        'last_rejected' => q_one("SELECT reject_reason, reviewed_at FROM face_profiles WHERE employee_id = ? AND status = 'rejected' ORDER BY id DESC LIMIT 1", [$e['id']]),
        'has_active' => (bool)$active,
    ]);
}

function page_face_enroll_post(): void
{
    $e = self_employee();
    $id = (int)$e['id'];
    if (!face_has_consent($id)) redirect(url('face_consent'));
    if ($e['end_date'] && $e['end_date'] < date('Y-m-d')) http_error(403, __('خدمتك منتهية.'));
    $frames = face_frames_from_post();
    if (!$frames) { page_face_enroll(['error' => __('مفيش لقطات من الكاميرا. افتح الكاميرا وجرّب تاني.')]); return; }
    $r = face_call('/enroll', ['frames' => $frames, 'params' => face_params()]);
    unset($frames, $_POST['frames']);
    if (($r['code'] ?? '') !== 'service') face_log($id, 'enroll', empty($r['ok']) ? (string)($r['code'] ?? 'error') : 'ok', null, (array)($r['metrics'] ?? []));
    if (empty($r['ok'])) {
        audit('face.enroll_rejected', $id, ['code' => $r['code'] ?? '?']);
        page_face_enroll(['error' => face_message($r)]);
        return;
    }
    $photo = base64_decode((string)$r['photo'], true);
    $emb = base64_decode((string)$r['embedding'], true);
    if ($photo === false || $emb === false || strlen($emb) !== 512) { page_face_enroll(['error' => __('حصل خطأ. جرّب تاني.')]); return; }

    db()->beginTransaction();
    // Only one pending photo at a time: a newer submission replaces (and wipes) the older pending one.
    foreach (q_all("SELECT * FROM face_profiles WHERE employee_id = ? AND status = 'pending' FOR UPDATE", [$id]) as $old) {
        face_wipe_profile($old, 'replaced', 'new_submission');
    }
    try {
        $file = face_store_file((string)$id, $photo);
    } catch (RuntimeException $ex) {
        db()->rollBack();
        error_log(safe_error($ex));
        page_face_enroll(['error' => __('حصل خطأ في حفظ الصورة على السيرفر. كلّم الإدارة.')]);
        return;
    }
    q('INSERT INTO face_profiles (employee_id, status, photo_file, embedding_enc, metrics_json) VALUES (?,?,?,?,?)',
        [$id, 'pending', $file, face_encrypt($emb), json_encode($r['metrics'] ?? [])]);
    $pid = (int)db()->lastInsertId();
    audit('face.enroll_submitted', $id, ['profile' => $pid]);
    db()->commit();
    flash('ok', __('تم رفع صورتك وهي مستنية موافقة الإدارة. تقدر تبصم من دلوقتي.'));
    redirect(url('checkin'));
}

// ═════════════ Super admin: review reference photos ═════════════
function page_face_review(): void
{
    require_manager();
    $rows = q_all("SELECT p.id, p.employee_id, p.submitted_at, p.metrics_json, e.name_ar, e.official_photo, d.name dept,
                   (SELECT COUNT(*) FROM attendance_face af WHERE af.profile_id = p.id) punches,
                   (SELECT COUNT(*) FROM face_profiles p2 WHERE p2.employee_id = p.employee_id AND p2.status = 'approved') has_approved
                   FROM face_profiles p JOIN employees e ON e.id = p.employee_id LEFT JOIN departments d ON d.id = e.department_id
                   WHERE p.status = 'pending' ORDER BY p.submitted_at");
    render('face_review', ['title' => __('مراجعة صور بصمة الوجه'), 'rows' => $rows]);
}

function page_face_review_post(): void
{
    require_manager();
    $pid = in_int('id');
    $p = $pid ? q_one("SELECT * FROM face_profiles WHERE id = ? AND status = 'pending'", [$pid]) : null;
    if (!$p) { flash('err', __('الطلب ده اتراجع قبل كده.')); redirect(url('face_review')); }
    $emp = (int)$p['employee_id'];
    $by = user()['username'];
    db()->beginTransaction();
    if (($_POST['decision'] ?? '') === 'approve') {
        // A change after approval replaces the old reference: its photo and embedding are deleted now.
        foreach (q_all("SELECT * FROM face_profiles WHERE employee_id = ? AND status = 'approved' FOR UPDATE", [$emp]) as $old) {
            face_wipe_profile($old, 'replaced', 'superseded_by_' . $pid);
        }
        q("UPDATE face_profiles SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [$by, $pid]);
        q("UPDATE attendance_face SET flag = 'ok' WHERE profile_id = ? AND flag = 'unapproved_photo'", [$pid]);
        audit('face.approve', $emp, ['profile' => $pid]);
        face_notify($emp, 'تمت الموافقة على صورة بصمة الوجه / Face photo approved', "صورتك المرجعية اتوافق عليها.\n\nYour reference photo was approved.");
        flash('ok', __('تمت الموافقة.'));
    } else {
        $reason = in_str('reason', 500);
        if ($reason === '') { db()->rollBack(); flash('err', __('اكتب سبب الرفض.')); redirect(url('face_review')); }
        face_wipe_profile($p, 'rejected', 'rejected');  // rejected photo + embedding are deleted immediately
        q('UPDATE face_profiles SET reviewed_by = ?, reviewed_at = NOW(), reject_reason = ? WHERE id = ?', [$by, $reason, $pid]);
        // Every punch verified against the rejected photo goes to HR.
        q("UPDATE attendance_face SET flag = 'hr_review' WHERE profile_id = ?", [$pid]);
        $n = (int)q_val('SELECT COUNT(*) FROM attendance_face WHERE profile_id = ?', [$pid]);
        audit('face.reject', $emp, ['profile' => $pid, 'reason' => $reason, 'punches_to_hr' => $n]);
        face_notify($emp, 'صورة بصمة الوجه اترفضت / Face photo rejected',
            "السبب: $reason\nارفع صورة جديدة من صفحة \"صورة البصمة\".\n\nReason: $reason\nPlease upload a new photo from the \"Face photo\" page.");
        flash('ok', __('تم الرفض، و%d بصمة اتحولت لمراجعة HR.', $n));
    }
    db()->commit();
    redirect(url('face_review'));
}

/** Decrypts and streams a reference photo (super admin) or an official photo (super admin / HR editors). */
function page_face_img(): void
{
    $kind = $_GET['kind'] ?? '';
    $id = in_int('id', $_GET);
    if ($id === null) http_error(404);
    if ($kind === 'profile') {
        require_manager();
        $rel = q_val('SELECT photo_file FROM face_profiles WHERE id = ?', [$id]);
        audit('face.photo_viewed', $id);
    } elseif ($kind === 'official') {
        if (!is_manager() && !(can('employees', 'update') && can_see_employee($id))) http_error(403);
        $rel = q_val('SELECT official_photo FROM employees WHERE id = ?', [$id]);
    } else {
        http_error(404);
    }
    $bytes = face_read_file($rel ?: null);
    if ($bytes === null) http_error(404);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    header('Content-Type: ' . (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, private');
    header('Content-Disposition: inline');
    echo $bytes;
    exit;
}

// ═════════════ Official employee photo (from HR) ═════════════
function page_emp_photo_post(): void
{
    $id = in_int('id');
    if ($id === null || !can('employees', 'update') || !can_see_employee($id)) http_error(403);
    $back = users_back() ?? url('form', ['res' => 'employees', 'id' => $id]);
    if (($_POST['op'] ?? '') === 'delete') { // old employees.photo.delete
        $old = q_val('SELECT official_photo FROM employees WHERE id = ?', [$id]);
        if ($old) {
            q('UPDATE employees SET official_photo = NULL WHERE id = ?', [$id]);
            face_delete_file($old);
            q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, changed_by) VALUES (?,?,?,?,?)', [$id, 'official_photo', 'صورة سابقة', null, user()['username']]);
            audit('employee.official_photo_deleted', $id);
        }
        flash('ok', __('تم حذف الصورة.'));
        redirect($back);
    }
    $f = $_FILES['photo'] ?? null;
    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 3 * 1024 * 1024) {
        flash('err', __('اختار صورة JPG أو PNG أقل من 3 ميجا.')); redirect($back);
    }
    $bytes = (string)file_get_contents($f['tmp_name']);
    @unlink($f['tmp_name']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    $info = @getimagesizefromstring($bytes);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true) || !$info || $info[0] < 100 || $info[1] < 100) {
        flash('err', __('الملف لازم يكون صورة JPG أو PNG.')); redirect($back);
    }
    $old = q_val('SELECT official_photo FROM employees WHERE id = ?', [$id]);
    $rel = face_store_file('official', $bytes);
    q('UPDATE employees SET official_photo = ? WHERE id = ?', [$rel, $id]);
    face_delete_file($old ?: null);
    q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, changed_by) VALUES (?,?,?,?,?)', [$id, 'official_photo', $old ? 'صورة سابقة' : null, 'صورة جديدة', user()['username']]);
    audit('employee.official_photo', $id);
    flash('ok', __('تم حفظ الصورة الرسمية.'));
    redirect($back);
}

// ═════════════ HR: face review queue ═════════════
function page_face_hr(): void
{
    require_perm('attendance', 'update');
    [$w, $p] = scope_sql('e');
    $blocked = q_all("SELECT s.employee_id, s.face_fail_streak, s.updated_at, e.name_ar FROM employee_review_state s JOIN employees e ON e.id = s.employee_id
                      WHERE s.face_review_status = 'hr_review' AND $w ORDER BY s.updated_at", $p);
    $punches = q_all("SELECT af.*, a.work_date, a.check_in_at, a.check_out_at, e.name_ar, fp.status profile_status, fp.reject_reason
                      FROM attendance_face af JOIN attendance a ON a.id = af.attendance_id JOIN employees e ON e.id = af.employee_id
                      LEFT JOIN face_profiles fp ON fp.id = af.profile_id
                      WHERE af.flag IN ('hr_review', 'unapproved_photo') AND $w ORDER BY af.flag = 'hr_review' DESC, af.created_at DESC LIMIT 500", $p);
    render('face_hr', ['title' => __('مراجعة بصمات الوجه (HR)'), 'blocked' => $blocked, 'punches' => $punches]);
}

function page_face_hr_post(): void
{
    require_perm('attendance', 'update');
    $by = user()['username'];
    $op = $_POST['op'] ?? '';
    if ($op === 'unblock') {
        $emp = in_int('employee_id');
        if (!$emp || !can_see_employee($emp)) http_error(403);
        q("UPDATE employee_review_state SET face_fail_streak = 0, face_review_status = NULL, updated_at = NOW() WHERE employee_id = ?", [$emp]);
        audit('face.hr_unblock', $emp, in_str('note', 500));
        flash('ok', __('الموظف يقدر يبصم تاني.'));
    } elseif ($op === 'punch') {
        $id = in_int('id');
        $af = $id ? q_one("SELECT * FROM attendance_face WHERE id = ? AND flag = 'hr_review'", [$id]) : null;
        if (!$af || !can_see_employee((int)$af['employee_id'])) http_error(404);
        $valid = ($_POST['decision'] ?? '') === 'ok';
        $note = in_str('note', 500);
        db()->beginTransaction();
        q('UPDATE attendance_face SET flag = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?', [$valid ? 'hr_ok' : 'hr_invalid', $by, $note, $id]);
        if (!$valid) { // an invalid punch is removed from the attendance record (old value kept in the audit log)
            $col = $af['kind'] === 'in' ? 'check_in_at' : 'check_out_at';
            $old = q_val("SELECT $col FROM attendance WHERE id = ?", [$af['attendance_id']]);
            q("UPDATE attendance SET $col = NULL WHERE id = ?", [$af['attendance_id']]);
            audit('attendance.face_invalidated', $af['attendance_id'], ['kind' => $af['kind'], 'old' => $old, 'note' => $note]);
        } else {
            audit('attendance.face_accepted', $af['attendance_id'], ['kind' => $af['kind'], 'note' => $note]);
        }
        db()->commit();
        flash('ok', $valid ? __('البصمة اتقبلت.') : __('البصمة اتلغت من سجل الحضور.'));
    } else {
        http_error(400);
    }
    redirect(url('face_hr'));
}

// ═════════════ Super admin: tuning statistics ═════════════
function pct(array $v, float $p): ?float
{
    if (!$v) return null;
    sort($v);
    return round($v[(int)min(count($v) - 1, max(0, floor($p / 100 * (count($v) - 1))))], 3);
}

function page_face_stats(): void
{
    require_manager();
    $from = valid_date($_GET['from'] ?? null) ?? date('Y-m-d', strtotime('-30 days'));
    $to = valid_date($_GET['to'] ?? null) ?? date('Y-m-d');
    $rows = q_all('SELECT employee_id, at, kind, result, distance, counted, device, metrics FROM face_match_log WHERE kind IS NOT NULL AND at >= ? AND at < ? + INTERVAL 1 DAY ORDER BY at', [$from, $to]);
    if (export_format()) {
        audit('export.' . export_format(), 'face_stats', "$from..$to");
        send_export(__('محاولات بصمة الوجه'), "face-attempts-$from-$to", ['employee', 'time', 'kind', 'result', 'score', 'yaw_range', 'motion', 'brightness', 'sharpness', 'same_person', 'ms', 'device'],
            array_map(function ($r) {
                $m = json_decode((string)$r['metrics'], true) ?: [];
                return [$r['employee_id'], $r['at'], $r['kind'], $r['result'], $r['distance'], $m['yaw_range'] ?? '', $m['motion'] ?? '', $m['brightness'] ?? '', $m['sharpness'] ?? '', $m['same_person'] ?? '', $m['ms'] ?? '', $r['device']];
            }, $rows));
    }
    $by = []; $scores = ['match' => [], 'no_match' => []]; $yaw = ['pass' => [], 'no_turn' => []]; $ms = []; $dev = [];
    foreach ($rows as $r) {
        $m = json_decode((string)$r['metrics'], true) ?: [];
        $okRow = in_array($r['result'], ['match', 'ok'], true);
        $by[$r['result']] = ($by[$r['result']] ?? 0) + 1;
        $dev[$r['device']][$okRow ? 'ok' : 'fail'] = ($dev[$r['device']][$okRow ? 'ok' : 'fail'] ?? 0) + 1;
        if (isset($scores[$r['result']]) && $r['distance'] !== null) $scores[$r['result']][] = (float)$r['distance'];
        if (isset($m['yaw_range'])) {
            if (in_array($r['result'], ['match', 'no_match', 'ok'], true)) $yaw['pass'][] = (float)$m['yaw_range'];
            elseif ($r['result'] === 'no_turn') $yaw['no_turn'][] = (float)$m['yaw_range'];
        }
        if (isset($m['ms'])) $ms[] = (float)$m['ms'];
    }
    render('face_stats', ['title' => __('إحصائيات بصمة الوجه'), 'from' => $from, 'to' => $to, 'total' => count($rows), 'by' => $by, 'scores' => $scores, 'yaw' => $yaw, 'ms' => $ms, 'dev' => $dev]);
}
