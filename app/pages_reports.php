<?php
declare(strict_types=1);

// ═════════════ Reports centre (old ReportService.CATALOG): every report on one screen, screen / CSV / Excel / print ═════════════

/*
 * key => [name, description, permission modules (any one is enough), filters].
 * Filters: period (from/to), month, year, emp, emp_req, dept/job (multi), doctype, ended.
 */
const REPORTS = [
    'attendanceSingle'    => ['تقرير حضور موظف واحد', 'حضور وانصراف وخصومات موظف معيّن خلال فترة محددة', ['reports', 'attendance'], ['period', 'emp_req']],
    'attendanceAll'       => ['تقرير حضور كل الموظفين', 'ملخص حضور وخصومات كل الموظفين خلال فترة محددة', ['reports', 'attendance'], ['period', 'dept', 'job']],
    'attendanceGrid'      => ['شبكة حضور الفترة', 'موظفين × أيام الفترة: دقايق التأخير في أيام الحضور، وحالة اليوم في الباقي', ['reports', 'attendance'], ['period', 'dept', 'job']],
    'employees'           => ['بيانات الموظفين', 'قائمة كاملة ببيانات الموظفين النشطين والمنتهية خدمتهم', ['employees'], ['dept', 'job', 'ended']],
    'newHires'            => ['الموظفين الجدد', 'الموظفين اللي اتعينوا خلال فترة محددة', ['employees'], ['period', 'dept', 'job']],
    'endOfService'        => ['نهاية الخدمة', 'الموظفين اللي خدمتهم انتهت خلال فترة محددة، مع حالة استلام الأوراق', ['end_of_service'], ['period', 'dept', 'job']],
    'employeeDocuments'   => ['تقرير مستندات موظف', 'كل المستندات المسجّلة لموظف معيّن وحالتها', ['docs_reports'], ['emp_req']],
    'missingDocuments'    => ['الموظفين الناقص لهم مستندات', 'الموظفين اللي لسه ناقصهم مستندات مطلوبة', ['docs_reports'], ['dept', 'job', 'ended']],
    'nearExpiryDocuments' => ['مستندات قربت تنتهي', 'المستندات اللي قربت تنتهي أو انتهت', ['docs_reports'], ['dept', 'job', 'ended']],
    'documentsByType'     => ['مستندات حسب النوع', 'حالة كل الموظفين بالنسبة لنوع مستند معيّن', ['docs_reports'], ['doctype', 'dept', 'job', 'ended']],
    'advances'            => ['تقرير السلف', 'السلف وأقساطها والمحصّل والمتبقي', ['advances'], ['period', 'dept', 'job']],
    'basicSalaries'       => ['تقرير الرواتب الأساسية', 'الراتب الأساسي الحالي لكل موظف نشط', ['salary'], ['dept', 'job']],
    'investigations'      => ['تقرير التحقيقات', 'التحقيقات وموظفيها وقراراتها', ['investigations'], ['period', 'emp']],
    'summerLog'           => ['سجل خصم الصيف', 'خصومات الصيف وحالة ردّها', ['salary'], ['year', 'dept', 'job']],
    'monthlyClosing'      => ['إقفال شهري', 'كشف مرتبات الفترة، أو نسخته المحفوظة لو الفترة مقفولة', ['payroll'], ['month', 'dept', 'job']],
];

function report_allowed(string $key, string $action = 'view'): bool
{
    foreach (REPORTS[$key][2] ?? [] as $m) if (can($m, $action)) return true;
    return false;
}

function reports_catalog(): array
{
    return array_filter(REPORTS, fn($k) => report_allowed($k), ARRAY_FILTER_USE_KEY);
}

