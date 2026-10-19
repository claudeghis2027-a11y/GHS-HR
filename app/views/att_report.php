<div class="page-head">
  <div><h1><?= e(__('تقرير الحضور الشهري')) ?></h1><div class="sub"><?= e(month_name((int)substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4)) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="att_report">
    <input type="month" name="m" value="<?= e($ym) ?>">
    <select name="dept"><option value=""><?= e(__('كل الأقسام')) ?></option><?php foreach ($depts as $id => $n): ?><option value="<?= (int)$id ?>" <?= $dept === $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
    <button class="btn small"><?= e(__('عرض')) ?></button>
    <?php if (can('attendance', 'export')): ?><a class="btn small" href="<?= e(url('att_report', ['m' => $ym, 'dept' => $dept, 'export' => 'csv'])) ?>">CSV</a><?php endif; ?>
  </form>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('أيام عمل')) ?></th><th><?= e(__('حضور')) ?></th><th><?= e(__('غياب')) ?></th><th><?= e(__('دقائق تأخير')) ?></th><th><?= e(__('بدون انصراف')) ?></th><th><?= e(__('أذونات')) ?></th><th><?= e(__('جزاءات (يوم)')) ?></th><th><?= e(__('إجمالي الخصم (يوم)')) ?></th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="12" class="muted center"><?= e(__('لا توجد بيانات.')) ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $t = $r['t']; ?>
    <tr>
      <td class="num"><?= (int)$r['emp']['id'] ?></td><td><?= e($r['emp']['name_ar']) ?></td><td><?= e($r['emp']['dept_name']) ?></td>
      <td class="num"><?= $t['work_days'] ?></td><td class="num"><?= $t['present'] ?></td><td class="num"><?= $t['absent'] ?></td><td class="num"><?= $t['late_min'] ?></td>
      <td class="num"><?= $t['missing_out'] ?></td><td class="num"><?= $t['perms'] ?></td><td class="num"><?= e(num($t['extra_days'])) ?></td><td class="num"><strong><?= e(num($t['total_days'])) ?></strong></td>
      <td><a href="<?= e(url('att_employee', ['emp' => $r['emp']['id'], 'm' => $ym])) ?>"><?= e(__('التفاصيل')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
