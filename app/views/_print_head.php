<?php
// Shared print letterhead (one design for every printed page and report). Needs $phTitle; optional $phChips (list of strings).
$phChips = array_values(array_filter($phChips ?? [], fn($c) => trim((string)$c) !== ''));
?>
<header class="ph">
  <div class="ph-brand">
    <img src="<?= e(asset('img/logo-480.png')) ?>" alt="" width="80" height="40">
    <div><strong>Green Heights International School</strong><span><?= e(__('مدارس جرين هايتس')) ?> · <?= e(__('نظام شئون العاملين')) ?></span></div>
  </div>
  <div class="ph-meta"><?= e(__('تاريخ الاستخراج')) ?>: <b class="num"><?= e(date('Y-m-d H:i')) ?></b><?php if ($phUser = $GLOBALS['USER']['username'] ?? null): ?><br><?= e(__('بواسطة')) ?>: <b><?= e((string)$phUser) ?></b><?php endif; ?></div>
</header>
<h1 class="ph-title"><?= e((string)$phTitle) ?></h1>
<div class="ph-chips" data-print-chips><?php foreach ($phChips as $c): ?><span><?= e((string)$c) ?></span><?php endforeach; ?></div>
