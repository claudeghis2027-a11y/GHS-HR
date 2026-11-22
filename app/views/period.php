<?php $closed = $per['status'] === 'closed'; $ACT = ['close' => 'إقفال', 'reopen' => 'إعادة فتح']; $ST = ['ready' => 'معتمدة', 'superseded' => 'ملغية (اتفتحت الفترة)', 'building' => 'ناقصة']; ?>
<div class="page-head">
  <div><h1><?= e(period_label($per)) ?></h1>
    <div class="sub"><?= e(__('من %s لحد %s', $per['date_from'], $per['date_to'])) ?> · <span class="badge <?= $closed ? 'green' : 'ochre' ?>"><?= e(__($closed ? 'مقفولة' : 'مفتوحة')) ?></span></div></div>
  <a class="btn" href="<?= e(url('periods')) ?>"><?= e(__('رجوع')) ?></a>
</div>
<?php if ($closed && !$snap): ?><div class="flash err"><?= e(__('الفترة مقفولة لكن مفيش نسخة إقفال معتمدة ليها. افتحها تاني وأقفلها من جديد.')) ?></div><?php endif; ?>
<?php if ($snap && ($snap['date_from'] !== $per['date_from'] || $snap['date_to'] !== $per['date_to'])): ?><div class="flash warn"><?= e(__('تواريخ الفترة اتغيّرت بعد الإقفال. النسخة دي محفوظة بـ %s → %s.', $snap['date_from'], $snap['date_to'])) ?></div><?php endif; ?>

<div class="grid2">
<?php if (!$closed && can('period_close', 'update') && dept_scope() === null): ?>
  <form method="post" action="<?= e(url('payroll')) ?>" class="card" data-confirm="<?= e(__('إقفال الفترة هيحفظ نسخة نهائية من المرتبات وشبكة الحضور. متأكد؟')) ?>">
    <h2><?= e(__('إقفال الفترة')) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="period_id" value="<?= (int)$per['id'] ?>">
    <label><?= e(__('ملاحظة')) ?> <input name="reason" maxlength="500"></label>
    <?php if ($gaps): ?>
    <div class="flash warn"><?= e(__('فيه أيام مش متغطية بين الفترات الرسمية: %s', gaps_text($gaps))) ?></div>
    <label class="check"><input type="checkbox" name="ack_gaps" value="1"> <?= e(__('موافق على الفجوات وعاوز أقفل')) ?></label>
    <?php endif; ?>
    <button class="btn danger"><?= e(__('إقفال')) ?></button>
  </form>
<?php endif; ?>
<?php if ($closed && can('period_reopen', 'update') && dept_scope() === null): ?>
  <form method="post" action="<?= e(url('period')) ?>" class="card" data-confirm="<?= e(__('إعادة الفتح هتلغي نسخة الإقفال، والأرقام هترجع تتحسب من جديد. متأكد؟')) ?>">
    <h2><?= e(__('إعادة فتح الفترة')) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$per['id'] ?>"><input type="hidden" name="action" value="reopen">
    <label><?= e(__('السبب')) ?> * <input name="reason" maxlength="500" required></label>
    <button class="btn"><?= e(__('إعادة فتح')) ?></button>
  </form>
<?php endif; ?>
</div>

<h2><?= e(__('شبكة الحضور')) ?></h2>
<?php if ($snap): ?>
<form method="get" class="filters">
  <input type="hidden" name="r" value="period"><input type="hidden" name="id" value="<?= (int)$per['id'] ?>"><input type="hidden" name="snap" value="<?= (int)$snap['id'] ?>">
  <select name="dept"><option value=""><?= e(__('كل الأقسام')) ?></option><?php foreach ($depts as $k => $v): ?><option value="<?= (int)$k ?>"<?= $dept === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <button class="btn small"><?= e(__('عرض')) ?></button>
  <?php if (can('salary', 'export')): ?><?= export_links('period', ['id' => $per['id'], 'snap' => $snap['id'], 'dept' => $dept]) ?><?php endif; ?>
  <a class="btn small" href="<?= e(url('payroll', ['m' => substr($per['date_to'], 0, 7)])) ?>"><?= e(__('كشف المرتبات')) ?></a>
