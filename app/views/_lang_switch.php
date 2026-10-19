<div class="lang-switch" role="group" aria-label="Language / اللغة">
  <?php foreach (LANGS as $code => $l): ?>
    <form method="post" action="<?= e(url('lang')) ?>">
      <?= csrf_field() ?><input type="hidden" name="to" value="<?= e($code) ?>"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
      <button type="submit" lang="<?= e($code) ?>" aria-current="<?= lang() === $code ? 'true' : 'false' ?>"><?= e($code === 'ar' ? 'ع' : 'EN') ?></button>
    </form>
  <?php endforeach; ?>
</div>
