<?php
declare(strict_types=1);

/** Rows of $table whose [$a,$b] date range covers $d (ranges already filtered to the period). */
function covering(array $rows, string $d, string $a = 'date_from', string $b = 'date_to'): ?array
{
    $hit = null;
    foreach ($rows as $r) if ($r[$a] <= $d && $d <= $r[$b]) $hit = $r; // later rows win
    return $hit;
}

function minutes_between(string $fromDt, string $toDt): int
{
    return (int)floor((strtotime($toDt) - strtotime($fromDt)) / 60);
}

/**
 * Day-by-day attendance evaluation for one employee, mirroring the rules of the old sheets:
 * schedule rules, deduction rules (late units, absence, missing check-out, early leave,
 * graded permissions), official holidays, personal leaves, exemptions and adjustments.
 */
function att_evaluate(int $empId, string $from, string $to): array
{
    $emp = q_one('SELECT e.*, d.name dept_name, d.attendance_exempt dept_exempt FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ?', [$empId]);
    if (!$emp) return ['emp' => null, 'days' => [], 'totals' => []];
    $range = [$to, $from];
    $weekend = array_map('intval', explode(',', (string)setting('weekend_days', '5,6')));
    $rounding = setting('late_rounding', 'ceil');

    $att = [];
    foreach (q_all('SELECT * FROM attendance WHERE employee_id = ? AND work_date BETWEEN ? AND ?', [$empId, $from, $to]) as $r) $att[$r['work_date']] = $r;
    $rules   = q_all('SELECT * FROM schedule_rules WHERE date_from <= ? AND date_to >= ? ORDER BY date_from, id', $range);
    $own     = q_all('SELECT * FROM employee_schedules WHERE employee_id = ? AND date_from <= ? AND date_to >= ? ORDER BY date_from, id', array_merge([$empId], $range));
    $dedRules= q_all('SELECT * FROM deduction_rules WHERE date_from <= ? AND date_to >= ? ORDER BY date_from, id', $range);
    $holidays = [];
    foreach (q_all("SELECT holiday_date, title FROM holidays WHERE status = 'ACTIVE' AND holiday_date BETWEEN ? AND ?", [$from, $to]) as $h) $holidays[$h['holiday_date']] = $h['title'];
    $leaves  = q_all('SELECT * FROM leaves WHERE employee_id = ? AND date_from <= ? AND date_to >= ?', array_merge([$empId], $range));
    $adj = [];
    foreach (q_all('SELECT * FROM attendance_adjustments WHERE employee_id = ? AND adj_date BETWEEN ? AND ? ORDER BY id', [$empId, $from, $to]) as $r) $adj[$r['adj_date']][] = $r;
    $forgot = array_flip(array_column(q_all('SELECT punch_date FROM forgot_punch_log WHERE employee_id = ? AND punch_date BETWEEN ? AND ?', [$empId, $from, $to]), 'punch_date'));
    $exempt = [];
    foreach (q_all('SELECT ex_date, SUM(minutes) m FROM late_exemptions WHERE employee_id = ? AND ex_date BETWEEN ? AND ? GROUP BY ex_date', [$empId, $from, $to]) as $r) $exempt[$r['ex_date']] = (int)$r['m'];
    $extra = [];
    foreach (q_all('SELECT ded_date, SUM(days) d, GROUP_CONCAT(reason SEPARATOR "، ") r FROM extra_deductions WHERE employee_id = ? AND ded_date BETWEEN ? AND ? GROUP BY ded_date', [$empId, $from, $to]) as $r) $extra[$r['ded_date']] = $r;
    $coEx = q_all("SELECT * FROM checkout_exemptions WHERE date_from <= ? AND date_to >= ? AND (scope = 'all' OR (scope = 'department' AND scope_value = ?) OR (scope = 'employee' AND scope_value = ?))",
        array_merge($range, [(string)$emp['department_id'], (string)$empId]));
    // Permission ordinal is counted per calendar month, so look back to the 1st of $from's month.
    $permRows = q_all('SELECT perm_date FROM permission_requests WHERE employee_id = ? AND perm_date BETWEEN ? AND ? ORDER BY perm_date, id', [$empId, substr($from, 0, 8) . '01', $to]);
    $permOrd = [];
    $perMonth = [];
    foreach ($permRows as $p) {
        $m = substr($p['perm_date'], 0, 7);
        $perMonth[$m] = ($perMonth[$m] ?? 0) + 1;
        $permOrd[$p['perm_date']] ??= $perMonth[$m];
    }

    $today = date('Y-m-d');
    $T = ['work_days' => 0, 'present' => 0, 'absent' => 0, 'late_min' => 0, 'late_days' => 0.0, 'missing_out' => 0, 'missing_out_days' => 0.0,
          'early_min' => 0, 'early_days' => 0.0, 'perms' => 0, 'perm_days' => 0.0, 'absent_days' => 0.0, 'extra_days' => 0.0, 'total_days' => 0.0];
    $days = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $row = ['date' => $d, 'dow' => (int)date('N', strtotime($d)), 'in' => null, 'out' => null, 'status' => '', 'late' => 0, 'early' => 0, 'ded' => 0.0, 'notes' => []];
        $a = $att[$d] ?? null;
        $in = $a['check_in_at'] ?? null;
        $out = $a['check_out_at'] ?? null;
        $waive = false;
        foreach ($adj[$d] ?? [] as $x) {
            if ($x['adj_type'] === 'set_check_in' && preg_match('/^\d{2}:\d{2}$/', (string)$x['value'])) $in = "$d {$x['value']}:00";
            if ($x['adj_type'] === 'set_check_out' && preg_match('/^\d{2}:\d{2}$/', (string)$x['value'])) $out = "$d {$x['value']}:00";
            if ($x['adj_type'] === 'waive_day') $waive = true;
            $row['notes'][] = __('تعديل: ') . $x['reason'];
        }
        if ($in && $out && $in === $out) $out = null; // single punch recorded as both
        $row['in'] = $in; $row['out'] = $out;

        if ($d > $today)                                            { $row['status'] = 'future'; }
        elseif ($emp['hire_date'] && $d < $emp['hire_date'])       { $row['status'] = 'before_hire'; }
        elseif ($emp['end_date'] && $d > $emp['end_date'])         { $row['status'] = 'after_end'; }
        elseif (isset($holidays[$d]))                              { $row['status'] = 'holiday'; $row['notes'][] = $holidays[$d]; }
        elseif (in_array($row['dow'], $weekend, true) && !$in)     { $row['status'] = 'weekend'; }
        elseif (covering($leaves, $d))                             { $row['status'] = 'leave'; }
        elseif ($emp['attendance_exempt'] || $emp['dept_exempt'])  { $row['status'] = 'exempt'; }
        elseif ($waive)                                            { $row['status'] = 'waived'; $T['work_days']++; }
        else {
            $T['work_days']++;
            $sched = covering($own, $d) ?? covering($rules, $d);
            $rule = covering($dedRules, $d);
            if (!$in && !$out) {
                if (isset($forgot[$d])) { $row['status'] = 'forgot'; }
                elseif ($d === $today) { $row['status'] = 'today'; $T['work_days']--; }
                else {
                    $row['status'] = 'absent'; $T['absent']++;
                    if ($rule) { $row['ded'] += (float)$rule['absence_days']; $T['absent_days'] += (float)$rule['absence_days']; }
                }
            } else {
                $row['status'] = 'present'; $T['present']++;
                $hasPerm = isset($permOrd[$d]);
                if ($hasPerm) {
                    $n = min(5, $permOrd[$d]);
                    $T['perms']++;
                    $pd = $rule ? (float)$rule["perm{$n}_days"] : 0.0;
                    $row['ded'] += $pd; $T['perm_days'] += $pd;
                    $row['notes'][] = __('إذن رقم %d', $permOrd[$d]);
                }
                if ($sched && $in && !$hasPerm) {
                    $late = max(0, minutes_between("$d {$sched['start_time']}", $in) - ($exempt[$d] ?? 0));
                    $row['late'] = $late;
                    $T['late_min'] += $late;
                    if ($late > 0 && $rule) {
                        $u = $late / max(1, (int)$rule['late_unit_min']);
                        $units = $rounding === 'floor' ? floor($u) : ceil($u);
                        $ld = $units * (float)$rule['late_unit_days'];
                        $row['ded'] += $ld; $T['late_days'] += $ld;
                    }
                }
                if (!$out) {
                    if (!isset($forgot[$d]) && !covering($coEx, $d) && $d < $today) {
                        $row['notes'][] = __('بدون بصمة انصراف');
                        $T['missing_out']++;
                        if ($rule) { $row['ded'] += (float)$rule['missing_checkout_days']; $T['missing_out_days'] += (float)$rule['missing_checkout_days']; }
                    }
                } elseif ($sched && !$hasPerm) {
                    $early = max(0, minutes_between($out, "$d {$sched['end_time']}"));
                    $row['early'] = $early;
                    $T['early_min'] += $early;
                    if ($early > 0 && $rule && $rule['early_leave_enabled']) {
                        $u = $early / max(1, (int)$rule['early_unit_min']);
                        $ed = ($rounding === 'floor' ? floor($u) : ceil($u)) * (float)$rule['early_unit_days'];
                        $row['ded'] += $ed; $T['early_days'] += $ed;
                    }
                }
                if (!$sched) $row['notes'][] = __('مفيش قاعدة مواعيد لليوم ده');
                // Coming in late can never cost more than not coming at all.
                if ($rule && $row['ded'] > (float)$rule['absence_days']) {
                    $row['ded'] = (float)$rule['absence_days'];
                    $row['notes'][] = __('الخصم متقيد بحد خصم يوم الغياب');
                }
            }
        }
        if (isset($extra[$d]) && !in_array($row['status'], ['future', 'before_hire', 'after_end'], true)) {
            $row['ded'] += (float)$extra[$d]['d'];
            $T['extra_days'] += (float)$extra[$d]['d'];
            $row['notes'][] = __('جزاء: ') . $extra[$d]['r'];
        }
        $T['total_days'] += $row['ded'];
        $days[] = $row;
    }
    return ['emp' => $emp, 'days' => $days, 'totals' => $T];
}

const DAY_STATUS = [
    'present' => 'حاضر', 'absent' => 'غائب', 'weekend' => 'عطلة أسبوعية', 'holiday' => 'عطلة رسمية', 'leave' => 'إجازة',
    'exempt' => 'معفى', 'waived' => 'معفى بقرار', 'today' => 'لسه ماسجلش النهارده', 'forgot' => 'نسيان بصمة', 'future' => '—', 'before_hire' => 'قبل التعيين', 'after_end' => 'بعد نهاية الخدمة',
];

function month_range(string $ym): array
{
    $from = $ym . '-01';
    return [$from, date('Y-m-t', strtotime($from))];
}

/** Great-circle distance in metres. */
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}
