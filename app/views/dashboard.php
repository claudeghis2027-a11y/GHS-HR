<?php
$h = (int)date('G');
$greet = $h < 12 ? 'صباح الخير، %s' : 'مساء الخير، %s';
$name = user()['display_name'] ?: (user()['employee_id'] ? q_val('SELECT name_ar FROM employees WHERE id = ?', [user()['employee_id']]) : user()['username']);
?>
<div class="page-head">
  <div>
    <h1><?= e(__($greet, $name)) ?></h1>
    <div class="sub"><?= e(day_name((int)date('N')) . ' ' . date('j') . ' ' . month_name((int)date('n')) . ' ' . date('Y')) ?></div>
  </div>
</div>

<?php if (my_employee_id()): ?>
<div class="card accent">
  <div class="head-row">
    <h2><?= e(__('حضورك النهارده')) ?></h2>
    <a class="btn primary" href="<?= e(url('checkin')) ?>"><?= e(__('تسجيل الحضور / الانصراف')) ?></a>
  </div>
  <div class="punch-status">
    <span class="badge <?= !empty($today['check_in_at']) ? 'green' : '' ?>"><?= e(__('وقت الحضور')) ?>: <?= !empty($today['check_in_at']) ? e(substr($today['check_in_at'], 11, 5)) : e(__('لم يسجل')) ?></span>
    <span class="badge <?= !empty($today['check_out_at']) ? 'green' : '' ?>"><?= e(__('وقت الانصراف')) ?>: <?= !empty($today['check_out_at']) ? e(substr($today['check_out_at'], 11, 5)) : e(__('لم يسجل')) ?></span>
    <?php if (!empty($unread)): ?><a class="badge ochre" href="<?= e(url('inbox')) ?>"><?= e(__('عندك %d إشعار جديد', (int)$unread)) ?></a><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($stats)): ?>
<?php if (!empty($no_points)): ?><div class="flash warn"><?= e(__('مفيش نقاط حضور بإحداثيات. الموظفين مش هيقدروا يبصموا لحد ما تضيف نقطة.')) ?> <a href="<?= e(url('list', ['res' => 'attendance_points'])) ?>"><?= e(__('نقاط الحضور')) ?></a></div><?php endif; ?>
<div class="stats">
  <?php if (can('employees')): ?><a class="stat" href="<?= e(url('list', ['res' => 'employees', 'current' => 1])) ?>"><?php else: ?><div class="stat"><?php endif; ?><span><?= $stats['employees'] ?></span><?= e(__('موظف حالي')) ?><?= can('employees') ? '</a>' : '</div>' ?>
  <?php if (can('attendance')): ?><a class="stat green" href="<?= e(url('list', ['res' => 'attendance', 'from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'present' => 1])) ?>"><?php else: ?><div class="stat green"><?php endif; ?><span><?= $stats['present'] ?></span><?= e(__('حاضر النهارده')) ?><?= can('attendance') ? '</a>' : '</div>' ?>
  <?php if ($stats['locked']): ?><a class="stat warn" href="<?= e(url('face_hr')) ?>"><span><?= $stats['locked'] ?></span><?= e(__('موظف بصمته مقفولة (فشل متكرر)')) ?></a><?php endif; ?>
  <?php if ($stats['faces'] !== null): ?><a class="stat <?= $stats['faces'] ? 'ochre' : '' ?>" href="<?= e(url('face_review')) ?>"><span><?= $stats['faces'] ?></span><?= e(__('صورة بصمة مستنية موافقتك')) ?></a><?php endif; ?>
  <?php if ($stats['requests'] !== null): ?><a class="stat ochre" href="<?= e(url('requests')) ?>"><span><?= $stats['requests'] ?></span><?= e(__('طلب تعديل بيانات')) ?></a><?php endif; ?>
  <?php if ($stats['docs'] !== null): ?><a class="stat warn" href="<?= e(url('list', ['res' => 'employee_documents'])) ?>"><span><?= $stats['docs'] ?></span><?= e(__('مستند منتهي أو قريب الانتهاء')) ?></a><?php endif; ?>
</div>
<div class="grid2">
  <?php if (can('attendance')): ?><div class="card"><h3><?= e(__('الحضور')) ?></h3><p class="muted"><?= e(__('التقرير الشهري بالتأخير والغياب والخصومات.')) ?></p><a class="btn" href="<?= e(url('att_report')) ?>"><?= e(__('التقرير الشهري')) ?></a></div><?php endif; ?>
  <?php if (can('salary')): ?><div class="card"><h3><?= e(__('المرتبات')) ?></h3><p class="muted"><?= e(__('كشف المرتبات للشهر، وإقفال الفترة.')) ?></p><a class="btn" href="<?= e(url('payroll')) ?>"><?= e(__('كشف المرتبات')) ?></a></div><?php endif; ?>
  <?php if (can('employees')): ?><div class="card"><h3><?= e(__('الموظفين')) ?></h3><p class="muted"><?= e(__('بيانات الموظفين، والرفع من Excel.')) ?></p><a class="btn" href="<?= e(url('list', ['res' => 'employees'])) ?>"><?= e(__('الموظفين')) ?></a></div><?php endif; ?>
</div>
<?php endif; ?>
