<div class="page-head">
  <div><h1><?= e(__('الرواتب الأساسية')) ?></h1><div class="sub"><?= e(__('الأساسي الساري النهارده للموظفين النشطين')) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="basic_salaries">
    <select name="dept"><option value=""><?= e(__('كل الأقسام')) ?></option><?php foreach ($depts as $k => $v): ?><option value="<?= (int)$k ?>"<?= $dept === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <select name="job"><option value=""><?= e(__('كل المسميات')) ?></option><?php foreach ($jobs as $k => $v): ?><option value="<?= (int)$k ?>"<?= $job === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <button class="btn small"><?= e(__('عرض')) ?></button>
    <?php if (can('salary', 'export')): ?><?= export_links('basic_salaries', ['dept' => $dept, 'job' => $job]) ?><?php endif; ?>
  </form>
</div>
<?php $missing = count(array_filter($rows, fn($r) => $r['basic'] === null)); $dups = count(array_filter($rows, fn($r) => $r['dups'] > 0)); ?>
<div class="stats">
  <div class="stat"><span><?= count($rows) ?></span><?= e(__('موظف')) ?></div>
  <div class="stat<?= $missing ? ' warn' : '' ?>"><span><?= $missing ?></span><?= e(__('من غير راتب أساسي')) ?></div>
  <div class="stat<?= $dups ? ' warn' : '' ?>"><span><?= $dups ?></span><?= e(__('عندهم أساسيين بنفس التاريخ')) ?></div>
</div>
<form method="post" action="<?= e(url('basic_salaries')) ?>" data-confirm="<?= e(__('هيتسجل راتب أساسي جديد للخانات اللي اتملت بس. متأكد؟')) ?>">
  <?= csrf_field() ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('المسمى')) ?></th><th><?= e(__('الأساسي الحالي')) ?></th><th><?= e(__('ساري من')) ?></th><th><?= e(__('أساسي جديد')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr<?= $r['basic'] === null || $r['dups'] ? ' class="row-warn"' : '' ?>><td class="num"><?= (int)$r['id'] ?></td><td><?= e($r['name_ar']) ?></td><td><?= e($r['dept_name']) ?></td><td><?= e($r['job_name']) ?></td>
        <td class="num"><?= $r['basic'] === null ? e(__('مفيش')) : e(money($r['basic'])) ?><?= $r['dups'] ? ' ⚠' : '' ?></td><td class="num"><?= e($r['effective_from']) ?></td>
        <td><?php if (can('salary', 'create')): ?><input name="basic[<?= (int)$r['id'] ?>]" inputmode="decimal" pattern="\d+(\.\d{1,2})?" class="input-narrow"><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= e(__('مفيش موظفين.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($rows && can('salary', 'create')): ?>
  <div class="card narrow">
    <label><?= e(__('تاريخ السريان')) ?> * <input type="date" name="effective_from" required></label>
    <label><?= e(__('السبب')) ?> <input name="reason" maxlength="500" placeholder="<?= e(__('حفظ جماعي للرواتب الأساسية')) ?>"></label>
    <button class="btn primary"><?= e(__('حفظ')) ?></button>
  </div>
  <?php endif; ?>
</form>
<?php if (can('salary', 'update') && $rates): ?>
<form method="post" action="<?= e(url('basic_salaries')) ?>" class="card" id="rates">
  <?= csrf_field() ?><input type="hidden" name="op" value="rates">
  <h2><?= e(__('إعدادات الرواتب (نِسَب العلاوة وخصم الربع لكل قسم)')) ?></h2>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('القسم')) ?></th><th><?= e(__('نسبة العلاوة %')) ?></th><th><?= e(__('نسبة خصم الربع %')) ?></th></tr></thead>
    <tbody><?php foreach ($rates as $d): $n = fn($v) => $v === null ? '' : (string)(float)$v; ?><tr>
      <td><?= e($d['name']) ?></td>
      <td><input type="number" name="allowance[<?= (int)$d['id'] ?>]" min="0" max="100" step="0.01" value="<?= e($n($d['allowance_rate'])) ?>" placeholder="0"></td>
      <td><input type="number" name="quarter[<?= (int)$d['id'] ?>]" min="0" max="100" step="0.01" value="<?= e($n($d['quarter_rate'])) ?>" placeholder="25"></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <div class="actions"><button class="btn primary"><?= e(__('حفظ النِسَب')) ?></button></div>
</form>
<?php endif; ?>
