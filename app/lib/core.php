<?php
declare(strict_types=1);

// ───────────── Database ─────────────
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Keep DB clock in step with PHP's local time (Cairo, incl. DST).
        $pdo->exec("SET time_zone = '" . date('P') . "', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function q_one(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function q_val(string $sql, array $params = []) { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }

function setting(string $k, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q_all('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v'];
    }
    return $cache[$k] ?? $default;
}

// ───────────── Output / HTTP ─────────────
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $dir;
}

function url(string $route = '', array $params = []): string
{
    $p = $route !== '' ? ['r' => $route] + $params : $params;
    return base_path() . '/index.php' . ($p ? '?' . http_build_query($p) : '');
}

function asset(string $path): string
{
    $file = __DIR__ . '/../../public/hr/assets/' . $path; // nginx serves /hr/assets/* from public/hr/assets
    $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : '1';
    return base_path() . '/assets/' . $path . '?v=' . $v;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    if (config('trust_proxy') && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(self), camera=(self), microphone=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store, private');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
}

function http_error(int $code, string $msg = ''): never
{
    http_response_code($code);
    $titles = [403 => __('غير مسموح'), 404 => __('الصفحة غير موجودة'), 400 => __('طلب غير صالح'), 429 => __('محاولات كثيرة'), 500 => __('خطأ في الخادم')];
    render('error', ['code' => $code, 'title' => $titles[$code] ?? __('خطأ'), 'msg' => $msg]);
    exit;
}

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function render(string $view, array $vars = [], bool $layout = true): void
{
    extract($vars, EXTR_SKIP);
    $viewFile = __DIR__ . '/../views/' . $view . '.php';
    ob_start();
    require $viewFile;
    $content = ob_get_clean();
    if ($layout) {
        require __DIR__ . '/../views/layout.php';
    } else {
        echo $content;
    }
}

// ───────────── Input ─────────────
function in_str(string $k, int $max = 500, ?array $src = null): string
{
    $src ??= $_POST;
    $v = $src[$k] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    return mb_substr($v, 0, $max);
}

function in_int(string $k, ?array $src = null): ?int
{
    $src ??= $_POST;
    $v = $src[$k] ?? null;
    if (is_string($v) && preg_match('/^-?\d{1,10}$/', trim($v))) return (int)trim($v);
    return null;
}

function valid_date(?string $s): ?string
{
    if (!$s || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y) ? $s : null;
}

function valid_month(?string $s): ?string
{
    return ($s && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $s)) ? $s : null;
}

// ───────────── CSRF ─────────────
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_error(400, __('انتهت صلاحية الصفحة. حدّث الصفحة وحاول مرة تانية.'));
    }
    // Defence in depth: reject cross-site Origin headers.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== 'null') {
        $host = parse_url($origin, PHP_URL_HOST);
        if (!$host || strcasecmp($host, (string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) !== 0) {
            http_error(400, __('مصدر الطلب غير مسموح.'));
        }
    }
}

// ───────────── Audit ─────────────
function audit(string $action, $target = null, $details = null): void
{
    $u = $GLOBALS['USER'] ?? null;
    if (is_array($details)) $details = json_encode($details, JSON_UNESCAPED_UNICODE);
    q('INSERT INTO audit_log (user_id, username, action, target, details, ip, user_agent) VALUES (?,?,?,?,?,?,?)', [
        $u['id'] ?? null,
        $u['username'] ?? null,
        $action,
        $target === null ? null : mb_substr((string)$target, 0, 255),
        $details === null ? null : mb_substr((string)$details, 0, 60000),
        client_ip(),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

// ───────────── Misc ─────────────
/** Log line for an exception without row values: DB messages (e.g. duplicate key) can contain personal data. */
function safe_error(Throwable $ex): string
{
    $msg = $ex instanceof PDOException ? 'SQLSTATE ' . $ex->getCode() : mb_substr($ex->getMessage(), 0, 300);
    return get_class($ex) . ": $msg at " . str_replace(APP_ROOT, '', $ex->getFile()) . ':' . $ex->getLine();
}

function csv_cell($v): string
{
    $v = (string)($v ?? '');
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v; // CSV formula injection
    return '"' . str_replace('"', '""', $v) . '"';
}

function send_csv(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('csv_cell', $header)) . "\r\n";
    foreach ($rows as $r) echo implode(',', array_map('csv_cell', $r)) . "\r\n";
    exit;
}

function money($v): string { return number_format((float)$v, 2); }
function num($v): string { $s = rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }

const AR_MONTHS = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
const AR_DAYS = [1 => 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];
