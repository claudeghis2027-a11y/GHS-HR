<div class="page-head"><div><h1><?= e(__('مراجعة بصمات الوجه')) ?></h1></div></div>
<h2><?= e(__('موظفين اتقفلت عليهم البصمة (فشل متكرر)')) ?></h2>
<div class="table-wrap"><table><thead><tr><th><?= e(__('الموظف')) ?></th><th><?= e(__('مرات الفشل ورا بعض')) ?></th><th><?= e(__('آخر تحديث')) ?></th><th></th></tr></thead><tbody>
<?php if (!$blocked): ?><tr><td colspan="4" class="muted center"><?= e(__('مفيش.')) ?></td></tr><?php endif; ?>
<?php foreach ($blocked as $b): ?>
  <tr><td><?= (int)$b['employee_id'] ?> - <?= e($b['name_ar']) ?></td><td class="num"><?= (int)$b['face_fail_streak'] ?></td><td class="num"><?= e($b['updated_at']) ?></td>
  <td><form method="post" action="<?= e(url('face_hr')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="unblock"><input type="hidden" name="employee_id" value="<?= (int)$b['employee_id'] ?>"><input name="note" placeholder="<?= e(__('ملاحظة')) ?>" maxlength="500"><button class="btn small"><?= e(__('افتح البصمة')) ?></button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div>

<h2><?= e(__('بصمات محتاجة مراجعة')) ?></h2>
<p class="muted"><?= e(__('"مراجعة HR" معناها إن الصورة اللي اتعملت بيها البصمة اترفضت. "صورة لسه ما اتوافقش عليها" للعلم بس، وبتتحول لعادية لما الصورة يتوافق عليها.')) ?></p>
<div class="table-wrap"><table class="small"><thead><tr><th><?= e(__('الموظف')) ?></th><th><?= e(__('اليوم')) ?></th><th><?= e(__('النوع')) ?></th><th><?= e(__('الوقت')) ?></th><th><?= e(__('درجة التطابق')) ?></th><th><?= e(__('الحالة')) ?></th><th></th></tr></thead><tbody>
<?php if (!$punches): ?><tr><td colspan="7" class="muted center"><?= e(__('مفيش.')) ?></td></tr><?php endif; ?>
<?php foreach ($punches as $p): $t = $p['kind'] === 'in' ? $p['check_in_at'] : $p['check_out_at']; ?>
  <tr><td><?= (int)$p['employee_id'] ?> - <?= e($p['name_ar']) ?></td><td class="num"><?= e($p['work_date']) ?></td><td><?= e(__($p['kind'] === 'in' ? 'تسجيل حضور' : 'تسجيل انصراف')) ?></td>
  <td class="num"><?= e($t ? substr($t, 11, 5) : __('اتلغت')) ?></td><td class="num"><?= e($p['score']) ?></td>
  <td><?php if ($p['flag'] === 'hr_review'): ?><span class="badge red"><?= e(__('مراجعة HR')) ?></span><?= $p['reject_reason'] ? ' <small class="muted">' . e(__('سبب رفض الصورة: %s', $p['reject_reason'])) . '</small>' : '' ?><?php else: ?><span class="badge ochre"><?= e(__('صورة لسه ما اتوافقش عليها')) ?></span><?php endif; ?></td>
  <td><?php if ($p['flag'] === 'hr_review'): ?>
    <form method="post" action="<?= e(url('face_hr')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="punch"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input name="note" placeholder="<?= e(__('ملاحظة')) ?>" maxlength="500">
      <button class="btn small" name="decision" value="ok"><?= e(__('قبول')) ?></button>
      <button class="btn small danger" name="decision" value="invalid"><?= e(__('إلغاء البصمة')) ?></button></form>
  <?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
