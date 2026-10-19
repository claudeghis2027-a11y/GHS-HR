<div class="page-head">
  <div><h1><?= e(__('كشف المرتبات')) ?></h1><div class="sub"><?= e(month_name((int)substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4)) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="payroll">
    <input type="month" name="m" value="<?= e($ym) ?>"><button class="btn small"><?= e(__('عرض')) ?></button>
    <?php if (can('salary', 'export')): ?><a class="btn small" href="<?= e(url('payroll', ['m' => $ym, 'export' => 'csv'])) ?>">CSV</a><?php endif; ?>
  </form>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('الأساسي')) ?></th><th><?= e(__('الإضافات')) ?></th><th><?= e(__('الخصومات')) ?></th><th><?= e(__('السلف')) ?></th><th><?= e(__('أيام خصم الحضور')) ?></th><th><?= e(__('قيمة خصم الحضور')) ?></th><th><?= e(__('الصافي')) ?></th><th></th></tr></thead>
  <tbody>
  <?php $sum = 0; foreach ($rows as $r): $sum += $r['net']; ?>
    <tr>
      <td class="num"><?= (int)$r['emp']['id'] ?></td><td><?= e($r['emp']['name_ar']) ?></td><td><?= e($r['emp']['dept_name']) ?></td>
      <td class="num"><?= e(money($r['basic'])) ?></td><td class="num"><?= e(money($r['additions'])) ?></td><td class="num"><?= e(money($r['deductions'])) ?></td><td class="num"><?= e(money($r['advances'])) ?></td>
      <td class="num"><?= e(num($r['att_days'])) ?></td><td class="num"><?= e(money($r['att_amount'])) ?></td><td class="num"><strong><?= e(money($r['net'])) ?></strong></td>
      <td><a href="<?= e(url('payroll', ['emp' => $r['emp']['id'], 'm' => $ym])) ?>"><?= e(__('المفردات')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><th colspan="9"><?= e(__('إجمالي الصافي')) ?></th><th class="num"><?= e(money($sum)) ?></th><th></th></tr></tfoot>
</table>
</div>

<?php if (can('salary', 'update') && dept_scope() === null && $periods): ?>
<form method="post" action="<?= e(url('payroll')) ?>" class="card narrow" data-confirm="<?= e(__('إقفال الفترة هيحفظ نسخة نهائية من المرتبات. متأكد؟')) ?>">
  <h2><?= e(__('إقفال فترة مرتبات')) ?></h2>
  <?= csrf_field() ?>
  <label><?= e(__('الفترة')) ?>
    <select name="period_id" required><?php foreach ($periods as $pp): ?><option value="<?= (int)$pp['id'] ?>"><?= e(($pp['name'] ?: __('فترة %d', (int)$pp['id'])) . ' (' . $pp['date_from'] . ' → ' . $pp['date_to'] . ')') ?></option><?php endforeach; ?></select>
  </label>
  <label><?= e(__('ملاحظة')) ?> <input name="reason" maxlength="500"></label>
  <button class="btn danger"><?= e(__('إقفال')) ?></button>
</form>
<?php endif; ?>
