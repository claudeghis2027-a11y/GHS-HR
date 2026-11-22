<?php
declare(strict_types=1);

/** Row of $rows whose [$a,$b] date range covers $d. Like the old sheets, the first match wins (rules never overlap). */
function covering(array $rows, string $d, string $a = 'date_from', string $b = 'date_to'): ?array
{
    foreach ($rows as $r) if ($r[$a] <= $d && $d <= $r[$b]) return $r;
    return null;
}

/** Minutes since midnight of a 'Y-m-d H:i:s' or 'H:i[:s]' value (seconds ignored, like the old sheets). */
function day_minutes(string $v): int
{
    if (!preg_match('/(\d{1,2}):(\d{2})/', strlen($v) > 10 ? substr($v, 11) : $v, $m)) return 0;
    return (int)$m[1] * 60 + (int)$m[2];
}

/** Old permission ladder: the number of permissions in the period, capped at one day. */
function permission_deduction(int $count): float
{
    return [0 => 0.0, 1 => 0.0, 2 => 0.25, 3 => 0.5, 4 => 0.75][$count] ?? 1.0;
}

/** Old forgotten-punch ladder: the first one is free, each one after it costs a quarter day. */
function forgot_deduction(int $count): float
{
    return max(0, $count - 1) * 0.25;
}

/** A holiday typed in by hand (old «الإجازات الرسمية» sheet), not a row of the state calendar. */
function holiday_is_manual(array $r): bool
{
    if (($r['htype'] ?? '') === 'SCHOOL_HOLIDAY') return true;
    return ($r['htype'] ?? '') === 'OFFICIAL_HOLIDAY' && empty($r['original_date']) && in_array((string)($r['source'] ?? ''), ['', 'إدخال يدوي'], true);
}

/** Old HolidayService.transferWindow: the dates an occasion moved across, except its observed day. date => [title, observed, original]. */
function holiday_transfer_window(): array
{
    $out = [];
    foreach (q_all("SELECT title, original_date, actual_date, holiday_date FROM holidays WHERE htype = 'OFFICIAL_HOLIDAY' AND status IN ('ACTIVE','CANCELLED') AND original_date IS NOT NULL") as $r) {
        $orig = (string)$r['original_date']; $obs = (string)($r['actual_date'] ?: $r['holiday_date']);
        if ($orig === $obs) continue;
        [$a, $b] = $orig < $obs ? [$orig, $obs] : [$obs, $orig];
        for ($k = $a, $g = 0; $k <= $b && $g < 60; $k = date('Y-m-d', strtotime("$k +1 day")), $g++) {
            if ($k !== $obs) $out[$k] = ['title' => (string)$r['title'], 'observed' => $obs, 'original' => $orig];
        }
    }
    return $out;
}

/**
 * Old HolidayService.effectiveMap: the state calendar (a holiday on the weekend stays a weekend)
 * plus the hand-typed holidays, which win — unless an occasion moved away from that date.
 */
function holidays_effective(string $from, string $to): array
{
    $weekend = array_map('intval', explode(',', (string)setting('weekend_days', '5,6')));
    $gov = []; $manual = [];
    foreach (q_all("SELECT * FROM holidays WHERE status = 'ACTIVE' AND holiday_date BETWEEN ? AND ? ORDER BY id", [$from, $to]) as $h) {
        if (holiday_is_manual($h)) { $manual[$h['holiday_date']] = $h['title']; continue; }
        if ($h['htype'] !== 'OFFICIAL_HOLIDAY' || in_array((int)date('N', strtotime($h['holiday_date'])), $weekend, true)) continue;
        $gov[$h['holiday_date']] = $h['title'];
    }
    if ($manual) {
        $win = holiday_transfer_window();
        foreach ($manual as $d => $t) if (!isset($win[$d])) $gov[$d] = $t;
    }
    return $gov;
}

/**
 * Day-by-day attendance for one employee — a port of the old GHIS AttendanceCalc
 * (classifyDay + employeeReport + employeeReportWithDeductions), same order of rules:
 *  - late and early-leave minutes are summed per deduction-rule period, late waivers are
 *    subtracted from that sum, then floor(minutes / unit) × unit value; no daily cap;
 *  - permissions and forgotten punches are counted over the whole period and priced on the
 *    old fixed ladders; they never change a day's status;
 *  - absence and missing check-out are priced per day from the day's deduction rule.
 */
