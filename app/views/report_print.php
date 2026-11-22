<?php $period = in_array('period', REPORTS[$key][3], true) ? __('من %s لحد %s', $f['from'], $f['to']) : (in_array('month', REPORTS[$key][3], true) ? $f['m'] : (in_array('year', REPORTS[$key][3], true) ? (string)$f['year'] : '')); ?>
<!doctype html>
<html lang="<?= e(lang()) ?>" dir="<?= e(dir_attr()) ?>">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('print.css')) ?>">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body class="paper-<?= e($paper) ?>">
<div class="no-print toolbar">
  <button type="button" data-print><?= e(__('طباعة / حفظ PDF')) ?></button>
  <?php foreach (['a4p' => 'A4 طولي', 'a4l' => 'A4 عرضي', 'a3l' => 'A3 عرضي'] as $p => $l): ?>
    <a href="<?= e(url('report', array_merge($_GET, ['r' => null, 'paper' => $p]))) ?>"<?= $p === $paper ? ' class="on"' : '' ?>><?= e(__($l)) ?></a>
  <?php endforeach; ?>
  <span class="hint"><?= e(__('لحفظ PDF: اختار «Save as PDF / حفظ كـ PDF» من نافذة الطباعة.')) ?></span>
</div>
<?php $phTitle = $title; $phChips = [$period, $r['subject'] ?? '']; require __DIR__ . '/_print_head.php'; ?>
<table class="cols-<?= count($r['headers']) > 24 ? 'many' : 'some' ?>">
  <thead><tr><?php foreach ($r['headers'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
  <tbody><?php foreach ($r['rows'] as $row): ?><tr><?php foreach (array_values($row) as $i => $c): ?><td<?= $i === 1 ? ' class="name"' : '' ?>><?= e(report_cell($c)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
</table>
<?php if ($r['totals']): ?><div class="totals"><?php foreach ($r['totals'] as [$l, $v]): ?><span><?= e($l) ?>: <b><?= e((string)$v) ?></b></span><?php endforeach; ?></div><?php endif; ?>
<footer class="foot"><span>Green Heights International School · <?= e(__('نظام شئون العاملين')) ?></span><span><?= count($r['headers']) ?> <?= e(__('عمود')) ?> · <?= count($r['rows']) ?> <?= e(__('صف')) ?></span></footer>
</body>
</html>
