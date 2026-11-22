<?php $emp = $r['emp']; $t = $r['totals']; ?>
<div class="page-head">
  <div><h1><?= e(__($title)) ?></h1><div class="sub"><?= e(($emp['name_ar'] ?? '') . ' · #' . (int)($emp['id'] ?? 0) . ' · ' . ($emp['dept_name'] ?? '')) ?></div></div>
  <?php if (empty($embed)): ?>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="<?= $self ? 'my_attendance' : 'att_employee' ?>">
    <?php if (!$self): ?><input type="hidden" name="emp" value="<?= (int)$emp['id'] ?>"><?php endif; ?>
    <?php if (!empty($periods)): ?>
    <label><?= e(__('الفترة')) ?> <select name="period" data-autosubmit><option value=""><?= e(__('— فترة مخصصة —')) ?></option>
      <?php foreach ($periods as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= !empty($per) && (int)$per['id'] === (int)$pp['id'] ? ' selected' : '' ?>><?= e(($pp['name'] ?: '#' . $pp['id']) . ' (' . $pp['date_from'] . ' → ' . $pp['date_to'] . ')') ?></option><?php endforeach; ?>
    </select></label>
    <?php endif; ?>
    <label><?= e(__('من')) ?> <input type="date" name="from" value="<?= e($from ?? '') ?>"></label>
    <label><?= e(__('إلى')) ?> <input type="date" name="to" value="<?= e($to ?? '') ?>"></label>
    <button class="btn small"><?= e(__('عرض')) ?></button>
  </form>
  <?php endif; ?>
</div>
<?php if (empty($embed) && !empty($from)): ?><p class="muted"><?= e(__('الفترة من %s إلى %s', $from, $to)) ?></p><?php endif; ?>
<div class="stats">
  <div class="stat"><span><?= $t['work_days'] ?></span><?= e(__('أيام العمل المطلوبة')) ?></div>
  <div class="stat green"><span><?= $t['present'] ?></span><?= e(__('حضور')) ?></div>
  <div class="stat"><span><?= $t['work_days'] ? e(num(round($t['present'] / $t['work_days'] * 100, 1))) . '%' : '—' ?></span><?= e(__('نسبة الحضور')) ?></div>
  <div class="stat"><span><?= $t['late_count'] ?></span><?= e(__('مرات التأخير')) ?></div>
  <div class="stat"><span><?= $t['absent'] ?></span><?= e(__('غياب')) ?></div>
  <div class="stat"><span><?= $t['late_min'] ?></span><?= e(__('دقيقة تأخير')) ?></div>
  <div class="stat"><span><?= $t['late_waiver_min'] ?></span><?= e(__('إعفاء (د)')) ?></div>
  <div class="stat"><span><?= $t['late_min_final'] ?></span><?= e(__('تأخير نهائي (د)')) ?></div>
  <div class="stat"><span><?= $t['missing_out'] ?></span><?= e(__('بدون انصراف')) ?></div>
  <div class="stat"><span><?= e(num($t['late_days'])) ?></span><?= e(__('خصم التأخير (يوم)')) ?></div>
  <div class="stat"><span><?= $t['perms'] ?></span><?= e(__('أذونات')) ?></div>
  <div class="stat"><span><?= $t['forgot'] ?></span><?= e(__('نسيان بصمة')) ?></div>
  <div class="stat warn"><span><?= num($t['total_days']) ?></span><?= e(__('إجمالي أيام الخصم')) ?></div>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('التاريخ')) ?></th><th><?= e(__('اليوم')) ?></th><th><?= e(__('الحالة')) ?></th><th><?= e(__('وقت الحضور')) ?></th><th><?= e(__('وقت الانصراف')) ?></th><th><?= e(__('حضور رسمي')) ?></th><th><?= e(__('انصراف رسمي')) ?></th><th><?= e(__('تأخير (د)')) ?></th><th><?= e(__('إعفاء (د)')) ?></th><th><?= e(__('انصراف مبكر (د)')) ?></th><th><?= e(__('خصم (يوم)')) ?></th><th><?= e(__('ملاحظات')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($r['days'] as $d): ?>
    <tr class="st-<?= e($d['status']) ?>">
      <td class="num"><?= e($d['date']) ?></td><td><?= e(day_name($d['dow'])) ?></td><td><?= e(__(DAY_STATUS[$d['status']] ?? $d['status'])) ?></td>
      <td class="num"><?= e($d['in'] ? substr($d['in'], 11, 5) : '') ?></td><td class="num"><?= e($d['out'] ? substr($d['out'], 11, 5) : '') ?></td>
      <td class="num muted"><?= e((string)$d['sched_in']) ?></td><td class="num muted"><?= e((string)$d['sched_out']) ?></td>
      <td class="num"><?= $d['late'] ?: '' ?></td><td class="num"><?= $d['waiver'] ?: '' ?></td><td class="num"><?= $d['early'] ?: '' ?></td><td class="num"><?= $d['ded'] ? e(num($d['ded'])) : '' ?></td>
      <td><?= e(implode(' · ', $d['notes'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted"><?= e(__('خصم التأخير والانصراف بدري بيتحسب على مجموع الدقايق في الفترة (بعد الإعفاءات) مش على كل يوم لوحده، والأذونات ونسيان البصمة على عددهم في الفترة: تأخير %s يوم، انصراف بدري %s، أذونات %s، نسيان بصمة %s، غياب %s، بدون انصراف %s، جزاءات %s.',
  num($t['late_days']), num($t['early_days']), num($t['perm_days']), num($t['forgot_days']), num($t['absent_days']), num($t['missing_out_days']), num($t['extra_days']))) ?></p>