</form>
<p class="muted"><?= e(__('نسخة رقم %d · %s · أقفلها %s يوم %s · %d موظف.', (int)$snap['id'], __($ST[$snap['state']] ?? $snap['state']), (string)$snap['closed_by'], (string)$snap['closed_at'], (int)$snap['employees'])) ?>
  <?= e(__('في الخانة: دقايق التأخير لو حاضر، أو حالة اليوم.')) ?></p>
<div class="table-wrap">
<table class="grid-table">
  <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th>
    <?php foreach ($days as $d): ?><th class="num" title="<?= e(AR_DAYS[(int)date('N', strtotime($d))]) ?>"><?= e(substr($d, 8) . '/' . substr($d, 5, 2)) ?></th><?php endforeach; ?>
    <?php foreach (att_breakdown_headers() as $h): ?><th><?= e($h) ?></th><?php endforeach; ?><th><?= e(__('الصافي النهائي')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $by = []; foreach ($r['cells'] as $c) $by[$c['d']] = $c; ?>
    <tr><td class="num"><?= (int)$r['emp']['id'] ?></td><td><?= e($r['emp']['name_ar']) ?></td>
      <?php foreach ($days as $d): $c = $by[$d] ?? null; ?><td class="num cell-<?= e($c['s'] ?? 'none') ?>"<?= $c && $c['in'] ? ' title="' . e(substr((string)$c['in'], 0, 5) . ' – ' . substr((string)$c['out'], 0, 5)) . '"' : '' ?>><?= e($c ? grid_text($c) : '-') ?></td><?php endforeach; ?>
      <?php foreach (att_breakdown_values($r['att'] ?: ['total_days' => $r['att_days']]) as $v): ?><td class="num"><?= e(is_float($v) ? num($v) : (string)$v) ?></td><?php endforeach; ?>
      <td class="num"><?= e(money($r['net_final'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<p class="muted"><?= e(__('الشبكة بتتحفظ لما الفترة تتقفل. قبل كده شوف «تقرير الحضور» للأرقام الحية.')) ?></p>
<?php endif; ?>

<h2><?= e(__('سجل الإقفال')) ?></h2>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('العملية')) ?></th><th><?= e(__('المستخدم')) ?></th><th><?= e(__('الوقت')) ?></th><th><?= e(__('السبب / الملاحظة')) ?></th><th><?= e(__('فجوات')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($log as $l): ?>
    <tr><td><?= e(__($ACT[$l['action']] ?? $l['action'])) ?></td><td><?= e($l['done_by']) ?></td><td class="num"><?= e($l['done_at']) ?></td><td><?= e($l['reason']) ?></td><td><?= $l['gap_warning'] ? e($l['gap_details']) : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$log): ?><tr><td colspan="5" class="muted"><?= e(__('مفيش عمليات لسه.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($snaps): ?>
<h2><?= e(__('نسخ الإقفال')) ?></h2>
<div class="table-wrap">
<table>
  <thead><tr><th>#</th><th><?= e(__('الحالة')) ?></th><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('الموظفين')) ?></th><th><?= e(__('أقفلها')) ?></th><th><?= e(__('الوقت')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($snaps as $s): ?>
    <tr><td class="num"><?= (int)$s['id'] ?></td><td><?= e(__($ST[$s['state']] ?? $s['state'])) ?></td><td class="num"><?= e($s['date_from']) ?></td><td class="num"><?= e($s['date_to']) ?></td><td class="num"><?= (int)$s['employees'] ?></td><td><?= e($s['closed_by']) ?></td><td class="num"><?= e($s['closed_at']) ?></td>
      <td><a href="<?= e(url('period', ['id' => $per['id'], 'snap' => $s['id']])) ?>"><?= e(__('عرض')) ?></a></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
