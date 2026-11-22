<?php
$filters = REPORTS[$key][3];
$has = fn($x) => in_array($x, $filters, true);
$q = array_filter(['rep' => $key, 'from' => $has('period') ? $f['from'] : null, 'to' => $has('period') ? $f['to'] : null, 'm' => $has('month') ? $f['m'] : null, 'year' => $has('year') ? $f['year'] : null,
    'emp' => $f['emp'], 'type' => $f['type'], 'dept' => $f['dept'] ?: null, 'job' => $f['job'] ?: null, 'ended' => $f['ended'] ? 1 : null], fn($v) => $v !== null);
$multi = function (string $name, array $opts, array $sel, string $all) { ?>
  <label><?= e(__($all)) ?> <select name="<?= $name ?>[]" multiple size="4"><?php foreach ($opts as $k => $v): ?><option value="<?= (int)$k ?>"<?= in_array((int)$k, $sel, true) ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
<?php };
?>
<div class="page-head">
  <div><h1><?= e(__(REPORTS[$key][0])) ?></h1><div class="sub"><?= e(__(REPORTS[$key][1])) ?><?= !empty($r['subject']) ? ' · ' . e($r['subject']) : '' ?></div></div>
  <a class="btn" href="<?= e(url('reports')) ?>"><?= e(__('كل التقارير')) ?></a>
</div>
<form method="get" class="filters card">
  <input type="hidden" name="r" value="report"><input type="hidden" name="rep" value="<?= e($key) ?>">
  <?php if ($has('period')): ?>
    <label><?= e(__('من')) ?> <input type="date" name="from" value="<?= e($f['from']) ?>" required></label>
    <label><?= e(__('إلى')) ?> <input type="date" name="to" value="<?= e($f['to']) ?>" required></label>
  <?php endif; ?>
  <?php if ($has('month')): ?><label><?= e(__('الشهر')) ?> <input type="month" name="m" value="<?= e($f['m']) ?>" required></label><?php endif; ?>
  <?php if ($has('year')): ?><label><?= e(__('السنة')) ?> <input type="number" name="year" value="<?= (int)$f['year'] ?>" min="2000" max="2100" required></label><?php endif; ?>
  <?php if ($has('emp') || $has('emp_req')): ?>
    <label><?= e(__('الموظف')) ?> <select name="emp"<?= $has('emp_req') ? ' required' : '' ?>><option value=""><?= e(__($has('emp_req') ? 'اختار الموظف' : 'كل الموظفين')) ?></option><?php foreach ($emps as $k => $v): ?><option value="<?= (int)$k ?>"<?= $f['emp'] === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
  <?php endif; ?>
  <?php if ($has('doctype')): ?>
    <label><?= e(__('نوع المستند')) ?> <select name="type" required><option value=""><?= e(__('اختار نوع المستند')) ?></option><?php foreach ($types as $k => $v): ?><option value="<?= (int)$k ?>"<?= $f['type'] === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
  <?php endif; ?>
  <?php if ($has('dept')) $multi('dept', $depts, $f['dept'], 'الأقسام (فاضي = الكل)'); ?>
  <?php if ($has('job')) $multi('job', $jobs, $f['job'], 'المسميات (فاضي = الكل)'); ?>
  <?php if ($has('ended')): ?><label class="check"><input type="checkbox" name="ended" value="1"<?= $f['ended'] ? ' checked' : '' ?>> <?= e(__('يشمل اللي سابوا الشغل')) ?></label><?php endif; ?>
  <button class="btn small primary"><?= e(__('عرض')) ?></button>
</form>
<?php if ($r['error']): ?><div class="flash err"><?= e($r['error']) ?></div><?php else: ?>
<div class="filters">
  <?php if ($canExport): ?>
    <?php $printUrl = url('report', $q + ['format' => 'print']); // the top-bar print button opens the full report in the print layout ?>
    <a class="btn small" href="<?= e(url('report', $q + ['format' => 'xlsx'])) ?>">⬇ Excel</a>
    <a class="btn small" href="<?= e(url('report', $q + ['format' => 'csv'])) ?>">⬇ CSV</a>
  <?php endif; ?>
</div>
<?php if ($r['totals']): ?><div class="stats"><?php foreach ($r['totals'] as [$l, $v]): ?><div class="stat"><span><?= e((string)$v) ?></span><?= e($l) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="table-wrap">
<table>
  <thead><tr><?php foreach ($r['headers'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($r['rows'] as $row): ?><tr><?php foreach ($row as $c): ?><td<?= is_int($c) || is_float($c) ? ' class="num"' : '' ?>><?= e(report_cell($c)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  <?php if (!$r['rows']): ?><tr><td colspan="<?= max(1, count($r['headers'])) ?>" class="muted"><?= e(__('مفيش نتايج.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
