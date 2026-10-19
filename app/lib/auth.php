<?php
declare(strict_types=1);

// Modules used by the permission matrix (super-admin-only modules are not listed here).
const MODULES = [
    'employees'      => 'الموظفين',
    'attendance'     => 'الحضور والانصراف',
    'rules'          => 'قواعد المواعيد والخصم والعطلات',
    'salary'         => 'المرتبات والسلف',
    'documents'      => 'المستندات',
    'investigations' => 'التحقيقات',
    'notifications'  => 'الإشعارات',
    'reports'        => 'التقارير',
    'audit'          => 'سجل التدقيق',
];
const ACTIONS = ['view', 'create', 'update', 'delete', 'export'];

function password_algo()
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

function hash_password(string $pw): string
{
    return password_hash($pw, password_algo());
}

function password_problems(string $pw, string $username = ''): ?string
{
    if (mb_strlen($pw) < 10) return __('كلمة المرور لازم تكون 10 حروف على الأقل.');
    if (mb_strlen($pw) > 200) return __('كلمة المرور طويلة جدًا.');
    if (!preg_match('/\p{L}/u', $pw) || !preg_match('/\d/', $pw)) return __('كلمة المرور لازم يكون فيها حروف وأرقام.');
    if ($username !== '' && mb_stripos($pw, $username) !== false) return __('كلمة المرور ماينفعش تحتوي على اسم المستخدم.');
    return null;
}

// ───────────── Session ─────────────
function start_secure_session(): void
{
    $https = is_https();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string)config('absolute_timeout', 28800));
    // Debian disables PHP's own session GC (it relies on a cron for the default dir); ours is private.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    $dir = config('storage_dir') . '/sessions';
    if (ini_get('session.save_path') !== $dir) session_save_path($dir); // the FPM pool may already pin it
    // Cookie is scoped to the app's sub-path (/hr/) so other apps on the domain never receive it.
    session_name($https ? '__Secure-ghishr' : 'ghishr');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() . '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    $now = time();
    $idle = (int)config('idle_timeout', 1800);
    $abs  = (int)config('absolute_timeout', 28800);
    if ($now - ($_SESSION['last'] ?? 0) > $idle || $now - ($_SESSION['created'] ?? 0) > $abs
        || ($_SESSION['ua'] ?? '') !== hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        destroy_session();
        return null;
    }
    $u = q_one('SELECT u.*, r.slug role_slug, r.name role_name, r.is_super, r.is_staff, r.dept_scope, r.active role_active
                FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$_SESSION['uid']]);
    if (!$u || !$u['active'] || !$u['role_active'] || (int)$u['session_epoch'] !== (int)($_SESSION['epoch'] ?? -1)) {
        destroy_session();
        return null;
    }
    $_SESSION['last'] = $now;
    if ($now - ($_SESSION['rotated'] ?? 0) > 900) { // rotate id every 15 min
        session_regenerate_id(true);
        $_SESSION['rotated'] = $now;
    }
    $u['perms'] = [];
    foreach (q_all('SELECT * FROM role_permissions WHERE role_id = ?', [$u['role_id']]) as $p) {
        $u['perms'][$p['module']] = $p;
    }
    return $u;
}

function destroy_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        session_destroy();
    }
}

// ───────────── Login ─────────────
/** @return array{ok:bool, msg?:string, user?:array} */
function attempt_login(string $username, string $password): array
{
    $ip = client_ip();
    $username = mb_strtolower(trim($username));
    $generic = __('اسم المستخدم أو كلمة المرور غير صحيحة.');
    $win = (int)config('ip_window_minutes', 15);

    $ipFails = (int)q_val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > NOW() - INTERVAL ? MINUTE', [$ip, $win]);
    if ($ipFails >= (int)config('max_failed_per_ip', 20)) {
        audit('auth.blocked_ip', $ip);
        return ['ok' => false, 'msg' => __('محاولات كثيرة من نفس الجهاز. حاول بعد %d دقيقة.', $win), 'code' => 429];
    }
    // Same ceiling for unknown usernames so lockout behaviour doesn't reveal which accounts exist.
    $userFails = (int)q_val('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND created_at > NOW() - INTERVAL ? MINUTE', [$username, (int)config('lock_minutes', 15)]);

    $u = q_one('SELECT * FROM users WHERE username = ?', [$username]);
    if (!$u && preg_match('/^\d{1,9}$/', $username)) $u = q_one('SELECT * FROM users WHERE employee_id = ?', [(int)$username]); // sign in with the employee code
    $locked = ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) || (!$u && $userFails >= (int)config('max_failed_per_user', 5));
    if ($locked) {
        q('INSERT INTO login_attempts (ip, username, success) VALUES (?,?,0)', [$ip, $username]);
        return ['ok' => false, 'msg' => __('الحساب مقفول مؤقتًا بسبب محاولات خاطئة. حاول بعد %d دقيقة أو كلّم الإدارة.', (int)config('lock_minutes', 15))];
    }

    $ok = false;
    if ($u && $u['password_hash']) {
        $ok = password_verify($password, $u['password_hash']);
        if ($ok && password_needs_rehash($u['password_hash'], password_algo())) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $u['id']]);
        }
    } elseif ($u && $u['legacy_hash']) {
        $ok = verify_legacy($password, (string)$u['legacy_hash'], (string)$u['legacy_salt']);
        if ($ok) { // upgrade to argon2id on first successful login
            q('UPDATE users SET password_hash = ?, legacy_hash = NULL, legacy_salt = NULL, password_changed_at = NOW() WHERE id = ?', [hash_password($password), $u['id']]);
            audit('auth.legacy_upgraded', $u['username']);
        }
    } else {
        password_verify($password, hash_password('timing-equaliser')); // unknown user: spend the same hashing time
    }

    q('INSERT INTO login_attempts (ip, username, success) VALUES (?,?,?)', [$ip, $username, $ok ? 1 : 0]);
    if (random_int(1, 100) === 1) q('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 30 DAY'); // housekeeping, no cron needed

    if (!$ok) {
        if ($u) {
            $fails = (int)$u['failed_logins'] + 1;
            $lock = $fails % (int)config('max_failed_per_user', 5) === 0;
            q('UPDATE users SET failed_logins = ?, locked_until = ' . ($lock ? 'NOW() + INTERVAL ? MINUTE' : 'locked_until') . ' WHERE id = ?',
                $lock ? [$fails, (int)config('lock_minutes', 15), $u['id']] : [$fails, $u['id']]);
            $GLOBALS['USER'] = null;
            audit($lock ? 'auth.locked' : 'auth.failed', $username);
        } else {
            audit('auth.failed_unknown', $username);
        }
        return ['ok' => false, 'msg' => $generic];
    }
    if (!$u['active']) {
        audit('auth.inactive', $username);
        return ['ok' => false, 'msg' => $generic];
    }

    session_regenerate_id(true);
    $_SESSION = [
        'uid'     => (int)$u['id'],
        'epoch'   => (int)$u['session_epoch'],
        'created' => time(),
        'last'    => time(),
        'rotated' => time(),
        'ua'      => hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
        'csrf'    => bin2hex(random_bytes(32)),
    ];
    q('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$u['id']]);
    $GLOBALS['USER'] = $u;
    audit('auth.login', $u['username']);
    return ['ok' => true, 'user' => $u];
}

