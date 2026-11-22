<?php
// Employee photo + login account (old employee profile: reference photo, reset to the default password).
// Needs $id (employee) and $back (where the forms return to).
$hasDefPw = setting('_emp_default_pw', '') !== '';
?>
<?php if (is_manager() || (can('employees', 'update') && can_see_employee($id))): $hasPhoto = (bool)q_val('SELECT official_photo FROM employees WHERE id = ?', [$id]); ?>
<div class="card<?= $hasPhoto ? '' : ' no-print' ?>" id="emp_photo">
  <h2><?= e(__('الصورة الرسمية للموظف')) ?></h2>
  <p class="muted no-print"><?= e(__('بتظهر للسوبر أدمن جنب صورة بصمة الوجه وقت المراجعة. بتتحفظ مشفّرة بره الويب.')) ?></p>
  <?php if ($hasPhoto): $src = url('face_img', ['kind' => 'official', 'id' => $id]); ?>
    <p><a href="<?= e($src) ?>" target="_blank" rel="noopener"><img class="face-img" src="<?= e($src) ?>" alt="<?= e(__('الصورة الرسمية')) ?>"></a></p>
    <p><a class="btn small" href="<?= e($src) ?>" target="_blank" rel="noopener"><?= e(__('عرض بالحجم الكامل')) ?></a></p>
  <?php else: ?>
    <p class="muted"><?= e(__('⚠️ مفيش صورة مرجعية للموظف ده.')) ?></p>
  <?php endif; ?>
  <?php if (can('employees', 'update')): ?>
  <form method="post" action="<?= e(url('emp_photo')) ?>" enctype="multipart/form-data" class="filters">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
    <label><?= e(__('صورة JPG أو PNG (حد أقصى 3 ميجا)')) ?> <input type="file" name="photo" accept="image/jpeg,image/png" required></label>
    <button class="btn"><?= e(__($hasPhoto ? 'تغيير الصورة' : 'رفع')) ?></button>
  </form>
  <?php if ($hasPhoto): ?>
  <form method="post" action="<?= e(url('emp_photo')) ?>" class="inline" data-confirm="<?= e(__('حذف الصورة المرجعية؟')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="op" value="delete"><input type="hidden" name="back" value="<?= e($back) ?>">
    <button class="btn small danger"><?= e(__('حذف الصورة')) ?></button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (is_manager()):
    $eacct = q_one('SELECT u.*, r.name role_name, r.is_super FROM users u JOIN roles r ON r.id = u.role_id WHERE u.employee_id = ?', [$id]); ?>
