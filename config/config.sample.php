<?php
// Production config. Lives in hr-system/config (outside any web root). DB credentials come from
// config/.env (mode 600-equivalent ACL, never committed):  DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
$lines = @file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) throw new RuntimeException('config/.env missing or unreadable');
$env = [];
foreach ($lines as $line) { // KEY=VALUE, optional surrounding quotes; no ini parsing quirks with special chars
    if (!preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m)) continue;
    $v = $m[2];
    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
    $env[$m[1]] = $v;
}
return [
    'db' => [
        'host' => $env['DB_HOST'] ?? 'localhost',
        'port' => (int)($env['DB_PORT'] ?? 3306),
        'name' => $env['DB_NAME'],
        'user' => $env['DB_USER'],
        'pass' => $env['DB_PASS'],
    ],
    'timezone'      => 'Africa/Cairo',
    'app_name'      => 'GHIS HR',
    'force_https'   => true,   // redirect HTTP -> HTTPS, Secure cookies
    'trust_proxy'   => false,  // nginx talks to PHP-FPM directly; HTTPS is passed as a FastCGI param
    'idle_timeout'     => 1800,
    'absolute_timeout' => 28800,
    'max_failed_per_user' => 5,
    'lock_minutes'        => 15,
    'max_failed_per_ip'   => 20,
    'ip_window_minutes'   => 15,
    'storage_dir'   => dirname(__DIR__) . '/storage',
    // Local face service (see face-service/). FACE_KEY = base64 of 32 random bytes; encrypts reference photos + embeddings.
    'face' => [
        'key'    => $env['FACE_KEY'] ?? '',
        'token'  => $env['FACE_TOKEN'] ?? '',
        'socket' => $env['FACE_SOCKET'] ?? '/run/gh-face/face.sock',
        'url'    => $env['FACE_URL'] ?? '',
    ],
];
