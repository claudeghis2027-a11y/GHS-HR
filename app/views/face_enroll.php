<div class="card narrow center accent">
  <h1><?= e(__('صورتك المرجعية لبصمة الوجه')) ?></h1>
  <?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($last_rejected && !$has_active): ?><div class="flash err"><?= e(__('صورتك السابقة اترفضت')) ?><?= $last_rejected['reject_reason'] ? ': ' . e($last_rejected['reject_reason']) : '' ?>. <?= e(__('ارفع صورة جديدة.')) ?></div><?php endif; ?>
  <?php if ($pending): ?><div class="flash info"><?= e(__('عندك صورة مستنية موافقة الإدارة (اترفعت %s). تقدر تبصم عادي، ولو رفعت صورة جديدة هتاخد مكانها.', substr($pending['submitted_at'], 0, 16))) ?></div>
  <?php elseif ($approved): ?><div class="flash ok"><?= e(__('صورتك المرجعية متوافق عليها. لو غيرتها، الجديدة محتاجة موافقة.')) ?></div><?php endif; ?>
  <ul class="tips">
    <li><?= e(__('قف في مكان منوّر، ومن غير نضارة شمس أو كمامة.')) ?></li>
    <li><?= e(__('خلي وشك كله جوه الإطار، ومحدش يبقى جنبك.')) ?></li>
    <li><?= e(__('هيطلب منك تبص قدام، وبعدين تلف راسك يمين وشمال براحة.')) ?></li>
  </ul>
  <form method="post" action="<?= e(url('face_enroll')) ?>" data-face-capture>
    <?= csrf_field() ?><input type="hidden" name="frames">
    <div class="camera" hidden><video playsinline muted></video><div class="cam-hint" data-hint></div></div>
    <p class="muted" data-status></p>
    <button type="button" class="btn primary big" data-capture><?= e(__('افتح الكاميرا وصوّر')) ?></button>
  </form>
</div>
