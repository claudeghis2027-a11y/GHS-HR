<div class="card narrow center accent">
  <h1><?= e(__('الموافقة على معالجة بصمة الوجه')) ?></h1>
  <?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
  <p class="muted"><?= e(__('قبل ما تستخدم تسجيل الحضور، لازم تقرا النص ده وتوافق عليه. موافقتك بتتسجل بالتاريخ والوقت.')) ?></p>
  <div class="consent pre"><?= e($text) ?></div>
  <?php if ($done): ?>
    <p class="flash ok"><?= e(__('إنت موافق على الإصدار ده بالفعل.')) ?></p>
    <a class="btn primary" href="<?= e(url('face_enroll')) ?>"><?= e(__('كمّل')) ?></a>
  <?php else: ?>
  <form method="post" action="<?= e(url('face_consent')) ?>">
    <?= csrf_field() ?><input type="hidden" name="version" value="<?= (int)$version ?>">
    <label class="check"><input type="checkbox" name="agree" value="1" required> <?= e(__('قريت النص وأوافق صراحةً على معالجة بصمة وجهي للغرض ده')) ?></label>
    <div class="btn-row"><button class="btn primary"><?= e(__('أوافق وكمّل')) ?></button></div>
  </form>
  <form method="post" action="<?= e(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="link"><?= e(__('مش موافق (خروج)')) ?></button></form>
  <p class="muted"><?= e(__('لو مش موافق، كلّم إدارة الموارد البشرية عشان يرتبولك طريقة تانية لتسجيل الحضور.')) ?></p>
  <?php endif; ?>
</div>
