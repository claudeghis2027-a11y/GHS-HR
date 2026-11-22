<?php
declare(strict_types=1);

/*
 * Backups (old BackupService) kept on the server, outside the web root: one gzip file of JSON lines
 * (a header line, then per table its columns and one line per row). Restore is per table and only
 * ADDS rows that are missing (INSERT IGNORE): nothing is ever deleted or overwritten.
 */

const BACKUP_MAGIC = 'ghs-hr-backup';

function backup_dir(): string
{
    $d = config('storage_dir') . '/backups';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

function backup_tables(): array
{
    return q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
}

function size_text(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

/** Writes a full backup and registers it; returns the backups_log id. Throws on failure (the partial file is removed). */
function backup_create(string $description, string $by): int
{
    $name = 'hr-backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.jsonl.gz';
    $path = backup_dir() . '/' . $name;
    $gz = gzopen($path, 'wb6');
    if (!$gz) throw new RuntimeException('backup: cannot write');
    try {
        $tables = backup_tables();
        gzwrite($gz, json_encode(['magic' => BACKUP_MAGIC, 'version' => 1, 'at' => date('Y-m-d H:i:s'), 'tables' => $tables], JSON_UNESCAPED_UNICODE) . "\n");
        foreach ($tables as $t) {
            $st = db()->query("SELECT * FROM `$t`");
            $cols = [];
            for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = $st->getColumnMeta($i)['name'];
            gzwrite($gz, json_encode(['t' => $t, 'cols' => $cols], JSON_UNESCAPED_UNICODE) . "\n");
            while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                $line = json_encode(['t' => $t, 'r' => $row], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($line === false) throw new RuntimeException("backup: encode failed in $t");
                gzwrite($gz, $line . "\n");
            }
        }
        gzclose($gz);
    } catch (Throwable $ex) {
        @gzclose($gz); @unlink($path);
        throw $ex;
    }
    q('INSERT INTO backups_log (at, description, source, size_text, file_ref, done_by) VALUES (NOW(),?,?,?,?,?)', [mb_substr($description, 0, 255), 'HR', size_text((int)filesize($path)), $name, $by]);
    $id = (int)db()->lastInsertId();
    audit('backup.create', $id, ['description' => $description]);
    return $id;
}

/** A registered backup whose file is on this server (old Drive backups are listed but not here). */
function backup_file(int $id): ?array
{
    $b = q_one('SELECT * FROM backups_log WHERE id = ?', [$id]);
    if (!$b || !preg_match('/^hr-backup-[\w-]+\.jsonl\.gz$/', (string)$b['file_ref']) || !is_file(backup_dir() . '/' . $b['file_ref'])) return null;
    return $b + ['path' => backup_dir() . '/' . $b['file_ref']];
}

/** Header line of a backup file, or null when it is not one of ours. */
function backup_header(string $path): ?array
{
    $gz = @gzopen($path, 'rb');
    if (!$gz) return null;
    $h = json_decode((string)gzgets($gz, 1 << 20), true);
    gzclose($gz);
    return is_array($h) && ($h['magic'] ?? '') === BACKUP_MAGIC ? $h : null;
}

/** Old restore: one table, only the rows missing now are added. Returns [error|null, rows added, rows in the backup]. */
function backup_restore(string $path, string $table): array
{
    if (!in_array($table, backup_tables(), true)) return [__('الجدول مش معروف.'), 0, 0];
    $now = array_column(q_all("SHOW COLUMNS FROM `$table`"), 'Field');
    $gz = gzopen($path, 'rb');
    if (!$gz || !backup_header($path)) return [__('ملف النسخة غير صالح.'), 0, 0];
    gzgets($gz, 1 << 20);
    $cols = null; $keep = []; $added = 0; $seen = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        while (($line = gzgets($gz, 1 << 26)) !== false) {
            if (!str_contains($line, '"t":' . json_encode($table, JSON_UNESCAPED_UNICODE) . ',')) continue;
            $x = json_decode($line, true);
            if (!is_array($x) || ($x['t'] ?? '') !== $table) continue;
            if (isset($x['cols'])) {
                $cols = $x['cols'];
                $keep = array_values(array_filter(array_keys($cols), fn($i) => in_array($cols[$i], $now, true)));
                $sql = "INSERT IGNORE INTO `$table` (" . implode(',', array_map(fn($i) => '`' . $cols[$i] . '`', $keep)) . ') VALUES (' . implode(',', array_fill(0, count($keep), '?')) . ')';
                $ins = $pdo->prepare($sql);
                continue;
            }
            if ($cols === null || !isset($x['r'])) continue;
            $seen++;
            $ins->execute(array_map(fn($i) => $x['r'][$i], $keep));
            $added += $ins->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        gzclose($gz);
        throw $ex;
    }
    gzclose($gz);
    if ($cols === null) return [__('الجدول ده مش موجود في النسخة دي.'), 0, 0];
    return [null, $added, $seen];
}

// ───────────── Data reset (old SystemService.resetData) ─────────────

/** The only tables the reset screen may clear (security, settings and the attendance app are never listed). */
const RESET_GROUPS = [
    'employees'         => ['بيانات الموظفين', ['employees']],
    'employeeHistory'   => ['سجل تعديلات بيانات الموظفين', ['employee_change_log']],
    'employeeDocs'      => ['مستندات الموظفين', ['employee_documents']],
    'investigations'    => ['التحقيقات ومرفقاتها', ['investigation_attachments', 'investigation_employees', 'investigations']],
    'enrollment'        => ['طلبات تعديل البيانات', ['profile_update_requests']],
    'attendanceAdj'     => ['تعديلات الحضور والإجازات', ['attendance_adjustments', 'leaves']],
    'forgotCheckin'     => ['سجل نسيان البصمة', ['forgot_punch_log']],
    'lateWaivers'       => ['إعفاءات التأخير والأذونات', ['late_exemptions', 'permission_requests']],
    'checkoutExc'       => ['استثناءات الانصراف', ['checkout_exemptions']],
    'scheduleOverrides' => ['مواعيد خاصة لموظفين', ['employee_schedules']],
    'salaryTx'          => ['حركات المرتب', ['salary_tx']],
    'advances'          => ['السلف وأقساطها', ['advances']],
    'extraPenalties'    => ['الخصومات الإضافية والجزاءات', ['extra_deductions']],
    'summerDeduction'   => ['سجل خصم الصيف', ['summer_deduction_log']],
    'closingPeriods'    => ['فترات المرتبات وسجل الإقفال', ['payroll_period_log', 'payroll_periods']],
    'closingSnapshots'  => ['نسخ الإقفال الشهري', ['payroll_snapshot_rows', 'payroll_snapshots']],
    'notifications'     => ['التنبيهات والإشعارات', ['notification_targets', 'notifications']],
];

/** Mandatory backup first; when it fails nothing is deleted. Returns [error|null, rows cleared, backup id]. */
function data_reset(array $groups, string $by): array
{
    $groups = array_values(array_unique($groups));
    if (!$groups) return [__('اختار عنصر واحد على الأقل.'), 0, null];
    foreach ($groups as $g) if (!isset(RESET_GROUPS[$g])) return [__('عنصر غير معروف: %s', $g), 0, null];
    try { $bid = backup_create('قبل إعادة ضبط البيانات', $by); }
    catch (Throwable $ex) { error_log(safe_error($ex)); return [__('فشل إنشاء النسخة الاحتياطية — ماتمّ حذف أي شيء.'), 0, null]; }
    $total = 0;
    q('SET foreign_key_checks = 0');
    try {
        foreach ($groups as $g) foreach (RESET_GROUPS[$g][1] as $t) $total += q("DELETE FROM `$t`")->rowCount();
    } finally {
        q('SET foreign_key_checks = 1');
    }
    audit('system.resetData', implode(',', $groups), ['rows' => $total, 'backup' => $bid]);
    return [null, $total, $bid];
}