/** Filters from the query string, with the old defaults (this month up to today). */
function report_filters(array $src): array
{
    $ints = fn($v) => array_values(array_filter(array_map('intval', is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v]))));
    $from = valid_date($src['from'] ?? null) ?? date('Y-m-01');
    $to = valid_date($src['to'] ?? null) ?? date('Y-m-d');
    return ['from' => $from, 'to' => $to, 'm' => valid_month($src['m'] ?? null) ?? date('Y-m'), 'year' => (int)($src['year'] ?? 0) ?: (int)date('Y'),
        'emp' => in_int('emp', $src), 'dept' => $ints($src['dept'] ?? null), 'job' => $ints($src['job'] ?? null), 'type' => in_int('type', $src), 'ended' => ($src['ended'] ?? '') === '1'];
}

/** Employees in the user's scope with department and job names, filtered by the multi department / job filters. */
function report_emps(array $f, string $extra = '', array $params = []): array
{
    [$w, $p] = scope_sql('e');
    $sql = "SELECT e.*, d.name dept_name, j.name job_name, s.name spec_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id
            LEFT JOIN specializations s ON s.id = e.specialization_id WHERE $w";
    if ($f['dept']) $sql .= ' AND e.department_id IN (' . implode(',', $f['dept']) . ')';
    if ($f['job']) $sql .= ' AND e.job_title_id IN (' . implode(',', $f['job']) . ')';
    return q_all($sql . ($extra ? " AND $extra" : '') . ' ORDER BY e.id', array_merge($p, $params));
}

function report_emp_filter(array $f): callable
{
    return fn(array $e) => (!$f['dept'] || in_array((int)$e['department_id'], $f['dept'], true)) && (!$f['job'] || in_array((int)$e['job_title_id'], $f['job'], true));
}

