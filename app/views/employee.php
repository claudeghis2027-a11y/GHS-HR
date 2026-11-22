<?php $id = (int)$emp['id']; $today = date('Y-m-d'); ?>
<div class="card">
  <h2><?= e(__('ملف الموظف')) ?>: <?= e($emp['name_ar']) ?> <span class="muted num">(<?= $id ?>)</span></h2>
  <div class="profile">
    <dl class="profile-grid">
      <div><dt><?= e(__('الحالة')) ?></dt><dd><?= e(__(employee_status($emp))) ?></dd></div>
      <div><dt><?= e(__('معفى من البصمة (نهائي)')) ?></dt><dd><?= e(__(employee_exempt_final($emp) ? 'نعم' : 'لا')) ?></dd></div>
      <?php foreach ($fields as $k => $f): ?>
        <div><dt><?= e(__($f['label'])) ?></dt><dd><?= e(display_value($f, $emp[$k] ?? null, $emp)) ?: '—' ?></dd></div>
      <?php endforeach; ?>
      <div><dt><?= e(__('بصمة الوجه')) ?></dt><dd><?= e($face === 'approved' ? __('مسجّلة ومعتمدة') : ($face === 'pending' ? __('مستنية المراجعة') : __('مش مسجّلة'))) ?></dd></div>
    </dl>
  </div>
  <div class="actions profile-actions">
    <?php if (can('employees', 'update')): ?><a class="btn primary" href="<?= e(url('form', ['res' => 'employees', 'id' => $id])) ?>"><?= e(__('تعديل')) ?></a><?php endif; ?>
    <?php if (can('attendance')): ?><a class="btn" href="<?= e(url('att_employee', ['emp' => $id])) ?>"><?= e(__('الحضور')) ?></a><?php endif; ?>
    <?php if (can('payroll')): ?><a class="btn" href="<?= e(url('payroll', ['emp' => $id])) ?>"><?= e(__('المرتب')) ?></a><?php endif; ?>
    <?php if (can('documents')): ?><a class="btn" href="<?= e(url('list', ['res' => 'employee_documents', 'emp' => $id])) ?>"><?= e(__('المستندات')) ?></a><?php endif; ?>
    <a class="btn ghost" href="<?= e(url('list', ['res' => 'employees'])) ?>"><?= e(__('رجوع للقائمة')) ?></a>
  </div>
</div>

<?php $back = url('employee', ['id' => $id]); require __DIR__ . '/_emp_access.php'; ?>

<div class="card">
  <h2><?= e(__('مواعيد خاصة')) ?></h2>
  <?php if (!$schedules): ?><p class="muted"><?= e(__('مفيش مواعيد خاصة للموظف ده.')) ?></p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('موعد الحضور')) ?></th><th><?= e(__('موعد الانصراف')) ?></th><th><?= e(__('المستخدم')) ?></th></tr></thead>
    <tbody><?php foreach ($schedules as $s): ?><tr><td class="num"><?= e($s['date_from']) ?></td><td class="num"><?= e($s['date_to']) ?></td><td class="num"><?= e(substr((string)$s['start_time'], 0, 5)) ?></td><td class="num"><?= e(substr((string)$s['end_time'], 0, 5)) ?></td><td><?= e((string)$s['created_by']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php if (can('rules', 'create')): ?><a class="btn small" href="<?= e(url('form', ['res' => 'employee_schedules', 'emp' => $id])) ?>"><?= e(__('إضافة ميعاد خاص')) ?></a><?php endif; ?>
</div>

<?php if ($basic !== null): ?>
<div class="card">
  <h2><?= e(__('الراتب الأساسي')) ?></h2>
  <p><?= e(__('الراتب الأساسي الحالي')) ?>: <strong class="num"><?= $basic['basic'] !== null ? e(num($basic['basic'])) : '—' ?></strong>
     · <?= e(__('تاريخ السريان الحالي')) ?>: <span class="num"><?= e($basic['effective_from'] ?? ($basicRows ? __('أول تاريخ سريان لسه ماجاش') : '—')) ?></span></p>
  <?php if ($basicRows): ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('تاريخ السريان')) ?></th><th><?= e(__('الراتب')) ?></th><th><?= e(__('الحالة')) ?></th><th><?= e(__('السبب')) ?></th></tr></thead>
    <tbody><?php foreach ($basicRows as $b): ?><tr>
      <td class="num"><?= e($b['date_from']) ?></td><td class="num"><?= e(num($b['amount'])) ?></td>
      <td><?= e($b['date_from'] > $today ? __('قادم') : ($b['date_from'] === $basic['effective_from'] ? __('حالي') : __('سابق'))) ?></td><td><?= e((string)$b['reason']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php else: ?><p class="muted"><?= e(__('مفيش راتب أساسي متسجّل.')) ?></p><?php endif; ?>
  <?php if (can('salary', 'create')): ?><a class="btn small" href="<?= e(url('form', ['res' => 'salary_tx', 'emp' => $id])) ?>"><?= e(__('إضافة حركة مرتب')) ?></a><?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= e(__('سجل التعديلات')) ?></h2>
  <?php if (!$history): ?><p class="muted"><?= e(__('مفيش تعديلات متسجّلة.')) ?></p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('التاريخ')) ?></th><th><?= e(__('الحقل')) ?></th><th><?= e(__('القيمة القديمة')) ?></th><th><?= e(__('القيمة الجديدة')) ?></th><th><?= e(__('السبب')) ?></th><th><?= e(__('المستخدم')) ?></th></tr></thead>
    <tbody><?php foreach ($history as $h): $lbl = $fields[$h['field']]['label'] ?? $h['field']; ?><tr>
      <td class="num"><?= e(substr((string)$h['changed_at'], 0, 16)) ?></td><td><?= e(__($lbl)) ?></td><td><?= e((string)$h['old_value']) ?></td><td><?= e((string)$h['new_value']) ?></td><td><?= e((string)$h['reason']) ?></td><td><?= e((string)$h['changed_by']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <a class="btn small ghost" href="<?= e(url('list', ['res' => 'employee_change_log', 'emp' => $id])) ?>"><?= e(__('السجل كامل')) ?></a>
  <?php endif; ?>
</div>
