<div class="page-head"><div><h1><?= e(__('المستخدمين')) ?></h1></div></div>
<?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('المستخدم')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('الصلاحية')) ?></th><th><?= e(__('الموظف')) ?></th><th><?= e(__('الحالة')) ?></th><th><?= e(__('آخر دخول')) ?></th><th><?= e(__('إجراءات')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u):
      $locked = $u['locked_until'] && strtotime($u['locked_until']) > time();
      $protected = !is_super() && q_val('SELECT is_super FROM roles WHERE id = ?', [$u['role_id']]); ?>
    <tr>
      <td dir="ltr"><?= e($u['username']) ?></td><td><?= e($u['display_name']) ?></td>
      <td>
        <?php if ($protected): ?><?= e(__($u['role_name'])) ?><?php else: ?>
        <form method="post" action="<?= e(url('users')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="role"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <select name="role_id" data-autosubmit><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === (int)$u['role_id'] ? 'selected' : '' ?>><?= e(__($r['name'])) ?></option><?php endforeach; ?></select>
        </form>
        <?php endif; ?>
      </td>
      <td><?= $u['employee_id'] ? (int)$u['employee_id'] . ' - ' . e($u['name_ar']) : '' ?></td>
      <td>
        <span class="badge <?= $u['active'] ? 'green' : 'red' ?>"><?= e(__($u['active'] ? 'نشط' : 'معطل')) ?></span>
        <?php if ($locked): ?><span class="badge red"><?= e(__('مقفول')) ?></span><?php endif; ?>
        <?php if ($u['legacy_hash']): ?><span class="badge ochre"><?= e(__('كلمة مرور قديمة')) ?></span><?php endif; ?>
        <?php if ($u['must_change_password']): ?><span class="badge"><?= e(__('لازم يغير كلمة المرور')) ?></span><?php endif; ?>
      </td>
      <td class="num"><?= e($u['last_login_at']) ?></td>
      <td class="actions">
        <?php if ($protected): ?><span class="muted"><?= e(__('للسوبر أدمن بس')) ?></span><?php else: ?>
        <form method="post" action="<?= e(url('users')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="link"><?= e(__($u['active'] ? 'تعطيل' : 'تفعيل')) ?></button></form>
        <?php if ($locked || $u['failed_logins']): ?><form method="post" action="<?= e(url('users')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="unlock"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="link"><?= e(__('فك القفل')) ?></button></form><?php endif; ?>
        <form method="post" action="<?= e(url('users')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="op" value="reset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="password" name="password" placeholder="<?= e(__('كلمة مرور جديدة')) ?>" minlength="10" required autocomplete="new-password">
          <label class="inline small"><input type="hidden" name="must_change" value="0"><input type="checkbox" name="must_change" value="1" checked> <?= e(__('يغيّرها أول دخول')) ?></label>
          <button class="link"><?= e(__('تعيين')) ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="grid2">
<form method="post" action="<?= e(url('users')) ?>" class="card">
  <h2><?= e(__('مستخدم جديد')) ?></h2>
  <?= csrf_field() ?><input type="hidden" name="op" value="create">
  <label><?= e(__('اسم المستخدم')) ?> <input name="username" pattern="[a-z0-9._\-]{1,64}" dir="ltr"><small class="muted"><?= e(__('لحساب موظف سيبه فاضي: هيبقى كود الموظف.')) ?></small></label>
  <label><?= e(__('الاسم الظاهر')) ?> <input name="display_name" maxlength="150"></label>
  <label><?= e(__('الصلاحية')) ?> <select name="role_id" required><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e(__($r['name'])) ?></option><?php endforeach; ?></select></label>
  <label><?= e(__('ربط بموظف (إلزامي لحساب الموظف)')) ?>
    <select name="employee_id"><option value="">—</option><?php foreach ($free_emps as $e2): ?><option value="<?= (int)$e2['id'] ?>"><?= (int)$e2['id'] ?> - <?= e($e2['name_ar']) ?></option><?php endforeach; ?></select>
  </label>
  <label><?= e(__('كلمة مرور مؤقتة')) ?> <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
  <button class="btn primary"><?= e(__('إنشاء')) ?></button>
</form>
<form method="post" action="<?= e(url('users')) ?>" class="card">
  <h2><?= e(__('حسابات لكل الموظفين اللي مالهمش حساب')) ?></h2>
  <?= csrf_field() ?><input type="hidden" name="op" value="bulk_employees">
  <p class="muted"><?= e(__('اسم المستخدم = كود الموظف، وكل واحد هيُطلب منه تغيير كلمة المرور أول دخول. عدد الموظفين بدون حساب: %d.', count($free_emps))) ?></p>
  <label><?= e(__('كلمة مرور مؤقتة موحدة')) ?> <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
  <button class="btn"><?= e(__('إنشاء الحسابات')) ?></button>
</form>
</div>
