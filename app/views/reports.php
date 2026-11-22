<div class="page-head"><div><h1><?= e(__('مركز التقارير')) ?></h1><div class="sub"><?= e(__('كل تقرير بيظهر حسب صلاحيتك، وتقدر تعرضه أو تنزّله Excel أو تطبعه / تحفظه PDF')) ?></div></div></div>
<div class="grid">
<?php foreach ($cat as $k => [$name, $desc]): ?>
  <a class="card report-card" href="<?= e(url('report', ['rep' => $k])) ?>"><h3><?= e(__($name)) ?></h3><p class="muted"><?= e(__($desc)) ?></p></a>
<?php endforeach; ?>
</div>
