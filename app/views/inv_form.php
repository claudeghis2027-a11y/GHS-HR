<?php $id = $inv['id'] ?? null; ?>
<div class="page-head">
  <div><h1><?= e(__('التحقيقات')) ?></h1><div class="sub"><?= $id ? e(__('تعديل %s (هيتحفظ كنسخة جديدة)', $inv['inv_no'] ?: '#' . $id)) : e(__('تحقيق جديد')) ?></div></div>
  <a class="btn" href="<?= e($id ? url('inv', ['id' => $id]) : url('inv')) ?>"><?= e(__('رجوع')) ?></a>
</div>
<?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('inv_edit')) ?>" class="card form-grid" enctype="multipart/form-data">
  <?= csrf_field() ?><?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
  <label><?= e(__('تاريخ التحقيق')) ?> * <input type="date" name="inv_date" value="<?= e($inv['inv_date'] ?? date('Y-m-d')) ?>" required></label>
  <label><?= e(__('عنوان/موضوع التحقيق')) ?> * <input name="subject" value="<?= e($inv['subject'] ?? '') ?>" maxlength="255" required></label>
  <label class="span2"><?= e(__('الموظفين')) ?> * <small class="muted"><?= e(__('اختار واحد أو أكتر (Ctrl / ضغطة طويلة)')) ?></small>
    <select name="employees[]" multiple size="8" required>
      <?php foreach ($emps as $k => $v): ?><option value="<?= (int)$k ?>"<?= in_array((int)$k, $sel, true) ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label class="span2"><?= e(__('القرار/النتيجة')) ?> <textarea name="decision" rows="3" maxlength="1000"><?= e($inv['decision'] ?? '') ?></textarea></label>
  <label class="span2"><?= e(__('ملاحظات')) ?> <textarea name="notes" rows="4" maxlength="5000"><?= e($inv['notes'] ?? '') ?></textarea></label>
  <label class="span2"><?= e(__('المرفقات (صور/PDF — أكثر من ملف)')) ?> <input type="file" name="files[]" accept="application/pdf,image/jpeg,image/png,image/webp" multiple></label>
  <div class="span2"><button class="btn primary"><?= e(__('حفظ التحقيق')) ?></button></div>
</form>
