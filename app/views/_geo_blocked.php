<div class="card center geo-blocked">
  <h2><?= e(__('لا توجد بيانات — أنت خارج نطاق المدرسة')) ?></h2>
  <p class="muted"><?= e(__('بياناتك ومستنداتك وتقاريرك بتظهر وإنت جوه المدرسة بس. الإشعارات متاحة في أي وقت.')) ?></p>
  <form method="post" action="<?= e(url('geo_check')) ?>" data-geo-gate data-manual>
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back ?? url('', array_filter($_GET, 'is_string'))) ?>">
    <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="acc">
    <p class="muted" data-status></p>
    <button type="button" class="btn primary" data-geo-retry><?= e(__('حاول تاني')) ?></button>
    <a class="btn ghost" href="<?= e(url('inbox')) ?>"><?= e(__('الإشعارات')) ?></a>
  </form>
</div>
