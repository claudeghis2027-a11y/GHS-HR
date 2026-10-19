<?php
declare(strict_types=1);
/*
 * Creates/updates the schema, loads base data and creates the super admin.
 *   php bin/install.php                       (asks for super admin username/password)
 *   HR_SUPER_USER=x HR_SUPER_PASS=y php bin/install.php   (non-interactive)
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';

function run_sql_file(string $file): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql)))) as $stmt) {
        db()->exec($stmt);
    }
}

echo "PHP " . PHP_VERSION . ", argon2id: " . (defined('PASSWORD_ARGON2ID') ? 'yes' : 'no (bcrypt fallback)') . "\n";
foreach (['pdo_mysql', 'mbstring', 'zip', 'simplexml', 'fileinfo'] as $ext) {
    echo str_pad($ext, 10) . (extension_loaded($ext) ? 'ok' : 'MISSING') . "\n";
}
run_sql_file(APP_ROOT . '/database/schema.sql');
run_sql_file(APP_ROOT . '/database/seed.sql');
echo "Schema + base data ready.\n";
// Make the audit trail append-only at the database level (the app never updates/deletes it).
foreach (['UPDATE' => 'audit_log_no_update', 'DELETE' => 'audit_log_no_delete'] as $ev => $name) {
    try {
        if (!q_val('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [$name])) {
            db()->exec("CREATE TRIGGER $name BEFORE $ev ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'");
        }
        echo "Trigger $name ok\n";
    } catch (PDOException $ex) {
        echo "WARNING: could not create trigger $name (code {$ex->getCode()}) — audit log not DB-locked\n";
    }
}

$superRole = (int)q_val("SELECT id FROM roles WHERE slug = 'super_admin'");
if (q_val('SELECT 1 FROM users WHERE role_id = ?', [$superRole])) {
    echo "Super admin already exists — skipped.\n";
    exit(0);
}
$user = getenv('HR_SUPER_USER') ?: null;
$pass = getenv('HR_SUPER_PASS') ?: null;
if (!$user) { echo 'Super admin username: '; $user = trim((string)fgets(STDIN)); }
if (!$pass) {
    echo 'Super admin password (hidden): ';
    if (DIRECTORY_SEPARATOR === '/') system('stty -echo');
    $pass = trim((string)fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') system('stty echo');
    echo "\n";
}
$user = mb_strtolower($user);
if (!preg_match('/^[a-z0-9._-]{3,64}$/', $user)) { fwrite(STDERR, "Invalid username.\n"); exit(1); }
if ($p = password_problems($pass, $user)) { fwrite(STDERR, "Weak password: $p\n"); exit(1); }
if (q_val('SELECT 1 FROM users WHERE username = ?', [$user])) { fwrite(STDERR, "Username already taken.\n"); exit(1); }
q('INSERT INTO users (username, display_name, password_hash, role_id, password_changed_at) VALUES (?,?,?,?,NOW())', [$user, 'Super Admin', hash_password($pass), $superRole]);
audit('install.super_admin', $user);
echo "Super admin '$user' created.\n";
