<?php $emp = $r['emp']; $t = $r['totals']; ?>
<div class="page-head">
  <div><h1><?= e(__($title)) ?></h1><div class="sub"><?= e(($emp['name_ar'] ?? '') . ' · #' . (int)($emp['id'] ?? 0) . ' · ' . ($emp['dept_name'] ?? '')) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="<?= $self ? 'my_attendance' : 'att_employee' ?>">
    <?php if (!$self): ?><input type="hidden" name="emp" value="<?= (int)$emp['id'] ?>"><?php endif; ?>
    <input type="month" name="m" value="<?= e($ym) ?>"><button class="btn small"><?= e(__('عرض')) ?></button>
  </form>
</div>
<div class="stats">
  <div class="stat"><span><?= $t['work_days'] ?></span><?= e(__('أيام عمل')) ?></div>
  <div class="stat green"><span><?= $t['present'] ?></span><?= e(__('حضور')) ?></div>
  <div class="stat"><span><?= $t['absent'] ?></span><?= e(__('غياب')) ?></div>
  <div class="stat"><span><?= $t['late_min'] ?></span><?= e(__('دقيقة تأخير')) ?></div>
  <div class="stat"><span><?= $t['missing_out'] ?></span><?= e(__('بدون انصراف')) ?></div>
  <div class="stat"><span><?= $t['perms'] ?></span><?= e(__('أذونات')) ?></div>
  <div class="stat warn"><span><?= num($t['total_days']) ?></span><?= e(__('إجمالي أيام الخصم')) ?></div>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('التاريخ')) ?></th><th><?= e(__('اليوم')) ?></th><th><?= e(__('الحالة')) ?></th><th><?= e(__('وقت الحضور')) ?></th><th><?= e(__('وقت الانصراف')) ?></th><th><?= e(__('تأخير (د)')) ?></th><th><?= e(__('انصراف مبكر (د)')) ?></th><th><?= e(__('خصم (يوم)')) ?></th><th><?= e(__('ملاحظات')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($r['days'] as $d): ?>
    <tr class="st-<?= e($d['status']) ?>">
      <td class="num"><?= e($d['date']) ?></td><td><?= e(day_name($d['dow'])) ?></td><td><?= e(__(DAY_STATUS[$d['status']] ?? $d['status'])) ?></td>
      <td class="num"><?= e($d['in'] ? substr($d['in'], 11, 5) : '') ?></td><td class="num"><?= e($d['out'] ? substr($d['out'], 11, 5) : '') ?></td>
      <td class="num"><?= $d['late'] ?: '' ?></td><td class="num"><?= $d['early'] ?: '' ?></td><td class="num"><?= $d['ded'] ? e(num($d['ded'])) : '' ?></td>
      <td><?= e(implode(' · ', $d['notes'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
