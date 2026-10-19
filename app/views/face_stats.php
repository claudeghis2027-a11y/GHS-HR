<?php
$rowP = fn(array $v) => $v ? implode(' · ', [__('أقل') . ' ' . pct($v, 0), '5% ' . pct($v, 5), __('الوسيط') . ' ' . pct($v, 50), '95% ' . pct($v, 95), __('أعلى') . ' ' . pct($v, 100)]) . ' (' . count($v) . ')' : '—';
$labels = ['match' => 'مطابق', 'no_match' => 'مش مطابق', 'ok' => 'صورة مرجعية اتقبلت', 'no_turn' => 'لف الراس مش كفاية', 'no_motion' => 'مفيش حركة', 'inconsistent' => 'الوش اتغير',
           'no_face' => 'مفيش وش', 'multi_face' => 'أكتر من وش', 'too_small' => 'الوش صغير', 'too_dark' => 'ضلمة', 'too_bright' => 'نور زيادة', 'blurry' => 'مهزوزة', 'not_frontal' => 'مش باصص قدام', 'few_frames' => 'لقطات ناقصة', 'bad_image' => 'صورة تالفة'];
?>
<div class="page-head">
  <div><h1><?= e(__('إحصائيات بصمة الوجه')) ?></h1><div class="sub"><?= e(__('%d محاولة', (int)$total)) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="face_stats">
    <label class="inline"><?= e(__('من')) ?> <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="inline"><?= e(__('إلى')) ?> <input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn small"><?= e(__('عرض')) ?></button>
    <a class="btn small" href="<?= e(url('face_stats', ['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>">CSV</a>
  </form>
</div>
<div class="stats">
  <div class="stat"><span><?= e(setting('face_match_threshold')) ?></span><?= e(__('حد القبول')) ?></div>
  <div class="stat ochre"><span><?= e(setting('face_min_yaw_range')) ?></span><?= e(__('أقل حركة راس')) ?></div>
  <div class="stat"><span><?= e(setting('face_max_failures')) ?></span><?= e(__('مرات الفشل قبل HR')) ?></div>
</div>
<div class="grid2">
  <div class="card">
    <h2><?= e(__('النتايج')) ?></h2>
    <table><tbody><?php arsort($by); foreach ($by as $k => $n): ?><tr><td><?= e(__($labels[$k] ?? $k)) ?></td><td class="num"><?= (int)$n ?></td></tr><?php endforeach; ?></tbody></table>
  </div>
  <div class="card">
    <h2><?= e(__('حسب الجهاز')) ?></h2>
    <table><thead><tr><th><?= e(__('الجهاز')) ?></th><th><?= e(__('نجح')) ?></th><th><?= e(__('فشل')) ?></th></tr></thead><tbody><?php foreach ($dev as $d => $c): ?><tr><td dir="ltr"><?= e($d) ?></td><td class="num"><?= (int)($c['ok'] ?? 0) ?></td><td class="num"><?= (int)($c['fail'] ?? 0) ?></td></tr><?php endforeach; ?></tbody></table>
  </div>
</div>
<div class="card">
  <h2><?= e(__('التوزيعات (عشان تظبط الحدود)')) ?></h2>
  <table><tbody>
    <tr><th><?= e(__('درجة التطابق — نفس الشخص (مطابق)')) ?></th><td class="num"><?= e($rowP($scores['match'])) ?></td></tr>
    <tr><th><?= e(__('درجة التطابق — اترفض (مش مطابق)')) ?></th><td class="num"><?= e($rowP($scores['no_match'])) ?></td></tr>
    <tr><th><?= e(__('حركة الراس — محاولات عدّت اختبار الحركة')) ?></th><td class="num"><?= e($rowP($yaw['pass'])) ?></td></tr>
    <tr><th><?= e(__('حركة الراس — اترفضت "لف الراس مش كفاية"')) ?></th><td class="num"><?= e($rowP($yaw['no_turn'])) ?></td></tr>
    <tr><th><?= e(__('وقت المقارنة على السيرفر (ms)')) ?></th><td class="num"><?= e($rowP($ms)) ?></td></tr>
  </tbody></table>
</div>
