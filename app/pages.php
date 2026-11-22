<?php
declare(strict_types=1);

// ═════════════ Auth ═════════════
function page_login(array $vars = []): void
{
    if (current_user()) redirect(url());
    render('login', $vars + ['title' => __('تسجيل الدخول'), 'bare' => true]);
}

function page_login_post(): void
{
    $username = in_str('username', 64);
    $password = is_string($_POST['password'] ?? null) ? mb_substr($_POST['password'], 0, 200) : '';
    if ($username === '' || $password === '') { page_login(['error' => __('اكتب اسم المستخدم وكلمة المرور.'), 'username' => $username]); return; }
    $r = attempt_login($username, $password);
    if (!$r['ok']) {
        if (($r['code'] ?? 0) === 429) http_response_code(429);
        usleep(random_int(200000, 400000)); // slow down brute force a bit
        page_login(['error' => $r['msg'], 'username' => $username]);
        return;
    }
    if (($_POST['remember'] ?? '') === '1' && !q_val('SELECT r.is_staff FROM roles r WHERE r.id = ?', [$r['user']['role_id']]) && $r['user']['employee_id'] !== null) {
        remember_issue($r['user']);
    }
    redirect(url());
}

/** Switches the UI language (cookie for everyone, saved on the account when logged in). */
function page_lang_post(): void
{
    $l = is_string($_POST['to'] ?? null) && isset(LANGS[$_POST['to']]) ? $_POST['to'] : 'ar';
    setcookie(LANG_COOKIE, $l, ['expires' => time() + 365 * 86400, 'path' => base_path() . '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    if ($u = current_user()) q('UPDATE users SET locale = ? WHERE id = ?', [$l, $u['id']]);
    $back = is_string($_POST['back'] ?? null) ? $_POST['back'] : '';
    $prefix = base_path() . '/index.php';
    redirect(($back === $prefix || str_starts_with($back, $prefix . '?')) && !preg_match('/[
]/', $back) ? $back : url());
}

function page_logout(): void
{
    audit('auth.logout', user()['username']);
    remember_forget();
    destroy_session();
    redirect(url('login'));
}

function page_password(array $vars = []): void
{
    render('password', $vars + ['title' => __('تغيير كلمة المرور')]);
}

function page_password_post(): void
{
    $u = user();
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $conf = (string)($_POST['confirm'] ?? '');
    $row = q_one('SELECT password_hash, legacy_hash, legacy_salt FROM users WHERE id = ?', [$u['id']]);
    $ok = $row['password_hash'] ? password_verify($cur, $row['password_hash']) : verify_legacy($cur, (string)$row['legacy_hash'], (string)$row['legacy_salt']);
    if (!$ok) { audit('password.change_failed', $u['username']); page_password(['error' => __('كلمة المرور الحالية غلط.')]); return; }
    if ($new !== $conf) { page_password(['error' => __('كلمة المرور الجديدة وتأكيدها مش متطابقين.')]); return; }
    if ($new === $cur) { page_password(['error' => __('اختار كلمة مرور مختلفة عن الحالية.')]); return; }
    if ($p = password_problems($new, $u['username'])) { page_password(['error' => $p]); return; }
    q('UPDATE users SET password_hash = ?, legacy_hash = NULL, legacy_salt = NULL, must_change_password = 0, password_changed_at = NOW(), session_epoch = session_epoch + 1 WHERE id = ?', [hash_password($new), $u['id']]);
    $_SESSION['epoch'] = (int)$u['session_epoch'] + 1; // keep this session, kill the others
    if (!empty($_COOKIE[remember_cookie()])) { remember_forget(); remember_issue(['session_epoch' => $_SESSION['epoch']] + $u); } // this device stays remembered
    session_regenerate_id(true);
    audit('password.changed', $u['username']);
    flash('ok', __('تم تغيير كلمة المرور.'));
    redirect(url());
}

// ═════════════ Dashboard ═════════════
function page_dashboard(): void
{
    $data = ['title' => __('الرئيسية')];
    if ($emp = my_employee_id()) {
        $data['today'] = q_one('SELECT * FROM attendance WHERE employee_id = ? AND work_date = CURDATE()', [$emp]);
        $data['unread'] = (int)q_val('SELECT COUNT(*) FROM notification_targets nt JOIN notifications n ON n.id = nt.notification_id
            WHERE nt.employee_id = ? AND nt.read_at IS NULL AND n.status <> "cancelled" AND nt.show_at <= NOW() AND (nt.hide_at IS NULL OR nt.hide_at > NOW())', [$emp]);
    }
    if (is_staff()) {
        [$w, $p] = scope_sql('e');
        $data['stats'] = [
            'employees' => (int)q_val("SELECT COUNT(*) FROM employees e WHERE $w AND (e.end_date IS NULL OR e.end_date >= CURDATE())", $p),
            'present'   => (int)q_val("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE $w AND a.work_date = CURDATE() AND a.check_in_at IS NOT NULL", $p),
            'locked'    => can('attendance', 'update') ? (int)q_val("SELECT COUNT(*) FROM employee_review_state s JOIN employees e ON e.id = s.employee_id WHERE $w AND s.face_review_status = 'hr_review'", $p) : null,
            'faces'     => is_manager() ? (int)q_val("SELECT COUNT(*) FROM face_profiles WHERE status = 'pending'") : null,
            'requests'  => is_manager() ? (int)q_val("SELECT COUNT(*) FROM profile_update_requests r JOIN employees e ON e.id = r.employee_id WHERE $w AND r.status = 'pending'", $p) : null,
            'docs'      => can('documents') ? (int)q_val("SELECT COUNT(*) FROM employee_documents d JOIN employees e ON e.id = d.employee_id WHERE $w AND d.expiry_date IS NOT NULL AND d.expiry_date <= CURDATE() + INTERVAL ? DAY", array_merge($p, [(int)setting('doc_expiry_alert_days', 30)])) : null,
        ];
        $data['no_points'] = can('rules') && !q_val('SELECT 1 FROM attendance_points WHERE active = 1 AND lat IS NOT NULL AND lng IS NOT NULL');
    }
    render('dashboard', $data);
}

// ═════════════ Employee: check-in ═════════════
function self_employee(): array
{
    $id = require_employee_self();
    $e = q_one('SELECT e.*, d.attendance_exempt dept_exempt FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ?', [$id]);
    if (!$e) http_error(403);
    return $e;
}

/** The attendance point nearest to a reading, and whether the reading is inside it (its radius + up to 30 m of GPS error). */
function geo_nearest(float $lat, float $lng, float $acc): array
{
    $best = null;
    foreach (q_all('SELECT * FROM attendance_points WHERE active = 1 AND lat IS NOT NULL AND lng IS NOT NULL') as $p) {
        $d = haversine($lat, $lng, (float)$p['lat'], (float)$p['lng']);
        if ($best === null || $d - $p['radius_m'] < $best['d'] - $best['p']['radius_m']) $best = ['p' => $p, 'd' => $d];
    }
    return [$best, $best && $best['d'] <= $best['p']['radius_m'] + min($acc, 30)];
}

// ── Old employee app location gate: an employee's own data, documents and reports open only inside the school ──
const GEO_GATE_SECONDS = 600; // one check is good for 10 minutes

function geo_gate_active(): bool
{
    return my_employee_id() !== null && !is_staff() && setting('self_geo_gate', '1') === '1'
        && q_val('SELECT 1 FROM attendance_points WHERE active = 1 AND lat IS NOT NULL AND lng IS NOT NULL LIMIT 1');
}

function geo_gate_ok(): bool
{
    return !geo_gate_active() || (int)($_SESSION['geo_ok_until'] ?? 0) >= time();
}

/** Shows the location check (on the same address) instead of the page until the employee is inside the school. */
function geo_gate_require(): void
{
    if (geo_gate_ok()) return;
    $q = array_filter($_GET, fn($v, $k) => is_string($v) && is_string($k), ARRAY_FILTER_USE_BOTH);
    render('geo_check', ['title' => __('التحقق من الموقع'), 'back' => url('', $q)]);
    exit;
}

function page_geo_check_post(): void
{
    require_employee_self();
    $back = users_back() ?? url();
    $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $acc = filter_var($_POST['acc'] ?? null, FILTER_VALIDATE_FLOAT);
    $ok = false;
    if ($lat !== false && $lng !== false && $lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
        [, $ok] = geo_nearest((float)$lat, (float)$lng, ($acc === false || $acc === null) ? 9999.0 : max(0.0, (float)$acc));
    }
    if ($ok) { $_SESSION['geo_ok_until'] = time() + GEO_GATE_SECONDS; redirect($back); }
    unset($_SESSION['geo_ok_until']);
    audit('geo_gate.outside', my_employee_id(), $lat === false || $lat === null ? 'no_fix' : 'outside');
    render('geo_check', ['title' => __('التحقق من الموقع'), 'back' => $back, 'outside' => true]);
}

/** Seconds left before the next check-in attempt is allowed. */
function checkin_wait(): int
{
    return max(0, (int)setting('checkin_cooldown_sec', '30') - (time() - (int)($_SESSION['checkin_last'] ?? 0)));
}

function page_checkin(): void
{
    $e = self_employee();
    if ($gate = face_gate_route((int)$e['id'])) redirect(url($gate));
    render('checkin', [
        'face' => face_enabled(), 'pending_photo' => (bool)face_pending_profile((int)$e['id']) && !q_val("SELECT 1 FROM face_profiles WHERE employee_id = ? AND status = 'approved'", [$e['id']]),
        'hr_review' => face_streak((int)$e['id'])['status'] === 'hr_review',
        'title' => __('تسجيل الحضور والانصراف'), 'emp' => $e,
        'today' => q_one('SELECT * FROM attendance WHERE employee_id = ? AND work_date = CURDATE()', [$e['id']]),
        'require_photo' => setting('checkin_require_photo', '0') === '1', 'wait' => checkin_wait(),
    ]);
}

function page_checkin_post(): void
{
    $e = self_employee();
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['in', 'out'], true)) http_error(400);
    // Old app: 30 seconds between attempts. A repeat of the attempt already handled (the browser retrying after a lost reply) just shows its result.
    $attempt = is_string($_POST['attempt'] ?? null) && preg_match('/^[A-Za-z0-9-]{8,40}$/', $_POST['attempt']) ? $_POST['attempt'] : '';
    if ($attempt !== '' && $attempt === ($_SESSION['checkin_attempt'] ?? null)) redirect(url('checkin'));
    if (($left = checkin_wait()) > 0) { flash('err', __('استنى %d ثانية قبل المحاولة الجاية.', $left)); redirect(url('checkin')); }
    $_SESSION['checkin_last'] = time();
    $_SESSION['checkin_attempt'] = $attempt;
    $fail = function (string $m) use ($action) { audit("checkin.$action.rejected", null, $m); flash('err', $m); redirect(url('checkin')); };
    if ($e['end_date'] && $e['end_date'] < date('Y-m-d')) $fail(__('خدمتك منتهية.'));
    if ($e['attendance_exempt'] || $e['dept_exempt']) $fail(__('إنت معفى من البصمة، مش محتاج تسجل.'));

    $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $acc = filter_var($_POST['acc'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat === null || abs($lat) > 90 || abs($lng) > 180) $fail(__('مقدرناش نحدد موقعك. فعّل الـ GPS واسمح للمتصفح بالموقع.'));
    $acc = ($acc === false || $acc === null) ? 9999.0 : max(0.0, $acc);

    [$best, $within] = geo_nearest((float)$lat, (float)$lng, $acc);
    if (setting('geofence_enforce', '1') === '1' && !$within) {
        $fail($best ? __('إنت بعيد عن أقرب نقطة حضور (%s) بمسافة %d متر.', $best['p']['name'], (int)$best['d']) : __('مفيش نقاط حضور متسجلة. كلّم الإدارة.'));
    }

    // Face verification (frames are only held in memory and dropped after the comparison).
    $faceRow = null;
    if (face_enabled()) {
        if ($gate = face_gate_route((int)$e['id'])) redirect(url($gate));
        if (face_streak((int)$e['id'])['status'] === 'hr_review') $fail(__('بصمة الوجه فشلت أكتر من مرة واتحولت لمراجعة الـ HR. كلّم الـ HR عشان يفتحولك البصمة.'));
        $frames = face_frames_from_post();
        if (!$frames) $fail(__('مفيش لقطات من الكاميرا. اسمح للمتصفح بالكاميرا وجرّب تاني.'));
        $prof = face_active_profile((int)$e['id']);
        $ref = $prof ? face_decrypt((string)$prof['embedding_enc']) : null;
        if ($ref === null) $fail(__('مفيش صورة مرجعية صالحة. ارفع صورتك من "صورة البصمة".'));
        $r = face_call('/verify', ['frames' => $frames, 'reference' => base64_encode($ref), 'params' => face_params()]);
        unset($frames, $ref, $_POST['frames']);
        if (in_array($r['code'] ?? '', ['service', 'busy'], true)) $fail(face_message($r));
        // Liveness failures and non-matches count toward the HR-review streak; camera/quality problems don't.
        $counts = empty($r['ok']) ? in_array($r['code'] ?? '', ['no_turn', 'no_motion', 'inconsistent'], true) : empty($r['match']);
        if (empty($r['ok']) || empty($r['match'])) {
            $res = empty($r['ok']) ? (string)($r['code'] ?? 'error') : 'no_match';
            $score = isset($r['score']) ? (float)$r['score'] : null;
            if ($counts) {
                $st = face_record_result((int)$e['id'], false, $score, $res, $action, (array)($r['metrics'] ?? []));
            } else {
                face_log((int)$e['id'], $action, $res, $score, (array)($r['metrics'] ?? []));
                $st = face_streak((int)$e['id']) + ['max' => (int)setting('face_max_failures', '3')];
            }
            $msg = empty($r['ok']) ? face_message($r) : __('الوش مش مطابق لصورتك المرجعية.');
            if ($counts) $msg .= ' ' . ($st['status'] === 'hr_review' ? __('اتحولت لمراجعة الـ HR.') : __('(محاولة %d من %d)', $st['streak'], $st['max']));
            $fail($msg);
        }
        face_record_result((int)$e['id'], true, (float)$r['score'], 'match', $action, (array)($r['metrics'] ?? []));
        $faceRow = ['profile_id' => (int)$prof['id'], 'score' => (float)$r['score'], 'flag' => $prof['status'] === 'approved' ? 'ok' : 'unapproved_photo'];
    }

    $photo = null;
    $raw = face_enabled() ? '' : ($_POST['photo'] ?? '');
    if (is_string($raw) && $raw !== '') {
        if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $raw, $m) || strlen($m[1]) > 1100000) $fail(__('الصورة غير صالحة.'));
        $bin = base64_decode($m[1], true);
        if ($bin === false || (new finfo(FILEINFO_MIME_TYPE))->buffer($bin) !== 'image/jpeg') $fail(__('الصورة غير صالحة.'));
        $dir = config('storage_dir') . '/uploads/attendance/' . $e['id'];
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $photo = $e['id'] . '/' . date('Ymd-His') . "-$action-" . bin2hex(random_bytes(6)) . '.jpg';
        file_put_contents(config('storage_dir') . '/uploads/attendance/' . $photo, $bin);
    } elseif (!face_enabled() && setting('checkin_require_photo', '0') === '1') {
        $fail(__('لازم تصور صورة سيلفي مع البصمة.'));
    }

    $now = date('Y-m-d H:i:s');
    $point = $best['p']['name'] ?? null;
    $dist = $best ? round($best['d'], 1) : null;
    db()->beginTransaction();
    $row = q_one('SELECT * FROM attendance WHERE employee_id = ? AND work_date = CURDATE() FOR UPDATE', [$e['id']]);
    if ($action === 'in') {
        if ($row && $row['check_in_at']) { db()->rollBack(); $fail(__('سجلت حضورك النهارده بالفعل الساعة %s.', substr($row['check_in_at'], 11, 5))); }
        q('INSERT INTO attendance (employee_id, work_date, check_in_at, check_in_point, check_in_distance, check_in_lat, check_in_lng, check_in_photo, source)
           VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, "web")', [$e['id'], $now, $point, $dist, $lat, $lng, $photo]);
        $attId = (int)db()->lastInsertId();
    } else {
        if (!$row || !$row['check_in_at']) { db()->rollBack(); $fail(__('لازم تسجل حضور الأول.')); }
        if ($row['check_out_at']) { db()->rollBack(); $fail(__('سجلت انصرافك بالفعل الساعة %s.', substr($row['check_out_at'], 11, 5))); }
        q('UPDATE attendance SET check_out_at = ?, check_out_point = ?, check_out_distance = ?, check_out_lat = ?, check_out_lng = ?, check_out_photo = ? WHERE id = ?',
            [$now, $point, $dist, $lat, $lng, $photo, $row['id']]);
        $attId = (int)$row['id'];
    }
    if ($faceRow) {
        q('INSERT INTO attendance_face (attendance_id, employee_id, kind, profile_id, score, flag) VALUES (?,?,?,?,?,?)',
            [$attId, $e['id'], $action, $faceRow['profile_id'], $faceRow['score'], $faceRow['flag']]);
    }
    audit("checkin.$action", $e['id'], ['point' => $point, 'distance' => $dist, 'acc' => $acc] + ($faceRow ? ['face_score' => $faceRow['score'], 'face_flag' => $faceRow['flag']] : []));
    db()->commit();
    flash('ok', $point ? __($action === 'in' ? 'تم تسجيل الحضور الساعة %s عند %s.' : 'تم تسجيل الانصراف الساعة %s عند %s.', date('H:i'), $point)
                        : __($action === 'in' ? 'تم تسجيل الحضور الساعة %s.' : 'تم تسجيل الانصراف الساعة %s.', date('H:i')));
    redirect(url('checkin'));
}

function page_photo(): void
{
    $id = in_int('id', $_GET);
    $kind = ($_GET['kind'] ?? '') === 'out' ? 'check_out_photo' : 'check_in_photo';
    $a = $id ? q_one('SELECT employee_id, check_in_photo, check_out_photo FROM attendance WHERE id = ?', [$id]) : null;
    if (!$a || !$a[$kind]) http_error(404);
    $own = my_employee_id() === (int)$a['employee_id'];
    if (!$own && !(can('attendance') && can_see_employee((int)$a['employee_id']))) http_error(403);
    $base = realpath(config('storage_dir') . '/uploads/attendance');
    $file = realpath($base . '/' . $a[$kind]);
    if (!$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) http_error(404);
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// ═════════════ Attendance reports ═════════════
/**
 * The period of one employee's report (old employee app «من / إلى»): a payroll period, any from/to range
 * (at most a year), a calendar month (?m=, older links), or else the current payroll period.
 */
function att_pick_range(): array
{
    $periods = q_all('SELECT * FROM payroll_periods ORDER BY date_from DESC LIMIT 36');
    $per = ($pid = in_int('period', $_GET)) !== null ? q_one('SELECT * FROM payroll_periods WHERE id = ?', [$pid]) : null;
    $gf = valid_date($_GET['from'] ?? null); $gt = valid_date($_GET['to'] ?? null);
    if ($per) [$from, $to] = [$per['date_from'], $per['date_to']];
    elseif ($gf && $gt && $gf <= $gt && strtotime($gt) - strtotime($gf) <= 366 * 86400) [$from, $to] = [$gf, $gt];
    elseif ($ym = valid_month($_GET['m'] ?? null)) [$from, $to] = month_range($ym);
    else [$from, $to, $per] = payroll_range(date('Y-m'));
    return ['from' => $from, 'to' => $to, 'ym' => substr($from, 0, 7), 'per' => $per, 'periods' => $periods];
}

function page_my_attendance(): void
{
    $id = require_employee_self();
    $rg = att_pick_range();
    render('att_days', $rg + ['title' => __('حضوري'), 'r' => att_evaluate($id, $rg['from'], $rg['to']), 'self' => true]);
}

function page_att_employee(): void
{
    require_perm('attendance');
    $id = in_int('emp', $_GET);
    if ($id === null || !can_see_employee($id)) http_error(403);
    $rg = att_pick_range();
    render('att_days', $rg + ['title' => __('تفاصيل حضور موظف'), 'r' => att_evaluate($id, $rg['from'], $rg['to']), 'self' => false]);
}

function page_att_report(): void
{
    require_perm('attendance');
    // Old «اقفال شهري»: an official period, or any from/to range, or a calendar month.
    $periods = q_all('SELECT * FROM payroll_periods ORDER BY date_from DESC');
    $per = null;
    if (($pid = in_int('period', $_GET)) !== null) $per = q_one('SELECT * FROM payroll_periods WHERE id = ?', [$pid]);
    $gf = valid_date($_GET['from'] ?? null); $gt = valid_date($_GET['to'] ?? null);
    if ($per) { [$from, $to] = [$per['date_from'], $per['date_to']]; $ym = substr($from, 0, 7); }
    elseif ($gf && $gt && $gf <= $gt) { [$from, $to] = [$gf, $gt]; $ym = substr($from, 0, 7); }
    else { $ym = valid_month($_GET['m'] ?? null) ?? date('Y-m'); [$from, $to, $per] = payroll_range($ym); }
    [$w, $p] = scope_sql('e');
    $dept = in_int('dept', $_GET);
    $sql = "SELECT e.id FROM employees e WHERE $w AND (e.hire_date IS NULL OR e.hire_date <= ?) AND (e.end_date IS NULL OR e.end_date >= ?)";
    $p = array_merge($p, [$to, $from]);
    if ($dept) { $sql .= ' AND e.department_id = ?'; $p[] = $dept; }
    $rows = [];
    foreach (q_all($sql . ' ORDER BY e.id', $p) as $r) {
        $x = att_evaluate((int)$r['id'], $from, $to);
        $rows[] = ['emp' => $x['emp'], 't' => $x['totals']];
    }
    if (export_format()) {
        require_perm('attendance', 'export');
        audit('export.' . export_format(), 'att_report', $ym);
        send_export(__('تقرير الحضور الشهري'), "attendance-$from-$to", array_merge([__('الكود'), __('الاسم'), __('القسم'), __('أيام العمل'), __('حضور')], att_breakdown_headers()),
            array_map(fn($r) => array_merge([$r['emp']['id'], $r['emp']['name_ar'], $r['emp']['dept_name'], $r['t']['work_days'], $r['t']['present']], array_map(fn($v) => is_float($v) ? num($v) : $v, att_breakdown_values($r['t']))), $rows));
    }
    render('att_report', ['title' => __('تقرير الحضور الشهري'), 'ym' => $ym, 'rows' => $rows, 'dept' => $dept, 'depts' => fk_options(['fk' => ['departments', 'name']]),
        'from' => $from, 'to' => $to, 'per' => $per, 'periods' => $periods, 'noRule' => array_sum(array_map(fn($r) => (int)$r['t']['absent_no_rule'], $rows))]);
}

// ═════════════ Salary ═════════════
function page_my_salary(): void
{
    $id = require_employee_self();
    $ym = valid_month($_GET['m'] ?? null) ?? date('Y-m');
    [$from, $to, $per] = payroll_range($ym);
    $snap = $per && $per['status'] === 'closed' ? period_snapshot((int)$per['id']) : null;
    $pp = $snap ? (snapshot_rows((int)$snap['id'])[$id] ?? null) : payroll_compute($id, $from, $to, $ym);
    if ($pp === null) http_error(404, __('مفيش مرتب محفوظ ليك في الفترة دي.'));
    render('payslip', ['title' => __('مرتبي'), 'ym' => $ym, 'per' => $per, 'p' => $pp, 'self' => true, 'snap' => $snap]);
}

function page_payroll(): void
{
    require_perm('payroll');
    $ym = valid_month($_GET['m'] ?? null) ?? date('Y-m');
    [$from, $to, $per] = payroll_range($ym);
    $emp = in_int('emp', $_GET);
    $dept = in_int('dept', $_GET);
    $job = in_int('job', $_GET);
    // Old monthlyClosing: a closed period is shown from its snapshot only, never recalculated live.
    $snap = $per && $per['status'] === 'closed' ? period_snapshot((int)$per['id']) : null;
    if ($per && $per['status'] === 'closed' && !$snap) {
        flash('err', __('الفترة "%s" مقفولة، لكن مفيش نسخة إقفال محفوظة ليها. الأرقام مش هتتعرض عشان متبانش كأنها محمية وهي بتتحسب حيًّا. افتح الفترة تاني وأقفلها من جديد.', period_label($per)));
        redirect(url('period', ['id' => $per['id']]));
    }
    if ($emp !== null) {
        if (!can_see_employee($emp)) http_error(403);
        $pp = $snap ? (snapshot_rows((int)$snap['id'])[$emp] ?? null) : payroll_compute($emp, $from, $to, $ym);
        if ($pp === null) http_error(404, __('الموظف ده مش موجود في نسخة الإقفال.'));
        render('payslip', ['title' => __('مفردات مرتب'), 'ym' => $ym, 'per' => $per, 'p' => $pp, 'self' => false, 'snap' => $snap]);
        return;
    }
    if ($snap) {
        $rows = array_values(snapshot_rows((int)$snap['id'], $dept, $job));
        payroll_export_csv($ym, $rows);
        render('payroll', ['title' => __('كشف المرتبات'), 'ym' => $ym, 'per' => $per, 'from' => $snap['date_from'], 'to' => $snap['date_to'], 'rows' => $rows, 'periods' => [], 'dept' => $dept, 'job' => $job, 'snap' => $snap, 'gaps' => [],
            'depts' => fk_options(['fk' => ['departments', 'name']]), 'jobs' => fk_options(['fk' => ['job_titles', 'name']])]);
        return;
    }
    [$w, $p] = scope_sql('e');
    $sql = "SELECT e.id FROM employees e WHERE $w AND (e.hire_date IS NULL OR e.hire_date <= ?) AND (e.end_date IS NULL OR e.end_date >= ?)";
    $p = array_merge($p, [$to, $from]);
    if ($dept) { $sql .= ' AND e.department_id = ?'; $p[] = $dept; }
    if ($job) { $sql .= ' AND e.job_title_id = ?'; $p[] = $job; }
    $rows = [];
    foreach (q_all($sql . ' ORDER BY e.id', $p) as $r) {
        $rows[] = payroll_compute((int)$r['id'], $from, $to, $ym);
    }
    payroll_export_csv($ym, $rows);
    $periods = q_all("SELECT * FROM payroll_periods WHERE status = 'open' ORDER BY date_from DESC");
    $gaps = period_gaps();
    render('payroll', ['title' => __('كشف المرتبات'), 'ym' => $ym, 'per' => $per, 'from' => $from, 'to' => $to, 'rows' => $rows, 'periods' => $periods, 'dept' => $dept, 'job' => $job, 'snap' => null, 'gaps' => $gaps,
        'depts' => fk_options(['fk' => ['departments', 'name']]), 'jobs' => fk_options(['fk' => ['job_titles', 'name']])]);
}

/** Closes a payroll period (old ClosingService.close): pay + attendance grid snapshot; gaps between periods need an explicit OK. */
function page_payroll_close(): void
{
    require_perm('period_close', 'update');
    if (dept_scope() !== null) http_error(403, __('إقفال الفترة متاح بس لمستخدم صلاحيته على كل الأقسام.'));
    $pid = in_int('period_id');
    if ($pid === null) http_error(400);
    try {
        $r = period_close($pid, user()['username'], (string)in_str('reason', 500), ($_POST['ack_gaps'] ?? '') === '1');
    } catch (RuntimeException $ex) {
        flash('err', $ex->getMessage());
        redirect(url('period', ['id' => $pid]));
    }
    if (!$r['ok']) {
        flash('err', __('فيه أيام مش متغطية بين الفترات الرسمية: %s. علّم على «موافق على الفجوات» عشان تقفل.', gaps_text($r['gaps'])));
        redirect(url('period', ['id' => $pid]));
    }
    flash('ok', __('تم إقفال الفترة وحفظ نسخة من المرتبات وشبكة الحضور (%d موظف).', $r['employees']));
    redirect(url('period', ['id' => $pid]));
}

function payroll_export_csv(string $ym, array $rows): void
{
    if (export_format()) {
        require_perm('payroll', 'export');
        audit('export.' . export_format(), 'payroll', $ym);
        send_export(__('الرواتب %s', $ym), "payroll-$ym", payroll_table_headers(), array_map(fn($r) => array_map(fn($v) => is_float($v) ? money($v) : $v, payroll_table_row($r)), $rows));
    }
}

// ═════════════ Profile & requests ═════════════
function editable_fields(): array
{
    return q_all("SELECT * FROM employee_field_permissions WHERE visible = 1 ORDER BY sort_order, field = 'specialization_id'");
}

function page_my_profile(array $vars = []): void
{
    $e = self_employee();
    render('my_profile', $vars + [
        'title' => __('بياناتي'), 'emp' => $e, 'fields' => editable_fields(),
        'pending' => q_one("SELECT * FROM profile_update_requests WHERE employee_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$e['id']]),
        'last' => q_one("SELECT * FROM profile_update_requests WHERE employee_id = ? AND status <> 'pending' ORDER BY id DESC LIMIT 1", [$e['id']]),
    ]);
}

function page_my_profile_post(): void
{
    $e = self_employee();
    if (q_val("SELECT 1 FROM profile_update_requests WHERE employee_id = ? AND status = 'pending'", [$e['id']])) {
        flash('err', __('عندك طلب تعديل لسه تحت المراجعة.'));
        redirect(url('my_profile'));
    }
    $changes = [];
    $errors = [];
    foreach (editable_fields() as $f) {
        if (!$f['editable']) continue;
        $k = $f['field'];
        if (!in_array($k, ['name_ar', 'name_en', 'phone', 'nationality'], true)) continue; // free-text fields only
        $v = in_str($k, 150);
        if ($f['required'] && $v === '') $errors[] = __('%s مطلوب.', __($f['label']));
        if ($k === 'phone' && $v !== '' && !preg_match('/^[0-9+\- ]{6,30}$/', $v)) $errors[] = __('رقم الهاتف غير صحيح.');
        if ($v !== (string)($e[$k] ?? '')) $changes[$k] = $v;
    }
    if ($errors) { page_my_profile(['errors' => $errors]); return; }
    if (!$changes) { flash('err', __('مفيش أي تغيير.')); redirect(url('my_profile')); }
    q('INSERT INTO profile_update_requests (employee_id, fields_json) VALUES (?,?)', [$e['id'], json_encode($changes, JSON_UNESCAPED_UNICODE)]);
    audit('profile.request', $e['id'], $changes);
    flash('ok', __('تم إرسال طلب التعديل للإدارة.'));
    redirect(url('my_profile'));
}

function page_requests(): void
{
    require_manager(); // profile/name change approvals: super admin and admin only
    [$w, $p] = scope_sql('e');
    $rows = q_all("SELECT r.*, e.name_ar, e.name_en, e.phone, e.nationality FROM profile_update_requests r JOIN employees e ON e.id = r.employee_id
                   WHERE $w ORDER BY r.status = 'pending' DESC, r.id DESC LIMIT 200", $p);
    render('requests', ['title' => __('طلبات تعديل البيانات'), 'rows' => $rows]);
}

function page_requests_post(): void
{
    require_manager();
    $id = in_int('id');
    $r = $id ? q_one("SELECT * FROM profile_update_requests WHERE id = ? AND status = 'pending'", [$id]) : null;
    if (!$r || !can_see_employee((int)$r['employee_id'])) http_error(404);
    $by = user()['username'];
    if (($_POST['decision'] ?? '') === 'approve') {
        $allowed = array_column(array_filter(editable_fields(), fn($f) => $f['editable']), 'field');
        $fields = array_intersect_key(json_decode($r['fields_json'], true) ?: [], array_flip(array_intersect($allowed, ['name_ar', 'name_en', 'phone', 'nationality'])));
        db()->beginTransaction();
        $old = q_one('SELECT * FROM employees WHERE id = ?', [$r['employee_id']]);
        $op = bin2hex(random_bytes(8));
        foreach ($fields as $k => $v) {
            q("UPDATE employees SET `$k` = ? WHERE id = ?", [$v === '' ? null : $v, $r['employee_id']]);
            q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?,?)', [$r['employee_id'], $k, $old[$k], $v, 'طلب تعديل من الموظف', $by, $op]);
        }
        q("UPDATE profile_update_requests SET status = 'approved', reviewer = ?, reviewed_at = NOW() WHERE id = ?", [$by, $id]);
        audit('profile.approve', $r['employee_id'], $fields);
        db()->commit();
        flash('ok', __('تم اعتماد التعديل.'));
    } else {
        $reason = in_str('reason', 500);
        q("UPDATE profile_update_requests SET status = 'rejected', reviewer = ?, reviewed_at = NOW(), reject_reason = ? WHERE id = ?", [$by, $reason, $id]);
        audit('profile.reject', $r['employee_id'], $reason);
        flash('ok', __('تم رفض الطلب.'));
    }
    redirect(url('requests'));
}

// ═════════════ Notifications inbox ═════════════
function page_inbox(): void
{
    $id = require_employee_self();
    $f = in_array($_GET['f'] ?? '', ['unread', 'read'], true) ? $_GET['f'] : 'all';
    $rows = q_all('SELECT n.id, n.ntype, n.title, n.body, n.contents, n.period_month, n.report_from, n.report_to, nt.show_at, nt.read_at FROM notification_targets nt JOIN notifications n ON n.id = nt.notification_id
                   WHERE nt.employee_id = ? AND n.status <> "cancelled" AND nt.show_at <= NOW() AND (nt.read_at IS NOT NULL OR nt.hide_at IS NULL OR nt.hide_at > NOW())'
                   . ($f === 'unread' ? ' AND nt.read_at IS NULL' : ($f === 'read' ? ' AND nt.read_at IS NOT NULL' : '')) . '
                   ORDER BY nt.show_at DESC LIMIT 100', [$id]);
    render('inbox', ['title' => __('الإشعارات'), 'rows' => $rows, 'f' => $f, 'unread' => inbox_unread($id)]);
}

/** Notifications showing now that the employee hasn't read (the count next to «الإشعارات»). */
function inbox_unread(int $empId): int
{
    static $c = [];
    return $c[$empId] ??= (int)q_val('SELECT COUNT(*) FROM notification_targets nt JOIN notifications n ON n.id = nt.notification_id
        WHERE nt.employee_id = ? AND n.status <> "cancelled" AND nt.read_at IS NULL AND nt.show_at <= NOW() AND (nt.hide_at IS NULL OR nt.hide_at > NOW())', [$empId]);
}

/** Old app formatRelative: «منذ ساعتين», «منذ 3 أيام»; older than a week shows the date. */
function rel_time(string $dt, ?int $now = null): string
{
    $s = max(0, ($now ?? time()) - strtotime($dt));
    if ($s < 60) return __('الآن');
    if ($s < 3600) return __('منذ %d دقيقة', intdiv($s, 60));
    if ($s < 86400) { $h = intdiv($s, 3600); return $h === 1 ? __('منذ ساعة') : ($h === 2 ? __('منذ ساعتين') : __('منذ %d ساعات', $h)); }
    $d = intdiv($s, 86400);
    if ($d < 7) return $d === 1 ? __('منذ يوم') : ($d === 2 ? __('منذ يومين') : __('منذ %d أيام', $d));
    return substr($dt, 0, 10);
}

function page_inbox_post(): void
{
    $id = require_employee_self();
    $nid = in_int('id');
    if ($nid) q('UPDATE notification_targets SET read_at = NOW() WHERE notification_id = ? AND employee_id = ? AND read_at IS NULL', [$nid, $id]);
    redirect(url('inbox', in_array($_POST['f'] ?? '', ['unread', 'read'], true) ? ['f' => $_POST['f']] : []));
}

/** A notification opened by its employee: a report notice shows the report itself (old attendance app op "report"). */
function page_notif_view(): void
{
    $id = require_employee_self();
    $nid = in_int('id', $_GET);
    $n = $nid ? q_one('SELECT n.*, nt.show_at, nt.read_at FROM notification_targets nt JOIN notifications n ON n.id = nt.notification_id
                       WHERE nt.notification_id = ? AND nt.employee_id = ? AND n.status <> "cancelled" AND nt.show_at <= NOW()
                         AND (nt.read_at IS NOT NULL OR nt.hide_at IS NULL OR nt.hide_at > NOW())', [$nid, $id]) : null;
    if (!$n) http_error(404);
    if (!$n['read_at']) q('UPDATE notification_targets SET read_at = NOW() WHERE notification_id = ? AND employee_id = ? AND read_at IS NULL', [$nid, $id]);
    $keys = array_values(array_filter(explode(',', (string)($n['contents'] ?? ''))));
    $vars = ['title' => $n['title'], 'n' => $n, 'report' => null, 'keys' => $keys, 'sec' => [],
             'showBody' => !$keys || in_array('message', $keys, true)];
    $hasPeriod = $n['report_from'] && $n['report_to'];
    $data = array_diff($keys, ['message']);
    if (($data || $n['ntype'] === 'تقرير حضور' || $n['ntype'] === 'كشف مرتب') && !geo_gate_ok()) { $vars['geoBlocked'] = true; render('notif_view', $vars); return; }
    $r = $hasPeriod && ($n['ntype'] === 'تقرير حضور' || array_intersect($keys, NOTIF_REPORT_KEYS)) ? att_evaluate($id, $n['report_from'], $n['report_to']) : null;
    if ($r) $vars['sec']['att'] = $r;
    if (in_array('profile', $keys, true)) $vars['sec']['profile'] = ['emp' => self_employee(), 'fields' => editable_fields()];
    if (in_array('documents', $keys, true)) $vars['sec']['documents'] = doc_status_self($id);
    if ($r && ($n['ntype'] === 'تقرير حضور' || in_array('monthlyClosing', $keys, true))) {
        $vars['report'] = ['att_days', ['title' => __('تقرير الحضور من %s إلى %s', $n['report_from'], $n['report_to']), 'ym' => substr($n['report_from'], 0, 7),
            'r' => $r, 'self' => true, 'embed' => true]];
    } elseif ($n['ntype'] === 'كشف مرتب' && $n['period_month']) {
        $ym = substr($n['period_month'], 0, 7);
        [$from, $to, $per] = payroll_range($ym);
        $snap = $per && $per['status'] === 'closed' ? period_snapshot((int)$per['id']) : null;
        $pp = $snap ? (snapshot_rows((int)$snap['id'])[$id] ?? null) : payroll_compute($id, $from, $to, $ym);
        if ($pp !== null) $vars['report'] = ['payslip', ['title' => __('كشف مرتب %s', $ym), 'ym' => $ym, 'per' => $per, 'p' => $pp, 'self' => true, 'snap' => $snap, 'embed' => true]];
    }
    render('notif_view', $vars);
}

// ═════════════ Audit ═════════════
function page_audit(): void
{
    require_perm('audit');
    $where = ['1=1']; $p = [];
    if (($a = in_str('action', 80, $_GET)) !== '') { $where[] = 'action LIKE ?'; $p[] = addcslashes($a, '%_\\') . '%'; }
    if (($u = in_str('user', 64, $_GET)) !== '') { $where[] = 'username = ?'; $p[] = $u; }
    if ($d = valid_date($_GET['from'] ?? null)) { $where[] = 'created_at >= ?'; $p[] = $d; }
    if ($d = valid_date($_GET['to'] ?? null)) { $where[] = 'created_at < ? + INTERVAL 1 DAY'; $p[] = $d; }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $w = implode(' AND ', $where);
    $total = (int)q_val("SELECT COUNT(*) FROM audit_log WHERE $w", $p);
    $rows = q_all("SELECT * FROM audit_log WHERE $w ORDER BY id DESC LIMIT 100 OFFSET " . (($page - 1) * 100), $p);
    render('audit', ['title' => __('سجل التدقيق'), 'rows' => $rows, 'total' => $total, 'page' => $page]);
}

// ═════════════ Super admin: users ═════════════
function page_users(array $vars = []): void
{
    require_manager();
    render('users', $vars + [
        'title' => __('المستخدمين'),
        'users' => q_all('SELECT u.*, r.name role_name, e.name_ar FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN employees e ON e.id = u.employee_id ORDER BY r.is_staff DESC, u.username'),
        'roles' => q_all('SELECT * FROM roles WHERE active = 1' . (is_super() ? '' : ' AND is_super = 0') . ' ORDER BY is_super DESC, is_staff DESC, name'),
        'free_emps' => q_all('SELECT e.id, e.name_ar FROM employees e LEFT JOIN users u ON u.employee_id = e.id WHERE u.id IS NULL ORDER BY e.id'),
    ]);
}

function page_users_post(): void
{
    require_manager();
    $op = $_POST['op'] ?? '';
    $me = user();
    $back = users_back();
    $mustChange = ($_POST['must_change'] ?? '1') === '1' ? 1 : 0;
    $defHash = (string)setting('_emp_default_pw', '');
    if ($op === 'default_password') {
        // Old ATT_DEFAULT_PASSWORD: one default password for employee accounts, kept only as a hash.
        $pw = (string)($_POST['password'] ?? '');
        if ($p = password_problems($pw)) { if ($back) { flash('err', $p); redirect($back); } page_users(['error' => $p]); return; }
        q("INSERT INTO settings (k, v, label) VALUES ('_emp_default_pw', ?, 'Employee default password (hash)') ON DUPLICATE KEY UPDATE v = VALUES(v)", [hash_password($pw)]);
        audit('setting.update', '_emp_default_pw', 'changed');
        flash('ok', __('اتحفظت كلمة المرور الافتراضية للموظفين.'));
        redirect($back ?: url('users'));
    }
    if ($op === 'create') {
        $username = mb_strtolower(in_str('username', 64));
        $pw = (string)($_POST['password'] ?? '');
        $useDefault = $pw === '' && $defHash !== '' && in_int('employee_id');
        $role = in_int('role_id');
        $emp = in_int('employee_id');
        if ($username === '' && $emp) $username = (string)$emp; // employee accounts: username = employee code
        $err = null;
        if ($role && !is_super() && q_val('SELECT is_super FROM roles WHERE id = ?', [$role])) $err = __('إعطاء صلاحية السوبر أدمن للسوبر أدمن بس.');
        if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) $err = __('اسم المستخدم: حروف إنجليزي صغيرة وأرقام و . _ - (3 حروف على الأقل).');
        elseif (q_val('SELECT 1 FROM users WHERE username = ?', [$username])) $err = __('اسم المستخدم موجود.');
        elseif (!$role || !q_val('SELECT 1 FROM roles WHERE id = ?', [$role])) $err = __('اختار الصلاحية.');
        elseif ($emp && q_val('SELECT 1 FROM users WHERE employee_id = ?', [$emp])) $err = __('الموظف ده عنده حساب بالفعل.');
        elseif ($emp && !q_val('SELECT 1 FROM employees WHERE id = ?', [$emp])) $err = __('الموظف غير موجود.');
        elseif (!q_val('SELECT is_staff FROM roles WHERE id = ?', [$role]) && !$emp) $err = __('حساب الموظف لازم يكون مربوط بموظف.');
        elseif (!$useDefault && ($p = password_problems($pw, $username))) $err = $p;
        if ($err) { if ($back) { flash('err', $err); redirect($back); } page_users(['error' => $err]); return; }
        q('INSERT INTO users (username, display_name, password_hash, role_id, employee_id, must_change_password) VALUES (?,?,?,?,?,?)',
            [$username, in_str('display_name', 150) ?: null, $useDefault ? $defHash : hash_password($pw), $role, $emp, $useDefault ? 1 : $mustChange]);
        audit('user.create', $username, ['role' => $role, 'employee' => $emp]);
        flash('ok', $mustChange ? __('تم إنشاء المستخدم. هيُطلب منه تغيير كلمة المرور أول دخول.') : __('تم إنشاء المستخدم.'));
    } elseif ($op === 'bulk_employees') {
        $pw = (string)($_POST['password'] ?? '');
        if (!($pw === '' && $defHash !== '') && ($p = password_problems($pw))) { page_users(['error' => $p]); return; }
        $role = (int)q_val("SELECT id FROM roles WHERE slug = 'employee'");
        $hash = $pw === '' ? $defHash : hash_password($pw);
        $n = 0;
        foreach (q_all('SELECT e.id FROM employees e LEFT JOIN users u ON u.employee_id = e.id WHERE u.id IS NULL AND (e.end_date IS NULL OR e.end_date >= CURDATE())') as $e) {
            if (q_val('SELECT 1 FROM users WHERE username = ?', [(string)$e['id']])) continue;
            q('INSERT INTO users (username, password_hash, role_id, employee_id, must_change_password) VALUES (?,?,?,?,1)', [(string)$e['id'], $hash, $role, $e['id']]);
            $n++;
        }
        audit('user.bulk_create', null, ['count' => $n]);
        flash('ok', __('تم إنشاء %d حساب للموظفين (اسم المستخدم = كود الموظف).', $n));
    } else {
        $id = in_int('id');
        $u = $id ? q_one('SELECT * FROM users WHERE id = ?', [$id]) : null;
        if (!$u) http_error(404);
        if ((int)$u['id'] === (int)$me['id'] && in_array($op, ['toggle', 'role'], true)) { page_users(['error' => __('مينفعش تعطل أو تغير صلاحية حسابك إنت.')]); return; }
        // The admin (assistant) can manage everyone except super admin accounts.
        $targetSuper = (bool)q_val('SELECT is_super FROM roles WHERE id = ?', [$u['role_id']]);
        $toSuper = $op === 'role' && ($r2 = in_int('role_id')) && q_val('SELECT is_super FROM roles WHERE id = ?', [$r2]);
        if (!is_super() && ($targetSuper || $toSuper)) {
            audit('access.denied', 'super_account', ['op' => $op, 'target' => $u['username']]);
            page_users(['error' => __('حسابات السوبر أدمن بيتحكم فيها السوبر أدمن بس.')]);
            return;
        }
        switch ($op) {
            case 'reset':
                $pw = (string)($_POST['password'] ?? '');
                if ($p = password_problems($pw, $u['username'])) { if ($back) { flash('err', $p); redirect($back); } page_users(['error' => $p]); return; }
                q('UPDATE users SET password_hash = ?, legacy_hash = NULL, legacy_salt = NULL, must_change_password = ?, failed_logins = 0, locked_until = NULL, session_epoch = session_epoch + 1 WHERE id = ?', [hash_password($pw), $mustChange, $id]);
                audit('user.reset_password', $u['username'], ['must_change' => $mustChange]);
                flash('ok', $mustChange ? __('تم تعيين كلمة المرور، وهيُطلب منه يغيّرها أول ما يدخل.') : __('تم تغيير كلمة المرور.'));
                break;
            case 'reset_default':
                // Old adminResetEmployeePassword_: back to the default password, logged in the employee history.
                if ($defHash === '' || !$u['employee_id']) { flash('err', __('مفيش كلمة مرور افتراضية محفوظة، أو الحساب ده مش حساب موظف.')); redirect($back ?: url('users')); }
                q('UPDATE users SET password_hash = ?, legacy_hash = NULL, legacy_salt = NULL, must_change_password = 1, failed_logins = 0, locked_until = NULL, session_epoch = session_epoch + 1 WHERE id = ?', [$defHash, $id]);
                q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?,?)',
                    [$u['employee_id'], 'password', '', '', 'إعادة تعيين كلمة المرور للافتراضي', $me['username'], bin2hex(random_bytes(8))]);
                audit('user.reset_default_password', $u['username']);
                flash('ok', __('كلمة المرور رجعت للافتراضية، وهيُطلب منه يغيّرها أول ما يدخل.'));
                break;
            case 'toggle':
                q('UPDATE users SET active = 1 - active, session_epoch = session_epoch + 1 WHERE id = ?', [$id]);
                audit('user.toggle_active', $u['username'], $u['active'] ? 'disabled' : 'enabled');
                flash('ok', __('تم.'));
                break;
            case 'unlock':
                q('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$id]);
                q('DELETE FROM login_attempts WHERE username = ?', [$u['username']]);
                audit('user.unlock', $u['username']);
                flash('ok', __('تم فك القفل.'));
                break;
            case 'role':
                $role = in_int('role_id');
                if (!$role || !q_val('SELECT 1 FROM roles WHERE id = ?', [$role])) http_error(400);
                q('UPDATE users SET role_id = ?, session_epoch = session_epoch + 1 WHERE id = ?', [$role, $id]);
                audit('user.role', $u['username'], ['from' => $u['role_id'], 'to' => $role]);
                flash('ok', __('تم تغيير الصلاحية.'));
                break;
            default:
                http_error(400);
        }
    }
    redirect($back ?: url('users'));
}

/** Optional return address (e.g. the employee file) for forms posted to the users page; same app only. */
function users_back(): ?string
{
    $b = is_string($_POST['back'] ?? null) ? $_POST['back'] : '';
    $prefix = base_path() . '/index.php?';
    return str_starts_with($b, $prefix) && !preg_match('/[\r\n]/', $b) ? $b : null;
}

// ═════════════ Super admin: roles ═════════════
function page_roles(): void
{
    require_manager();
    $roles = q_all('SELECT * FROM roles ORDER BY is_super DESC, is_staff DESC, id');
    $perms = [];
    foreach (q_all('SELECT * FROM role_permissions') as $p) $perms[$p['role_id']][$p['module']] = $p;
    render('roles', ['title' => __('الصلاحيات'), 'roles' => $roles, 'perms' => $perms, 'depts' => fk_options(['fk' => ['departments', 'name']])]);
}

function page_roles_post(): void
{
    require_manager();
    if (($_POST['op'] ?? '') === 'create') {
        $name = in_str('name', 100);
        $slug = 'r' . bin2hex(random_bytes(4));
        if ($name === '') { flash('err', __('اكتب اسم الصلاحية.')); redirect(url('roles')); }
        q('INSERT INTO roles (slug, name, is_staff) VALUES (?,?,1)', [$slug, $name]);
        audit('role.create', $name);
        flash('ok', __('تمت إضافة الصلاحية.'));
        redirect(url('roles'));
    }
    $id = in_int('id');
    $role = $id ? q_one('SELECT * FROM roles WHERE id = ?', [$id]) : null;
    if (!$role || $role['is_super'] || !$role['is_staff'] || $role['slug'] === 'admin') http_error(400);
    db()->beginTransaction();
    q('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
    $matrix = is_array($_POST['p'] ?? null) ? $_POST['p'] : [];
    foreach (MODULES as $m => $_) {
        $row = is_array($matrix[$m] ?? null) ? $matrix[$m] : [];
        $vals = array_map(fn($a) => !empty($row[$a]) ? 1 : 0, ACTIONS);
        if (array_sum($vals) === 0) continue;
        if ($vals[0] === 0) $vals = array_fill(0, 5, 0); // nothing without view
        q('INSERT INTO role_permissions (role_id, module, can_view, can_create, can_update, can_delete, can_export) VALUES (?,?,?,?,?,?,?)', array_merge([$id, $m], $vals));
    }
    $scope = is_array($_POST['dept'] ?? null) ? array_values(array_filter(array_map('intval', $_POST['dept']))) : [];
    q('UPDATE roles SET name = ?, dept_scope = ?, active = ? WHERE id = ?', [in_str('name', 100) ?: $role['name'], $scope ? implode(',', $scope) : null, !empty($_POST['active']) ? 1 : 0, $id]);
    q('UPDATE users SET session_epoch = session_epoch + 1 WHERE role_id = ?', [$id]); // re-login with new rights
    audit('role.update', $role['name'], ['matrix' => $matrix, 'scope' => $scope]);
    db()->commit();
    flash('ok', __('تم حفظ الصلاحية. المستخدمين اللي عليها هيسجلوا دخول تاني.'));
    redirect(url('roles'));
}

// ═════════════ Super admin: settings (old «الإعدادات» screen: one card per job) ═════════════
// How each general setting is edited; a key missing here stays a plain text box.
const SETTING_TYPES = [
    'checkin_require_photo' => 'bool', 'face_enabled' => 'bool', 'geofence_enforce' => 'bool', 'self_geo_gate' => 'bool',
    'checkin_cooldown_sec' => 'int', 'remember_days' => 'int',
    'weekend_days' => 'weekdays', 'day_divisor' => 'int', 'doc_expiry_alert_days' => 'int',
    'face_max_failures' => 'int', 'face_consent_version' => 'int',
    'face_match_threshold' => 'decimal', 'face_min_yaw_range' => 'decimal',
    'face_consent_text' => 'long', 'face_consent_text_en' => 'long',
];
const WEEKDAYS = [6 => 'السبت', 7 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة'];

function page_settings(): void
{
    require_manager();
    $today = date('Y-m-d');
    render('settings', [
        'title'  => __('الإعدادات'),
        'rows'   => q_all("SELECT * FROM settings WHERE k NOT LIKE '\\_%' AND k <> 'auto_employee_code' ORDER BY k"),
        'fields' => q_all("SELECT * FROM employee_field_permissions ORDER BY sort_order, field = 'specialization_id'"),
        'depts'  => q_all('SELECT id, name, active, attendance_exempt FROM departments ORDER BY name'),
        'jobs'   => q_all('SELECT id, name, active FROM job_titles ORDER BY name'),
        'specs'  => q_all('SELECT id, name, active FROM specializations ORDER BY name'),
        'auto_code' => setting('auto_employee_code', '1') !== '0',
        'exempt_emps' => q_all('SELECT e.id, e.name_ar, d.name AS dept FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.attendance_exempt = 1 ORDER BY e.id'),
        'free_emps'   => q_all('SELECT id, name_ar FROM employees WHERE attendance_exempt = 0 AND (end_date IS NULL OR end_date > ?) ORDER BY id', [$today]),
        'years'  => q_all('SELECT * FROM academic_years ORDER BY date_from DESC'),
        'edit_year' => in_int('edit_year', $_GET),
        'checks' => ($_GET['check'] ?? '') === '1' ? system_checks() : null,
    ]);
}

function page_settings_post(): void
{
    require_manager();
    $op = (string)($_POST['op'] ?? 'general');
    $back = url('settings') . (in_array($op, ['name_add', 'name_rename', 'name_toggle'], true) ? '#names' : (str_starts_with($op, 'exempt') ? '#exempt' : (str_starts_with($op, 'year') ? '#years' : '')));
    $err = match ($op) {
        'general'  => settings_save_general(),
        'fields'   => settings_save_fields(),
        'autocode' => settings_save_autocode(),
        'name_add', 'name_rename', 'name_toggle' => settings_name_list($op),
        'exempt_dept_add', 'exempt_dept_remove', 'exempt_emp_add', 'exempt_emp_remove' => settings_exempt($op),
        'year_add', 'year_update', 'year_activate' => settings_year($op),
        default => __('عملية غير معروفة.'),
    };
    if ($err) flash('err', $err); else flash('ok', __('تم الحفظ.'));
    redirect($back);
}

function settings_save_general(): ?string
{
    $vals = is_array($_POST['s'] ?? null) ? $_POST['s'] : [];
    $days = is_array($_POST['weekend'] ?? null) ? array_values(array_intersect(array_keys(WEEKDAYS), array_map('intval', $_POST['weekend']))) : [];
    sort($days);
    $vals['weekend_days'] = implode(',', $days);
    $rows = q_all("SELECT k, v, label FROM settings WHERE k NOT LIKE '\\_%' AND k <> 'auto_employee_code'");
    $new = [];
    foreach ($rows as $s) {
        $type = SETTING_TYPES[$s['k']] ?? 'text';
        if ($type === 'bool') { $new[$s['k']] = !empty($vals[$s['k']]) ? '1' : '0'; continue; }
        if (!isset($vals[$s['k']]) || !is_string($vals[$s['k']])) continue;
        $v = mb_substr(str_replace("\r\n", "\n", trim($vals[$s['k']])), 0, $type === 'long' ? 5000 : 500);
        $label = __($s['label'] ?: $s['k']);
        if ($type === 'int' && !preg_match('/^\d{1,6}$/', $v)) return __('%s لازم يكون رقم صحيح.', $label);
        if ($type === 'decimal' && (!is_numeric($v) || (float)$v < 0 || (float)$v > 1)) return __('%s لازم يكون رقم من 0 لـ 1.', $label);
        $new[$s['k']] = $v;
    }
    if (($new['day_divisor'] ?? '30') === '0') return __('%s لازم يكون أكبر من صفر.', __('قاسم اليوم'));
    $old = array_column($rows, 'v', 'k');
    foreach (['face_consent_text', 'face_consent_text_en'] as $k) {
        // Changing the consent wording always requires everyone to agree again.
        if (isset($new[$k]) && $new[$k] !== (string)$old[$k] && ($new['face_consent_version'] ?? '') === (string)$old['face_consent_version']) {
            $new['face_consent_version'] = (string)((int)$old['face_consent_version'] + 1);
            audit('setting.update', 'face_consent_version', 'auto-bumped because the consent text changed');
        }
    }
    foreach ($new as $k => $v) {
        if ($v === (string)$old[$k]) continue;
        q('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
        audit('setting.update', $k, ['from' => $old[$k], 'to' => $v]);
    }
    return null;
}

function settings_save_fields(): ?string
{
    $fp = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    foreach (q_all('SELECT field FROM employee_field_permissions') as $f) {
        $r = is_array($fp[$f['field']] ?? null) ? $fp[$f['field']] : [];
        q('UPDATE employee_field_permissions SET visible = ?, editable = ?, required = ? WHERE field = ?', [!empty($r['visible']) ? 1 : 0, !empty($r['editable']) ? 1 : 0, !empty($r['required']) ? 1 : 0, $f['field']]);
    }
    audit('settings.employee_fields');
    return null;
}

/** Old SettingsService.setAutoCode. */
function settings_save_autocode(): ?string
{
    $v = ($_POST['auto_code'] ?? '') === '1' ? '1' : '0';
    $old = (string)setting('auto_employee_code', '1');
    if ($v !== $old) {
        q('UPDATE settings SET v = ? WHERE k = ?', [$v, 'auto_employee_code']);
        audit('settings.autoCode', null, $v === '1' ? 'نعم' : 'لا');
    }
    return null;
}

/** Old NameListService.manage: add | rename | setActive — never deletes, employee data untouched. */
function settings_name_list(string $op): ?string
{
    $kind = in_array($_POST['kind'] ?? '', ['job', 'spec'], true) ? $_POST['kind'] : 'dept';
    $table = ['dept' => 'departments', 'job' => 'job_titles', 'spec' => 'specializations'][$kind];
    $label = ['dept' => __('القسم'), 'job' => __('المسمى الوظيفي'), 'spec' => __('التخصص')][$kind];
    $norm = fn(string $x) => preg_replace('/\s+/u', ' ', trim($x));
    $taken = fn(string $name, int $except = 0) => (bool)q_val("SELECT 1 FROM `$table` WHERE name = ? AND id <> ?", [$name, $except]);
    if ($op === 'name_add') {
        $name = $norm(in_str('name', 150));
        if ($name === '') return __('الاسم مطلوب.');
        if ($taken($name)) return __('%s «%s» موجود بالفعل.', $label, $name);
        q("INSERT INTO `$table` (name, active) VALUES (?, 1)", [$name]);
        audit('nameList.add', "$kind:$name");
        return null;
    }
    $row = q_one("SELECT * FROM `$table` WHERE id = ?", [in_int('id') ?? 0]);
    if (!$row) return __('%s مش موجود.', $label);
    if ($op === 'name_rename') {
        $name = $norm(in_str('name', 150));
        if ($name === '') return __('الاسم الجديد مطلوب.');
        if ($name === $row['name']) return null;
        if ($taken($name, (int)$row['id'])) return __('%s «%s» موجود بالفعل.', $label, $name);
        q("UPDATE `$table` SET name = ? WHERE id = ?", [$name, $row['id']]);
        audit('nameList.rename', "$kind:{$row['name']}", $name);
        return null;
    }
    q("UPDATE `$table` SET active = 1 - active WHERE id = ?", [$row['id']]);
    audit('nameList.setActive', "$kind:{$row['name']}", $row['active'] ? 'لا' : 'نعم');
    return null;
}

/** Old exemptCard: departments exempt from attendance, and employees exempted by hand (with a history row). */
function settings_exempt(string $op): ?string
{
    $id = in_int('id') ?? 0;
    if ($op === 'exempt_dept_add' || $op === 'exempt_dept_remove') {
        $d = q_one('SELECT id, name FROM departments WHERE id = ?', [$id]);
        if (!$d) return __('اختار قسم.');
        $on = $op === 'exempt_dept_add' ? 1 : 0;
        q('UPDATE departments SET attendance_exempt = ? WHERE id = ?', [$on, $id]);
        audit($on ? 'exemptDept.add' : 'exemptDept.remove', $d['name']);
        return null;
    }
    $e = q_one('SELECT id, attendance_exempt FROM employees WHERE id = ?', [$id]);
    if (!$e) return __('اختار موظف.');
    $on = $op === 'exempt_emp_add' ? 1 : 0;
    if ((int)$e['attendance_exempt'] === $on) return null;
    db()->beginTransaction();
    q('UPDATE employees SET attendance_exempt = ? WHERE id = ?', [$on, $id]);
    q('INSERT INTO employee_change_log (employee_id, field, old_value, new_value, reason, changed_by, op_id) VALUES (?,?,?,?,?,?,?)',
        [$id, 'attendance_exempt', (string)(1 - $on), (string)$on, $on ? 'إعفاء يدوي من البصمة' : 'إلغاء الإعفاء اليدوي من البصمة', user()['username'], bin2hex(random_bytes(8))]);
    db()->commit();
    audit($on ? 'employee.exempt' : 'employee.unexempt', $id);
    return null;
}

/** Old AcademicYearService: add (inactive), update, setActive (only one active year). */
function settings_year(string $op): ?string
{
    if ($op === 'year_activate') {
        $y = q_one('SELECT id, name FROM academic_years WHERE id = ?', [in_int('id') ?? 0]);
        if (!$y) return __('السنة مش موجودة.');
        academic_year_activate((int)$y['id']);
        audit('academicYear.setActive', $y['name']);
        return null;
    }
    $name = trim(in_str('name', 100));
    $from = valid_date($_POST['date_from'] ?? null);
    $to = valid_date($_POST['date_to'] ?? null);
    if ($name === '') return __('اسم السنة مطلوب.');
    if (!$from || !$to || $from > $to) return __('من تاريخ / لحد تاريخ غير صالحين.');
    $id = $op === 'year_update' ? (in_int('id') ?? 0) : 0;
    if ($op === 'year_update' && !q_val('SELECT 1 FROM academic_years WHERE id = ?', [$id])) return __('السنة مش موجودة.');
    if (q_val('SELECT 1 FROM academic_years WHERE name = ? AND id <> ?', [$name, $id])) return __('السنة موجودة بالفعل.');
    if ($id) {
        q('UPDATE academic_years SET name = ?, date_from = ?, date_to = ? WHERE id = ?', [$name, $from, $to, $id]);
        audit('academicYear.update', $name, ['from' => $from, 'to' => $to]);
    } else {
        q('INSERT INTO academic_years (name, date_from, date_to, active, created_by) VALUES (?,?,?,0,?)', [$name, $from, $to, user()['username']]);
        audit('academicYear.add', $name, ['from' => $from, 'to' => $to]);
    }
    return null;
}

/** Old «التحقق من البيئة», for this server: read-only checks, each PASS / FAIL with a short detail. */
function system_checks(): array
{
    $out = [];
    $add = function (string $name, bool $ok, string $detail) use (&$out) { $out[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail]; };
    try {
        $add(__('قاعدة البيانات'), true, (string)q_val('SELECT VERSION()'));
        $tables = q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        preg_match_all('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', (string)@file_get_contents(dirname(__DIR__) . '/database/schema.sql'), $m);
        $missing = array_diff($m[1], $tables);
        $add(__('جداول النظام'), !$missing, $missing ? __('ناقص: %s', implode(', ', $missing)) : __('%d جدول', count($tables)));
    } catch (Throwable $e) {
        $add(__('قاعدة البيانات'), false, __('مش متاحة'));
    }
    $add('PHP', PHP_VERSION_ID >= 80100, PHP_VERSION);
    $ext = array_filter(['pdo_mysql', 'mbstring', 'zip', 'fileinfo', 'zlib', 'curl'], fn($x) => !extension_loaded($x));
    $add(__('إضافات PHP'), !$ext, $ext ? __('ناقص: %s', implode(', ', $ext)) : 'pdo_mysql, mbstring, zip, fileinfo, zlib, curl');
    foreach (['backups' => __('فولدر النسخ الاحتياطية'), 'uploads' => __('فولدر الملفات المرفوعة'), 'tmp' => __('فولدر الملفات المؤقتة')] as $dir => $label) {
        $path = config('storage_dir') . '/' . $dir;
        if (!is_dir($path)) @mkdir($path, 0750, true);
        $add($label, is_dir($path) && is_writable($path), is_writable($path) ? __('قابل للكتابة') : __('مش قابل للكتابة'));
    }
    if (face_enabled()) {
        $h = face_health();
        $add(__('خدمة بصمة الوجه'), $h, $h ? __('شغالة') : __('مش بترد'));
    }
    $last = q_val('SELECT MAX(at) FROM backups_log');
    $age = $last ? (int)floor((time() - strtotime((string)$last)) / 86400) : null;
    $add(__('آخر نسخة احتياطية'), $age !== null && $age <= 7, $last ? __('%s (من %d يوم)', $last, $age) : __('مفيش نسخ لسه'));
    audit('system.verify', null, count(array_filter($out, fn($c) => !$c['ok'])) . ' fail');
    return $out;
}

// ═════════════ Employees: Excel template / upload ═════════════
function emp_import_cleanup(): void
{
    foreach (glob(emp_import_dir() . '/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
}

function page_emp_template(): void
{
    if (!can('employees', 'create') && !can('employees', 'update')) http_error(403);
    $filled = ($_GET['filled'] ?? '') === '1';
    if ($filled) require_perm('employees', 'export');
    $file = emp_template_file($filled);
    audit('employees.template_download', $filled ? 'filled' : 'empty');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="employees-' . ($filled ? 'data-' : 'template-') . date('Ymd') . '.xlsx"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    unlink($file);
    exit;
}

function page_emp_import(array $vars = []): void
{
    if (!can('employees', 'create') && !can('employees', 'update')) http_error(403);
    emp_import_cleanup();
    render('emp_import', $vars + ['title' => __('رفع بيانات الموظفين من Excel')]);
}

function page_emp_import_post(): void
{
    if (!can('employees', 'create') && !can('employees', 'update')) http_error(403);
    if (($_POST['op'] ?? '') === 'confirm') {
        $tok = $_SESSION['emp_import'] ?? null;
        $sent = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        if (!$tok || !hash_equals($tok['token'], $sent) || $tok['exp'] < time()) { flash('err', __('المعاينة انتهت. ارفع الملف تاني.')); redirect(url('emp_import')); }
        $file = emp_import_dir() . '/plan-' . $tok['token'] . '.json';
        $plan = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        @unlink($file);
        unset($_SESSION['emp_import']);
        if (!is_array($plan)) { flash('err', __('المعاينة انتهت. ارفع الملف تاني.')); redirect(url('emp_import')); }
        $pw = (string)($_POST['password'] ?? '');
        if ($pw !== '' && ($p = password_problems($pw))) { flash('err', $p); redirect(url('emp_import')); }
        try {
            $d = emp_import_apply($plan, $pw);
        } catch (RuntimeException $ex) {
            flash('err', $ex->getMessage()); redirect(url('emp_import'));
        }
        flash('ok', __('تم: %d موظف جديد، %d تحديث، %d تغيير راتب، %d حساب جديد، %d تغيير صلاحية.', $d['new'], $d['update'], $d['salary'], $d['accounts'], $d['roles']));
        redirect(url('list', ['res' => 'employees']));
    }

    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { page_emp_import(['error' => __('اختار ملف Excel (.xlsx).')]); return; }
    if ($f['size'] > 5 * 1024 * 1024) { page_emp_import(['error' => __('أقصى حجم للملف 5 ميجا.')]); return; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!preg_match('/\.xlsx$/i', (string)$f['name']) || !in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'], true)) {
        page_emp_import(['error' => __('الملف لازم يكون .xlsx')]); return;
    }
    try {
        $res = emp_import_parse($f['tmp_name']);
    } catch (Throwable $ex) {
        page_emp_import(['error' => __('مقدرناش نقرا الملف. اتأكد إنه .xlsx من القالب.')]); return;
    } finally {
        @unlink($f['tmp_name']);
    }
    if (isset($res['fatal'])) { page_emp_import(['error' => $res['fatal']]); return; }
    audit('employees.excel_preview', null, ['stats' => $res['stats'], 'error_rows' => count($res['errors'])]);
    $token = null;
    if (!empty($_SESSION['emp_import']['token'])) @unlink(emp_import_dir() . '/plan-' . $_SESSION['emp_import']['token'] . '.json'); // drop an older preview
    unset($_SESSION['emp_import']);
    if ($res['rows'] && !$res['errors']) {
        $token = bin2hex(random_bytes(16));
        file_put_contents(emp_import_dir() . "/plan-$token.json", json_encode($res['rows'], JSON_UNESCAPED_UNICODE));
        $_SESSION['emp_import'] = ['token' => $token, 'exp' => time() + 1800];
    }
    page_emp_import(['res' => $res, 'token' => $token]);
}

function page_styleguide(): void
{
    require_manager();
    render('styleguide', ['title' => 'دليل التصميم']);
}