/** Hashes imported from the Google Sheets system (SHA-256 + salt, exact order unknown). */
function verify_legacy(string $pw, string $hash, string $salt): bool
{
    $hash = strtolower(trim($hash));
    if ($salt === '' && str_contains($hash, ':')) [$salt, $hash] = explode(':', $hash, 2);
    $cands = [
        hash('sha256', $salt . $pw), hash('sha256', $pw . $salt), hash('sha256', $pw),
        hash_hmac('sha256', $pw, $salt), hash_hmac('sha256', $salt, $pw),
        hash('sha256', $salt . ':' . $pw), hash('sha256', $pw . ':' . $salt),
    ];
    foreach ($cands as $c) if (hash_equals($c, $hash)) return true;
    return false;
}

// ───────────── Authorization ─────────────
function user(): array
{
    return $GLOBALS['USER'];
}

function is_super(): bool { return !empty($GLOBALS['USER']['is_super']); }
/** Super admin or Admin. Admin acts as the super admin's assistant with the same powers,
 *  except anything that touches a super admin account (see page_users_post). */
function is_manager(): bool { return is_super() || ($GLOBALS['USER']['role_slug'] ?? '') === 'admin'; }
function is_staff(): bool { return !empty($GLOBALS['USER']['is_staff']); }
function my_employee_id(): ?int { $id = $GLOBALS['USER']['employee_id'] ?? null; return $id === null ? null : (int)$id; }

function can(string $module, string $action = 'view'): bool
{
    $u = $GLOBALS['USER'] ?? null;
    if (!$u) return false;
    if ($u['is_super'] || ($u['role_slug'] ?? '') === 'admin') return true;
    if (!$u['is_staff']) return false;
    return !empty($u['perms'][$module]['can_' . $action]);
}

function require_perm(string $module, string $action = 'view'): void
{
    if (!can($module, $action)) {
        audit('access.denied', "$module.$action", ['route' => $_GET['r'] ?? '']);
        http_error(403);
    }
}

function require_super(): void
{
    if (!is_super()) { audit('access.denied', 'super', ['route' => $_GET['r'] ?? '']); http_error(403); }
}

function require_manager(): void
{
    if (!is_manager()) { audit('access.denied', 'manager', ['route' => $_GET['r'] ?? '']); http_error(403); }
}

function require_employee_self(): int
{
    $id = my_employee_id();
    if ($id === null) http_error(403, __('الحساب ده مش مربوط بموظف.'));
    return $id;
}

/** Department ids the current staff user may see; null = all. */
function dept_scope(): ?array
{
    $u = $GLOBALS['USER'];
    if ($u['is_super'] || ($u['role_slug'] ?? '') === 'admin' || $u['dept_scope'] === null || trim((string)$u['dept_scope']) === '') return null;
    return array_values(array_filter(array_map('intval', explode(',', $u['dept_scope']))));
}

/** SQL fragment restricting an employees alias to the user's department scope. */
function scope_sql(string $empAlias = 'e'): array
{
    $s = dept_scope();
    if ($s === null) return ['1=1', []];
    if (!$s) return ['1=0', []];
    return [$empAlias . '.department_id IN (' . implode(',', array_fill(0, count($s), '?')) . ')', $s];
}

function can_see_employee(int $empId): bool
{
    if (my_employee_id() === $empId) return true;
    if (!is_staff()) return false;
    [$w, $p] = scope_sql('e');
    return (bool)q_val("SELECT 1 FROM employees e WHERE e.id = ? AND $w", array_merge([$empId], $p));
}
