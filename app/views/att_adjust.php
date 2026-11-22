<?php
$empSel = function (string $name = 'employee_id') use ($emps): string {
    $h = '<select name="' . e($name) . '" required><option value="">' . e(__('— اختار —')) . '</option>';
    foreach ($emps as $k => $v) $h .= '<option value="' . (int)$k . '">' . e($v) . '</option>';
    return $h . '</select>';
};
$open = function (string $res, string $part) use ($from, $to): string {
    return '<form method="post" action="' . e(url('form', ['res' => $res])) . '" class="filters">' . csrf_field()
        . '<input type="hidden" name="_back" value="att_adjust#' . e($part) . '"><input type="hidden" name="_back_from" value="' . e($from) . '"><input type="hidden" name="_back_to" value="' . e($to) . '">';
};
$canAdd = fn(string $k) => isset($parts[$k]) && can(resource($parts[$k][0])['module'], 'create');
$canEdit = fn(string $k) => can(resource($parts[$k][0])['module'], 'update');
$scopes = ['all' => 'كل الموظفين', 'department' => 'قسم', 'employee' => 'موظف'];
$editLink = fn(string $k, array $r) => $canEdit($k) ? '<a href="' . e(url('form', ['res' => $parts[$k][0], 'id' => $r['id']])) . '">' . e(__('تعديل')) . '</a>' : '';
?>
<div class="page-head">
  <div><h1><?= e(__('إعفاءات التأخير والخصومات')) ?></h1><div class="sub"><?= e(__('الإعفاءات والأذونات والنسيان والجزاءات واستثناءات الانصراف والإجازات وتعديلات الحضور في شاشة واحدة')) ?></div></div>
</div>

<?php if ($canAdd('waivers')): ?>
<div class="card no-print" id="waiver_form">
  <h2><?= e(__('تسجيل إعفاء تأخير')) ?></h2>
  <p class="muted"><?= e(__($parts['waivers'][3])) ?></p>
  <?= $open('late_exemptions', 'waivers') ?>
    <label><?= e(__('الموظف')) ?> <?= $empSel() ?></label>
    <label><?= e(__('التاريخ')) ?> <input type="date" name="ex_date" required></label>
    <label><?= e(__('دقائق الإعفاء المعتمدة')) ?> <input type="number" name="minutes" min="1" max="1440" required></label>
    <label><?= e(__('جهة الموافقة')) ?> <input name="approver" maxlength="150" required></label>
    <label><?= e(__('سبب الإعفاء')) ?> <input name="reason" maxlength="500" required></label>
    <div class="span2" data-late-helper data-url="<?= e(url('late_of_day')) ?>">
      <button type="button" class="btn" data-late-btn><?= e(__('عرض تأخير اليوم')) ?></button>
      <span class="muted" data-late-out></span>
    </div>
    <button class="btn primary"><?= e(__('حفظ الإعفاء')) ?></button>
  </form>
</div>
<?php endif; ?>

<form method="get" class="filters card">
  <input type="hidden" name="r" value="att_adjust">
  <label><?= e(__('من التاريخ')) ?> <input type="date" name="from" value="<?= e($from) ?>"></label>
  <label><?= e(__('إلى التاريخ')) ?> <input type="date" name="to" value="<?= e($to) ?>"></label>
  <button class="btn small"><?= e(__('بحث')) ?></button>
</form>

