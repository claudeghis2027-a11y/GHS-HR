<div class="page-head">
  <div><h1><?= e($n['title']) ?></h1><div class="sub"><span class="badge blue"><?= e(__($n['ntype'])) ?></span> <span class="num"><?= e(substr((string)$n['show_at'], 0, 16)) ?></span>
    <?php if ($n['report_from'] && $n['report_to']): ?> · <?= e(__('الفترة من %s إلى %s', $n['report_from'], $n['report_to'])) ?><?php endif; ?></div></div>
  <div class="actions no-print"><a class="btn small ghost" href="<?= e(url('inbox')) ?>"><?= e(__('رجوع للإشعارات')) ?></a></div>
</div>
<?php if ($showBody && trim((string)$n['body']) !== ''): ?><div class="card"><p class="pre"><?= e($n['body']) ?></p></div><?php endif; ?>
<?php if (!empty($geoBlocked)): ?>
  <?php require __DIR__ . '/_geo_blocked.php'; ?>
<?php else:
  $t = isset($sec['att']) ? $sec['att']['totals'] : null;
  $days = isset($sec['att']) ? $sec['att']['days'] : []; ?>

<?php if (isset($sec['profile'])): $pe = $sec['profile']['emp']; ?>
<div class="card">
  <h2><?= e(__('البيانات الشخصية')) ?></h2>
  <dl class="profile-grid">
    <div><dt><?= e(__('كود الموظف')) ?></dt><dd class="num"><?= (int)$pe['id'] ?></dd></div>
    <?php foreach ($sec['profile']['fields'] as $f): $k = $f['field']; $val = $pe[$k] ?? '';
      if ($k === 'department_id') $val = $val ? (fk_options(['fk' => ['departments', 'name']])[$val] ?? '') : '';
      if ($k === 'job_title_id') $val = $val ? (fk_options(['fk' => ['job_titles', 'name']])[$val] ?? '') : '';
      if ($k === 'specialization_id') $val = $val ? (fk_options(['fk' => ['specializations', 'name']])[$val] ?? '') : ''; ?>
      <div><dt><?= e(__($f['label'])) ?></dt><dd><?= e((string)$val) ?: '—' ?></dd></div>
    <?php endforeach; ?>
  </dl>
</div>
<?php endif; ?>

<?php if (isset($sec['documents'])): $docs = $sec['documents'];
  $missing = array_filter($docs, fn($d) => $d['status'] === 'ناقص');
  $have = array_filter($docs, fn($d) => $d['doc'] !== null); ?>
<div class="card">
  <h2><?= e(__('المستندات')) ?></h2>
  <h3><?= e(__('ناقصة (%d)', count($missing))) ?></h3>
  <?php if ($missing): ?><p><?php foreach ($missing as $d): ?><span class="badge red"><?= e($d['type']['name']) ?></span> <?php endforeach; ?></p>
  <?php else: ?><p class="muted"><?= e(__('مفيش مستندات مطلوبة ناقصة.')) ?></p><?php endif; ?>
  <h3><?= e(__('مكتملة (%d)', count($have))) ?></h3>
  <?php if ($have): ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('النوع')) ?></th><th><?= e(__('رقم المستند')) ?></th><th><?= e(__('تاريخ الانتهاء')) ?></th><th><?= e(__('الحالة')) ?></th></tr></thead>
    <tbody><?php foreach ($have as $d): ?>
      <tr><td><?= e($d['type']['name']) ?></td><td class="num"><?= e((string)$d['doc']['doc_number']) ?></td><td class="num"><?= e((string)$d['doc']['expiry_date']) ?></td>
        <td><span class="badge <?= $d['status'] === 'منتهي' ? 'red' : ($d['status'] === 'قريب الانتهاء' ? '' : 'green') ?>"><?= e(__($d['status'])) ?></span></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php else: ?><p class="muted"><?= e(__('مفيش مستندات مسجلة.')) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($t && in_array('absence', $keys, true)): $abs = array_filter($days, fn($d) => $d['status'] === 'absent'); ?>
