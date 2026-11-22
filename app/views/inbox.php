<div class="page-head"><div><h1><?= e(__('الإشعارات')) ?></h1><?php if ($unread): ?><div class="sub"><?= e(__('%d غير مقروء', $unread)) ?></div><?php endif; ?></div></div>
<div class="filters seg no-print">
  <?php foreach (['all' => 'الكل', 'unread' => 'غير المقروء', 'read' => 'المقروء'] as $k => $lbl): ?>
    <a class="btn small <?= $f === $k ? 'primary' : 'ghost' ?>" href="<?= e(url('inbox', $k === 'all' ? [] : ['f' => $k])) ?>"<?= $f === $k ? ' aria-current="page"' : '' ?>><?= e(__($lbl)) ?></a>
  <?php endforeach; ?>
</div>
<?php if (!$rows): ?><div class="card center muted"><?= e(__('مفيش إشعارات.')) ?></div><?php endif; ?>
<?php foreach ($rows as $n):
  $keys = array_filter(explode(',', (string)$n['contents']));
  $showBody = !$keys || in_array('message', $keys, true);
  $hasData = array_diff($keys, ['message']) || ($n['ntype'] === 'كشف مرتب' && $n['period_month']) || ($n['ntype'] === 'تقرير حضور' && $n['report_from']); ?>
  <div class="card <?= $n['read_at'] ? 'read' : 'unread' ?>">
    <div class="head-row"><h3><a href="<?= e(url('notif_view', ['id' => $n['id']])) ?>"><?= e($n['title']) ?></a></h3>
      <span><?php if (!$n['read_at']): ?><span class="badge red"><?= e(__('جديد')) ?></span> <?php endif; ?><span class="badge blue"><?= e(__($n['ntype'])) ?></span>
        <small class="muted" title="<?= e(substr($n['show_at'], 0, 16)) ?>"><?= e(rel_time($n['show_at'])) ?></small></span></div>
    <?php if ($showBody): ?><p class="pre"><?= e($n['body']) ?></p><?php endif; ?>
    <?php if ($hasData): ?>
      <p><?php if ($n['report_from']): ?><?= e(__('الفترة: %s إلى %s', $n['report_from'], $n['report_to'])) ?><?php elseif ($n['period_month']): ?><?= e(__('الشهر: %s', substr($n['period_month'], 0, 7))) ?><?php endif; ?>
        <a class="btn small primary" href="<?= e(url('notif_view', ['id' => $n['id']])) ?>"><?= e(__('📄 عرض التقرير')) ?></a></p>
    <?php endif; ?>
    <?php if (!$n['read_at']): ?>
      <form method="post" action="<?= e(url('inbox')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><button class="btn small"><?= e(__('تمت القراءة')) ?></button></form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
