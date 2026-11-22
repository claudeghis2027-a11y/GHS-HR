<?php $ST = ['active' => 'سارية', 'paid' => 'مسددة', 'cancelled' => 'ملغاة']; ?>
<div class="page-head">
  <div><h1><?= e(__('تقرير السلف')) ?></h1><div class="sub"><?= e(__('المحصّل = الأقساط اللي ميعادها جه لحد النهارده')) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="advances_report">
    <label><?= e(__('من')) ?> <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label><?= e(__('لحد')) ?> <input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn small"><?= e(__('عرض')) ?></button>
    <?php if (can('salary', 'export')): ?><?= export_links('advances_report', ['from' => $from, 'to' => $to]) ?><?php endif; ?>
    <?php if (can('salary', 'create')): ?><a class="btn small primary" href="<?= e(url('form', ['res' => 'advances'])) ?>">+ <?= e(__('سلفة جديدة')) ?></a><?php endif; ?>
  </form>
</div>
<div class="stats">
  <div class="stat"><span><?= e(money($tot['total'])) ?></span><?= e(__('إجمالي السلف السارية')) ?></div>
  <div class="stat green"><span><?= e(money($tot['collected'])) ?></span><?= e(__('المحصّل')) ?></div>
  <div class="stat warn"><span><?= e(money($tot['remaining'])) ?></span><?= e(__('المتبقي')) ?></div>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('تاريخ السلفة')) ?></th><th><?= e(__('الإجمالي')) ?></th><th><?= e(__('الأقساط')) ?></th><th><?= e(__('بداية الخصم')) ?></th><th><?= e(__('المحصّل')) ?></th><th><?= e(__('المتبقي')) ?></th><th><?= e(__('الحالة')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="num"><?= (int)$r['employee_id'] ?></td><td><?= e($r['name_ar']) ?></td><td><?= e($r['dept_name']) ?></td><td class="num"><?= e($r['advance_date']) ?></td><td class="num"><?= e(money($r['total'])) ?></td>
      <td class="num" title="<?= e(implode(' · ', array_map(fn($i) => $i['due'] . ': ' . money($i['value']), $r['schedule']))) ?>"><?= (int)$r['collected_count'] ?> / <?= (int)$r['installments'] ?></td>
      <td class="num"><?= e($r['deduct_from']) ?></td><td class="num"><?= e(money($r['collected'])) ?></td><td class="num"><?= e(money($r['remaining'])) ?></td>
      <td><?= e(__($ST[$r['status']] ?? $r['status'])) ?><?= $r['cancel_reason'] ? '<br><small class="muted">' . e($r['cancel_reason']) . '</small>' : '' ?></td>
      <td><?php if ($r['status'] !== 'cancelled' && can('salary', 'update')): ?>
        <form method="post" action="<?= e(url('advances_report')) ?>" class="inline" data-confirm="<?= e(__('إلغاء السلفة هيوقف الأقساط اللي جاية. متأكد؟')) ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input name="reason" maxlength="500" required placeholder="<?= e(__('سبب الإلغاء')) ?>" class="input-narrow"> <button class="btn small"><?= e(__('إلغاء')) ?></button>
        </form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="11" class="muted"><?= e(__('مفيش سلف.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
