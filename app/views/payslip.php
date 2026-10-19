<?php $emp = $p['emp']; ?>
<div class="page-head">
  <div><h1><?= e(__($title)) ?></h1><div class="sub"><?= e(($emp['name_ar'] ?? '') . ' · #' . (int)($emp['id'] ?? 0)) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="<?= $self ? 'my_salary' : 'payroll' ?>">
    <?php if (!$self): ?><input type="hidden" name="emp" value="<?= (int)$emp['id'] ?>"><?php endif; ?>
    <input type="month" name="m" value="<?= e($ym) ?>"><button class="btn small"><?= e(__('عرض')) ?></button>
  </form>
</div>
<div class="card narrow accent">
  <p class="muted"><?= e(__('شهر %s — المبالغ تقديرية لحد إقفال الفترة.', month_name((int)substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4))) ?></p>
  <table>
    <thead><tr><th><?= e(__('البند')) ?></th><th><?= e(__('إضافة')) ?></th><th><?= e(__('خصم')) ?></th><th><?= e(__('ملاحظة')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($p['lines'] as $l): ?>
      <tr><td><?= e(__($l['name'])) ?></td><td class="num"><?= $l['dir'] === 'add' ? e(money($l['amount'])) : '' ?></td><td class="num"><?= $l['dir'] !== 'add' ? e(money($l['amount'])) : '' ?></td><td><?= e($l['reason']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th><?= e(__('الإجمالي')) ?></th><th class="num"><?= e(money($p['additions'])) ?></th><th class="num"><?= e(money($p['deductions'] + $p['advances'] + $p['att_amount'])) ?></th><th></th></tr>
      <tr class="net"><th><?= e(__('الصافي')) ?></th><th colspan="3" class="num"><?= e(money($p['net'])) ?></th></tr>
    </tfoot>
  </table>
  <p class="muted"><?= e(__('أيام خصم الحضور: %s (غياب %d، تأخير %d دقيقة).', num($p['att_days']), (int)$p['att']['absent'], (int)$p['att']['late_min'])) ?></p>
</div>
