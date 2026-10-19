<div class="page-head"><div><h1><?= e(__('الإعدادات')) ?></h1></div></div>
<form method="post" action="<?= e(url('settings')) ?>">
  <?= csrf_field() ?>
  <div class="card form-grid">
    <?php foreach ($rows as $s): $long = in_array($s['k'], ['face_consent_text', 'face_consent_text_en'], true); ?>
      <label class="<?= $long ? 'span2' : '' ?>"><?= e(__($s['label'] ?: $s['k'])) ?>
        <?php if ($long): ?>
          <textarea name="s[<?= e($s['k']) ?>]" rows="9" maxlength="5000" dir="<?= $s['k'] === 'face_consent_text_en' ? 'ltr' : 'rtl' ?>"><?= e($s['v']) ?></textarea>
        <?php else: ?>
          <input name="s[<?= e($s['k']) ?>]" value="<?= e($s['v']) ?>" maxlength="500" dir="ltr">
        <?php endif; ?>
        <?php if ($s['note']): ?><small class="muted"><?= e(__($s['note'])) ?></small><?php endif; ?>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="card">
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
  </div>
  <button class="btn primary"><?= e(__('حفظ')) ?></button>
</form>