<div class="card accent no-print" id="emp_account">
  <h2><?= e(__('حساب الدخول وكلمة المرور')) ?></h2>
  <p><?= e(__('كود الموظف')) ?>: <strong class="num"><?= (int)$id ?></strong> · <span class="muted"><?= e(__('الكود ثابت ومينفعش يتغير، والموظف يقدر يدخل بيه.')) ?></span></p>
  <?php if (!$hasDefPw): ?>
    <p class="muted"><?= e(__('مفيش كلمة مرور افتراضية للموظفين لسه.')) ?> <a href="<?= e(url('users')) ?>"><?= e(__('حددها من صفحة المستخدمين')) ?></a></p>
  <?php endif; ?>
  <?php if ($eacct): $locked = $eacct['locked_until'] && $eacct['locked_until'] > date('Y-m-d H:i:s'); ?>
    <p><?= e(__('اسم المستخدم')) ?>: <strong dir="ltr"><?= e($eacct['username']) ?></strong> · <?= e(__($eacct['role_name'])) ?> ·
      <span class="badge <?= $eacct['active'] ? 'green' : 'red' ?>"><?= e(__($eacct['active'] ? 'نشط' : 'معطل')) ?></span>
      <?php if ($locked): ?><span class="badge red"><?= e(__('مقفول مؤقتًا')) ?></span><?php endif; ?>
      <?php if ($eacct['must_change_password']): ?><span class="badge"><?= e(__('لازم يغيّر كلمة المرور')) ?></span><?php endif; ?></p>
    <p class="muted"><?= e(__('آخر دخول')) ?>: <span class="num"><?= e($eacct['last_login_at'] ? substr((string)$eacct['last_login_at'], 0, 16) : '—') ?></span>
      · <?= e(__('آخر تغيير لكلمة المرور')) ?>: <span class="num"><?= e($eacct['password_changed_at'] ? substr((string)$eacct['password_changed_at'], 0, 16) : '—') ?></span></p>
    <?php if ($eacct['is_super'] && !is_super()): ?><p class="muted"><?= e(__('حسابات السوبر أدمن بيتحكم فيها السوبر أدمن بس.')) ?></p><?php else: ?>
    <div class="actions">
      <?php if ($hasDefPw): ?>
      <form method="post" action="<?= e(url('users')) ?>" class="inline" data-confirm="<?= e(__('كلمة المرور هترجع للافتراضية. متأكد؟')) ?>">
        <?= csrf_field() ?><input type="hidden" name="op" value="reset_default"><input type="hidden" name="id" value="<?= (int)$eacct['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
        <button class="btn primary"><?= e(__('رجّع كلمة المرور للافتراضي')) ?></button>
      </form>
      <?php endif; ?>
      <?php if ($locked): ?>
      <form method="post" action="<?= e(url('users')) ?>" class="inline">
        <?= csrf_field() ?><input type="hidden" name="op" value="unlock"><input type="hidden" name="id" value="<?= (int)$eacct['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
        <button class="btn"><?= e(__('فك القفل')) ?></button>
      </form>
      <?php endif; ?>
      <form method="post" action="<?= e(url('users')) ?>" class="inline" data-confirm="<?= e(__('متأكد؟')) ?>">
        <?= csrf_field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int)$eacct['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
        <button class="btn ghost"><?= e(__($eacct['active'] ? 'تعطيل الحساب' : 'تفعيل الحساب')) ?></button>
      </form>
    </div>
    <details>
      <summary><?= e(__('تعيين كلمة مرور جديدة')) ?></summary>
      <form method="post" action="<?= e(url('users')) ?>">
        <?= csrf_field() ?><input type="hidden" name="op" value="reset"><input type="hidden" name="id" value="<?= (int)$eacct['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
        <label><?= e(__('كلمة مرور جديدة')) ?> <input type="password" name="password" required minlength="10" autocomplete="new-password" dir="ltr"></label>
        <label class="check"><input type="hidden" name="must_change" value="0"><input type="checkbox" name="must_change" value="1" checked> <?= e(__('يغيّرها أول ما يدخل')) ?></label>
        <button class="btn"><?= e(__('تغيير كلمة المرور')) ?></button>
      </form>
    </details>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted"><?= e(__('الموظف ده مالوش حساب دخول.')) ?></p>
    <form method="post" action="<?= e(url('users')) ?>">
      <?= csrf_field() ?><input type="hidden" name="op" value="create"><input type="hidden" name="employee_id" value="<?= (int)$id ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
      <input type="hidden" name="role_id" value="<?= (int)q_val("SELECT id FROM roles WHERE slug = 'employee'") ?>">
      <label><?= e(__('كلمة مرور')) ?><?= $hasDefPw ? ' ' . e(__('(فاضي = الافتراضية)')) : '' ?> <input type="password" name="password" <?= $hasDefPw ? '' : 'required' ?> minlength="10" autocomplete="new-password" dir="ltr"></label>
      <label class="check"><input type="hidden" name="must_change" value="0"><input type="checkbox" name="must_change" value="1" checked> <?= e(__('يغيّرها أول ما يدخل')) ?></label>
      <button class="btn primary"><?= e(__('إنشاء حساب (اسم المستخدم = الكود)')) ?></button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
