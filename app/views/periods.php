<div class="page-head">
  <div><h1><?= e(__('فترات المرتبات')) ?></h1><div class="sub"><?= e(__('الفترات الرسمية مابتتداخلش، والفترة المقفولة بتتعرض من نسخة الإقفال بس.')) ?></div></div>
  <?php if (can('salary', 'create')): ?><a class="btn primary" href="<?= e(url('form', ['res' => 'payroll_periods'])) ?>">+ <?= e(__('إضافة فترة')) ?></a><?php endif; ?>
</div>
<?php if ($gaps): ?><div class="flash warn"><?= e(__('فيه أيام مش متغطية بين الفترات الرسمية: %s', gaps_text($gaps))) ?></div><?php endif; ?>
<div class="table-wrap">
<table>
  <thead><tr><th>#</th><th><?= e(__('اسم الفترة')) ?></th><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('الحالة')) ?></th><th><?= e(__('أقفلها')) ?></th><th><?= e(__('تاريخ الإقفال')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="num"><?= (int)$r['id'] ?></td><td><?= e($r['name']) ?></td><td class="num"><?= e($r['date_from']) ?></td><td class="num"><?= e($r['date_to']) ?></td>
      <td><span class="badge <?= $r['status'] === 'closed' ? 'green' : 'ochre' ?>"><?= e(__($r['status'] === 'closed' ? 'مقفولة' : 'مفتوحة')) ?></span><?= $r['status'] === 'closed' && !$r['ready'] ? ' ⚠' : '' ?></td>
      <td><?= e($r['closed_by']) ?></td><td class="num"><?= e($r['closed_at']) ?></td>
      <td><a href="<?= e(url('period', ['id' => $r['id']])) ?>"><?= e(__('التفاصيل')) ?></a>
        <?php if ($r['status'] !== 'closed' && can('salary', 'update')): ?> · <a href="<?= e(url('form', ['res' => 'payroll_periods', 'id' => $r['id']])) ?>"><?= e(__('تعديل')) ?></a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted"><?= e(__('مفيش فترات لسه.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
