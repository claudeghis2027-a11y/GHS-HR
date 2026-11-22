<div class="page-head"><div><h1><?= e(__('استيراد ملفات Drive')) ?></h1><div class="sub"><?= e(__('نزّل فولدر المستندات من Drive القديم، وارفعه هنا ZIP أو حطه في فولدر الاستيراد على السيرفر. النظام مابيتصلش بجوجل.')) ?></div></div></div>
<div class="stats">
  <div class="stat"><span><?= (int)$plan['pending'] ?></span><?= e(__('سجل لسه ملفه على Drive')) ?></div>
  <div class="stat"><span><?= (int)$files ?></span><?= e(__('ملف في فولدر الاستيراد')) ?></div>
  <div class="stat green"><span><?= count($plan['docs']) + count($plan['atts']) ?></span><?= e(__('ملف اتعرف على سجله')) ?></div>
  <div class="stat<?= $plan['ambiguous'] ? ' warn' : '' ?>"><span><?= (int)$plan['ambiguous'] ?></span><?= e(__('ليه أكتر من ملف (اتساب)')) ?></div>
  <div class="stat"><span><?= (int)$plan['missing'] ?></span><?= e(__('ملفه مش موجود')) ?></div>
</div>
<div class="grid">
  <form method="post" action="<?= e(url('drive_import')) ?>" class="card" enctype="multipart/form-data">
    <h2><?= e(__('رفع ZIP')) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="op" value="zip">
    <label><?= e(__('ملف ZIP من Drive')) ?> <input type="file" name="file" accept=".zip,application/zip" required></label>
    <button class="btn"><?= e(__('رفع')) ?></button>
    <p class="muted"><?= e(__('لو الملف كبير على الرفع، حط الملفات بنفسك في: %s', $dir)) ?></p>
  </form>
  <form method="post" action="<?= e(url('drive_import')) ?>" class="card" data-confirm="<?= e(__('الملفات اللي اتعرف عليها هتتنسخ وتتربط بسجلاتها. متأكد؟')) ?>">
    <h2><?= e(__('ربط الملفات')) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="op" value="apply">
    <p class="muted"><?= e(__('المستندات بتتعرف من اسم الملف القديم (الكود_الاسم_النوع_التاريخ)، ومرفقات التحقيقات من اسم الملف المحفوظ.')) ?></p>
    <button class="btn primary"<?= count($plan['docs']) + count($plan['atts']) ? '' : ' disabled' ?>><?= e(__('ربط %d ملف', count($plan['docs']) + count($plan['atts']))) ?></button>
  </form>
</div>
