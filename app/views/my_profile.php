<div class="page-head"><div><h1><?= e(__('بياناتي')) ?></h1><div class="sub"><?= e(__('التعديلات بتتطبق بعد موافقة الإدارة.')) ?></div></div></div>
<?php foreach ($errors ?? [] as $er): ?><div class="flash err"><?= e($er) ?></div><?php endforeach; ?>
<?php if ($pending): ?><div class="flash info"><?= e(__('عندك طلب تعديل اتبعت يوم %s ولسه تحت المراجعة.', substr($pending['submitted_at'], 0, 16))) ?></div><?php endif; ?>
<?php if ($last && $last['status'] === 'rejected'): ?><div class="flash err"><?= e(__('آخر طلب اترفض')) ?><?= $last['reject_reason'] ? ': ' . e($last['reject_reason']) : '' ?></div><?php endif; ?>
<form method="post" action="<?= e(url('my_profile')) ?>" class="card form-grid">
  <?= csrf_field() ?>
  <label><?= e(__('كود الموظف')) ?> <input value="<?= (int)$emp['id'] ?>" disabled></label>
  <?php foreach ($fields as $f):
      $k = $f['field'];
      $val = $emp[$k] ?? '';
      if ($k === 'department_id') $val = $val ? (fk_options(['fk' => ['departments', 'name']])[$val] ?? '') : '';
      if ($k === 'job_title_id') $val = $val ? (fk_options(['fk' => ['job_titles', 'name']])[$val] ?? '') : '';
      if ($k === 'specialization_id') $val = $val ? (fk_options(['fk' => ['specializations', 'name']])[$val] ?? '') : '';
      $canEdit = $f['editable'] && !$pending && in_array($k, ['name_ar', 'name_en', 'phone', 'nationality'], true);
  ?>
    <label><?= e(__($f['label'])) ?><?= $f['required'] && $canEdit ? ' *' : '' ?>
      <input name="<?= e($k) ?>" value="<?= e($val) ?>" maxlength="150" <?= $canEdit ? '' : 'disabled' ?> <?= $f['required'] && $canEdit ? 'required' : '' ?> <?= in_array($k, ['name_en', 'phone'], true) ? 'dir="ltr"' : '' ?>>
    </label>
  <?php endforeach; ?>
  <?php if (!$pending): ?><div class="span2"><button class="btn primary"><?= e(__('إرسال طلب تعديل')) ?></button></div><?php endif; ?>
</form>
