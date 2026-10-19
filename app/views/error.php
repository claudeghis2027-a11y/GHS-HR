<div class="card narrow center accent">
  <p class="muted"><?= (int)$code ?></p>
  <h1><?= e(__($title)) ?></h1>
  <?php if ($msg !== ''): ?><p><?= e($msg) ?></p><?php endif; ?>
  <p><a class="btn primary" href="<?= e(url()) ?>"><?= e(__('رجوع للرئيسية')) ?></a></p>
</div>
