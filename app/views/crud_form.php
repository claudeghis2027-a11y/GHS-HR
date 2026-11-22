<?php
$key = $res['key'];
$isNew = $id === null;
$editable = $isNew ? can($res['module'], 'create') : can($res['module'], 'update');
$editable = $editable && empty($res['readonly']);
?>
<div class="page-head">
  <div><h1><?= e(__($res['title'])) ?></h1><div class="sub"><?= $isNew ? e(__('إضافة')) : e(__('تعديل #%d', (int)$id)) ?></div></div>
  <a class="btn" href="<?= e(url('list', ['res' => $key])) ?>"><?= e(__('رجوع')) ?></a>
</div>
<?php if (!empty($errors['_'])): ?><div class="flash err"><?= e($errors['_']) ?></div><?php endif; ?>
<?php if ($errors && empty($errors['_'])): ?><div class="flash err"><?= e(__('راجع الحقول المعلَّمة.')) ?></div><?php endif; ?>

<?php $hasFile = (bool)array_filter($res['fields'], fn($f) => $f['type'] === 'file'); ?>
<form method="post" action="<?= e(url('form', ['res' => $key])) ?>" class="card form-grid"<?= $hasFile ? ' enctype="multipart/form-data"' : '' ?>>
  <?= csrf_field() ?>
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
  <?php foreach ($res['fields'] as $name => $f):
      if (!empty($f['auto']) && $isNew) continue;
      if (!empty($f['create_only']) && !$isNew) continue;
      if (!empty($f['edit_only']) && $isNew) continue;
      if ($f['type'] === 'filelink' || $f['type'] === 'scope') continue;
      $v = $row[$name] ?? null;
      $ro = !$editable || !empty($f['readonly']) || !empty($f['auto']);
      $err = $errors[$name] ?? null;
      $req = !empty($f['required']) ? 'required' : '';
      $dis = $ro ? 'disabled' : '';
  ?>
    <?php $wrap = $f['type'] === 'enum' && !empty($f['multi']) ? 'div' : 'label'; // a group of checkboxes can't sit inside one label ?>
    <<?= $wrap ?> class="<?= $err ? 'has-err' : '' ?> <?= $f['type'] === 'textarea' || $wrap === 'div' ? 'span2' : '' ?>"<?= !empty($f['show_if']) ? ' data-show-if="' . e($f['show_if'][0]) . '" data-show-val="' . e($f['show_if'][1]) . '"' : '' ?>>
      <?= e(__($f['label'])) ?><?= $req ? ' *' : '' ?>
      <?php switch ($f['type']):
        case 'bool': ?>
          <span class="check"><input type="hidden" name="<?= e($name) ?>" value="0"><input type="checkbox" name="<?= e($name) ?>" value="1" <?= $v ? 'checked' : '' ?> <?= $dis ?>> <?= e(__('نعم')) ?></span>
        <?php break; case 'enum': if (!empty($f['multi'])): $sel = array_flip(array_filter(explode(',', (string)$v))); ?>
          <span class="check-list"><?php foreach ($f['options'] as $ov => $ol): ?>
            <label class="check"><input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($ov) ?>" <?= isset($sel[(string)$ov]) ? 'checked' : '' ?> <?= $dis ?>> <?= e(__($ol)) ?></label>
          <?php endforeach; ?></span>
        <?php else: ?>
          <select name="<?= e($name) ?>" <?= $req ?> <?= $dis ?>>
            <?php if (!$req && !isset($f['options'][''])): ?><option value="">—</option><?php endif; ?>
            <?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= e($ov) ?>" <?= (string)$v === (string)$ov ? 'selected' : '' ?>><?= e(__($ol)) ?></option><?php endforeach; ?>
          </select>
        <?php endif; break; case 'file': ?>
          <input type="file" name="<?= e($name) ?>" accept="<?= e($f['accept'] ?? 'application/pdf,image/jpeg,image/png,image/webp') ?>" <?= $dis ?>>
          <?php if (!empty($row[$f['ref'] ?? '']) && $id !== null): ?><a href="<?= e(url($f['view'], ['id' => $id])) ?>" target="_blank" rel="noopener"><?= e(__('عرض الملف الحالي')) ?></a><?php endif; ?>
        <?php break; case 'fk': case 'employee': $opts = $f['type'] === 'fk' ? fk_form_options($f, $v) : employee_options();
          if (!empty($f['multi'])): $sel = array_flip(array_filter(explode(',', (string)$v))); ?>
          <select name="<?= e($name) ?>[]" multiple size="6" <?= $dis ?>>
            <?php foreach ($opts as $ov => $ol): ?><option value="<?= (int)$ov ?>" <?= isset($sel[(string)$ov]) ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?>
          </select>
          <?php else: ?>
          <select name="<?= e($name) ?>" <?= $req ?> <?= $dis ?>>
            <option value="">—</option>
            <?php foreach ($opts as $ov => $ol): ?><option value="<?= (int)$ov ?>" <?= (string)$v === (string)$ov ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?>
          </select>
          <?php endif; ?>
        <?php break; case 'textarea': ?>
          <textarea name="<?= e($name) ?>" rows="4" maxlength="<?= (int)($f['max'] ?? 5000) ?>" <?= $req ?> <?= $dis ?>><?= e($v) ?></textarea>
        <?php break; default:
          $type = ['int' => 'number', 'decimal' => 'text', 'date' => 'date', 'time' => 'time', 'datetime' => 'datetime-local', 'month' => 'month'][$f['type']] ?? 'text';
          if ($f['type'] === 'month' && $v) $v = substr((string)$v, 0, 7);
          if ($f['type'] === 'datetime' && $v) $v = str_replace(' ', 'T', substr((string)$v, 0, 16));
          if ($f['type'] === 'time' && $v) $v = substr((string)$v, 0, 5); ?>
          <input type="<?= $type ?>" name="<?= e($name) ?>" value="<?= e($v) ?>" <?= $req ?> <?= $dis ?>
            <?= $f['type'] === 'decimal' ? 'inputmode="decimal"' : '' ?> <?= isset($f['max']) && $type === 'text' ? 'maxlength="' . (int)$f['max'] . '"' : '' ?>>
      <?php endswitch; ?>
      <?php if (!empty($f['hint'])): ?><small class="muted"><?= e(__($f['hint'])) ?></small><?php endif; ?>
      <?php if ($err): ?><small class="err-text"><?= e($err) ?></small><?php endif; ?>
    </<?= $wrap ?>>
  <?php endforeach; ?>
  <?php if ($key === 'attendance_points' && $editable): ?>
  <div class="span2 geo-tools" data-geo-fill>
    <button type="button" class="btn" data-geo-btn>📍 <?= e(__('استخدم موقعي الحالي')) ?></button>
    <a class="btn ghost" data-geo-map href="#" target="_blank" rel="noopener noreferrer" hidden><?= e(__('اعرض على الخريطة')) ?></a>
    <p class="muted" data-geo-status><?= e(__('قف في مكان النقطة (مثلاً نص الحوش أو جنب البوابة) واضغط الزرار. هناخد أدق قراءة خلال 10 ثواني.')) ?></p>
  </div>
  <?php endif; ?>
  <?php if ($key === 'late_exemptions' && $editable): ?>
  <div class="span2" data-late-helper data-url="<?= e(url('late_of_day')) ?>">
    <button type="button" class="btn" data-late-btn><?= e(__('احسب تأخير اليوم')) ?></button>
    <span class="muted" data-late-out><?= e(__('اختار الموظف والتاريخ، وهنجيب دقايق التأخير من البصمة.')) ?></span>
  </div>
  <?php endif; ?>
  <?php if ($editable): ?><div class="span2"><button class="btn primary"><?= e(__('حفظ')) ?></button></div><?php endif; ?>
</form>

<?php if ($key === 'notifications' && !$isNew && ($row['status'] ?? '') !== 'cancelled' && can('notifications', 'update')): ?>
<form method="post" action="<?= e(url('notif_cancel')) ?>" class="card narrow" data-confirm="<?= e(__('الإشعار هيتلغى ومش هيظهر للموظفين. متأكد؟')) ?>">
  <h2><?= e(__('إلغاء الإشعار')) ?></h2>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <label><?= e(__('سبب الإلغاء')) ?> * <input name="reason" maxlength="500" required></label>
  <button class="btn"><?= e(__('إلغاء الإشعار')) ?></button>
</form>
<?php endif; ?>

<?php if ($key === 'employees' && !$isNew) { $back = url('form', ['res' => 'employees', 'id' => $id]); require __DIR__ . '/_emp_access.php'; } ?>