<div class="card">
  <h2><?= e(__('الغياب')) ?></h2>
  <div class="stats"><div class="stat"><span><?= (int)$t['absent'] ?></span><?= e(__('أيام الغياب')) ?></div><div class="stat warn"><span><?= e(num($t['absent_days'])) ?></span><?= e(__('خصم الغياب (يوم)')) ?></div></div>
  <?php if ($abs): ?><p><?php foreach ($abs as $d): ?><span class="badge num"><?= e($d['date']) ?> · <?= e(day_name($d['dow'])) ?></span> <?php endforeach; ?></p>
  <?php else: ?><p class="muted"><?= e(__('مفيش غياب في الفترة دي.')) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($t && in_array('late', $keys, true)): $late = array_filter($days, fn($d) => $d['late'] > 0); ?>
<div class="card">
  <h2><?= e(__('التأخير')) ?></h2>
  <div class="stats">
    <div class="stat"><span><?= (int)$t['late_count'] ?></span><?= e(__('مرات التأخير')) ?></div>
    <div class="stat"><span><?= (int)$t['late_min'] ?></span><?= e(__('دقيقة تأخير')) ?></div>
    <div class="stat"><span><?= (int)$t['late_waiver_min'] ?></span><?= e(__('إعفاء (د)')) ?></div>
    <div class="stat"><span><?= (int)$t['late_min_final'] ?></span><?= e(__('تأخير نهائي (د)')) ?></div>
    <div class="stat warn"><span><?= e(num($t['late_days'])) ?></span><?= e(__('خصم التأخير (يوم)')) ?></div>
  </div>
  <?php if ($late): ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('التاريخ')) ?></th><th><?= e(__('اليوم')) ?></th><th><?= e(__('وقت الحضور')) ?></th><th><?= e(__('حضور رسمي')) ?></th><th><?= e(__('تأخير (د)')) ?></th><th><?= e(__('إعفاء (د)')) ?></th></tr></thead>
    <tbody><?php foreach ($late as $d): ?>
      <tr><td class="num"><?= e($d['date']) ?></td><td><?= e(day_name($d['dow'])) ?></td><td class="num"><?= e($d['in'] ? substr($d['in'], 11, 5) : '') ?></td>
        <td class="num muted"><?= e((string)$d['sched_in']) ?></td><td class="num"><?= (int)$d['late'] ?></td><td class="num"><?= $d['waiver'] ?: '' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php else: ?><p class="muted"><?= e(__('مفيش تأخير في الفترة دي.')) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($t && in_array('deductions', $keys, true)): ?>
<div class="card">
  <h2><?= e(__('الخصومات')) ?></h2>
  <div class="table-wrap"><table>
    <thead><tr><th><?= e(__('البند')) ?></th><th><?= e(__('خصم (يوم)')) ?></th></tr></thead>
    <tbody>
      <?php foreach (['late_days' => 'التأخير', 'early_days' => 'الانصراف المبكر', 'absent_days' => 'الغياب', 'missing_out_days' => 'بدون انصراف', 'perm_days' => 'الأذونات', 'forgot_days' => 'نسيان البصمة', 'extra_days' => 'الجزاءات'] as $k => $lbl): ?>
      <tr><td class="name"><?= e(__($lbl)) ?></td><td class="num"><?= e(num($t[$k])) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th><?= e(__('إجمالي أيام الخصم')) ?></th><th class="num"><?= e(num($t['total_days'])) ?></th></tr></tfoot>
  </table></div>
</div>
<?php endif; ?>

<?php if ($report): [$view, $rv] = $report; (function (string $__v, array $__vars) { extract($__vars); require __DIR__ . '/' . $__v . '.php'; })($view, $rv); ?>
<?php elseif ($n['ntype'] !== 'عام' && !$sec): ?><div class="card muted"><?= e(__('التقرير ده مش متاح دلوقتي.')) ?></div><?php endif; ?>
<?php endif; ?>
