<?php
$canAdd = can('rules', 'create'); $canEdit = can('rules', 'update');
$v = $form ?? ($edit ? ['from' => $edit['date_from'], 'to' => $edit['date_to'], 'in' => substr((string)$edit['start_time'], 0, 5), 'out' => substr((string)$edit['end_time'], 0, 5), 'reason' => '', 'id' => (int)$edit['id']]
    : ['from' => '', 'to' => '', 'in' => '08:00', 'out' => '15:00', 'reason' => '', 'id' => null]);
$editing = !empty($v['id']);
?>
<div class="page-head"><div><h1><?= e(__('القواعد')) ?></h1><div class="sub"><?= e(__('قواعد تاريخية: كل فترة بقاعدتها، والفترات مابتتداخلش. التعديل بيحفظ النسخة القديمة في الأرشيف، ومفيش حذف.')) ?></div></div>
  <div class="filters"><a class="btn small" href="<?= e(url('holiday_calendar')) ?>"><?= e(__('العطلات الرسمية للدولة')) ?></a></div></div>

<div class="card">
  <h2><?= e(__('قواعد المواعيد (تاريخية — كل فترة بقاعدتها)')) ?></h2>
  <?php if (!$rules): ?><p class="muted"><?= e(__('لا توجد قواعد مواعيد — أضف أول قاعدة.')) ?></p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('معرّف القاعدة')) ?></th><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('موعد الحضور')) ?></th><th><?= e(__('موعد الانصراف')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($rules as $r): $code = $r['code'] ?: (string)$r['id']; ?>
      <tr<?= $editing && (int)$v['id'] === (int)$r['id'] ? ' class="row-warn"' : '' ?>>
        <td class="num"><?= e($code) ?></td><td class="num"><?= e($r['date_from']) ?></td><td class="num"><?= e($r['date_to']) ?></td>
        <td class="num"><?= e(substr((string)$r['start_time'], 0, 5)) ?></td><td class="num"><?= e(substr((string)$r['end_time'], 0, 5)) ?></td>
        <td class="actions">
          <?php if ($canEdit): ?><a href="<?= e(url('sched_rules', ['edit' => $r['id']])) ?>"><?= e(__('تعديل')) ?></a><?php endif; ?>
          <a href="<?= e(url('sched_rules', ['archive' => $code])) ?>"><?= e(__('الأرشيف')) ?></a>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php $dmin = fn($m, $v) => (int)$m . ' ' . __('د') . ' = ' . rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') . ' ' . __('يوم'); $num = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); ?>
<div class="card" id="deduction">
  <h2><?= e(__('قواعد الخصم (تاريخية — شامل سلم الأذونات)')) ?></h2>
  <?php if (!$ded): ?><p class="muted"><?= e(__('لا توجد قواعد خصم — أضف أول قاعدة.')) ?></p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('وحدة التأخير')) ?></th><th><?= e(__('خصم الغياب')) ?></th><th><?= e(__('خصم عدم الانصراف')) ?></th><th><?= e(__('خصم الانصراف المبكر')) ?></th><th><?= e(__('سلم الأذونات (1/2/3/4/5+)')) ?></th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($ded as $r): ?><tr>
      <td class="num"><?= e($r['date_from']) ?></td><td class="num"><?= e($r['date_to']) ?></td>
      <td class="num"><?= e($dmin($r['late_unit_min'], $r['late_unit_days'])) ?></td><td class="num"><?= e($num($r['absence_days'])) ?></td><td class="num"><?= e($num($r['missing_checkout_days'])) ?></td>
      <td><?= e($r['early_leave_enabled'] ? __('نعم') . ' (' . $dmin($r['early_unit_min'], $r['early_unit_days']) . ')' : __('لا')) ?></td>
      <td class="num"><?= e(implode(' / ', array_map($num, [$r['perm1_days'], $r['perm2_days'], $r['perm3_days'], $r['perm4_days'], $r['perm5_days']]))) ?></td>
      <?php if ($canEdit): ?><td class="actions"><a href="<?= e(url('form', ['res' => 'deduction_rules', 'id' => $r['id']])) ?>"><?= e(__('تعديل')) ?></a></td><?php endif; ?>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card" id="manual">
  <h2><?= e(__('إجازات رسمية مسجّلة يدويًا (إدخال محلي)')) ?></h2>
  <p class="muted"><?= e(__('دي مُدخلات يدوية، مش من تقويم الدولة. بتُعامل كإجازة رسمية في الحساب وبتغلب التقويم المتزامن لو اتعارضت معاه. تقويم الدولة معروض في صفحة «العطلات الرسمية للدولة».')) ?></p>
  <?php if (!$manual): ?><p class="muted"><?= e(__('لا توجد إجازات رسمية مسجّلة يدويًا.')) ?></p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('التاريخ')) ?></th><th><?= e(__('المناسبة')) ?></th><th><?= e(__('المصدر')) ?></th><th><?= e(__('مطابقتها في تقويم الدولة')) ?></th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($manual as $h): ?><tr>
      <td class="num"><?= e($h['holiday_date']) ?></td><td><?= e($h['title']) ?><?= $h['status'] !== 'ACTIVE' ? ' <span class="badge red">' . e(__('ملغاة')) . '</span>' : '' ?></td><td><?= e(__('إدخال يدوي')) ?></td><td class="muted"><?= e($h['match']) ?></td>
      <?php if ($canEdit): ?><td class="actions"><a href="<?= e(url('form', ['res' => 'holidays', 'id' => $h['id']])) ?>"><?= e(__('تعديل')) ?></a></td><?php endif; ?>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card" id="checkout">
  <h2><?= e(__('استثناءات إلزام الانصراف')) ?></h2>
  <?php $scopes = ['all' => 'كل الموظفين', 'department' => 'قسم', 'employee' => 'موظف']; ?>
  <?php if (!$coEx): ?><p class="muted"><?= e(__('لا توجد استثناءات.')) ?></p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('النطاق')) ?></th><th><?= e(__('القيمة')) ?></th><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th></tr></thead>
    <tbody><?php foreach ($coEx as $x): ?><tr>
      <td><?= e(__($scopes[$x['scope']] ?? $x['scope'])) ?></td><td><?= e($x['scope'] === 'department' ? (string)($x['dept_name'] ?? $x['scope_value']) : ($x['scope'] === 'employee' ? (string)($x['emp_name'] ?? $x['scope_value']) : '—')) ?></td>
      <td class="num"><?= e($x['date_from']) ?></td><td class="num"><?= e($x['date_to']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php if ($canAdd): ?><p><a class="btn small" href="<?= e(url('form', ['res' => 'checkout_exemptions'])) ?>">+ <?= e(__('إضافة استثناء')) ?></a></p><?php endif; ?>
</div>

<?php if ($archCode !== ''): ?>
<div class="card">
  <h2><?= e(__('أرشيف قاعدة المواعيد %s', $archCode)) ?></h2>
  <?php if (!$archive): ?><p class="muted"><?= e(__('مفيش تعديلات مسجّلة على القاعدة دي.')) ?></p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('رقم الأرشيف')) ?></th><th><?= e(__('العملية')) ?></th><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('موعد الحضور')) ?></th><th><?= e(__('موعد الانصراف')) ?></th><th><?= e(__('المستخدم')) ?></th><th><?= e(__('الوقت')) ?></th><th><?= e(__('السبب')) ?></th></tr></thead>
    <tbody><?php foreach ($archive as $a): ?><tr>
      <td class="num"><?= e((string)$a['archive_code']) ?></td><td><?= e(__((string)$a['operation'])) ?></td><td class="num"><?= e((string)$a['date_from']) ?></td><td class="num"><?= e((string)$a['date_to']) ?></td>
      <td class="num"><?= e(substr((string)$a['start_time'], 0, 5)) ?></td><td class="num"><?= e(substr((string)$a['end_time'], 0, 5)) ?></td><td><?= e((string)$a['done_by']) ?></td><td class="num"><?= e(substr((string)$a['done_at'], 0, 16)) ?></td><td><?= e((string)$a['reason']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($editing ? $canEdit : $canAdd): ?>
<form method="post" action="<?= e(url('sched_rules')) ?>" class="card">
  <?= csrf_field() ?>
  <h2><?= e($editing ? __('تعديل قاعدة المواعيد %s', (string)($edit['code'] ?? $v['id'])) : '+ ' . __('إضافة قاعدة مواعيد جديدة')) ?></h2>
  <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><?php endif; ?>
  <div class="form-grid">
    <label><?= e(__('من تاريخ')) ?> <input type="date" name="date_from" required value="<?= e($v['from']) ?>"></label>
    <label><?= e(__('لحد تاريخ')) ?> <input type="date" name="date_to" required value="<?= e($v['to']) ?>"></label>
    <label><?= e(__('موعد الحضور')) ?> <input type="time" name="start_time" required value="<?= e($v['in']) ?>"></label>
    <label><?= e(__('موعد الانصراف')) ?> <input type="time" name="end_time" required value="<?= e($v['out']) ?>"></label>
    <?php if ($editing): ?><label class="span2"><?= e(__('سبب التعديل')) ?> <input name="reason" maxlength="500" placeholder="<?= e(__('سبب التعديل (يتسجّل في الأرشيف)')) ?>" value="<?= e($v['reason']) ?>"></label><?php endif; ?>
  </div>
  <div class="actions">
    <button class="btn primary"><?= e($editing ? __('حفظ التعديل') : __('إضافة القاعدة')) ?></button>
    <?php if ($editing): ?><a class="btn ghost" href="<?= e(url('sched_rules')) ?>"><?= e(__('إلغاء')) ?></a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php if ($canAdd): $dv = $dedForm ?? []; $dval = fn($k) => e((string)($dv[$k] ?? DEDUCTION_DEFAULTS[$k])); ?>
<form method="post" action="<?= e(url('sched_rules')) ?>" class="card">
  <?= csrf_field() ?><input type="hidden" name="op" value="deduction_add">
  <h2>+ <?= e(__('إضافة قاعدة خصم جديدة')) ?></h2>
  <div class="form-grid">
    <label><?= e(__('من تاريخ')) ?> <input type="date" name="date_from" required value="<?= e((string)($dv['date_from'] ?? '')) ?>"></label>
    <label><?= e(__('لحد تاريخ')) ?> <input type="date" name="date_to" required value="<?= e((string)($dv['date_to'] ?? '')) ?>"></label>
    <?php foreach (['late_unit_min', 'late_unit_days', 'absence_days', 'missing_checkout_days'] as $k): ?>
    <label><?= e(__(DEDUCTION_LABELS[$k])) ?> <input type="number" name="<?= $k ?>" min="<?= str_ends_with($k, '_min') ? 1 : 0 ?>" step="<?= str_ends_with($k, '_min') ? 1 : 0.25 ?>" value="<?= $dval($k) ?>"></label>
    <?php endforeach; ?>
    <label><?= e(__('تفعيل خصم الانصراف المبكر')) ?> <select name="early_leave_enabled"><option value=""><?= e(__('لا')) ?></option><option value="1"<?= !empty($dv['early_leave_enabled']) ? ' selected' : '' ?>><?= e(__('نعم')) ?></option></select></label>
    <?php foreach (['early_unit_min', 'early_unit_days', 'perm1_days', 'perm2_days', 'perm3_days', 'perm4_days', 'perm5_days'] as $k): ?>
    <label><?= e(__(DEDUCTION_LABELS[$k])) ?> <input type="number" name="<?= $k ?>" min="<?= str_ends_with($k, '_min') ? 1 : 0 ?>" step="<?= str_ends_with($k, '_min') ? 1 : 0.25 ?>" value="<?= $dval($k) ?>"></label>
    <?php endforeach; ?>
  </div>
  <div class="actions"><button class="btn primary"><?= e(__('حفظ قاعدة الخصم')) ?></button></div>
</form>

<form method="post" action="<?= e(url('sched_rules')) ?>" class="card">
  <?= csrf_field() ?><input type="hidden" name="op" value="holiday_add">
  <h2><?= e(__('إضافة إجازة رسمية')) ?></h2>
  <div class="form-grid">
    <label><?= e(__('التاريخ')) ?> <input type="date" name="holiday_date" required></label>
    <label><?= e(__('المناسبة')) ?> <input name="title" maxlength="255" placeholder="<?= e(__('المناسبة')) ?>"></label>
  </div>
  <div class="actions"><button class="btn primary"><?= e(__('إضافة')) ?></button></div>
</form>
<?php endif; ?>

<div class="card" id="overrides">
  <h2><?= e(__('مواعيد خاصة لموظفين')) ?></h2>
  <p class="muted"><?= e(__('تحديد مواعيد حضور وانصراف مختلفة لموظف خلال فترة زمنية محددة. القاعدة الخاصة بتحلّ محل القاعدة العامة في مداها بس — وبرّه المدى بيرجع للقاعدة العامة تلقائيًا.')) ?></p>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="sched_rules">
    <label><?= e(__('الموظف')) ?> <select name="emp" data-autosubmit><option value=""><?= e(__('— اختار موظف —')) ?></option><?php foreach ($emps as $k => $v): ?><option value="<?= (int)$k ?>"<?= $ovEmp === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <button class="btn small"><?= e(__('عرض')) ?></button>
  </form>
  <?php if ($ovEmp !== null): ?>
    <?php if (!$overrides): ?><p class="muted"><?= e(__('لا توجد مواعيد خاصة للموظف ده.')) ?></p><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('موعد الحضور')) ?></th><th><?= e(__('موعد الانصراف')) ?></th><th><?= e(__('المستخدم')) ?></th><th><?= e(__('تاريخ التسجيل')) ?></th></tr></thead>
      <tbody><?php foreach ($overrides as $o): ?><tr>
        <td class="num"><?= e($o['date_from']) ?></td><td class="num"><?= e($o['date_to']) ?></td><td class="num"><?= e(substr((string)$o['start_time'], 0, 5)) ?></td><td class="num"><?= e(substr((string)$o['end_time'], 0, 5)) ?></td>
        <td><?= e((string)($o['created_by'] ?? '—')) ?></td><td class="num"><?= e(substr((string)($o['created_at'] ?? ''), 0, 16) ?: '—') ?></td>
      </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($canAdd): ?>
  <form method="post" action="<?= e(url('sched_rules')) ?>">
    <?= csrf_field() ?><input type="hidden" name="op" value="override_add">
    <div class="form-grid">
      <label><?= e(__('الموظف')) ?> <select name="employee_id" required><option value=""><?= e(__('— اختار موظف —')) ?></option><?php foreach ($emps as $k => $v): ?><option value="<?= (int)$k ?>"<?= $ovEmp === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      <label><?= e(__('من تاريخ')) ?> <input type="date" name="date_from" required></label>
      <label><?= e(__('لحد تاريخ')) ?> <input type="date" name="date_to" required></label>
      <label><?= e(__('موعد الحضور')) ?> <input type="time" name="start_time" required value="08:00"></label>
      <label><?= e(__('موعد الانصراف')) ?> <input type="time" name="end_time" required value="15:00"></label>
    </div>
    <div class="actions"><button class="btn primary"><?= e(__('حفظ')) ?></button></div>
  </form>
  <?php endif; ?>
</div>