function att_evaluate(int $empId, string $from, string $to): array
{
    $emp = q_one('SELECT e.*, d.name dept_name, d.attendance_exempt dept_exempt FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ?', [$empId]);
    if (!$emp) return ['emp' => null, 'days' => [], 'totals' => []];
    $range = [$to, $from];
    $weekend = array_map('intval', explode(',', (string)setting('weekend_days', '5,6')));

    $att = [];
    foreach (q_all('SELECT * FROM attendance WHERE employee_id = ? AND work_date BETWEEN ? AND ?', [$empId, $from, $to]) as $r) $att[$r['work_date']] = $r;
    $rules   = q_all('SELECT * FROM schedule_rules WHERE date_from <= ? AND date_to >= ? ORDER BY date_from, id', $range);
    $own     = q_all('SELECT * FROM employee_schedules WHERE employee_id = ? AND date_from <= ? AND date_to >= ? ORDER BY date_from, id', array_merge([$empId], $range));
    $dedRules= q_all('SELECT * FROM deduction_rules WHERE date_from <= ? AND date_to >= ? ORDER BY date_from, id', $range);
    $holidays = holidays_effective($from, $to);
    $leaves  = q_all('SELECT * FROM leaves WHERE employee_id = ? AND date_from <= ? AND date_to >= ?', array_merge([$empId], $range));
    // Latest adjustment per (day, kind) wins, like the old getAdjustmentsMap_.
    $adj = [];
    foreach (q_all('SELECT * FROM attendance_adjustments WHERE employee_id = ? AND adj_date BETWEEN ? AND ? ORDER BY created_at, id', [$empId, $from, $to]) as $r) {
        $adj[$r['adj_date']][$r['adj_type']] = $r;
    }
    $waivers = [];
    foreach (q_all('SELECT ex_date, SUM(minutes) m FROM late_exemptions WHERE employee_id = ? AND ex_date BETWEEN ? AND ? GROUP BY ex_date', [$empId, $from, $to]) as $r) $waivers[$r['ex_date']] = (int)$r['m'];
    $coEx = q_all("SELECT * FROM checkout_exemptions WHERE date_from <= ? AND date_to >= ? AND (scope = 'all' OR (scope = 'department' AND scope_value = ?) OR (scope = 'employee' AND scope_value = ?))",
        array_merge($range, [(string)$emp['department_id'], (string)$empId]));
    $permCount = (int)q_val('SELECT COUNT(*) FROM permission_requests WHERE employee_id = ? AND perm_date BETWEEN ? AND ?', [$empId, $from, $to]);
    $forgotCount = (int)q_val('SELECT COUNT(*) FROM forgot_punch_log WHERE employee_id = ? AND punch_date BETWEEN ? AND ?', [$empId, $from, $to]);
    $perms = array_flip(array_column(q_all('SELECT perm_date FROM permission_requests WHERE employee_id = ? AND perm_date BETWEEN ? AND ?', [$empId, $from, $to]), 'perm_date'));
    $forgot = array_flip(array_column(q_all('SELECT punch_date FROM forgot_punch_log WHERE employee_id = ? AND punch_date BETWEEN ? AND ?', [$empId, $from, $to]), 'punch_date'));
    $extra = [];
    foreach (q_all('SELECT ded_date, SUM(days) d, GROUP_CONCAT(reason SEPARATOR "، ") r FROM extra_deductions WHERE employee_id = ? AND ded_date BETWEEN ? AND ? GROUP BY ded_date', [$empId, $from, $to]) as $r) $extra[$r['ded_date']] = $r;
    $exemptEmp = $emp['attendance_exempt'] || $emp['dept_exempt'];

    $today = date('Y-m-d');
    $nowMin = day_minutes(date('H:i'));
    $T = ['work_days' => 0, 'present' => 0, 'absent' => 0, 'absent_no_rule' => 0, 'leave_days' => 0, 'late_count' => 0, 'late_min' => 0, 'late_waiver_min' => 0,
          'late_min_final' => 0, 'late_days_original' => 0.0, 'late_days' => 0.0, 'early_count' => 0, 'early_min' => 0, 'early_days' => 0.0,
          'missing_out' => 0, 'missing_out_days' => 0.0, 'absent_days' => 0.0, 'perms' => $permCount, 'perm_days' => permission_deduction($permCount),
          'forgot' => $forgotCount, 'forgot_days' => forgot_deduction($forgotCount), 'extra_days' => 0.0, 'total_days' => 0.0, 'attendance_rate' => null];
    $lateByRule = []; $lateFinalByRule = []; $earlyByRule = []; $ruleById = [];
    $days = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $row = ['date' => $d, 'dow' => (int)date('N', strtotime($d)), 'in' => null, 'out' => null, 'status' => '', 'late' => 0, 'early' => 0, 'ded' => 0.0, 'notes' => [],
                'sched_in' => null, 'sched_out' => null, 'waiver' => 0, 'missing_out' => false];
        $a = $att[$d] ?? null;
        $x = $adj[$d] ?? [];
        foreach ($x as $r) if ($r['reason']) $row['notes'][] = __('تعديل: ') . $r['reason'];
        $exclude = isset($x['waive_day']);
        $outside = ($emp['hire_date'] && $d < $emp['hire_date']) || ($emp['end_date'] && $d > $emp['end_date']);
        $in = $a['check_in_at'] ?? null;
        $out = $a['check_out_at'] ?? null;

        if (isset($holidays[$d]) && !$exclude && !$outside) {
            $row['status'] = 'holiday'; $row['notes'][] = $holidays[$d];
            $row['in'] = $in; $row['out'] = $out; // shown only, no effect
        }
        elseif ($d > $today)                                    { $row['status'] = 'future'; }
        elseif ($exclude)                                       { $row['status'] = 'waived'; }
        elseif ($emp['hire_date'] && $d < $emp['hire_date'])    { $row['status'] = 'before_hire'; }
        elseif ($emp['end_date'] && $d > $emp['end_date'])      { $row['status'] = 'after_end'; }
        elseif ($exemptEmp)                                     { $row['status'] = 'exempt'; }
        elseif (in_array($row['dow'], $weekend, true))          { $row['status'] = 'weekend'; $row['in'] = $in; $row['out'] = $out; }
        elseif ($lv = covering($leaves, $d))                    { $row['status'] = 'leave'; if (!empty($lv['reason'])) $row['notes'][] = $lv['reason']; }
        else {
            if (isset($x['force_absent'])) { $in = null; $out = null; }
            else {
                if (isset($x['set_check_in']) && preg_match('/^\d{2}:\d{2}$/', (string)$x['set_check_in']['value'])) $in = "$d {$x['set_check_in']['value']}:00";
                if (isset($x['set_check_out']) && preg_match('/^\d{2}:\d{2}$/', (string)$x['set_check_out']['value'])) $out = "$d {$x['set_check_out']['value']}:00";
                if (isset($x['force_present']) && !$in) $in = "$d " . (preg_match('/^\d{2}:\d{2}$/', (string)$x['force_present']['value']) ? $x['force_present']['value'] : '00:00') . ':00';
            }
            $row['in'] = $in; $row['out'] = $out;
            $sched = covering($own, $d) ?? covering($rules, $d);
            $rule = covering($dedRules, $d);
            if (!$in && $d === $today && $sched && $nowMin < day_minutes($sched['start_time'])) {
                $row['status'] = 'today'; // before today's start time: neutral
            } elseif (!$in) {
                $row['status'] = 'absent'; $T['absent']++; $T['work_days']++;
                $row['ded'] = $rule ? (float)$rule['absence_days'] : 0.0;
                $T['absent_days'] += $row['ded'];
                if (!$rule || $row['ded'] <= 0) $T['absent_no_rule']++;
            } else {
                $row['status'] = 'present'; $T['present']++; $T['work_days']++;
                $rk = $rule ? (string)$rule['id'] : 'none';
                if ($rule) $ruleById[$rk] = $rule;
                if ($sched) {
                    $row['sched_in'] = substr((string)$sched['start_time'], 0, 5); $row['sched_out'] = substr((string)$sched['end_time'], 0, 5);
                    $row['late'] = max(0, day_minutes($in) - day_minutes($sched['start_time']));
                    if ($out) $row['early'] = max(0, day_minutes($sched['end_time']) - day_minutes($out));
                } else {
                    $row['notes'][] = __('مفيش قاعدة مواعيد لليوم ده');
                }
                if ($row['late'] > 0) {
                    $T['late_count']++; $T['late_min'] += $row['late'];
                    $lateByRule[$rk] = ($lateByRule[$rk] ?? 0) + $row['late'];
                    $w = min($waivers[$d] ?? 0, $row['late']);
                    $lateFinalByRule[$rk] = ($lateFinalByRule[$rk] ?? 0) + $row['late'] - $w;
                    if ($w > 0) { $row['waiver'] = $w; $T['late_waiver_min'] += $w; $row['notes'][] = __('إعفاء تأخير %d دقيقة', $w); }
                }
                if ($row['early'] > 0) {
                    $T['early_count']++; $T['early_min'] += $row['early'];
                    if ($rule && $rule['early_leave_enabled']) $earlyByRule[$rk] = ($earlyByRule[$rk] ?? 0) + $row['early'];
                }
                if (!$out && !covering($coEx, $d, 'date_from', 'date_to')) {
                    $row['notes'][] = __('بدون بصمة انصراف');
                    $T['missing_out']++; $row['missing_out'] = true;
                    if ($rule) { $row['ded'] = (float)$rule['missing_checkout_days']; $T['missing_out_days'] += $row['ded']; }
                }
            }
            if (isset($perms[$d])) $row['notes'][] = __('إذن');
            if (isset($forgot[$d])) $row['notes'][] = __('نسيان بصمة');
        }
        if (in_array($row['status'], ['holiday', 'weekend', 'leave'], true)) $T['leave_days']++;
        if (isset($extra[$d])) {
            $row['ded'] += (float)$extra[$d]['d'];
            $T['extra_days'] += (float)$extra[$d]['d'];
            $row['notes'][] = __('جزاء: ') . $extra[$d]['r'];
        }
        $days[] = $row;
    }
    foreach ($lateByRule as $rk => $m) {
        if (!isset($ruleById[$rk])) continue;
        $r = $ruleById[$rk];
        $unit = max(1, (int)$r['late_unit_min']);
        $T['late_days_original'] += floor($m / $unit) * (float)$r['late_unit_days'];
        $T['late_days'] += floor(($lateFinalByRule[$rk] ?? 0) / $unit) * (float)$r['late_unit_days'];
    }
    foreach ($earlyByRule as $rk => $m) {
        $r = $ruleById[$rk];
        $T['early_days'] += floor($m / max(1, (int)$r['early_unit_min'])) * (float)$r['early_unit_days'];
    }
    $T['late_min_final'] = max(0, $T['late_min'] - $T['late_waiver_min']);
    $T['attendance_rate'] = $T['work_days'] > 0 ? round($T['present'] / $T['work_days'] * 100, 1) : null;
    foreach (['late_days_original', 'late_days', 'early_days', 'missing_out_days', 'absent_days', 'extra_days'] as $k) $T[$k] = round($T[$k], 2);
    $T['total_days'] = round($T['absent_days'] + $T['missing_out_days'] + $T['late_days'] + $T['early_days'] + $T['perm_days'] + $T['forgot_days'] + $T['extra_days'], 2);
    return ['emp' => $emp, 'days' => $days, 'totals' => $T];
}

