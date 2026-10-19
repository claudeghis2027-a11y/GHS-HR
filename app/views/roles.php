<div class="page-head"><div><h1><?= e(__('الصلاحيات')) ?></h1><div class="sub"><?= e(__('"سوبر أدمن" ليه كل حاجة. "موظف / مدرس" بيشوف بياناته بس. باقي الصلاحيات بتتحدد بالمصفوفة، و"نطاق الأقسام" بيقصر المستخدم على موظفي أقسام معينة (فاضي = كل الأقسام).')) ?></div></div></div>
<?php foreach ($roles as $r): if ($r['is_super'] || !$r['is_staff'] || $r['slug'] === 'admin') { ?>
  <div class="card"><h2><?= e(__($r['name'])) ?></h2><p class="muted"><?= e(__($r['is_super'] ? 'كل الصلاحيات (ثابتة).' : ($r['slug'] === 'admin' ? 'مساعد السوبر أدمن: كل الصلاحيات، ما عدا التحكم في حسابات السوبر أدمن (ثابتة).' : 'خدمة ذاتية: بياناته وحضوره ومرتبه وإشعاراته فقط (ثابتة).'))) ?></p></div>
<?php continue; } $scope = $r['dept_scope'] ? array_map('intval', explode(',', $r['dept_scope'])) : []; ?>
<form method="post" action="<?= e(url('roles')) ?>" class="card">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
  <div class="head-row">
    <label class="inline"><?= e(__('الاسم')) ?> <input name="name" value="<?= e($r['name']) ?>" maxlength="100"></label>
    <label class="inline"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" <?= $r['active'] ? 'checked' : '' ?>> <?= e(__('نشطة')) ?></label>
  </div>
  <div class="table-wrap">
  <table class="matrix">
    <thead><tr><th><?= e(__('القسم')) ?></th><th><?= e(__('عرض')) ?></th><th><?= e(__('إضافة')) ?></th><th><?= e(__('تعديل')) ?></th><th><?= e(__('حذف')) ?></th><th><?= e(__('تصدير')) ?></th></tr></thead>
    <tbody>
    <?php foreach (MODULES as $m => $label): $p = $perms[$r['id']][$m] ?? []; ?>
      <tr><td><?= e(__($label)) ?></td>
      <?php foreach (ACTIONS as $a): ?><td><input type="checkbox" name="p[<?= e($m) ?>][<?= $a ?>]" value="1" <?= !empty($p['can_' . $a]) ? 'checked' : '' ?> aria-label="<?= e(__($label) . ' / ' . $a) ?>"></td><?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <fieldset><legend><?= e(__('نطاق الأقسام')) ?></legend>
    <?php foreach ($depts as $id => $n): ?><label class="inline"><input type="checkbox" name="dept[]" value="<?= (int)$id ?>" <?= in_array((int)$id, $scope, true) ? 'checked' : '' ?>> <?= e($n) ?></label><?php endforeach; ?>
  </fieldset>
  <button class="btn primary"><?= e(__('حفظ')) ?></button>
</form>
<?php endforeach; ?>
<form method="post" action="<?= e(url('roles')) ?>" class="card narrow">
  <?= csrf_field() ?><input type="hidden" name="op" value="create">
  <h2><?= e(__('صلاحية جديدة')) ?></h2>
  <label><?= e(__('الاسم')) ?> <input name="name" required maxlength="100"></label>
  <button class="btn"><?= e(__('إضافة')) ?></button>
</form>
