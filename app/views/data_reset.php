<div class="page-head"><div><h1><?= e(__('إعادة ضبط البيانات')) ?></h1><div class="sub"><?= e(__('بيمسح بيانات المجموعات اللي تختارها بس. قبل أي مسح بتتعمل نسخة احتياطية إجباري، ولو فشلت مفيش حاجة بتتمسح.')) ?></div></div></div>
<form method="post" action="<?= e(url('data_reset')) ?>" class="card" data-confirm="<?= e(__('البيانات المختارة هتتمسح. متأكد؟')) ?>">
  <?= csrf_field() ?>
  <fieldset><legend><?= e(__('المجموعات')) ?></legend>
    <?php foreach ($groups as $k => [$label, $n]): ?><label class="check"><input type="checkbox" name="groups[]" value="<?= e($k) ?>"> <?= e(__($label)) ?> <small class="muted">(<?= (int)$n ?>)</small></label><?php endforeach; ?>
  </fieldset>
  <label><?= e(__('كلمة المرور بتاعتك')) ?> <input type="password" name="password" required autocomplete="current-password"></label>
  <label><?= e(__('اكتب «إعادة ضبط» للتأكيد')) ?> <input name="confirm" required autocomplete="off"></label>
  <button class="btn primary"><?= e(__('إعادة ضبط')) ?></button>
</form>
