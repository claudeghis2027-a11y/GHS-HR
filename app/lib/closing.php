<?php
declare(strict_types=1);

/*
 * Monthly closing — a port of the old GHIS ClosingService: official periods never overlap, gaps between them are
 * warned about, closing freezes the attendance grid and the pay of every employee, and a closed period is shown
 * from that snapshot only (never recalculated live). Reopening needs a reason and supersedes the snapshot.
 */

/** Old findPeriodGaps_: uncovered days between consecutive official periods. */
function period_gaps(): array
{
    $ps = q_all('SELECT id, date_from, date_to FROM payroll_periods ORDER BY date_from, id');
    $gaps = [];
    for ($i = 0; $i < count($ps) - 1; $i++) {
        $end = $ps[$i]['date_to']; $next = $ps[$i + 1]['date_from'];
        $diff = (int)round((strtotime("$next 00:00:00 UTC") - strtotime("$end 00:00:00 UTC")) / 86400);
        if ($diff > 1) $gaps[] = ['from' => date('Y-m-d', strtotime("$end +1 day")), 'to' => date('Y-m-d', strtotime("$next -1 day")), 'days' => $diff - 1];
    }
    return $gaps;
}

function gaps_text(array $gaps): string
{
    return implode(' · ', array_map(fn($g) => __('%s → %s (%d يوم)', $g['from'], $g['to'], $g['days']), $gaps));
}

/** Old checkDateRangeOverlap_: the first row of $table whose [date_from, date_to] overlaps the range, other than $exceptId. */
function range_overlap(string $table, string $from, string $to, ?int $exceptId = null): ?array
{
    return q_one("SELECT * FROM `$table` WHERE date_from <= ? AND date_to >= ? AND id <> ? ORDER BY date_from LIMIT 1", [$to, $from, $exceptId ?? 0]);
}

function period_label(array $per): string
{
    return $per['name'] ?: __('فترة %d', (int)$per['id']);
}

/** Keys a snapshot row always has, so snapshots saved by older versions still render. */
const SNAPSHOT_DEFAULTS = ['errors' => [], 'basic' => 0.0, 'daily_value' => 0.0, 'allowance' => 0.0, 'earnings' => 0.0, 'additions' => 0.0, 'deductions' => 0.0, 'advances' => 0.0,
    'quarter' => 0.0, 'summer_rule' => '', 'att_days' => 0.0, 'att_amount' => 0.0, 'gross' => 0.0, 'total_deductions' => 0.0, 'net' => 0.0, 'lines' => [], 'att' => [], 'cells' => []];

/** The ready snapshot of a period, or null. */
function period_snapshot(int $periodId): ?array
{
    return q_one("SELECT * FROM payroll_snapshots WHERE period_id = ? AND state = 'ready' ORDER BY id DESC LIMIT 1", [$periodId]);
}

/** Snapshot rows decoded, keyed by employee id, limited to the user's department scope. */
function snapshot_rows(int $snapshotId, ?int $dept = null, ?int $job = null): array
{
    [$w, $p] = scope_sql('e');
    $sql = "SELECT r.* FROM payroll_snapshot_rows r JOIN employees e ON e.id = r.employee_id WHERE r.snapshot_id = ? AND $w";
    $p = array_merge([$snapshotId], $p);
    if ($dept) { $sql .= ' AND e.department_id = ?'; $p[] = $dept; }
    if ($job) { $sql .= ' AND e.job_title_id = ?'; $p[] = $job; }
    $out = [];
    foreach (q_all($sql . ' ORDER BY r.id', $p) as $r) {
        $d = (json_decode($r['data_json'], true) ?: []) + SNAPSHOT_DEFAULTS;
        if (!isset($d['net_final'])) $d['net_final'] = round5((float)$d['net']); // snapshots taken before the round-5 rule
        $d['emp'] = ['id' => (int)$r['employee_id'], 'name_ar' => $r['name_ar'], 'dept_name' => $r['department']];
        $d['errors'] ??= [];
        $out[(int)$r['employee_id']] = $d;
    }
    return $out;
}

/** Compact attendance cell of one day, as the old closing grid stored it. */
function grid_cell(array $d): array
{
    return ['d' => $d['date'], 's' => $d['status'], 'late' => (int)$d['late'], 'early' => (int)$d['early'], 'in' => $d['in'], 'out' => $d['out']];
}

/** Text shown in a grid cell: late minutes on a present day (old: '0' when on time), otherwise the day status. */
function grid_text(array $c): string
{
    if ($c['s'] === 'present') return (string)$c['late'];
    return __(DAY_STATUS[$c['s']] ?? $c['s']);
}

/**
 * Old ClosingService.close: freezes the pay and the attendance grid of every employee in the period, verifies what
 * was written, then marks the period closed. Throws RuntimeException (nothing is saved) on any failure.
 */
