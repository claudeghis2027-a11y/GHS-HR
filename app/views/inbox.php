<div class="page-head"><div><h1><?= e(__('الإشعارات')) ?></h1></div></div>
<?php if (!$rows): ?><div class="card center muted"><?= e(__('مفيش إشعارات.')) ?></div><?php endif; ?>
<?php foreach ($rows as $n): ?>
  <div class="card <?= $n['read_at'] ? 'read' : 'unread' ?>">
    <div class="head-row"><h3><?= e($n['title']) ?></h3><span><span class="badge blue"><?= e(__($n['ntype'])) ?></span> <small class="muted num"><?= e(substr($n['show_at'], 0, 16)) ?></small></span></div>
    <p class="pre"><?= e($n['body']) ?></p>
    <?php if (!$n['read_at']): ?>
      <form method="post" action="<?= e(url('inbox')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn small"><?= e(__('تمت القراءة')) ?></button></form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