const DAY_STATUS = [
    'present' => 'حاضر', 'absent' => 'غائب', 'weekend' => 'عطلة أسبوعية', 'holiday' => 'عطلة رسمية', 'leave' => 'إجازة',
    'exempt' => 'معفى', 'waived' => 'مستثنى (تعديل يدوي)', 'today' => 'لسه ماجاش ميعاد الحضور', 'future' => '—', 'before_hire' => 'قبل التعيين', 'after_end' => 'بعد نهاية الخدمة',
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

/** Old per-employee deduction breakdown (monthly closing / attendance report columns): [label, totals key, is-days]. */
const ATT_BREAKDOWN = [
    ['تأخير (د)', 'late_min', false], ['إعفاء (د)', 'late_waiver_min', false], ['تأخير نهائي (د)', 'late_min_final', false], ['خصم التأخير', 'late_days', true],
    ['انصراف مبكر (د)', 'early_min', false], ['خصم الانصراف', 'early_days', true], ['غياب', 'absent', false], ['خصم الغياب', 'absent_days', true],
    ['بدون انصراف', 'missing_out', false], ['خصم عدم الانصراف', 'missing_out_days', true], ['أذونات', 'perms', false], ['خصم الأذونات', 'perm_days', true],
    ['نسيان تسجيل', 'forgot', false], ['خصم النسيان', 'forgot_days', true], ['جزاءات', 'extra_days', true], ['إجمالي الخصم', 'total_days', true],
];

function att_breakdown_headers(): array
{
    return array_map(fn($c) => __($c[0]), ATT_BREAKDOWN);
}

/** Raw values in ATT_BREAKDOWN order (days as floats, counts/minutes as ints). */
function att_breakdown_values(array $t): array
{
    return array_map(fn($c) => $c[2] ? (float)($t[$c[1]] ?? 0) : (int)($t[$c[1]] ?? 0), ATT_BREAKDOWN);
}
