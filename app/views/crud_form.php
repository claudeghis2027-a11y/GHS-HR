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

<form method="post" action="<?= e(url('form', ['res' => $key])) ?>" class="card form-grid">
  <?= csrf_field() ?>
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
  <?php foreach ($res['fields'] as $name => $f):
      if (!empty($f['auto']) && $isNew) continue;
      if (!empty($f['create_only']) && !$isNew) continue;
      $v = $row[$name] ?? null;
      $ro = !$editable || !empty($f['readonly']) || !empty($f['auto']);
      $err = $errors[$name] ?? null;
      $req = !empty($f['required']) ? 'required' : '';
      $dis = $ro ? 'disabled' : '';
  ?>
    <label class="<?= $err ? 'has-err' : '' ?> <?= $f['type'] === 'textarea' ? 'span2' : '' ?>">
      <?= e(__($f['label'])) ?><?= $req ? ' *' : '' ?>
      <?php switch ($f['type']):
        case 'bool': ?>
          <span class="check"><input type="hidden" name="<?= e($name) ?>" value="0"><input type="checkbox" name="<?= e($name) ?>" value="1" <?= $v ? 'checked' : '' ?> <?= $dis ?>> <?= e(__('نعم')) ?></span>
        <?php break; case 'enum': ?>
          <select name="<?= e($name) ?>" <?= $req ?> <?= $dis ?>>
            <?php if (!$req && !isset($f['options'][''])): ?><option value="">—</option><?php endif; ?>
            <?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= e($ov) ?>" <?= (string)$v === (string)$ov ? 'selected' : '' ?>><?= e(__($ol)) ?></option><?php endforeach; ?>
          </select>
        <?php break; case 'fk': case 'employee': $opts = $f['type'] === 'fk' ? fk_options($f) : employee_options(); ?>
          <select name="<?= e($name) ?>" <?= $req ?> <?= $dis ?>>
            <option value="">—</option>
            <?php foreach ($opts as $ov => $ol): ?><option value="<?= (int)$ov ?>" <?= (string)$v === (string)$ov ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?>
          </select>
        <?php break; case 'textarea': ?>
          <textarea name="<?= e($name) ?>" rows="4" maxlength="<?= (int)($f['max'] ?? 5000) ?>" <?= $req ?> <?= $dis ?>><?= e($v) ?></textarea>
        <?php break; default:
          $type = ['int' => 'number', 'decimal' => 'text', 'date' => 'date', 'time' => 'time', 'datetime' => 'datetime-local'][$f['type']] ?? 'text';
          if ($f['type'] === 'datetime' && $v) $v = str_replace(' ', 'T', substr((string)$v, 0, 16));
          if ($f['type'] === 'time' && $v) $v = substr((string)$v, 0, 5); ?>
          <input type="<?= $type ?>" name="<?= e($name) ?>" value="<?= e($v) ?>" <?= $req ?> <?= $dis ?>
            <?= $f['type'] === 'decimal' ? 'inputmode="decimal"' : '' ?> <?= isset($f['max']) && $type === 'text' ? 'maxlength="' . (int)$f['max'] . '"' : '' ?>>
      <?php endswitch; ?>
      <?php if (!empty($f['hint'])): ?><small class="muted"><?= e(__($f['hint'])) ?></small><?php endif; ?>
      <?php if ($err): ?><small class="err-text"><?= e($err) ?></small><?php endif; ?>
    </label>
  <?php endforeach; ?>
  <?php if ($key === 'attendance_points' && $editable): ?>
  <div class="span2 geo-tools" data-geo-fill>
    <button type="button" class="btn" data-geo-btn>📍 <?= e(__('استخدم موقعي الحالي')) ?></button>
    <a class="btn ghost" data-geo-map href="#" target="_blank" rel="noopener noreferrer" hidden><?= e(__('اعرض على الخريطة')) ?></a>
    <p class="muted" data-geo-status><?= e(__('قف في مكان النقطة (مثلاً نص الحوش أو جنب البوابة) واضغط الزرار. هناخد أدق قراءة خلال 10 ثواني.')) ?></p>
  </div>
  <?php endif; ?>
  <?php if ($editable): ?><div class="span2"><button class="btn primary"><?= e(__('حفظ')) ?></button></div><?php endif; ?>
