<?php
$badge = ['ساري' => 'green', 'قريب الانتهاء' => 'ochre', 'منتهي' => 'red', 'ناقص' => 'red', 'غير مطلوب' => 'blue'];
$copy = ['original' => 'أصل', 'copy' => 'صورة'];
$q = ['tab' => $tab, 'dept' => $f['dept'], 'job' => $f['job'], 'type' => $f['type'], 'emp' => $f['emp'], 'ended' => $f['ended'] ? 1 : null];
?>
<div class="page-head">
  <div><h1><?= e(__('تقارير المستندات')) ?></h1><div class="sub"><?= e(__(DOC_REPORTS[$tab])) ?></div></div>
  <a class="btn" href="<?= e(url('list', ['res' => 'employee_documents'])) ?>"><?= e(__('مستندات الموظفين')) ?></a>
</div>
<div class="filters">
  <?php foreach (DOC_REPORTS as $k => $label): ?><a class="btn small<?= $k === $tab ? ' primary' : '' ?>" href="<?= e(url('doc_reports', ['tab' => $k] + array_diff_key($q, ['tab' => 1]))) ?>"><?= e(__($label)) ?></a><?php endforeach; ?>
</div>
<form method="get" class="filters card">
  <input type="hidden" name="r" value="doc_reports"><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <?php if ($tab === 'employee'): ?>
    <select name="emp" required><option value=""><?= e(__('اختار الموظف')) ?></option><?php foreach ($emps as $k => $v): ?><option value="<?= (int)$k ?>"<?= $f['emp'] === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
  <?php else: ?>
    <?php if ($tab === 'type'): ?><select name="type" required><option value=""><?= e(__('اختار نوع المستند')) ?></option><?php foreach ($types as $k => $v): ?><option value="<?= (int)$k ?>"<?= $f['type'] === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select><?php endif; ?>
    <select name="dept"><option value=""><?= e(__('كل الأقسام')) ?></option><?php foreach ($depts as $k => $v): ?><option value="<?= (int)$k ?>"<?= $f['dept'] === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <select name="job"><option value=""><?= e(__('كل المسميات')) ?></option><?php foreach ($jobs as $k => $v): ?><option value="<?= (int)$k ?>"<?= $f['job'] === (int)$k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <label class="check"><input type="checkbox" name="ended" value="1"<?= $f['ended'] ? ' checked' : '' ?>> <?= e(__('يشمل اللي سابوا الشغل')) ?></label>
  <?php endif; ?>
  <button class="btn small"><?= e(__('عرض')) ?></button>
  <?php if (can('docs_reports', 'export') && $rows): ?><?= export_links('doc_reports', $q) ?><?php endif; ?>
</form>
<div class="stats"><div class="stat"><span><?= count($rows) ?></span><?= e(__($tab === 'missing' ? 'موظف' : 'صف')) ?></div></div>
<div class="table-wrap">
<table>
<?php if ($tab === 'missing'): ?>
  <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('المسمى')) ?></th><th><?= e(__('عدد الناقص')) ?></th><th><?= e(__('المستندات الناقصة')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="num"><?= (int)$r['emp']['id'] ?></td><td><a href="<?= e(url('doc_reports', ['tab' => 'employee', 'emp' => $r['emp']['id']])) ?>"><?= e($r['emp']['name_ar']) ?></a></td><td><?= e($r['emp']['dept_name']) ?></td><td><?= e($r['emp']['job_name']) ?></td>
      <td class="num"><?= count($r['types']) ?></td><td><?= e(implode('، ', $r['types'])) ?></td></tr>
  <?php endforeach; ?>
<?php else: ?>
  <thead><tr><?php if ($tab !== 'employee'): ?><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><?php endif; ?><th><?= e(__('نوع المستند')) ?></th><th><?= e(__('الحالة')) ?></th><th><?= e(__('رقم المستند')) ?></th><th><?= e(__('أصل أم صورة')) ?></th><th><?= e(__('تاريخ الانتهاء')) ?></th><th><?= e(__('الأيام الباقية')) ?></th><th><?= e(__('ملف')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $d = $r['doc']; ?>
    <tr><?php if ($tab !== 'employee'): ?><td class="num"><?= (int)$r['emp']['id'] ?></td><td><a href="<?= e(url('doc_reports', ['tab' => 'employee', 'emp' => $r['emp']['id']])) ?>"><?= e($r['emp']['name_ar']) ?></a></td><td><?= e($r['emp']['dept_name']) ?></td><?php endif; ?>
      <td><?= e($r['type']['name']) ?></td><td><span class="badge <?= $badge[$r['status']] ?? '' ?>"><?= e(__($r['status'])) ?></span></td>
      <td><?= e($d['doc_number'] ?? '') ?></td><td><?= e($d ? __($copy[$d['is_original']] ?? '') : '') ?></td><td class="num"><?= e($d['expiry_date'] ?? '') ?></td><td class="num"><?= $r['days_left'] ?? '' ?></td>
      <td><?php if ($d && $d['file_ref']): ?><a href="<?= e(url('doc_file', ['id' => $d['id']])) ?>" target="_blank" rel="noopener">📎</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
<?php endif; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="muted"><?= e(__($tab === 'type' && !$f['type'] ? 'اختار نوع المستند.' : ($tab === 'employee' && !$f['emp'] ? 'اختار الموظف.' : 'مفيش نتايج.'))) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($tab === 'employee' && $f['emp'] && can('documents', 'create')): ?><p><a class="btn" href="<?= e(url('form', ['res' => 'employee_documents', 'emp' => $f['emp']])) ?>"><?= e(__('إضافة مستند')) ?></a></p><?php endif; ?>
