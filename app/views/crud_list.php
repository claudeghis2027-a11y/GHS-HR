<?php
$key = $res['key'];
$canEdit = !$selfOnly && empty($res['readonly']) && can($res['module'], 'update');
$canCreate = !$selfOnly && empty($res['readonly']) && can($res['module'], 'create');
$canDelete = !$selfOnly && empty($res['readonly']) && can($res['module'], 'delete');
$route = $selfOnly ? 'my' : 'list';
$qs = array_filter(['res' => $key, 'q' => $_GET['q'] ?? null, 'emp' => $_GET['emp'] ?? null, 'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null,
    'current' => $_GET['current'] ?? null, 'present' => $_GET['present'] ?? null], fn($v) => is_string($v) && $v !== '');
?>
<div class="page-head">
  <div><h1><?= e(__($res['title'])) ?></h1><div class="sub"><?= e(__('%d سجل', (int)$total)) ?></div></div>
  <div>
    <?php if ($canCreate): ?><a class="btn primary" href="<?= e(url('form', ['res' => $key] + (isset($qs['emp']) ? ['emp' => $qs['emp']] : []))) ?>">+ <?= e(__('إضافة')) ?></a><?php endif; ?>
    <?php if (!$selfOnly && can($res['module'], 'export')): ?><a class="btn" href="<?= e(url('list', $qs + ['export' => 'csv'])) ?>"><?= e(__('تصدير CSV')) ?></a><?php endif; ?>
  </div>
</div>

<?php if (!$selfOnly): ?>
<form method="get" class="filters">
  <input type="hidden" name="r" value="list"><input type="hidden" name="res" value="<?= e($key) ?>">
  <?php if (!empty($res['search'])): ?><input name="q" placeholder="<?= e(__('بحث')) ?>" value="<?= e($_GET['q'] ?? '') ?>"><?php endif; ?>
  <?php if (!empty($res['emp']) && $res['emp'] !== 'id'): ?><input name="emp" placeholder="<?= e(__('كود الموظف')) ?>" inputmode="numeric" value="<?= e($_GET['emp'] ?? '') ?>"><?php endif; ?>
  <?php if (!empty($res['date_filter'])): ?>
    <label class="inline"><?= e(__('من')) ?> <input type="date" name="from" value="<?= e($_GET['from'] ?? '') ?>"></label>
    <label class="inline"><?= e(__('إلى')) ?> <input type="date" name="to" value="<?= e($_GET['to'] ?? '') ?>"></label>
  <?php endif; ?>
  <?php if (!empty($qs['current'])): ?><input type="hidden" name="current" value="1"><span class="badge blue"><?= e(__('الموظفين الحاليين بس')) ?></span><?php endif; ?>
  <?php if (!empty($qs['present'])): ?><input type="hidden" name="present" value="1"><span class="badge green"><?= e(__('اللي سجّلوا حضور بس')) ?></span><?php endif; ?>
  <button class="btn small"><?= e(__('عرض')) ?></button>
  <?php if (!empty($qs['current']) || !empty($qs['present'])): ?><a class="btn small ghost" href="<?= e(url('list', ['res' => $key])) ?>"><?= e(__('عرض الكل')) ?></a><?php endif; ?>
</form>
<?php endif; ?>

<div class="table-wrap">
<table>
  <thead><tr>
    <?php foreach ($res['list'] as $c): ?><th><?= e($c === 'id' ? '#' : __($res['fields'][$c]['label'])) ?></th><?php endforeach; ?>
    <?php if ($key === 'attendance'): ?><th><?= e(__('صور')) ?></th><?php endif; ?>
    <?php if ($canEdit || $canDelete || $key === 'employees'): ?><th></th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="20" class="muted center"><?= e(__('لا توجد بيانات.')) ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <tr>
      <?php foreach ($res['list'] as $c): ?>
        <td><?= e($c === 'id' ? $row['id'] : display_value($res['fields'][$c], $row[$c] ?? null, $row)) ?></td>
      <?php endforeach; ?>
      <?php if ($key === 'attendance'): ?><td>
        <?php if ($row['check_in_photo']): ?><a href="<?= e(url('photo', ['id' => $row['id'], 'kind' => 'in'])) ?>" target="_blank" rel="noopener"><?= e(__('وقت الحضور')) ?></a><?php endif; ?>
        <?php if ($row['check_out_photo']): ?><a href="<?= e(url('photo', ['id' => $row['id'], 'kind' => 'out'])) ?>" target="_blank" rel="noopener"><?= e(__('وقت الانصراف')) ?></a><?php endif; ?>
      </td><?php endif; ?>
      <?php if ($canEdit || $canDelete || $key === 'employees'): ?><td class="actions">
        <?php if ($key === 'employees'): ?>
          <?php if (can('attendance')): ?><a href="<?= e(url('att_employee', ['emp' => $row['id']])) ?>"><?= e(__('الحضور')) ?></a><?php endif; ?>
          <?php if (can('salary')): ?><a href="<?= e(url('payroll', ['emp' => $row['id']])) ?>"><?= e(__('المرتب')) ?></a><?php endif; ?>
          <?php if (can('documents')): ?><a href="<?= e(url('list', ['res' => 'employee_documents', 'emp' => $row['id']])) ?>"><?= e(__('المستندات')) ?></a><?php endif; ?>
        <?php endif; ?>
        <?php if ($canEdit): ?><a href="<?= e(url('form', ['res' => $key, 'id' => $row[$res['pk']]])) ?>"><?= e(__('تعديل')) ?></a><?php endif; ?>
        <?php if ($canDelete): ?>
          <form method="post" action="<?= e(url('delete', ['res' => $key])) ?>" class="inline" data-confirm="<?= e(__('متأكد من الحذف؟')) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row[$res['pk']] ?>"><button class="link danger"><?= e(__('حذف')) ?></button>
          </form>
        <?php endif; ?>
      </td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php $pages = (int)ceil($total / $per); if ($pages > 1): ?>
<nav class="pager" aria-label="<?= e(__('الصفحات')) ?>">
  <?php for ($i = 1; $i <= $pages && $i <= 200; $i++): ?>
    <a class="<?= $i === $page ? 'on' : '' ?>" href="<?= e(url($route, $qs + ['page' => $i])) ?>"><?= $i ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>
