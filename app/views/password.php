<div class="page-head"><div><h1><?= e(__('تغيير كلمة المرور')) ?></h1><div class="sub"><?= e(__('10 حروف على الأقل، فيها حروف وأرقام.')) ?></div></div></div>
<div class="card narrow">
  <?php if (user()['must_change_password']): ?><div class="flash warn"><?= e(__('لازم تغيّر كلمة المرور المؤقتة قبل ما تكمل.')) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('password')) ?>">
    <?= csrf_field() ?>
    <label><?= e(__('كلمة المرور الحالية')) ?> <input type="password" name="current" required autocomplete="current-password" dir="ltr"></label>
    <label><?= e(__('كلمة المرور الجديدة')) ?> <input type="password" name="new" required minlength="10" autocomplete="new-password" dir="ltr"></label>
    <label><?= e(__('تأكيد كلمة المرور الجديدة')) ?> <input type="password" name="confirm" required minlength="10" autocomplete="new-password" dir="ltr"></label>
    <button class="btn primary"><?= e(__('حفظ')) ?></button>
  </form>
</div>
