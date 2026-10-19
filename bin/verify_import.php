<?php
declare(strict_types=1);
/*
 * Reconciles the Excel workbooks against the database after an import.
 * Prints counts and totals only (never names, phones or per-person values).
 *   php bin/verify_import.php --db=<HR database.xlsx> --att=<attendance.xlsx>
 * Exit code 0 = everything matches, 1 = at least one mismatch.
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';

$opt = getopt('', ['db:', 'att:']);
if (empty($opt['db']) || empty($opt['att'])) { fwrite(STDERR, "Usage: --db=<xlsx> --att=<xlsx>\n"); exit(2); }
$DB = new XlsxReader($opt['db']);
$AT = new XlsxReader($opt['att']);
$TZ = new DateTimeZone(config('timezone', 'Africa/Cairo'));

function cell($r, $k) { $v = $r[$k] ?? ''; return is_string($v) ? trim($v) : $v; }
function day($x): ?string
{
    global $TZ;
    if ($x === '' || $x === null) return null;
    if (is_int($x) || is_float($x)) return gmdate('Y-m-d', (int)round(($x - 25569) * 86400));
    try { return (new DateTimeImmutable((string)$x, $TZ))->setTimezone($TZ)->format('Y-m-d'); } catch (Exception) { return null; }
}
$rows = [];
$bad = 0;
function check(string $metric, $excel, $db): void
{
    global $rows, $bad;
    $ok = is_float($excel) || is_float($db) ? abs((float)$excel - (float)$db) < 0.005 : (string)$excel === (string)$db;
    if (!$ok) $bad++;
    $rows[] = [$metric, $excel, $db, $ok ? 'OK' : 'MISMATCH'];
}

// ── employees ──
$codes = [];
foreach ($DB->records('بيانات الموظفين') as $r) if ((int)cell($r, 'كود الموظف') > 0) $codes[(int)cell($r, 'كود الموظف')] = 1;
foreach ($AT->records('بيانات الموظفين') as $r) if ((int)cell($r, 'كود الموظف') > 0) $codes[(int)cell($r, 'كود الموظف')] = 1;
check('employees', count($codes), (int)q_val('SELECT COUNT(*) FROM employees'));
check('employee accounts (attendance passwords)', count(array_filter($AT->records('كلمات المرور'), fn($r) => isset($codes[(int)cell($r, 'كود الموظف')]))),
      (int)q_val('SELECT COUNT(*) FROM users WHERE employee_id IS NOT NULL'));

// ── attendance per month ──
$attX = [];
foreach ($AT->sheetNames() as $s) {
    if (!preg_match('/^\d{4}-\d{2}$/', $s)) continue;
    foreach ($AT->records($s) as $r) if (isset($codes[(int)cell($r, 'كود الموظف')])) {
        $d = day(cell($r, 'التاريخ')) ?? day(cell($r, 'وقت الحضور'));
        $attX[substr((string)$d, 0, 7)] = ($attX[substr((string)$d, 0, 7)] ?? 0) + 1;
    }
}
$attD = [];
foreach (q_all("SELECT DATE_FORMAT(work_date, '%Y-%m') m, COUNT(*) c FROM attendance WHERE source = 'import' GROUP BY m") as $r) $attD[$r['m']] = (int)$r['c'];
foreach (array_unique(array_merge(array_keys($attX), array_keys($attD))) as $m) check("attendance rows $m", $attX[$m] ?? 0, $attD[$m] ?? 0);
check('attendance rows total', array_sum($attX), array_sum($attD));

// ── salary transactions: active amount per month, split additions / deductions ──
$dir = [];
foreach ($DB->records('أنواع حركات المرتب') as $r) $dir[(string)cell($r, 'اسم النوع')] = cell($r, 'إضافة أم خصم؟') === 'خصم' ? 'deduct' : 'add';
$tx = [];
foreach ($DB->records('حركات المرتب') as $r) {
    if (!isset($codes[(int)cell($r, 'كود الموظف')])) continue;
    $tx[] = ['from' => day(cell($r, 'من تاريخ')), 'to' => day(cell($r, 'لحد تاريخ (فاضي = مستمرة)')), 'amt' => (float)cell($r, 'القيمة'), 'dir' => $dir[(string)cell($r, 'نوع الحركة')] ?? 'add'];
}
check('salary transactions (count)', count($tx), (int)q_val('SELECT COUNT(*) FROM salary_tx'));
$first = min(array_merge(array_filter(array_column($tx, 'from')), [date('Y-m-d')]));
for ($m = substr($first, 0, 7); $m <= date('Y-m'); $m = date('Y-m', strtotime("$m-01 +1 month"))) {
    [$a, $b] = [$m . '-01', date('Y-m-t', strtotime("$m-01"))];
    foreach (['add' => 'additions', 'deduct' => 'deductions'] as $k => $label) {
        $x = 0.0;
        foreach ($tx as $t) if ($t['dir'] === $k && $t['from'] && $t['from'] <= $b && (!$t['to'] || $t['to'] >= $a)) $x += $t['amt'];
        $y = (float)q_val('SELECT COALESCE(SUM(t.amount), 0) FROM salary_tx t JOIN salary_tx_types ty ON ty.id = t.type_id
                           WHERE ty.direction = ? AND t.date_from <= ? AND (t.date_to IS NULL OR t.date_to >= ?)', [$k, $b, $a]);
        check("salary $label $m", round($x, 2), round($y, 2));
    }
}

// ── deductions & advances ──
$sumBy = function (array $recs, string $dateCol, string $valCol) use ($codes): array {
    $o = [];
    foreach ($recs as $r) if (isset($codes[(int)cell($r, 'كود الموظف')]) && ($d = day(cell($r, $dateCol)))) {
        $m = substr($d, 0, 7);
        $o[$m] = [($o[$m][0] ?? 0) + 1, ($o[$m][1] ?? 0) + (float)cell($r, $valCol)];
    }
    return $o;
};
$pairs = [
    ['extra deductions (days)', $sumBy($DB->records('سجل الخصومات الإضافية والجزاءات'), 'التاريخ', 'عدد الأيام المخصومة'), "SELECT DATE_FORMAT(ded_date,'%Y-%m') m, COUNT(*) c, SUM(days) s FROM extra_deductions GROUP BY m"],
    ['advances (total)', $sumBy($DB->records('سلف الموظفين'), 'تاريخ السلفة', 'الإجمالي'), "SELECT DATE_FORMAT(advance_date,'%Y-%m') m, COUNT(*) c, SUM(total) s FROM advances GROUP BY m"],
    ['late exemptions (minutes)', $sumBy($DB->records('إعفاءات التأخير المعتمدة'), 'التاريخ', 'دقائق الإعفاء المعتمدة'), "SELECT DATE_FORMAT(ex_date,'%Y-%m') m, COUNT(*) c, SUM(minutes) s FROM late_exemptions GROUP BY m"],
    ['permissions', $sumBy($DB->records('سجل الأذونات'), 'التاريخ', 'كود الموظف'), "SELECT DATE_FORMAT(perm_date,'%Y-%m') m, COUNT(*) c, NULL s FROM permission_requests GROUP BY m"],
];
foreach ($pairs as [$label, $x, $sql]) {
    $y = [];
    foreach (q_all($sql) as $r) $y[$r['m']] = [(int)$r['c'], (float)$r['s']];
    $months = array_unique(array_merge(array_keys($x), array_keys($y)));
    if (!$months) { check("$label — rows", 0, 0); continue; }
    foreach ($months as $m) {
        check("$label $m — rows", $x[$m][0] ?? 0, $y[$m][0] ?? 0);
        if ($label !== 'permissions') check("$label $m — sum", round($x[$m][1] ?? 0, 2), round($y[$m][1] ?? 0, 2));
    }
}
$sx = 0.0; $sc = 0;
foreach ($DB->records('سجل خصم واسترداد الصيف') as $r) if (isset($codes[(int)cell($r, 'كود الموظف')])) { $sc++; $sx += (float)cell($r, 'المبلغ المخصوم فعليًا'); }
check('summer deductions — rows', $sc, (int)q_val('SELECT COUNT(*) FROM summer_deduction_log'));
check('summer deductions — sum', round($sx, 2), round((float)q_val('SELECT COALESCE(SUM(amount),0) FROM summer_deduction_log'), 2));

// ── print ──
$w = max(array_map(fn($r) => mb_strlen($r[0]), $rows)) + 2;
printf("%s%14s%14s  %s\n", str_pad('metric', $w), 'excel', 'database', 'result');
foreach ($rows as [$m, $x, $y, $s]) printf("%s%14s%14s  %s\n", $m . str_repeat(' ', $w - mb_strlen($m)), $x, $y, $s);
echo $bad ? "\n$bad MISMATCH(ES)\n" : "\nALL MATCH\n";
exit($bad ? 1 : 0);
