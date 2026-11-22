<div class="page-head"><div><h1><?= e(__('التحقق من الموقع')) ?></h1></div></div>
<?php if (!empty($outside)): ?>
  <?php require __DIR__ . '/_geo_blocked.php'; ?>
<?php else: ?>
<form method="post" action="<?= e(url('geo_check')) ?>" class="card center" data-geo-gate>
  <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
  <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="acc">
  <p><?= e(__('البيانات دي بتفتح من جوه المدرسة بس. بنتأكد من موقعك…')) ?></p>
  <p class="muted" data-status></p>
  <button type="button" class="btn primary" data-geo-retry hidden><?= e(__('حاول تاني')) ?></button>
</form>
<?php endif; ?>
