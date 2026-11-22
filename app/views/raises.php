<div class="page-head">
  <div><h1><?= e(__('اعتماد العلاوة السنوية')) ?></h1>
    <div class="sub"><?= e(month_name((int)substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4)) ?><?= $raiseMonth ? ' · ' . e(__('شهر العلاوة للسنة النشطة: %s', $raiseMonth)) : ' · ' . e(__('مفيش شهر علاوة متحدد للسنة النشطة')) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="raises">
    <input type="month" name="m" value="<?= e($ym) ?>">
    <select name="dept"><option value=""><?= e(__('كل الأقسام')) ?></option><?php foreach ($depts as $k => $v): ?><option value="<?= (int)$k ?>"<?= $dept === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <button class="btn small"><?= e(__('عرض')) ?></button>
  </form>
</div>
<p class="muted"><?= e(__('العلاوة = الأساسي في أول الفترة × نسبة القسم. الاعتماد بيسجّل راتب أساسي جديد (الأساسي الحالي + العلاوة) من تاريخ السريان، فالشهور اللي بعده بتتحسب بيه.')) ?></p>
<form method="post" action="<?= e(url('raises')) ?>" data-confirm="<?= e(__('هيتسجل راتب أساسي جديد للموظفين المختارين. متأكد؟')) ?>">
  <?= csrf_field() ?><input type="hidden" name="m" value="<?= e($ym) ?>">
  <div class="table-wrap">
  <table>
    <thead><tr><th><input type="checkbox" data-check-all="emp[]" aria-label="<?= e(__('الكل')) ?>"></th><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('الأساسي')) ?></th><th><?= e(__('النسبة')) ?></th><th><?= e(__('العلاوة')) ?></th><th><?= e(__('الأساسي الجديد')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?php if (!$r['approved']): ?><input type="checkbox" name="emp[]" value="<?= (int)$r['id'] ?>"><?php endif; ?></td>
        <td class="num"><?= (int)$r['id'] ?></td><td><?= e($r['name_ar']) ?></td><td><?= e($r['dept_name']) ?></td><td class="num"><?= e(money($r['basic'])) ?></td><td class="num"><?= e(num($r['rate'])) ?>%</td>
        <td class="num"><?= e(money($r['allowance'])) ?></td><td class="num"><?= e(money(round2($r['basic'] + $r['allowance']))) ?></td>
        <td><?= $r['approved'] ? '<span class="badge green">' . e(__('اتعتمدت')) . '</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="muted"><?= e(__('مفيش موظفين مستحقين علاوة في الشهر ده.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($rows && can('salary', 'create')): ?>
  <div class="card narrow">
    <label><?= e(__('تاريخ السريان')) ?> * <input type="date" name="effective_from" value="<?= e($eff) ?>" required></label>
    <small class="muted"><?= e(__('الافتراضي أول يوم في الفترة اللي بعد شهر العلاوة، عشان العلاوة ماتتحسبش مرتين.')) ?></small>
    <label><?= e(__('السبب')) ?> <input name="reason" maxlength="500" placeholder="<?= e(__('اعتماد العلاوة السنوية')) ?>"></label>
    <button class="btn primary"><?= e(__('اعتماد المختارين')) ?></button>
  </div>
  <?php endif; ?>
</form>
