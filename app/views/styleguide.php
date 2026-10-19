<?php $sw = [['sw-blue', 'Blue / أزرق', '--blue #1E4CA1'], ['sw-blue7', 'Blue 700', '--blue-700 #163A7D'], ['sw-blue50', 'Blue 50', '--blue-50 #EEF3FB'],
             ['sw-green', 'Green / أخضر', '--green #017538'], ['sw-green50', 'Green 50', '--green-50 #EAF5EE'], ['sw-ochre', 'Ochre / ذهبي', '--ochre #B97A22'],
             ['sw-ochre50', 'Ochre 50', '--ochre-50 #FBF3E6'], ['sw-bg', 'Background', '--bg #F7F3EC'], ['sw-surface', 'Surface', '--surface #FFFFFF'],
             ['sw-line', 'Line', '--line #E6DED1'], ['sw-text', 'Text', '--text #2A2621'], ['sw-danger', 'Danger', '--danger #B3402B'], ['sw-warning', 'Warning', '--warning #9A5B0E']]; ?>
<div class="page-head"><div><h1><?= e(__('دليل التصميم')) ?></h1><div class="sub"><?= e(__('الألوان والمكونات الثابتة للنظام. التفاصيل في docs/STYLE_GUIDE.md.')) ?></div></div></div>
<div class="card"><h2><?= e(__('الألوان')) ?></h2>
  <div class="swatches"><?php foreach ($sw as [$c, $n, $t]): ?><div class="swatch"><i class="<?= $c ?>"></i><div><b><?= e($n) ?></b><br><code dir="ltr"><?= e($t) ?></code></div></div><?php endforeach; ?></div>
</div>
<div class="grid2">
  <div class="card"><h2><?= e(__('الأزرار')) ?></h2>
    <p><button class="btn primary"><?= e(__('حفظ')) ?></button> <button class="btn"><?= e(__('رجوع')) ?></button> <button class="btn success"><?= e(__('تسجيل انصراف')) ?></button> <button class="btn danger"><?= e(__('حذف')) ?></button> <button class="btn ghost"><?= e(__('خروج')) ?></button></p>
    <p><button class="btn small primary"><?= e(__('اعتماد')) ?></button> <button class="btn small"><?= e(__('عرض')) ?></button> <button class="btn" disabled><?= e(__('حفظ')) ?></button></p>
    <h3><?= e(__('الشارات')) ?></h3>
    <p><span class="badge green"><?= e(__('معتمد')) ?></span> <span class="badge blue"><?= e(__('تحديث')) ?></span> <span class="badge ochre"><?= e(__('تحت المراجعة')) ?></span> <span class="badge red"><?= e(__('مرفوض')) ?></span> <span class="badge"><?= e(__('معطل')) ?></span></p>
  </div>
  <div class="card"><h2><?= e(__('التنبيهات')) ?></h2>
    <div class="flash ok"><?= e(__('تم الحفظ.')) ?></div><div class="flash err"><?= e(__('راجع الحقول المعلَّمة.')) ?></div>
    <div class="flash warn"><?= e(__('لازم تغيّر كلمة المرور المؤقتة قبل ما تكمل.')) ?></div><div class="flash info"><?= e(__('التعديلات بتتطبق بعد موافقة الإدارة.')) ?></div>
  </div>
</div>
<div class="stats"><div class="stat"><span>128</span><?= e(__('موظف حالي')) ?></div><div class="stat green"><span>117</span><?= e(__('حاضر النهارده')) ?></div><div class="stat ochre"><span>3</span><?= e(__('طلب تعديل بيانات')) ?></div><div class="stat warn"><span>2</span><?= e(__('إجمالي أيام الخصم')) ?></div></div>
<div class="card form-grid"><label><?= e(__('اسم المستخدم')) ?><input dir="ltr" placeholder="user"></label><label><?= e(__('القسم')) ?><select><option>—</option></select></label><label class="has-err"><?= e(__('التاريخ')) ?><input type="date"><small class="err-text"><?= e(__('%s تاريخ غير صحيح.', __('التاريخ'))) ?></small></label></div>