/** Builds one report: ['headers' => [...], 'rows' => [[...]], 'totals' => [[label, value]], 'error' => ?string]. Cells keep raw numbers. */
function report_build(string $key, array $f): array
{
    $out = ['headers' => [], 'rows' => [], 'totals' => [], 'error' => null];
    $err = fn(string $m) => ['headers' => [], 'rows' => [], 'totals' => [], 'error' => $m];
    if (in_array('period', REPORTS[$key][3], true) && $f['from'] > $f['to']) return $err(__('تاريخ البداية لازم يكون قبل أو يساوي تاريخ النهاية.'));
    $empCols = [__('الكود'), __('الاسم'), __('القسم'), __('المسمى')];
    $empCells = fn(array $e) => [(int)$e['id'], $e['name_ar'], (string)$e['dept_name'], (string)$e['job_name']];
    $activeSql = '(e.hire_date IS NULL OR e.hire_date <= ?) AND (e.end_date IS NULL OR e.end_date >= ?)';
    switch ($key) {
        case 'attendanceSingle':
            if (!$f['emp'] || !can_see_employee($f['emp'])) return $err(__('اختار الموظف.'));
            $r = att_evaluate($f['emp'], $f['from'], $f['to']);
            if (!$r['emp']) return $err(__('اختار الموظف.'));
            $out['subject'] = $r['emp']['name_ar'] . ' (' . (int)$r['emp']['id'] . ')';
            $out['headers'] = [__('التاريخ'), __('اليوم'), __('الحالة'), __('حضور فعلي'), __('انصراف فعلي'), __('حضور رسمي'), __('انصراف رسمي'), __('تأخير (د)'), __('إعفاء (د)'), __('انصراف مبكر (د)'), __('بدون انصراف'), __('خصم (يوم)'), __('ملاحظات')];
            foreach ($r['days'] as $d) $out['rows'][] = [$d['date'], day_name($d['dow']), __(DAY_STATUS[$d['status']] ?? $d['status']), $d['in'] ? substr($d['in'], 11, 5) : '', $d['out'] ? substr($d['out'], 11, 5) : '',
                (string)$d['sched_in'], (string)$d['sched_out'], $d['late'] ?: '', $d['waiver'] ?: '', $d['early'] ?: '', $d['missing_out'] ? __('نعم') : '', $d['ded'] ? (float)$d['ded'] : '', implode(' · ', $d['notes'])];
            $t = $r['totals'];
            $out['totals'] = [[__('أيام العمل المطلوبة'), $t['work_days']], [__('حضور'), $t['present']], [__('الإجازات'), $t['leave_days']], [__('نسبة الحضور'), $t['attendance_rate'] === null ? '—' : num($t['attendance_rate']) . '%'], [__('مرات التأخير'), $t['late_count']]];
            foreach (ATT_BREAKDOWN as $c) $out['totals'][] = [__($c[0]), $c[2] ? num((float)$t[$c[1]]) : (int)$t[$c[1]]];
            return $out;
        case 'attendanceAll':
        case 'attendanceGrid':
            $emps = report_emps($f, '(e.hire_date IS NULL OR e.hire_date <= ?) AND (e.end_date IS NULL OR e.end_date >= ?)', [$f['to'], $f['from']]);
            $days = [];
            for ($d = $f['from']; $d <= $f['to'] && count($days) < 62; $d = date('Y-m-d', strtotime("$d +1 day"))) $days[] = $d;
            if ($key === 'attendanceGrid' && $f['to'] > end($days)) return $err(__('الشبكة بتتعرض لحد 62 يوم بس.'));
            $sum = ['present' => 0, 'absent' => 0, 'late_min' => 0, 'total_days' => 0.0];
            $out['headers'] = $key === 'attendanceAll'
                ? array_merge($empCols, [__('الحالة'), __('أيام العمل المطلوبة'), __('حضور'), __('نسبة الحضور'), __('مرات التأخير')], att_breakdown_headers())
                : array_merge([__('الكود'), __('الاسم'), __('القسم')], array_map(fn($d) => substr($d, 8) . '/' . substr($d, 5, 2), $days), att_breakdown_headers());
            foreach ($emps as $e) {
                $r = att_evaluate((int)$e['id'], $f['from'], $f['to']);
                $t = $r['totals'];
                foreach ($sum as $k => $_) $sum[$k] += $t[$k];
                if ($key === 'attendanceAll') {
                    $out['rows'][] = array_merge($empCells($e), [__(employee_status($e)), $t['work_days'], $t['present'], $t['attendance_rate'] === null ? '' : $t['attendance_rate'] . '%', $t['late_count']], att_breakdown_values($t));
                } else {
                    $by = [];
                    foreach ($r['days'] as $d) $by[$d['date']] = grid_text(grid_cell($d));
                    $out['rows'][] = array_merge([(int)$e['id'], $e['name_ar'], (string)$e['dept_name']], array_map(fn($d) => $by[$d] ?? '-', $days), att_breakdown_values($t));
                }
            }
            $out['totals'] = [[__('موظف'), count($emps)], [__('حضور'), $sum['present']], [__('غياب'), $sum['absent']], [__('دقيقة تأخير'), $sum['late_min']], [__('إجمالي أيام الخصم'), num(round($sum['total_days'], 2))]];
            return $out;
        case 'employees':
            $today = date('Y-m-d');
            $emps = report_emps($f, $f['ended'] ? '' : '(e.end_date IS NULL OR e.end_date >= ?)', $f['ended'] ? [] : [$today]);
            $out['headers'] = array_merge(array_slice($empCols, 0, 3), [__('التخصص')], array_slice($empCols, 3), [__('الاسم بالإنجليزي'), __('تاريخ التعيين'), __('تاريخ نهاية الخدمة'), __('الحالة'), __('رقم التليفون'), __('الجنسية'), __('طريقة القبض'), __('معفى من البصمة')]);
            $active = 0;
            foreach ($emps as $e) {
                $on = !$e['end_date'] || $e['end_date'] >= $today;
                $active += $on ? 1 : 0;
                $out['rows'][] = array_merge(array_slice($empCells($e), 0, 3), [(string)$e['spec_name']], array_slice($empCells($e), 3), [(string)$e['name_en'], (string)$e['hire_date'], (string)$e['end_date'], __($on ? 'نشط' : 'منتهي الخدمة'), (string)$e['phone'], (string)$e['nationality'],
                    __(['cash' => 'كاش', 'visa' => 'فيزا'][$e['pay_method']] ?? ''), __($e['attendance_exempt'] ? 'نعم' : 'لا')]);
            }
            $out['totals'] = [[__('موظف'), count($emps)], [__('نشط'), $active], [__('منتهي الخدمة'), count($emps) - $active]];
            return $out;
        case 'newHires':
        case 'endOfService':
            $col = $key === 'newHires' ? 'hire_date' : 'end_date';
            $emps = report_emps($f, "e.$col BETWEEN ? AND ?", [$f['from'], $f['to']]);
            $out['headers'] = $key === 'newHires' ? array_merge($empCols, [__('تاريخ التعيين')]) : array_merge($empCols, [__('تاريخ التعيين'), __('تاريخ نهاية الخدمة'), __('استلم أوراقه بعد ترك الخدمة')]);
            foreach ($emps as $e) $out['rows'][] = $key === 'newHires' ? array_merge($empCells($e), [$e['hire_date']]) : array_merge($empCells($e), [(string)$e['hire_date'], $e['end_date'], __($e['papers_received'] ? 'نعم' : 'لا')]);
            $out['totals'] = [[__('موظف'), count($emps)]];
            return $out;
        case 'employeeDocuments':
        case 'missingDocuments':
        case 'nearExpiryDocuments':
        case 'documentsByType':
            $tab = ['employeeDocuments' => 'employee', 'missingDocuments' => 'missing', 'nearExpiryDocuments' => 'near', 'documentsByType' => 'type'][$key];
            if ($tab === 'employee' && !$f['emp']) return $err(__('اختار الموظف.'));
            if ($tab === 'type' && !$f['type']) return $err(__('اختار نوع المستند.'));
            $rows = doc_report_rows($tab, ['dept' => null, 'job' => null, 'type' => $f['type'], 'emp' => $f['emp'], 'ended' => $f['ended']]);
            $keep = report_emp_filter($f);
            $rows = array_values(array_filter($rows, fn($r) => $keep($r['emp'])));
            if ($tab === 'missing') {
                $out['headers'] = array_merge($empCols, [__('عدد الناقص'), __('المستندات الناقصة')]);
                foreach ($rows as $r) $out['rows'][] = array_merge($empCells($r['emp']), [count($r['types']), implode('، ', $r['types'])]);
                $out['totals'] = [[__('موظف'), count($rows)], [__('مستند ناقص'), array_sum(array_map(fn($r) => count($r['types']), $rows))]];
                return $out;
            }
            $copy = ['original' => 'أصل', 'copy' => 'صورة'];
            $out['headers'] = array_merge($tab === 'employee' ? [] : $empCols, [__('نوع المستند'), __('الحالة'), __('رقم المستند'), __('أصل أم صورة'), __('تاريخ الإصدار'), __('تاريخ الانتهاء'), __('الأيام الباقية')]);
            $count = [];
            foreach ($rows as $r) {
                $d = $r['doc'];
                $count[$r['status']] = ($count[$r['status']] ?? 0) + 1;
                $out['rows'][] = array_merge($tab === 'employee' ? [] : $empCells($r['emp']), [$r['type']['name'], __($r['status']), (string)($d['doc_number'] ?? ''), $d ? __($copy[$d['is_original']] ?? '') : '', (string)($d['issue_date'] ?? ''), (string)($d['expiry_date'] ?? ''), $r['days_left'] ?? '']);
            }
            if ($tab === 'employee' && $rows) $out['subject'] = $rows[0]['emp']['name_ar'] . ' (' . (int)$rows[0]['emp']['id'] . ')';
            foreach ($count as $s => $n) $out['totals'][] = [__($s), $n];
            return $out;
        case 'advances':
            $ids = array_column(report_emps($f), null, 'id');
            $today = date('Y-m-d');
            $out['headers'] = array_merge($empCols, [__('تاريخ السلفة'), __('الإجمالي'), __('عدد الأقساط'), __('بداية الخصم'), __('المحصّل'), __('أقساط محصّلة'), __('المتبقي'), __('الحالة')]);
            $tot = ['total' => 0.0, 'collected' => 0.0, 'remaining' => 0.0];
            foreach (q_all('SELECT * FROM advances WHERE advance_date BETWEEN ? AND ? ORDER BY advance_date, id', [$f['from'], $f['to']]) as $a) {
                if (!isset($ids[$a['employee_id']])) continue;
                $p = advance_progress($a, $today);
                if ($a['status'] !== 'cancelled') $tot['total'] += (float)$a['total'];
                $tot['collected'] += $p['collected']; $tot['remaining'] += $p['remaining'];
                $out['rows'][] = array_merge($empCells($ids[$a['employee_id']]), [$a['advance_date'], (float)$a['total'], (int)$a['installments'], $a['deduct_from'], (float)$p['collected'], $p['collected_count'], (float)$p['remaining'],
                    __(['active' => 'سارية', 'paid' => 'مسددة', 'cancelled' => 'ملغاة'][$a['status']] ?? $a['status'])]);
            }
            $out['totals'] = [[__('عدد السلف'), count($out['rows'])], [__('الإجمالي'), money($tot['total'])], [__('المحصّل'), money($tot['collected'])], [__('المتبقي'), money($tot['remaining'])]];
            return $out;
        case 'basicSalaries':
            $today = date('Y-m-d');
            $out['headers'] = array_merge($empCols, [__('الأساسي الحالي'), __('ساري من'), __('ملاحظات')]);
            $sum = 0.0; $missing = 0;
            foreach (report_emps($f, $activeSql, [$today, $today]) as $e) {
                $b = basic_current((int)$e['id'], $today);
                $sum += $b['basic'] ?? 0; $missing += $b['basic'] === null ? 1 : 0;
                $out['rows'][] = array_merge($empCells($e), [$b['basic'] ?? '', (string)$b['effective_from'], trim(($b['basic'] === null ? __('مفيش راتب أساسي مسجَّل') : '') . ($b['dups'] ? ' ' . __('أساسيين بنفس التاريخ') : ''))]);
            }
            $out['totals'] = [[__('موظف'), count($out['rows'])], [__('إجمالي الأساسي'), money($sum)], [__('من غير راتب أساسي'), $missing]];
            return $out;
        case 'investigations':
            [$w, $p] = inv_visible_sql();
            $sql = "SELECT i.* FROM investigations i WHERE $w AND i.cancelled = 0 AND i.inv_date BETWEEN ? AND ?";
            $p = array_merge($p, [$f['from'], $f['to']]);
            if ($f['emp']) { $sql .= ' AND EXISTS (SELECT 1 FROM investigation_employees x WHERE x.investigation_id = i.id AND x.cancelled = 0 AND x.employee_id = ?)'; $p[] = $f['emp']; }
            $out['headers'] = [__('رقم التحقيق'), __('التاريخ'), __('الموضوع'), __('الموظفين'), __('القرار/النتيجة'), __('ملاحظات'), __('مرفقات'), __('المستخدم')];
            foreach (q_all($sql . ' ORDER BY i.inv_date, i.id', $p) as $i) {
                $names = array_map(fn($x) => $x['id'] . ' - ' . $x['name_ar'], q_all('SELECT e.id, e.name_ar FROM investigation_employees ie JOIN employees e ON e.id = ie.employee_id WHERE ie.investigation_id = ? AND ie.cancelled = 0 ORDER BY e.id', [$i['id']]));
                $out['rows'][] = [$i['inv_no'] ?: '#' . $i['id'], $i['inv_date'], $i['subject'], implode('، ', $names), (string)$i['decision'], (string)$i['notes'],
                    (int)q_val('SELECT COUNT(*) FROM investigation_attachments WHERE investigation_id = ? AND cancelled = 0', [$i['id']]), (string)$i['created_by']];
            }
            $out['totals'] = [[__('تحقيق'), count($out['rows'])]];
            return $out;
        case 'summerLog':
            $ids = array_column(report_emps($f), null, 'id');
            $out['headers'] = array_merge($empCols, [__('الشهر'), __('السنة'), __('الأساسي وقتها'), __('المبلغ'), __('النوع'), __('حالة الرد'), __('تاريخ الرد')]);
            $total = 0.0; $refunded = 0.0;
            foreach (q_all('SELECT * FROM summer_deduction_log WHERE year_no = ? ORDER BY employee_id, month_no', [$f['year']]) as $s) {
                if (!isset($ids[$s['employee_id']])) continue;
                $total += (float)$s['amount']; $refunded += $s['refund_status'] === 'تم' ? (float)$s['amount'] : 0;
                $out['rows'][] = array_merge($empCells($ids[$s['employee_id']]), [month_name((int)$s['month_no']), (int)$s['year_no'], (float)$s['basic_salary'], (float)$s['amount'], (string)$s['ded_type'], (string)$s['refund_status'], (string)$s['refunded_at']]);
            }
            $out['totals'] = [[__('الإجمالي'), money($total)], [__('اترد'), money($refunded)], [__('لسه'), money($total - $refunded)]];
            return $out;
        case 'monthlyClosing':
            [$from, $to, $per] = payroll_range($f['m']);
            $snap = $per && $per['status'] === 'closed' ? period_snapshot((int)$per['id']) : null;
            if ($per && $per['status'] === 'closed' && !$snap) return $err(__('الفترة مقفولة لكن مفيش نسخة إقفال معتمدة ليها. افتحها تاني وأقفلها من جديد.'));
            $emps = array_column(report_emps($f), null, 'id');
            $rows = [];
            if ($snap) { foreach (snapshot_rows((int)$snap['id']) as $id => $r) if (isset($emps[$id])) $rows[] = $r; }
            else foreach ($emps as $id => $e) if ((!$e['hire_date'] || $e['hire_date'] <= $to) && (!$e['end_date'] || $e['end_date'] >= $from)) $rows[] = payroll_compute((int)$id, $from, $to, $f['m']);
            $out['subject'] = __('من %s لحد %s', $from, $to) . ($snap ? ' · ' . __('من نسخة الإقفال') : '');
            $out['headers'] = payroll_table_headers();
            $sum = ['basic' => 0.0, 'ded' => 0.0, 'net' => 0.0];
            foreach ($rows as $r) {
                $out['rows'][] = payroll_table_row($r);
                $sum['basic'] += (float)$r['basic']; $sum['ded'] += (float)$r['total_deductions']; $sum['net'] += (float)$r['net_final'];
            }
            $out['totals'] = [[__('موظف'), count($rows)], [__('إجمالي الأساسي'), money($sum['basic'])], [__('إجمالي الخصومات'), money($sum['ded'])], [__('إجمالي الصافي'), money($sum['net'])]];
            return $out;
    }
    return $err(__('التقرير مش موجود.'));
}

