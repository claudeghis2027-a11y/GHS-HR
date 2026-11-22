<?php $emp = $p['emp']; ?>
<div class="page-head">
  <div><h1><?= e(__($title)) ?></h1><div class="sub"><?= e(($emp['name_ar'] ?? '') . ' · #' . (int)($emp['id'] ?? 0)) ?></div></div>
  <?php if (empty($embed)): ?>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="<?= $self ? 'my_salary' : 'payroll' ?>">
    <?php if (!$self): ?><input type="hidden" name="emp" value="<?= (int)$emp['id'] ?>"><?php endif; ?>
    <input type="month" name="m" value="<?= e($ym) ?>"><button class="btn small"><?= e(__('عرض')) ?></button>
  </form>
  <?php endif; ?>
</div>
<div class="card narrow accent">
  <?php $mLabel = month_name((int)substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4); ?>
  <?php if (!empty($snap)): ?>
  <p class="muted"><span class="badge green"><?= e(__('فترة مقفولة')) ?></span> <?= e(__('شهر %s — المبالغ دي من نسخة الإقفال المحفوظة يوم %s.', $mLabel, substr((string)$snap['closed_at'], 0, 10))) ?> <?= e(__('من %s لحد %s', $p['from'] ?? $snap['date_from'], $p['to'] ?? $snap['date_to'])) ?></p>
  <?php else: ?>
  <p class="muted"><?= e(__('شهر %s — المبالغ تقديرية لحد إقفال الفترة.', $mLabel)) ?> <?= e(__('من %s لحد %s', $p['from'], $p['to'])) ?></p>
  <?php endif; ?>
  <?php foreach ($p['errors'] as $er): ?><div class="flash err"><?= e($er) ?></div><?php endforeach; ?>
  <table>
    <thead><tr><th><?= e(__('البند')) ?></th><th><?= e(__('إضافة')) ?></th><th><?= e(__('خصم')) ?></th><th><?= e(__('ملاحظة')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($p['lines'] as $l): ?>
      <tr><td><?= e(__($l['name'])) ?></td><td class="num"><?= $l['dir'] === 'add' ? e(money($l['amount'])) : '' ?></td><td class="num"><?= $l['dir'] !== 'add' ? e(money($l['amount'])) : '' ?></td><td><?= e($l['reason']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th><?= e(__('الإجمالي')) ?></th><th class="num"><?= e(money($p['gross'])) ?></th><th class="num"><?= e(money($p['total_deductions'])) ?></th><th></th></tr>
      <tr><th><?= e(__('الصافي')) ?></th><th colspan="3" class="num"><?= e(money($p['net'])) ?></th></tr>
      <tr class="net"><th><?= e(__('الصافي النهائي (مقرّب لأقرب 5 جنيه)')) ?></th><th colspan="3" class="num"><?= e(money($p['net_final'])) ?></th></tr>
    </tfoot>
  </table>
  <p class="muted"><?= e(__('أيام خصم الحضور: %s (غياب %d، تأخير %d دقيقة).', num($p['att_days']), (int)($p['att']['absent'] ?? 0), (int)($p['att']['late_min'] ?? 0))) ?> <?= e(__('قيمة اليوم: %s.', money($p['daily_value']))) ?></p>
</div>