<?php if (array_filter(['perms', 'forgot', 'extra', 'checkout', 'leaves', 'adjust'], $canAdd)): ?>
<div class="card no-print" id="ops">
  <h2><?= e(__('عمليات الحضور والجزاءات')) ?></h2>
  <?php if ($canAdd('perms')): ?><h3><?= e(__('تسجيل إذن')) ?></h3>
  <?= $open('permission_requests', 'perms') ?><label><?= e(__('الموظف')) ?> <?= $empSel() ?></label><label><?= e(__('التاريخ')) ?> <input type="date" name="perm_date" required></label><button class="btn small"><?= e(__('تسجيل')) ?></button></form><?php endif; ?>
  <?php if ($canAdd('forgot')): ?><h3><?= e(__('نسيان تسجيل الحضور/الانصراف')) ?></h3>
  <?= $open('forgot_punch_log', 'forgot') ?><label><?= e(__('الموظف')) ?> <?= $empSel() ?></label><label><?= e(__('التاريخ')) ?> <input type="date" name="punch_date" required></label><button class="btn small"><?= e(__('تسجيل')) ?></button></form><?php endif; ?>
  <?php if ($canAdd('extra')): ?><h3><?= e(__('خصومات إضافية / جزاءات')) ?></h3>
  <?= $open('extra_deductions', 'extra') ?><label><?= e(__('الموظف')) ?> <?= $empSel() ?></label><label><?= e(__('التاريخ')) ?> <input type="date" name="ded_date" required></label>
    <label><?= e(__('السبب/النوع')) ?> <input name="reason" maxlength="500" required></label><label><?= e(__('عدد الأيام المخصومة')) ?> <input type="number" name="days" min="0.25" max="31" step="0.25" required></label><button class="btn small"><?= e(__('تسجيل')) ?></button></form><?php endif; ?>
  <?php if ($canAdd('checkout')): ?><h3><?= e(__('استثناءات إلزام الانصراف (مؤقتة)')) ?></h3>
  <?= $open('checkout_exemptions', 'checkout') ?><label><?= e(__('النطاق')) ?> <select name="scope"><?php foreach ($scopes as $k => $v): ?><option value="<?= $k ?>"><?= e(__($v)) ?></option><?php endforeach; ?></select></label>
    <label><?= e(__('القسم (لو النطاق قسم)')) ?> <select name="scope_dept"><option value=""></option><?php foreach ($depts as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label><?= e(__('الموظف (لو النطاق موظف)')) ?> <select name="scope_emp"><option value=""></option><?php foreach ($emps as $k => $v): ?><option value="<?= (int)$k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label><?= e(__('من تاريخ')) ?> <input type="date" name="date_from" required></label><label><?= e(__('لحد تاريخ')) ?> <input type="date" name="date_to" required></label><button class="btn small"><?= e(__('إضافة')) ?></button></form><?php endif; ?>
  <?php if ($canAdd('leaves')): ?><h3><?= e(__('إجازة شخصية معتمدة')) ?></h3>
  <?= $open('leaves', 'leaves') ?><label><?= e(__('الموظف')) ?> <?= $empSel() ?></label><label><?= e(__('من تاريخ')) ?> <input type="date" name="date_from" required></label><label><?= e(__('لحد تاريخ')) ?> <input type="date" name="date_to" required></label>
    <label><?= e(__('السبب')) ?> <input name="reason" maxlength="500"></label><button class="btn small"><?= e(__('إضافة')) ?></button></form><?php endif; ?>
  <?php if ($canAdd('adjust')): ?><h3><?= e(__('تعديل الحضور')) ?></h3>
  <?= $open('attendance_adjustments', 'adjust') ?><label><?= e(__('الموظف')) ?> <?= $empSel() ?></label><label><?= e(__('التاريخ')) ?> <input type="date" name="adj_date" required></label>
    <label><?= e(__('نوع التعديل')) ?> <select name="adj_type" required><?php foreach ($adjTypes as $k => $v): ?><option value="<?= e($k) ?>"><?= e(__($v)) ?></option><?php endforeach; ?></select></label>
    <label><?= e(__('الوقت (للتعديلات الزمنية)')) ?> <input type="time" name="value"></label><label><?= e(__('السبب')) ?> <input name="reason" maxlength="500" required></label><button class="btn small"><?= e(__('تسجيل')) ?></button></form><?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($parts as $k => [$res, $col, $partTitle, $note]): $list = $rows[$k] ?? []; ?>
<div class="card" id="<?= e($k) ?>">
  <h2><?= e(__($partTitle)) ?> <small class="muted">(<?= count($list) ?>)</small></h2>
  <?php if ($note !== '' && $k !== 'waivers'): ?><p class="muted"><?= e(__($note)) ?></p><?php endif; ?>
  <?php if (!$list): ?><p class="muted"><?= e(__('لا توجد سجلات في المدى ده.')) ?></p><?php else: ?>
  <div class="table-wrap"><table>
  <?php if ($k === 'waivers'): ?>
    <thead><tr><th><?= e(__('الموظف')) ?></th><th><?= e(__('التاريخ')) ?></th><th><?= e(__('دقائق الإعفاء')) ?></th><th><?= e(__('سبب الإعفاء')) ?></th><th><?= e(__('جهة الموافقة')) ?></th><th><?= e(__('المستخدم')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($list as $r): ?><tr><td><?= e($r['employee_id'] . ' - ' . $r['emp_name']) ?></td><td class="num"><?= e($r['ex_date']) ?></td><td class="num"><?= (int)$r['minutes'] ?></td><td><?= e($r['reason']) ?></td><td><?= e($r['approver']) ?></td><td><?= e((string)$r['created_by']) ?></td><td class="actions"><?= $editLink($k, $r) ?></td></tr><?php endforeach; ?></tbody>
  <?php elseif ($k === 'perms' || $k === 'forgot'): $dc = $col; ?>
    <thead><tr><th><?= e(__('الموظف')) ?></th><th><?= e(__('التاريخ')) ?></th><th><?= e(__('المستخدم')) ?></th><th><?= e(__('تاريخ التسجيل')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($list as $r): ?><tr><td><?= e($r['employee_id'] . ' - ' . $r['emp_name']) ?></td><td class="num"><?= e($r[$dc]) ?></td><td><?= e((string)$r['created_by']) ?></td><td class="num"><?= e(substr((string)($r['created_at'] ?? ''), 0, 16)) ?></td><td class="actions"><?= $editLink($k, $r) ?></td></tr><?php endforeach; ?></tbody>
  <?php elseif ($k === 'extra'): ?>
    <thead><tr><th><?= e(__('الموظف')) ?></th><th><?= e(__('التاريخ')) ?></th><th><?= e(__('السبب/النوع')) ?></th><th><?= e(__('عدد الأيام المخصومة')) ?></th><th><?= e(__('المستخدم')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($list as $r): ?><tr><td><?= e($r['employee_id'] . ' - ' . $r['emp_name']) ?></td><td class="num"><?= e($r['ded_date']) ?></td><td><?= e($r['reason']) ?></td><td class="num"><?= e(num((float)$r['days'])) ?></td><td><?= e((string)$r['created_by']) ?></td><td class="actions"><?= $editLink($k, $r) ?></td></tr><?php endforeach; ?></tbody>
  <?php elseif ($k === 'checkout'): ?>
    <thead><tr><th><?= e(__('النطاق')) ?></th><th><?= e(__('القيمة')) ?></th><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('المستخدم')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($list as $r): ?><tr><td><?= e(__($scopes[$r['scope']] ?? $r['scope'])) ?></td><td><?= e($r['scope'] === 'department' ? (string)($r['dept_name'] ?? $r['scope_value']) : ($r['scope'] === 'employee' ? (string)($r['emp_name'] ?? $r['scope_value']) : '—')) ?></td><td class="num"><?= e($r['date_from']) ?></td><td class="num"><?= e($r['date_to']) ?></td><td><?= e((string)$r['created_by']) ?></td><td class="actions"><?= $editLink($k, $r) ?></td></tr><?php endforeach; ?></tbody>
  <?php elseif ($k === 'leaves'): ?>
    <thead><tr><th><?= e(__('الموظف')) ?></th><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('السبب')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($list as $r): ?><tr><td><?= e($r['employee_id'] . ' - ' . $r['emp_name']) ?></td><td class="num"><?= e($r['date_from']) ?></td><td class="num"><?= e($r['date_to']) ?></td><td><?= e((string)$r['reason']) ?></td><td class="actions"><?= $editLink($k, $r) ?></td></tr><?php endforeach; ?></tbody>
  <?php else: ?>
    <thead><tr><th><?= e(__('الموظف')) ?></th><th><?= e(__('التاريخ')) ?></th><th><?= e(__('نوع التعديل')) ?></th><th><?= e(__('القيمة')) ?></th><th><?= e(__('السبب')) ?></th><th><?= e(__('المستخدم')) ?></th></tr></thead>
    <tbody><?php foreach ($list as $r): ?><tr><td><?= e($r['employee_id'] . ' - ' . $r['emp_name']) ?></td><td class="num"><?= e($r['adj_date']) ?></td><td><?= e(__($adjTypes[$r['adj_type']] ?? $r['adj_type'])) ?></td><td><?= e((string)($r['value'] ?? '') ?: '—') ?></td><td><?= e((string)$r['reason']) ?></td><td><?= e((string)$r['created_by']) ?></td></tr><?php endforeach; ?></tbody>
  <?php endif; ?>
  </table></div>
  <?php endif; ?>
  <p><a class="btn small ghost" href="<?= e(url('list', ['res' => $res])) ?>"><?= e(__('السجل كامل')) ?></a></p>
</div>
<?php endforeach; ?>