</form>

<?php if ($key === 'employees' && !$isNew && is_manager()):
    $acct = q_one('SELECT u.*, r.name role_name, r.is_super FROM users u JOIN roles r ON r.id = u.role_id WHERE u.employee_id = ?', [$id]);
    $back = url('form', ['res' => 'employees', 'id' => $id]); ?>
<div class="card narrow accent">
  <h2><?= e(__('حساب الدخول')) ?></h2>
  <p><?= e(__('كود الموظف')) ?>: <strong class="num"><?= (int)$id ?></strong> · <span class="muted"><?= e(__('الكود ثابت ومينفعش يتغير، والموظف يقدر يدخل بيه.')) ?></span></p>
  <?php if ($acct): ?>
    <p><?= e(__('اسم المستخدم')) ?>: <strong dir="ltr"><?= e($acct['username']) ?></strong> · <?= e(__($acct['role_name'])) ?> ·
      <span class="badge <?= $acct['active'] ? 'green' : 'red' ?>"><?= e(__($acct['active'] ? 'نشط' : 'معطل')) ?></span></p>
    <?php if ($acct['is_super'] && !is_super()): ?><p class="muted"><?= e(__('حسابات السوبر أدمن بيتحكم فيها السوبر أدمن بس.')) ?></p><?php else: ?>
    <form method="post" action="<?= e(url('users')) ?>">
      <?= csrf_field() ?><input type="hidden" name="op" value="reset"><input type="hidden" name="id" value="<?= (int)$acct['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
      <label><?= e(__('كلمة مرور جديدة')) ?> <input type="password" name="password" required minlength="10" autocomplete="new-password" dir="ltr"></label>
      <label class="check"><input type="hidden" name="must_change" value="0"><input type="checkbox" name="must_change" value="1" checked> <?= e(__('يغيّرها أول ما يدخل')) ?></label>
      <button class="btn primary"><?= e(__('تغيير كلمة المرور')) ?></button>
    </form>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted"><?= e(__('الموظف ده مالوش حساب دخول.')) ?></p>
    <form method="post" action="<?= e(url('users')) ?>">
      <?= csrf_field() ?><input type="hidden" name="op" value="create"><input type="hidden" name="employee_id" value="<?= (int)$id ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
      <input type="hidden" name="role_id" value="<?= (int)q_val("SELECT id FROM roles WHERE slug = 'employee'") ?>">
      <label><?= e(__('كلمة مرور')) ?> <input type="password" name="password" required minlength="10" autocomplete="new-password" dir="ltr"></label>
      <label class="check"><input type="hidden" name="must_change" value="0"><input type="checkbox" name="must_change" value="1" checked> <?= e(__('يغيّرها أول ما يدخل')) ?></label>
      <button class="btn primary"><?= e(__('إنشاء حساب (اسم المستخدم = الكود)')) ?></button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($key === 'employees' && !$isNew && can('employees', 'update')): $hasPhoto = (bool)q_val('SELECT official_photo FROM employees WHERE id = ?', [$id]); ?>
<form method="post" action="<?= e(url('emp_photo')) ?>" enctype="multipart/form-data" class="card narrow">
  <h2><?= e(__('الصورة الرسمية للموظف')) ?></h2>
  <p class="muted"><?= e(__('بتظهر للسوبر أدمن جنب صورة بصمة الوجه وقت المراجعة. بتتحفظ مشفّرة بره الويب.')) ?></p>
  <?php if ($hasPhoto): ?><img class="face-img" src="<?= e(url('face_img', ['kind' => 'official', 'id' => $id])) ?>" alt="<?= e(__('الصورة الرسمية')) ?>"><?php endif; ?>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <label><?= e(__('صورة JPG أو PNG (حد أقصى 3 ميجا)')) ?> <input type="file" name="photo" accept="image/jpeg,image/png" required></label>
  <button class="btn"><?= e(__('رفع')) ?></button>
</form>
<?php endif; ?>
