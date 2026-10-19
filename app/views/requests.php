<?php $labels = ['name_ar' => 'الاسم بالعربي', 'name_en' => 'الاسم بالإنجليزي', 'phone' => 'رقم الهاتف', 'nationality' => 'الجنسية']; ?>
<div class="page-head"><div><h1><?= e(__('طلبات تعديل البيانات')) ?></h1></div></div>
<div class="table-wrap">
<table>
  <thead><tr><th>#</th><th><?= e(__('الموظف')) ?></th><th><?= e(__('التعديلات المطلوبة')) ?></th><th><?= e(__('تاريخ الطلب')) ?></th><th><?= e(__('الحالة')) ?></th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted center"><?= e(__('لا توجد طلبات.')) ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $f = json_decode($r['fields_json'], true) ?: []; ?>
    <tr>
      <td class="num"><?= (int)$r['id'] ?></td><td><?= (int)$r['employee_id'] ?> - <?= e($r['name_ar']) ?></td>
      <td><?php foreach ($f as $k => $v): ?><div><?= e(__($labels[$k] ?? $k)) ?>: <del><?= e($r[$k] ?? '') ?></del> ← <strong><?= e($v) ?></strong></div><?php endforeach; ?></td>
      <td class="num"><?= e(substr($r['submitted_at'], 0, 16)) ?></td>
      <td><?php $st = ['pending' => ['تحت المراجعة', 'ochre'], 'approved' => ['معتمد', 'green'], 'rejected' => ['مرفوض', 'red']][$r['status']]; ?><span class="badge <?= $st[1] ?>"><?= e(__($st[0])) ?></span><?= $r['reviewer'] ? ' <small class="muted">' . e($r['reviewer']) . '</small>' : '' ?></td>
      <td>
        <?php if ($r['status'] === 'pending'): ?>
        <form method="post" action="<?= e(url('requests')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="approve"><button class="btn small primary"><?= e(__('اعتماد')) ?></button></form>
        <form method="post" action="<?= e(url('requests')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="reject"><input name="reason" placeholder="<?= e(__('سبب الرفض')) ?>" maxlength="500"><button class="btn small danger"><?= e(__('رفض')) ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
