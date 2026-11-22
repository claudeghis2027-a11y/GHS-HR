<div class="page-head"><div><h1><?= e(__('رسائل واتساب')) ?></h1><div class="sub"><?= e(__('السيرفر مابيبعتش أي رسالة. بتعاين الرسالة، وزرار «افتح واتساب» بيفتحها من جهازك، وبعد ما تبعتها تعلّم «اتبعتت».')) ?></div></div></div>
<form method="get" class="card filters">
  <input type="hidden" name="r" value="whatsapp"><input type="hidden" name="go" value="1">
  <label><?= e(__('نوع الرسالة')) ?> <select name="type"><?php foreach (WA_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $type ? ' selected' : '' ?>><?= e(__($l)) ?></option><?php endforeach; ?></select></label>
  <label><?= e(__('الشهر (لكشف المرتب)')) ?> <input type="month" name="m" value="<?= e($f['m']) ?>"></label>
  <label><?= e(__('من')) ?> <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label><?= e(__('إلى')) ?> <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <label><?= e(__('الموظفين')) ?> <select name="emps[]" multiple size="5"><?php foreach ($emps as $k => $v): ?><option value="<?= (int)$k ?>"<?= in_array((int)$k, $sel, true) ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
  <label><?= e(__('أو كل موظفي الأقسام')) ?> <select name="dept[]" multiple size="5"><?php foreach ($depts as $k => $v): ?><option value="<?= (int)$k ?>"<?= in_array((int)$k, $f['dept'], true) ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
  <button class="btn primary"><?= e(__('معاينة')) ?></button>
</form>
<?php if (isset($_GET['go']) && !$messages): ?><div class="card muted"><?= e(__('اختار موظف أو قسم.')) ?></div><?php endif; ?>
<?php foreach ($messages as $m): ?>
  <div class="card">
    <div class="head-row"><h3><?= (int)$m['emp']['id'] ?> - <?= e($m['emp']['name_ar']) ?></h3><span class="num" dir="ltr"><?= e($m['phone'] ? '+' . $m['phone'] : '') ?></span></div>
    <?php if ($m['text'] !== null): ?><textarea class="wa-text" rows="8" data-wa-text aria-label="<?= e(__('نص الرسالة')) ?>"><?= e($m['text']) ?></textarea><?php endif; ?>
    <?php if ($m['error']): ?><p class="err-text"><?= e($m['error']) ?></p><?php endif; ?>
    <?php if ($m['text'] !== null && $m['phone']): ?>
      <a class="btn" href="https://wa.me/<?= e($m['phone']) ?>?text=<?= e(rawurlencode($m['text'])) ?>" data-wa-open data-phone="<?= e($m['phone']) ?>" target="_blank" rel="noopener noreferrer"><?= e(__('افتح واتساب')) ?></a>
      <button type="button" class="btn" data-wa-copy data-done="<?= e(__('اتنسخت ✓')) ?>"><?= e(__('نسخ')) ?></button>
      <?php if (can('whatsapp', 'create')): ?>
      <button type="button" class="btn<?= $m['sent'] ? ' ghost' : '' ?>" data-wa-log data-url="<?= e(url('whatsapp')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-emp="<?= (int)$m['emp']['id'] ?>" data-type="<?= e($type) ?>" data-period="<?= e($m['period']) ?>"<?= $m['sent'] ? ' disabled' : '' ?>><?= e(__($m['sent'] ? 'اتبعتت قبل كده ✓' : 'اتبعتت')) ?></button>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