/** Display text of a raw cell (money-style floats, everything else as is). */
function report_cell($v): string
{
    if (is_float($v)) return number_format($v, 2);
    return (string)$v;
}

/** Old print rule: the paper follows the width of the table (A4 portrait, A4 landscape, then A3 landscape). */
function report_paper(int $cols): string
{
    return $cols <= 8 ? 'a4p' : ($cols <= 16 ? 'a4l' : 'a3l');
}

function send_xlsx(string $filename, string $sheet, array $headers, array $rows): never
{
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $x = new XlsxWriter();
    $x->addSheet($sheet, array_merge([$headers], $rows), array_map(fn($h) => max(10, min(40, mb_strlen((string)$h) + 4)), $headers));
    $x->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

const EXPORT_FORMATS = ['csv', 'xlsx', 'print'];

/** The export format asked for on this request (?export=csv|xlsx|print), or null. */
function export_format(): ?string
{
    $f = $_GET['export'] ?? '';
    return in_array($f, EXPORT_FORMATS, true) ? $f : null;
}

/** One table out as CSV, Excel or a printable page (old Export.buttons: طباعة / PDF / Excel). The caller checks the export permission. */
function send_export(string $title, string $file, array $headers, array $rows, string $subject = ''): never
{
    $fmt = export_format() ?? 'csv';
    $rows = array_map(fn($r) => array_map(fn($c) => is_float($c) ? number_format($c, 2) : (string)$c, array_values($r)), $rows);
    if ($fmt === 'csv') send_csv("$file.csv", $headers, $rows);
    if ($fmt === 'xlsx') send_xlsx("$file.xlsx", mb_substr($title, 0, 31), $headers, $rows);
    $paper = in_array($_GET['paper'] ?? '', ['a4p', 'a4l', 'a3l'], true) ? $_GET['paper'] : report_paper(count($headers));
    render('table_print', ['title' => $title, 'headers' => $headers, 'rows' => $rows, 'subject' => $subject, 'paper' => $paper], false);
    exit;
}

/** Excel / CSV download buttons for a page whose handler calls send_export(). Printing is the one print button in the top bar. */
function export_links(string $route, array $q): string
{
    $h = '';
    foreach (['xlsx' => 'Excel', 'csv' => 'CSV'] as $f => $l) $h .= '<a class="btn small" href="' . e(url($route, $q + ['export' => $f])) . '">⬇ ' . e($l) . '</a> ';
    return $h;
}

function page_reports(): void
{
    $cat = reports_catalog();
    if (!$cat) http_error(403);
    render('reports', ['title' => __('مركز التقارير'), 'cat' => $cat]);
}

function page_report(): void
{
    $key = $_GET['rep'] ?? '';
    if (!isset(REPORTS[$key])) http_error(404);
    if (!report_allowed($key)) { audit('access.denied', "report.$key"); http_error(403); }
    $f = report_filters($_GET);
    $r = report_build($key, $f);
    $name = __(REPORTS[$key][0]);
    $fmt = $_GET['format'] ?? '';
    if (in_array($fmt, ['csv', 'xlsx', 'print'], true) && !$r['error']) {
        if (!report_allowed($key, 'export')) { audit('access.denied', "report.$key.export"); http_error(403); }
        audit('export.' . $fmt, 'report.' . $key, ['rows' => count($r['rows'])]);
        $file = $key . '-' . date('Ymd');
        if ($fmt === 'csv') send_csv("$file.csv", $r['headers'], array_map(fn($row) => array_map('report_cell', $row), $r['rows']));
        if ($fmt === 'xlsx') send_xlsx("$file.xlsx", $name, $r['headers'], $r['rows']);
        $paper = in_array($_GET['paper'] ?? '', ['a4p', 'a4l', 'a3l'], true) ? $_GET['paper'] : report_paper(count($r['headers']));
        render('report_print', ['title' => $name, 'key' => $key, 'f' => $f, 'r' => $r, 'paper' => $paper], false);
        return;
    }
    render('report', ['title' => $name, 'key' => $key, 'f' => $f, 'r' => $r, 'canExport' => report_allowed($key, 'export'),
        'depts' => fk_options(['fk' => ['departments', 'name']]), 'jobs' => fk_options(['fk' => ['job_titles', 'name']]),
        'types' => fk_options(['fk' => ['document_types', 'name']]), 'emps' => employee_options()]);
}
