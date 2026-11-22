<div class="page-head">
  <div><h1><?= e(__('كشف المرتبات')) ?></h1>
    <div class="sub"><?= e(month_name((int)substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4)) ?> · <?= e(__('من %s لحد %s', $from, $to)) ?><?= $per ? ' · ' . e($per['name'] ?: __('فترة %d', (int)$per['id'])) : '' ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="payroll">
    <input type="month" name="m" value="<?= e($ym) ?>">
    <select name="dept"><option value=""><?= e(__('كل الأقسام')) ?></option><?php foreach ($depts as $k => $v): ?><option value="<?= (int)$k ?>"<?= $dept === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <select name="job"><option value=""><?= e(__('كل المسميات')) ?></option><?php foreach ($jobs as $k => $v): ?><option value="<?= (int)$k ?>"<?= $job === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <button class="btn small"><?= e(__('عرض')) ?></button>
    <?php if (can('payroll', 'export')): ?><?= export_links('payroll', ['m' => $ym, 'dept' => $dept, 'job' => $job]) ?><?php endif; ?>
    <?php if (report_allowed('monthlyClosing', 'export')): $rq = ['rep' => 'monthlyClosing', 'm' => $ym] + ($dept ? ['dept' => [$dept]] : []) + ($job ? ['job' => [$job]] : []); ?>
      <a class="btn small" href="<?= e(url('report', $rq + ['format' => 'xlsx'])) ?>">Excel</a>
      <a class="btn small" href="<?= e(url('report', $rq + ['format' => 'print'])) ?>" target="_blank" rel="noopener"><?= e(__('طباعة / PDF')) ?></a>
    <?php endif; ?>
  </form>
</div>
<?php if (!empty($snap)): ?><div class="flash ok"><?= e(__('الفترة دي مقفولة. الأرقام من نسخة الإقفال المحفوظة يوم %s (%s)، ومش بتتحسب من جديد.', substr((string)$snap['closed_at'], 0, 10), (string)$snap['closed_by'])) ?> <a href="<?= e(url('period', ['id' => $per['id']])) ?>"><?= e(__('شبكة الحضور وسجل الإقفال')) ?></a></div><?php endif; ?>
<?php if (!$per): ?><div class="flash warn"><?= e(__('مفيش فترة مرتبات رسمية بتنتهي في الشهر ده، فالكشف محسوب على الشهر الميلادي. ضيف الفترة من "فترات المرتبات".')) ?></div><?php endif; ?>
<?php $errs = array_filter($rows, fn($r) => $r['errors']); if ($errs): ?><div class="flash err"><?= e(__('فيه %d موظف عندهم مشكلة في بيانات المرتب (شوف عمود الملاحظات).', count($errs))) ?></div><?php endif; ?>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('الأساسي')) ?></th><th><?= e(__('قيمة اليوم')) ?></th><th><?= e(__('العلاوة')) ?></th><th><?= e(__('الاستحقاقات')) ?></th><th><?= e(__('إجمالي المستحق')) ?></th>
    <th><?= e(__('أيام خصم الحضور')) ?></th><th><?= e(__('قيمة خصم الحضور')) ?></th><th><?= e(__('الخصومات')) ?></th><th><?= e(__('السلف')) ?></th><th><?= e(__('خصم الصيف')) ?></th><th><?= e(__('إجمالي الخصومات')) ?></th>
    <th><?= e(__('الصافي')) ?></th><th><?= e(__('الصافي النهائي')) ?></th><th><?= e(__('ملاحظات')) ?></th><th></th></tr></thead>
  <tbody>
  <?php $sum = ['basic' => 0.0, 'gross' => 0.0, 'ded' => 0.0, 'net' => 0.0, 'net_final' => 0.0];
  foreach ($rows as $r):
    $sum['basic'] += $r['basic']; $sum['gross'] += $r['gross']; $sum['ded'] += $r['total_deductions']; $sum['net'] += $r['net']; $sum['net_final'] += $r['net_final']; ?>
    <tr<?= $r['errors'] ? ' class="row-warn"' : '' ?>>
      <td class="num"><?= (int)$r['emp']['id'] ?></td><td><?= e($r['emp']['name_ar']) ?><?= $r['errors'] ? ' ⚠' : '' ?></td><td><?= e($r['emp']['dept_name']) ?></td>
      <td class="num"><?= e(money($r['basic'])) ?></td><td class="num"><?= e(money($r['daily_value'])) ?></td><td class="num"><?= e(money($r['allowance'])) ?></td><td class="num"><?= e(money($r['earnings'])) ?></td><td class="num"><?= e(money($r['gross'])) ?></td>
      <td class="num"><?= e(num($r['att_days'])) ?></td><td class="num"><?= e(money($r['att_amount'])) ?></td><td class="num"><?= e(money($r['deductions'])) ?></td><td class="num"><?= e(money($r['advances'])) ?></td><td class="num"><?= e(money($r['quarter'])) ?></td><td class="num"><?= e(money($r['total_deductions'])) ?></td>
      <td class="num"><?= e(money($r['net'])) ?></td><td class="num"><strong><?= e(money($r['net_final'])) ?></strong></td>
      <td class="small"><?= e(implode(' · ', payroll_notes($r))) ?></td>
      <td><a href="<?= e(url('payroll', ['emp' => $r['emp']['id'], 'm' => $ym])) ?>"><?= e(__('المفردات')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><th colspan="3"><?= e(__('الإجمالي')) ?></th><th class="num"><?= e(money($sum['basic'])) ?></th><th colspan="3"></th><th class="num"><?= e(money($sum['gross'])) ?></th><th colspan="5"></th>
    <th class="num"><?= e(money($sum['ded'])) ?></th><th class="num"><?= e(money($sum['net'])) ?></th><th class="num"><?= e(money($sum['net_final'])) ?></th><th colspan="2"></th></tr></tfoot>
</table>
</div>

<?php if (can('salary', 'update') && dept_scope() === null && $periods): ?>
<form method="post" action="<?= e(url('payroll')) ?>" class="card narrow" data-confirm="<?= e(__('إقفال الفترة هيحفظ نسخة نهائية من المرتبات وشبكة الحضور. متأكد؟')) ?>">
  <h2><?= e(__('إقفال فترة مرتبات')) ?></h2>
  <?= csrf_field() ?>
  <label><?= e(__('الفترة')) ?>
    <select name="period_id" required><?php foreach ($periods as $pp): ?><option value="<?= (int)$pp['id'] ?>"><?= e(($pp['name'] ?: __('فترة %d', (int)$pp['id'])) . ' (' . $pp['date_from'] . ' → ' . $pp['date_to'] . ')') ?></option><?php endforeach; ?></select>
  </label>
  <label><?= e(__('ملاحظة')) ?> <input name="reason" maxlength="500"></label>
  <?php if ($gaps): ?>
  <div class="flash warn"><?= e(__('فيه أيام مش متغطية بين الفترات الرسمية: %s', gaps_text($gaps))) ?></div>
  <label class="check"><input type="checkbox" name="ack_gaps" value="1"> <?= e(__('موافق على الفجوات وعاوز أقفل')) ?></label>
  <?php endif; ?>
  <button class="btn danger"><?= e(__('إقفال')) ?></button>
</form>
<?php endif; ?>
