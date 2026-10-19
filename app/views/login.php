<div class="auth">
  <section class="auth-brand" aria-hidden="true">
    <div><span class="logo-plate"><img src="<?= e(asset('img/logo-800.png')) ?>" alt="" width="400" height="202"></span></div>
    <div>
      <h2><?= e(__('نظام الموارد البشرية والحضور')) ?></h2>
      <p><?= e(__('كل بيانات الموظفين والحضور والمرتبات في مكان واحد آمن، لفريق مدارس جرين هايتس.')) ?></p>
    </div>
    <div class="foot">© <?= date('Y') ?> Green Heights Schools</div>
  </section>
  <section class="auth-form">
    <?php require __DIR__ . '/_lang_switch.php'; ?>
    <div class="login-card">
      <img class="logo-small" src="<?= e(asset('img/logo-480.png')) ?>" alt="Green Heights Schools" width="180" height="91">
      <h1><?= e(__('تسجيل الدخول')) ?></h1>
      <p class="sub"><?= e(__('ادخل باسم المستخدم أو كود الموظف.')) ?></p>
      <?php if (!empty($error)): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= e(url('login')) ?>" autocomplete="on">
        <?= csrf_field() ?>
        <label><?= e(__('اسم المستخدم أو كود الموظف')) ?>
          <input name="username" required maxlength="64" autocomplete="username" autofocus value="<?= e($username ?? '') ?>" dir="ltr">
        </label>
        <label><?= e(__('كلمة المرور')) ?>
          <input type="password" name="password" required maxlength="200" autocomplete="current-password" dir="ltr">
        </label>
        <button class="btn primary wide"><?= e(__('دخول')) ?></button>
      </form>
      <p class="secure-note">🔒 <?= e(__('اتصال مشفّر. لو نسيت كلمة المرور كلّم إدارة الموارد البشرية.')) ?></p>
    </div>
  </section>
</div>
