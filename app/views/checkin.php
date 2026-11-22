<div class="card narrow center accent">
  <h1><?= e(__('تسجيل الحضور والانصراف')) ?></h1>
  <p class="muted"><?= e(day_name((int)date('N')) . ' ' . date('j') . ' ' . month_name((int)date('n'))) ?></p>
  <p class="clock" data-clock><?= e(date('H:i')) ?></p>
  <div class="punch-status">
    <span class="badge <?= !empty($today['check_in_at']) ? 'green' : '' ?>"><?= e(__('وقت الحضور')) ?>: <?= !empty($today['check_in_at']) ? e(substr($today['check_in_at'], 11, 5) . ' · ' . $today['check_in_point']) : e(__('لم يسجل')) ?></span>
    <span class="badge <?= !empty($today['check_out_at']) ? 'green' : '' ?>"><?= e(__('وقت الانصراف')) ?>: <?= !empty($today['check_out_at']) ? e(substr($today['check_out_at'], 11, 5) . ' · ' . $today['check_out_point']) : e(__('لم يسجل')) ?></span>
  </div>
  <?php if ($face && $pending_photo): ?><p class="flash info"><?= e(__('صورتك المرجعية لسه مستنية موافقة الإدارة، والبصمات هتتعلّم لحد ما يتوافق عليها.')) ?></p><?php endif; ?>
  <?php if ($face && $hr_review): ?><p class="flash err"><?= e(__('بصمة الوجه اتقفلت بعد كذا محاولة فاشلة ورا بعض. كلّم الـ HR.')) ?></p><?php endif; ?>

  <form method="post" action="<?= e(url('checkin')) ?>" id="checkin-form" data-require-photo="<?= !$face && $require_photo ? '1' : '0' ?>" data-face="<?= $face ? '1' : '0' ?>" data-wait="<?= (int)($wait ?? 0) ?>" data-done="<?= e(url('checkin')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="acc">
    <input type="hidden" name="photo"><input type="hidden" name="frames"><input type="hidden" name="action"><input type="hidden" name="attempt">
    <div class="camera" hidden>
      <video playsinline muted></video>
      <div class="cam-hint" data-hint></div>
      <canvas hidden></canvas>
      <img alt="" hidden>
    </div>
    <p class="muted" data-status><?= e($face ? __('هنحتاج الموقع والكاميرا. هيطلب منك تبص قدام وتلف راسك يمين وشمال.') : ($require_photo ? __('هنحتاج إذن الموقع والكاميرا عشان نسجل البصمة.') : __('هنحتاج إذن الموقع عشان نسجل البصمة.'))) ?></p>
    <?php if (!$face && $require_photo): ?><button type="button" class="btn" data-camera><?= e(__('فتح الكاميرا')) ?></button><?php endif; ?>
    <div class="btn-row">
      <button type="button" class="btn primary big" data-punch="in" <?= !empty($today['check_in_at']) || ($face && $hr_review) ? 'disabled' : '' ?>><?= e(__('تسجيل حضور')) ?></button>
      <button type="button" class="btn success big" data-punch="out" <?= empty($today['check_in_at']) || !empty($today['check_out_at']) || ($face && $hr_review) ? 'disabled' : '' ?>><?= e(__('تسجيل انصراف')) ?></button>
    </div>
  </form>
</div>
