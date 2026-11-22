<div class="page-head">
  <div><h1><?= e(__('التحقيقات')) ?></h1><div class="sub"><?= e(__('التعديل بيحفظ نسخة جديدة والقديمة بتفضل في السجل')) ?></div></div>
  <?php if (can('investigations', 'create')): ?><a class="btn primary" href="<?= e(url('inv_edit')) ?>"><?= e(__('تحقيق جديد')) ?></a><?php endif; ?>
</div>
<form method="get" class="filters card">
  <input type="hidden" name="r" value="inv">
  <label><?= e(__('من')) ?> <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label><?= e(__('إلى')) ?> <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <select name="emp"><option value=""><?= e(__('كل الموظفين')) ?></option><?php foreach ($emps as $k => $v): ?><option value="<?= (int)$k ?>"<?= $f['emp'] === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <input name="q" value="<?= e($f['q']) ?>" placeholder="<?= e(__('بحث')) ?>">
  <select name="show"><option value=""><?= e(__('السارية')) ?></option><option value="cancelled"<?= $f['show'] === 'cancelled' ? ' selected' : '' ?>><?= e(__('الملغاة')) ?></option><option value="all"<?= $f['show'] === 'all' ? ' selected' : '' ?>><?= e(__('الكل')) ?></option></select>
  <button class="btn small"><?= e(__('عرض')) ?></button>
</form>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('رقم التحقيق')) ?></th><th><?= e(__('التاريخ')) ?></th><th><?= e(__('الموضوع')) ?></th><th><?= e(__('الموظفين')) ?></th><th><?= e(__('القرار/النتيجة')) ?></th><th><?= e(__('مرفقات')) ?></th><th><?= e(__('الحالة')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="num"><a href="<?= e(url('inv', ['id' => $r['id']])) ?>"><?= e($r['inv_no'] ?: '#' . $r['id']) ?></a></td><td class="num"><?= e($r['inv_date']) ?></td><td><?= e($r['subject']) ?></td>
      <td><?= e(implode('، ', array_map(fn($x) => $x['id'] . ' - ' . $x['name_ar'], $empsBy[$r['id']] ?? []))) ?></td><td><?= e(mb_strimwidth((string)$r['decision'], 0, 80, '…')) ?></td>
      <td class="num"><?= $attBy[$r['id']] ?? '' ?></td><td><span class="badge <?= $r['cancelled'] ? 'red' : 'green' ?>"><?= e(__($r['cancelled'] ? 'ملغى' : 'ساري')) ?></span></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= e(__('مفيش تحقيقات.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
