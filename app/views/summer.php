<div class="page-head">
  <div><h1><?= e(__('خصم الصيف')) ?></h1><div class="sub"><?= e(__('سنة %d', $year)) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="summer">
    <input type="number" name="year" value="<?= (int)$year ?>" min="2000" max="2100" class="input-narrow">
    <select name="type"><option value=""><?= e(__('كل الأنواع')) ?></option><?php foreach (['ربع', 'ربعين', 'نصف', 'نصفين'] as $t): ?><option<?= $type === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <select name="dept"><option value=""><?= e(__('كل الأقسام')) ?></option><?php foreach ($depts as $k => $v): ?><option value="<?= (int)$k ?>"<?= $dept === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <select name="job"><option value=""><?= e(__('كل المسميات')) ?></option><?php foreach ($jobs as $k => $v): ?><option value="<?= (int)$k ?>"<?= $job === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <button class="btn small"><?= e(__('عرض')) ?></button>
    <?php if (can('salary', 'export')): ?><?= export_links('summer', ['year' => $year, 'type' => $type, 'dept' => $dept, 'job' => $job]) ?><?php endif; ?>
  </form>
</div>
<div class="stats">
  <div class="stat"><span><?= count($rows) ?></span><?= e(__('صف')) ?></div>
  <div class="stat warn"><span><?= e(money($total)) ?></span><?= e(__('إجمالي المخصوم')) ?></div>
  <div class="stat green"><span><?= e(money($refunded)) ?></span><?= e(__('اللي اترد')) ?></div>
</div>
<?php if (can('salary', 'update') && dept_scope() === null): ?>
<div class="grid2">
  <form method="post" action="<?= e(url('summer')) ?>" class="card" data-confirm="<?= e(__('هيتحسب خصم الصيف لكل الموظفين في السنة دي (اللي اتحسب قبل كده مش هيتكرر). متأكد؟')) ?>">
    <h2><?= e(__('حساب خصم الصيف')) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="action" value="calculate">
    <label><?= e(__('السنة')) ?> <input type="number" name="year" value="<?= (int)$year ?>" min="2000" max="2100" required></label>
    <p class="muted"><?= e(__('ربع المرتب الأساسي عن يوليو (أو يوليو وأغسطس) حسب تصنيف الموظف ثم القسم. استثناء السنة الأولى 1 و2 = نص المرتب ومش بيترد.')) ?></p>
    <button class="btn primary"><?= e(__('احسب')) ?></button>
  </form>
  <form method="post" action="<?= e(url('summer')) ?>" class="card" data-confirm="<?= e(__('هيتعلّم الخصم إنه اترد. متأكد؟')) ?>">
    <h2><?= e(__('رد خصم الصيف')) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="action" value="refund">
    <label><?= e(__('السنة')) ?> <input type="number" name="year" value="<?= (int)$year ?>" min="2000" max="2100" required></label>
    <label><?= e(__('كود الموظف (فاضي = كل الموظفين)')) ?> <input type="number" name="employee_id" min="1"></label>
    <label><?= e(__('النوع')) ?> <select name="type"><option value=""><?= e(__('ربع وربعين (لموظف واحد بس)')) ?></option><option>ربع</option><option>ربعين</option></select></label>
    <p class="muted"><?= e(__('خصم «نصف» و«نصفين» (استثناء السنة الأولى) مابيترد.')) ?></p>
    <button class="btn"><?= e(__('رد')) ?></button>
  </form>
</div>
<?php endif; ?>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('الشهر')) ?></th><th><?= e(__('الأساسي وقتها')) ?></th><th><?= e(__('المبلغ')) ?></th><th><?= e(__('النوع')) ?></th><th><?= e(__('حالة الرد')) ?></th><th><?= e(__('تاريخ الرد')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="num"><?= (int)$r['employee_id'] ?></td><td><?= e($r['name_ar']) ?></td><td><?= e($r['dept_name']) ?></td>
      <td><?= isset(AR_MONTHS[(int)$r['month_no']]) ? e(month_name((int)$r['month_no'])) : e((string)$r['month_no']) ?></td>
      <td class="num"><?= e(money($r['basic_salary'])) ?></td><td class="num"><?= e(money($r['amount'])) ?></td><td><?= e($r['ded_type']) ?></td><td><?= e($r['refund_status']) ?></td><td class="num"><?= e($r['refunded_at']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="muted"><?= e(__('مفيش خصم صيف متسجل للاختيار ده.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php $rules = ['Rule 1' => 'Rule 1 (ربعين — يوليو وأغسطس)', 'Rule 2' => 'Rule 2 (ربع — يوليو)']; ?>
<div class="card" id="summer_depts">
  <h2><?= e(__('تصنيف أقسام خصم الصيف')) ?></h2>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('القسم')) ?></th><th><?= e(__('القاعدة')) ?></th><th><?= e(__('الحالة')) ?></th></tr></thead>
    <tbody><?php foreach ($summerDepts as $d): ?><tr><td><?= e($d['name']) ?></td><td><?= e($d['summer_rule'] ? __($rules[$d['summer_rule']] ?? $d['summer_rule']) : '—') ?></td><td><?= e(__($d['active'] ? 'نشط' : 'موقوف')) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php if (can('salary', 'update') && dept_scope() === null): ?>
  <form method="post" action="<?= e(url('summer')) ?>" class="filters">
    <?= csrf_field() ?><input type="hidden" name="action" value="dept_rule">
    <label><?= e(__('القسم')) ?> <select name="dept_id" required><option value=""><?= e(__('— اختار قسم —')) ?></option><?php foreach ($summerDepts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></label>
    <label><?= e(__('القاعدة')) ?> <select name="rule"><option value=""><?= e(__('— بدون تصنيف —')) ?></option><?php foreach ($rules as $k => $v): ?><option value="<?= e($k) ?>"><?= e(__($v)) ?></option><?php endforeach; ?></select></label>
    <button class="btn primary"><?= e(__('حفظ التصنيف')) ?></button>
  </form>
  <?php endif; ?>
</div>
