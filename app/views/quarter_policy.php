<div class="page-head">
  <div><h1><?= e(__('سياسة خصم الربعين')) ?></h1><div class="sub"><?= e(__('القسم × الشهر للسنة الدراسية')) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="quarter_policy">
    <select name="year"><?php foreach ($years as $y): ?><option value="<?= (int)$y['id'] ?>"<?= (int)$y['id'] === $yid ? ' selected' : '' ?>><?= e($y['name']) ?></option><?php endforeach; ?></select>
    <button class="btn small"><?= e(__('عرض')) ?></button>
  </form>
</div>
<?php if (!$years): ?>
  <div class="flash warn"><?= e(__('ضيف سنة دراسية الأول من "السنوات الدراسية".')) ?></div>
<?php else: ?>
<form method="post" action="<?= e(url('quarter_policy')) ?>">
  <?= csrf_field() ?><input type="hidden" name="year" value="<?= (int)$yid ?>">
  <div class="table-wrap">
  <table class="matrix">
    <thead><tr><th><?= e(__('القسم')) ?></th><?php foreach ($months as $m): ?><th><?= e(month_name((int)substr($m, 5, 2))) ?><br><small><?= e(substr($m, 0, 4)) ?></small></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($depts as $d): ?>
      <tr><td><?= e($d['name']) ?></td>
      <?php foreach ($months as $m): $k = $d['id'] . '|' . $m; ?><td><input type="checkbox" name="cell[<?= e($k) ?>]" value="1" <?= !empty($applied[$k]) ? 'checked' : '' ?> <?= can('salary', 'update') ? '' : 'disabled' ?>></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted"><?= e(__('زي النظام القديم: الجدول ده بيتحفظ كمرجع، وخصم الصيف في الكشف بيتحسب من تصنيف الموظف والقسم ونسبة خصم الربع في شاشة الأقسام.')) ?></p>
  <?php if (can('salary', 'update')): ?><button class="btn primary"><?= e(__('حفظ')) ?></button><?php endif; ?>
</form>
<?php endif; ?>
