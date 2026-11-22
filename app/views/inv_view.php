<?php $active = !$inv['cancelled']; ?>
<div class="page-head">
  <div><h1><?= e($inv['inv_no'] ?: '#' . $inv['id']) ?> · <?= e($inv['subject']) ?></h1>
    <div class="sub"><?= e($inv['inv_date']) ?> · <?= e(__('سجّله')) ?>: <?= e($inv['created_by']) ?> · <span class="badge <?= $active ? 'green' : 'red' ?>"><?= e(__($active ? 'ساري' : ($inv['superseded_by'] ? 'نسخة قديمة' : 'ملغى'))) ?></span></div></div>
  <div><a class="btn" href="<?= e(url('inv')) ?>"><?= e(__('رجوع')) ?></a>
  <?php if ($active && can('investigations', 'update')): ?> <a class="btn primary" href="<?= e(url('inv_edit', ['id' => $inv['id']])) ?>"><?= e(__('تعديل')) ?></a><?php endif; ?>
  <?php if ($inv['superseded_by']): ?> <a class="btn" href="<?= e(url('inv', ['id' => $inv['superseded_by']])) ?>"><?= e(__('النسخة الأحدث')) ?></a><?php endif; ?></div>
</div>
<?php if (!$active && $inv['cancel_reason']): ?><div class="flash err"><?= e(__('اتلغى بواسطة %s في %s. السبب: %s', $inv['cancelled_by'], substr((string)$inv['cancelled_at'], 0, 16), $inv['cancel_reason'])) ?></div><?php endif; ?>
<div class="grid">
  <div class="card">
    <h2><?= e(__('الموظفين')) ?></h2>
    <ul><?php foreach ($emps as $x): ?><li><?= (int)$x['id'] ?> - <?= e($x['name_ar']) ?> <small class="muted"><?= e($x['dept_name']) ?></small></li><?php endforeach; ?></ul>
    <h2><?= e(__('القرار/النتيجة')) ?></h2><p class="pre"><?= e($inv['decision']) ?></p>
    <h2><?= e(__('ملاحظات')) ?></h2><p class="pre"><?= e($inv['notes']) ?></p>
  </div>
  <div class="card">
    <h2><?= e(__('المرفقات')) ?></h2>
    <?php if (!$atts): ?><p class="muted"><?= e(__('مفيش مرفقات.')) ?></p><?php endif; ?>
    <ul>
    <?php foreach ($atts as $a): ?>
      <li><a href="<?= e(url('inv_file', ['id' => $a['id']])) ?>" target="_blank" rel="noopener">📎 <?= e($a['file_name']) ?></a> <small class="muted"><?= e(substr((string)$a['uploaded_at'], 0, 16)) ?> · <?= e($a['uploaded_by']) ?></small>
        <?php if ($active && can('investigations', 'update')): ?>
        <form method="post" action="<?= e(url('inv')) ?>" class="inline" data-confirm="<?= e(__('المرفق هيتشال من التحقيق (الملف بيفضل محفوظ). متأكد؟')) ?>"><?= csrf_field() ?><input type="hidden" name="op" value="att_remove"><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>"><input type="hidden" name="att" value="<?= (int)$a['id'] ?>"><button class="btn small ghost"><?= e(__('شيل')) ?></button></form>
        <?php endif; ?></li>
    <?php endforeach; ?>
    </ul>
    <?php if ($active && can('investigations', 'update')): ?>
    <form method="post" action="<?= e(url('inv')) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="op" value="att_add"><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
      <label><?= e(__('المرفقات (صور/PDF — أكثر من ملف)')) ?> <input type="file" name="files[]" accept="application/pdf,image/jpeg,image/png,image/webp" multiple required></label>
      <button class="btn"><?= e(__('رفع')) ?></button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php if ($active && can('investigations', 'delete')): ?>
<form method="post" action="<?= e(url('inv')) ?>" class="card narrow" data-confirm="<?= e(__('التحقيق هيتلغى (السجل بيفضل محفوظ). متأكد؟')) ?>">
  <h2><?= e(__('إلغاء التحقيق')) ?></h2>
  <?= csrf_field() ?><input type="hidden" name="op" value="cancel"><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
  <label><?= e(__('سبب الإلغاء')) ?> * <input name="reason" maxlength="500" required></label>
  <button class="btn"><?= e(__('إلغاء التحقيق')) ?></button>
</form>
<?php endif; ?>
<?php if (count($versions) > 1): ?>
<div class="card">
  <h2><?= e(__('سجل النسخ')) ?></h2>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th><?= e(__('التاريخ')) ?></th><th><?= e(__('الموضوع')) ?></th><th><?= e(__('الحالة')) ?></th><th><?= e(__('المستخدم')) ?></th><th><?= e(__('وقت الحفظ')) ?></th></tr></thead>
    <tbody><?php foreach ($versions as $v): ?>
      <tr<?= (int)$v['id'] === (int)$inv['id'] ? ' class="row-warn"' : '' ?>><td class="num"><a href="<?= e(url('inv', ['id' => $v['id']])) ?>"><?= (int)$v['id'] ?></a></td><td class="num"><?= e($v['inv_date']) ?></td><td><?= e($v['subject']) ?></td>
        <td><?= e(__(!$v['cancelled'] ? 'ساري' : ($v['superseded_by'] ? 'نسخة قديمة' : 'ملغى'))) ?></td><td><?= e($v['created_by']) ?></td><td class="num"><?= e(substr((string)$v['created_at'], 0, 16)) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>