function period_close(int $periodId, string $by, string $note, bool $ackGaps): array
{
    $per = q_one('SELECT * FROM payroll_periods WHERE id = ?', [$periodId]);
    if (!$per) throw new RuntimeException(__('الفترة مش موجودة.'));
    if ($per['status'] === 'closed') throw new RuntimeException(__('الفترة "%s" مقفولة بالفعل.', period_label($per)));
    $gaps = period_gaps();
    if ($gaps && !$ackGaps) return ['ok' => false, 'needs_gap_ack' => true, 'gaps' => $gaps];
    $ym = substr($per['date_to'], 0, 7);
    $emps = q_all('SELECT e.id, e.name_ar, d.name dept FROM employees e LEFT JOIN departments d ON d.id = e.department_id
                   WHERE (e.hire_date IS NULL OR e.hire_date <= ?) AND (e.end_date IS NULL OR e.end_date >= ?) ORDER BY e.id', [$per['date_to'], $per['date_from']]);
    db()->beginTransaction();
    try {
        q("INSERT INTO payroll_snapshots (period_id, period_name, date_from, date_to, employees, closed_by, state, diagnostics) VALUES (?,?,?,?,?,?,'building',?)",
            [$periodId, $per['name'], $per['date_from'], $per['date_to'], count($emps), $by, json_encode(['gaps' => $gaps], JSON_UNESCAPED_UNICODE)]);
        $sid = (int)db()->lastInsertId();
        foreach ($emps as $e) {
            $c = payroll_compute((int)$e['id'], $per['date_from'], $per['date_to'], $ym);
            $c['cells'] = array_map('grid_cell', $c['days']);
            unset($c['emp'], $c['days']);
            q('INSERT INTO payroll_snapshot_rows (snapshot_id, employee_id, name_ar, department, data_json) VALUES (?,?,?,?,?)',
                [$sid, $e['id'], $e['name_ar'], $e['dept'], json_encode($c, JSON_UNESCAPED_UNICODE)]);
        }
        // Old validateSnapshot_: check what was actually written before the period is marked closed.
        $saved = q_all('SELECT employee_id, data_json FROM payroll_snapshot_rows WHERE snapshot_id = ?', [$sid]);
        if (count($saved) !== count($emps)) throw new RuntimeException(__('عدد الصفوف المحفوظة (%d) مش مطابق للمتوقَّع (%d).', count($saved), count($emps)));
        $ids = [];
        foreach ($saved as $r) {
            $d = json_decode($r['data_json'], true);
            if (!is_array($d) || !isset($d['cells'], $d['net_final'])) throw new RuntimeException(__('صف الموظف %d مش قابل للقراءة.', (int)$r['employee_id']));
            $ids[(int)$r['employee_id']] = true;
        }
        foreach ($emps as $e) if (!isset($ids[(int)$e['id']])) throw new RuntimeException(__('الموظف %d مش موجود في الصفوف المحفوظة.', (int)$e['id']));
        q("UPDATE payroll_snapshots SET state = 'superseded' WHERE period_id = ? AND state = 'ready'", [$periodId]);
        q("UPDATE payroll_snapshots SET state = 'ready' WHERE id = ?", [$sid]);
        q("UPDATE payroll_periods SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ?", [$by, $periodId]);
        q('INSERT INTO payroll_period_log (period_id, action, done_by, reason, gap_warning, gap_details) VALUES (?,?,?,?,?,?)',
            [$periodId, 'close', $by, $note, $gaps ? 1 : 0, $gaps ? gaps_text($gaps) : null]);
        audit('period.close', $periodId, ['snapshot' => $sid, 'employees' => count($emps), 'gaps' => count($gaps)]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollBack();
        throw $ex instanceof RuntimeException ? new RuntimeException(__('فشل التحقق من نسخة الإقفال: %s الفترة فضلت مفتوحة ومفيش نسخة معتمدة.', $ex->getMessage())) : $ex;
    }
    return ['ok' => true, 'snapshot' => $sid, 'employees' => count($emps), 'gaps' => $gaps];
}

/** Old ClosingService.reopen: a reason is required; the ready snapshot is superseded and the period is open again. */
function period_reopen(int $periodId, string $by, string $reason): int
{
    if (trim($reason) === '') throw new RuntimeException(__('لازم تكتب سبب إعادة الفتح.'));
    $per = q_one('SELECT * FROM payroll_periods WHERE id = ?', [$periodId]);
    if (!$per) throw new RuntimeException(__('الفترة مش موجودة.'));
    if ($per['status'] !== 'closed') throw new RuntimeException(__('الفترة "%s" مفتوحة بالفعل.', period_label($per)));
    db()->beginTransaction();
    $n = q("UPDATE payroll_snapshots SET state = 'superseded' WHERE period_id = ? AND state = 'ready'", [$periodId])->rowCount();
    q("UPDATE payroll_periods SET status = 'open' WHERE id = ?", [$periodId]);
    q('INSERT INTO payroll_period_log (period_id, action, done_by, reason) VALUES (?,?,?,?)', [$periodId, 'reopen', $by, $reason]);
    audit('period.reopen', $periodId, ['reason' => $reason, 'superseded' => $n]);
    db()->commit();
    return $n;
}
