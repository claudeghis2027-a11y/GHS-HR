<?php
// Old «الإعدادات» screen: one card per job, in the old order, then this system's own settings.
$csrf = csrf_field();
$post = e(url('settings'));
$yn = fn(bool $on) => '<span class="badge ' . ($on ? 'green' : 'red') . '">' . e(__($on ? 'نشط' : 'موقوف')) . '</span>';
?>
<div class="page-head"><div><h1><?= e(__('الإعدادات')) ?></h1></div></div>

<div class="card">
  <h2><?= e(__('إعدادات الرواتب')) ?></h2>
  <p class="muted"><?= e(__('نِسَب العلاوة وخصم الربع لكل قسم بتتظبط من تعديل القسم في قايمة الأقسام.')) ?></p>
  <a class="btn" href="<?= e(url('list', ['res' => 'departments'])) ?>"><?= e(__('فتح الأقسام')) ?></a>
</div>

<div class="grid2" id="names">
<?php foreach (['dept' => [__('إدارة الأقسام'), $depts, __('اسم القسم الجديد'), __('إضافة قسم')], 'job' => [__('إدارة المسميات الوظيفية'), $jobs, __('اسم المسمى الوظيفي الجديد'), __('إضافة مسمى وظيفي')], 'spec' => [__('إدارة التخصصات'), $specs, __('اسم التخصص الجديد'), __('إضافة تخصص')]] as $kind => [$title, $items, $ph, $addLabel]): ?>
<div class="card">
  <h2><?= e($title) ?></h2>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= e(__('الاسم')) ?></th><th><?= e(__('الحالة')) ?></th><th><?= e(__('إجراءات')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?>
      <tr>
        <td>
          <form method="post" action="<?= $post ?>" class="inline"><?= $csrf ?><input type="hidden" name="op" value="name_rename"><input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
            <input name="name" value="<?= e($i['name']) ?>" maxlength="150" required aria-label="<?= e(__('الاسم')) ?>"> <button class="link"><?= e(__('تعديل الاسم')) ?></button></form>
        </td>
        <td><?= $yn((bool)$i['active']) ?></td>
        <td><form method="post" action="<?= $post ?>" class="inline"><?= $csrf ?><input type="hidden" name="op" value="name_toggle"><input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="link"><?= e(__($i['active'] ? 'تعطيل' : 'تفعيل')) ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="3" class="muted"><?= e(__('القائمة فاضية — أضف أول عنصر.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= $post ?>" class="inline-form"><?= $csrf ?><input type="hidden" name="op" value="name_add"><input type="hidden" name="kind" value="<?= $kind ?>">
    <label><?= e($ph) ?> <input name="name" maxlength="150" required></label> <button class="btn primary"><?= e($addLabel) ?></button></form>
  <p class="muted"><?= e(__('التعطيل لا يحذف القيمة ولا يمس بيانات الموظفين الحاليين.')) ?></p>
</div>
<?php endforeach; ?>
</div>

<form method="post" action="<?= $post ?>" class="card">
  <?= $csrf ?><input type="hidden" name="op" value="autocode">
  <h2><?= e(__('إعداد توليد أكواد الموظفين')) ?></h2>
  <label><?= e(__('توليد كود الموظف تلقائيًا؟')) ?>
    <select name="auto_code"><option value="1" <?= $auto_code ? 'selected' : '' ?>><?= e(__('نعم')) ?></option><option value="0" <?= $auto_code ? '' : 'selected' ?>><?= e(__('لا')) ?></option></select></label>
  <p class="muted"><?= e(__('نعم = الكود يتحدد تلقائيًا (أكبر كود رقمي + 1). لا = المستخدم يكتب الكود يدويًا.')) ?></p>
  <button class="btn primary"><?= e(__('حفظ')) ?></button>
</form>

<div class="card" id="exempt">
  <h2><?= e(__('إدارة الإعفاء من البصمة')) ?></h2>
  <h3><?= e(__('الأقسام المعفاة من رصد البصمة')) ?></h3>
  <?php $exDepts = array_filter($depts, fn($d) => $d['attendance_exempt']); ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= e(__('القسم')) ?></th><th><?= e(__('إجراءات')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($exDepts as $d): ?>
      <tr><td><?= e($d['name']) ?></td><td><form method="post" action="<?= $post ?>" class="inline"><?= $csrf ?><input type="hidden" name="op" value="exempt_dept_remove"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="link danger"><?= e(__('إلغاء الإعفاء')) ?></button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$exDepts): ?><tr><td colspan="2" class="muted"><?= e(__('مفيش أقسام معفاة.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= $post ?>" class="inline-form"><?= $csrf ?><input type="hidden" name="op" value="exempt_dept_add">
    <label><?= e(__('القسم')) ?> <select name="id" required><option value="">— <?= e(__('اختر قسمًا')) ?> —</option><?php foreach ($depts as $d): if ($d['attendance_exempt']) continue; ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></label>
    <button class="btn primary"><?= e(__('إعفاء القسم')) ?></button></form>

  <h3><?= e(__('الموظفون المعفون من البصمة (إعفاء يدوي)')) ?></h3>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('إجراءات')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($exempt_emps as $x): ?>
      <tr><td class="num"><?= (int)$x['id'] ?></td><td><?= e($x['name_ar']) ?></td><td><?= e($x['dept']) ?></td>
        <td><form method="post" action="<?= $post ?>" class="inline"><?= $csrf ?><input type="hidden" name="op" value="exempt_emp_remove"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="link danger"><?= e(__('إلغاء الإعفاء')) ?></button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$exempt_emps): ?><tr><td colspan="4" class="muted"><?= e(__('مفيش موظفين معفون يدويًا.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= $post ?>" class="inline-form"><?= $csrf ?><input type="hidden" name="op" value="exempt_emp_add">
    <label><?= e(__('الموظف')) ?> <select name="id" required><option value="">— <?= e(__('اختر موظفًا')) ?> —</option><?php foreach ($free_emps as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['name_ar']) ?> (<?= (int)$x['id'] ?>)</option><?php endforeach; ?></select></label>
    <button class="btn primary"><?= e(__('إعفاء الموظف')) ?></button></form>
  <p class="muted"><?= e(__('إعفاء الموظف وإلغاؤه بيتسجلوا في سجل تعديلات بياناته.')) ?></p>
</div>

<div class="card" id="years">
  <h2><?= e(__('السنة الدراسية')) ?></h2>
  <div class="table-wrap">
  <table>
    <thead><tr><th><?= e(__('اسم السنة')) ?></th><th><?= e(__('من تاريخ')) ?></th><th><?= e(__('لحد تاريخ')) ?></th><th><?= e(__('الحالة')) ?></th><th><?= e(__('إجراءات')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($years as $y): if ((int)$y['id'] === $edit_year): ?>
      <tr><td colspan="5">
        <form method="post" action="<?= $post ?>" class="inline-form"><?= $csrf ?><input type="hidden" name="op" value="year_update"><input type="hidden" name="id" value="<?= (int)$y['id'] ?>">
          <label><?= e(__('اسم السنة')) ?> <input name="name" value="<?= e($y['name']) ?>" maxlength="100" required></label>
          <label><?= e(__('من تاريخ')) ?> <input type="date" name="date_from" value="<?= e($y['date_from']) ?>" required></label>
          <label><?= e(__('لحد تاريخ')) ?> <input type="date" name="date_to" value="<?= e($y['date_to']) ?>" required></label>
          <button class="btn primary"><?= e(__('حفظ')) ?></button> <a href="<?= e(url('settings')) ?>#years"><?= e(__('إلغاء')) ?></a></form>
      </td></tr>
    <?php else: ?>
      <tr><td><?= e($y['name']) ?></td><td class="num"><?= e($y['date_from']) ?></td><td class="num"><?= e($y['date_to']) ?></td>
        <td><?= $y['active'] ? '<span class="badge green">' . e(__('نشطة')) . '</span>' : '—' ?></td>
        <td class="actions">
          <?php if (!$y['active']): ?><form method="post" action="<?= $post ?>" class="inline"><?= $csrf ?><input type="hidden" name="op" value="year_activate"><input type="hidden" name="id" value="<?= (int)$y['id'] ?>"><button class="link"><?= e(__('تفعيل')) ?></button></form><?php endif; ?>
          <a href="<?= e(url('settings', ['edit_year' => $y['id']])) ?>#years"><?= e(__('تعديل')) ?></a></td></tr>
    <?php endif; endforeach; ?>
    <?php if (!$years): ?><tr><td colspan="5" class="muted"><?= e(__('مفيش سنوات دراسية مسجّلة.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= $post ?>" class="inline-form"><?= $csrf ?><input type="hidden" name="op" value="year_add">
    <label><?= e(__('اسم السنة')) ?> <input name="name" maxlength="100" placeholder="2026-2027" required></label>
    <label><?= e(__('من تاريخ')) ?> <input type="date" name="date_from" required></label>
    <label><?= e(__('لحد تاريخ')) ?> <input type="date" name="date_to" required></label>
    <button class="btn primary"><?= e(__('إضافة سنة دراسية')) ?></button></form>
  <p class="muted"><?= e(__('السنة الجديدة بتتضاف موقوفة. تفعيل سنة بيوقف السنة النشطة اللي قبلها.')) ?></p>
</div>

<div class="card" id="check">
  <h2><?= e(__('التحقق من البيئة')) ?></h2>
  <p class="muted"><?= e(__('فحص للقراءة فقط: قاعدة البيانات، الجداول، PHP، فولدرات التخزين، خدمة بصمة الوجه، آخر نسخة احتياطية.')) ?></p>
  <?php if ($checks !== null): $fail = count(array_filter($checks, fn($c) => !$c['ok'])); ?>
    <p class="status-line">PASS: <?= count($checks) - $fail ?> · FAIL: <?= $fail ?></p>
    <div class="table-wrap">
    <table>
      <thead><tr><th><?= e(__('البند')) ?></th><th><?= e(__('النتيجة')) ?></th><th><?= e(__('التفاصيل')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($checks as $c): ?><tr><td><?= e($c['name']) ?></td><td><span class="badge <?= $c['ok'] ? 'green' : 'red' ?>"><?= $c['ok'] ? 'PASS' : 'FAIL' ?></span></td><td class="muted" dir="auto"><?= e($c['detail']) ?></td></tr><?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <a class="btn" href="<?= e(url('settings', ['check' => 1])) ?>#check"><?= e(__('تشغيل الفحص')) ?></a>
</div>

<div class="grid2">
  <div class="card">
    <h2><?= e(__('إعدادات المرتبات للسنة')) ?></h2>
    <p class="muted"><?= e(__('شهر العلاوة السنوية، وشهور خصم الربعين وشهر ردهم، والأقسام، لكل سنة دراسية.')) ?></p>
    <a class="btn" href="<?= e(url('list', ['res' => 'payroll_year_settings'])) ?>"><?= e(__('فتح إعدادات المرتبات للسنة')) ?></a>
  </div>
  <div class="card">
    <h2><?= e(__('سياسة خصم الربعين')) ?></h2>
    <p class="muted"><?= e(__('تحديد الشهور اللي بيتخصم فيها الربع لكل قسم خلال السنة الدراسية.')) ?></p>
    <a class="btn" href="<?= e(url('quarter_policy')) ?>"><?= e(__('فتح سياسة خصم الربعين')) ?></a>
  </div>
</div>

<form method="post" action="<?= $post ?>" class="card">
  <?= $csrf ?><input type="hidden" name="op" value="general">
  <h2><?= e(__('إعدادات الحضور والنظام')) ?></h2>
  <div class="form-grid">
  <?php foreach ($rows as $s): $type = SETTING_TYPES[$s['k']] ?? 'text'; $label = e(__($s['label'] ?: $s['k'])); $note = $s['note'] && $type !== 'bool' ? '<small class="muted">' . e(__($s['note'])) . '</small>' : ''; ?>
    <?php if ($type === 'weekdays'): $on = array_map('intval', explode(',', (string)$s['v'])); ?>
      <fieldset class="span2"><legend><?= $label ?></legend>
        <?php foreach (WEEKDAYS as $n => $day): ?><label class="inline"><input type="checkbox" name="weekend[]" value="<?= $n ?>" <?= in_array($n, $on, true) ? 'checked' : '' ?>> <?= e(__($day)) ?></label> <?php endforeach; ?>
      </fieldset>
    <?php elseif ($type === 'bool'): ?>
      <label><?= $label ?>
        <select name="s[<?= e($s['k']) ?>]"><option value="1" <?= $s['v'] === '1' ? 'selected' : '' ?>><?= e(__('نعم')) ?></option><option value="0" <?= $s['v'] === '1' ? '' : 'selected' ?>><?= e(__('لا')) ?></option></select></label>
    <?php elseif ($type === 'long'): ?>
      <label class="span2"><?= $label ?>
        <textarea name="s[<?= e($s['k']) ?>]" rows="9" maxlength="5000" dir="<?= $s['k'] === 'face_consent_text_en' ? 'ltr' : 'rtl' ?>"><?= e($s['v']) ?></textarea><?= $note ?></label>
    <?php else: ?>
      <label><?= $label ?>
        <input name="s[<?= e($s['k']) ?>]" value="<?= e($s['v']) ?>" maxlength="500" dir="ltr" <?= $type === 'int' ? 'inputmode="numeric" pattern="\d{1,6}"' : ($type === 'decimal' ? 'inputmode="decimal"' : '') ?>><?= $note ?></label>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>
  <button class="btn primary"><?= e(__('حفظ')) ?></button>
</form>

<form method="post" action="<?= $post ?>" class="card">
  <?= $csrf ?><input type="hidden" name="op" value="fields">
  <h2><?= e(__('حقول الموظف في صفحة "بياناتي"')) ?></h2>
  <table class="matrix">
    <thead><tr><th><?= e(__('الحقل')) ?></th><th><?= e(__('ظاهر')) ?></th><th><?= e(__('قابل للتعديل (بطلب)')) ?></th><th><?= e(__('إلزامي')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($fields as $f): ?>
      <tr><td><?= e(__($f['label'])) ?></td>
      <?php foreach (['visible', 'editable', 'required'] as $c): ?><td><input type="checkbox" name="f[<?= e($f['field']) ?>][<?= $c ?>]" value="1" <?= $f[$c] ? 'checked' : '' ?>></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted"><?= e(__('التعديل بيتطبق بس على الاسم والتليفون والجنسية؛ القسم والمسمى والتعيين بتعدلهم الإدارة.')) ?></p>
  <button class="btn primary"><?= e(__('حفظ')) ?></button>
</form>

<?php if (is_super()): ?>
<div class="card">
  <h2><?= e(__('إعادة ضبط بيانات النظام')) ?></h2>
  <p class="muted"><?= e(__('للسوبر أدمن بس. بيمسح بيانات المجموعات المختارة فقط، بعد نسخة احتياطية إجبارية.')) ?></p>
  <a class="btn danger" href="<?= e(url('data_reset')) ?>"><?= e(__('فتح إعادة الضبط')) ?></a>
</div>
<?php endif; ?>
