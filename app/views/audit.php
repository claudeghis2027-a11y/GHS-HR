<div class="page-head"><div><h1><?= e(__('سجل التدقيق')) ?></h1><div class="sub"><?= e(__('%d سجل', (int)$total)) ?></div></div></div>
<form method="get" class="filters">
  <input type="hidden" name="r" value="audit">
  <input name="action" placeholder="<?= e(__('الإجراء (مثلا auth.)')) ?>" value="<?= e($_GET['action'] ?? '') ?>" dir="ltr">
  <input name="user" placeholder="<?= e(__('المستخدم')) ?>" value="<?= e($_GET['user'] ?? '') ?>" dir="ltr">
  <label class="inline"><?= e(__('من')) ?> <input type="date" name="from" value="<?= e($_GET['from'] ?? '') ?>"></label>
  <label class="inline"><?= e(__('إلى')) ?> <input type="date" name="to" value="<?= e($_GET['to'] ?? '') ?>"></label>
  <button class="btn small"><?= e(__('عرض')) ?></button>
</form>
<div class="table-wrap">
<table class="small">
  <thead><tr><th><?= e(__('الوقت')) ?></th><th><?= e(__('المستخدم')) ?></th><th><?= e(__('الإجراء')) ?></th><th><?= e(__('الهدف')) ?></th><th><?= e(__('التفاصيل')) ?></th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="num"><?= e($r['created_at']) ?></td><td><?= e($r['username']) ?></td><td dir="ltr"><?= e($r['action']) ?></td><td><?= e($r['target']) ?></td>
      <td class="details"><?= e(mb_strimwidth((string)$r['details'], 0, 300, '…')) ?></td><td dir="ltr"><?= e($r['ip']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php $pages = (int)ceil($total / 100); if ($pages > 1): ?>
<nav class="pager"><?php for ($i = 1; $i <= min($pages, 200); $i++): ?><a class="<?= $i === $page ? 'on' : '' ?>" href="<?= e(url('audit', array_filter(['action' => $_GET['action'] ?? null, 'user' => $_GET['user'] ?? null, 'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null], 'is_string') + ['page' => $i])) ?>"><?= $i ?></a><?php endfor; ?></nav>
<?php endif; ?>
