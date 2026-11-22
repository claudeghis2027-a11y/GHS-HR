<div class="page-head"><div><h1><?= e(__('النسخ الاحتياطي')) ?></h1><div class="sub"><?= e(__('نسخة كاملة من قاعدة البيانات بتتحفظ على السيرفر بره الويب. الاسترجاع بيضيف الصفوف الناقصة بس ومابيمسحش أي حاجة.')) ?></div></div></div>
<?php if (can('backup', 'create')): ?>
<div class="grid">
  <form method="post" action="<?= e(url('backup')) ?>" class="card" data-confirm="<?= e(__('هتتعمل نسخة احتياطية كاملة دلوقتي. متأكد؟')) ?>">
    <h2><?= e(__('نسخة جديدة')) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="op" value="create">
    <label><?= e(__('الوصف')) ?> <input name="description" maxlength="255" placeholder="<?= e(__('نسخة احتياطية يدوية')) ?>"></label>
    <button class="btn primary"><?= e(__('إنشاء نسخة')) ?></button>
  </form>
  <form method="post" action="<?= e(url('backup')) ?>" class="card" enctype="multipart/form-data">
    <h2><?= e(__('رفع نسخة')) ?></h2>
    <?= csrf_field() ?><input type="hidden" name="op" value="upload">
    <label><?= e(__('ملف نسخة من النظام ده (.jsonl.gz)')) ?> <input type="file" name="file" accept=".gz,application/gzip" required></label>
    <label><?= e(__('الوصف')) ?> <input name="description" maxlength="255"></label>
    <button class="btn"><?= e(__('رفع')) ?></button>
  </form>
</div>
<?php endif; ?>
<div class="table-wrap">
<table>
  <thead><tr><th>#</th><th><?= e(__('التاريخ والوقت')) ?></th><th><?= e(__('الوصف')) ?></th><th><?= e(__('المصدر')) ?></th><th><?= e(__('الحجم')) ?></th><th><?= e(__('المستخدم')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $b): ?>
    <tr><td class="num"><?= (int)$b['id'] ?></td><td class="num"><?= e($b['at']) ?></td><td><?= e($b['description']) ?></td><td><?= e($b['source']) ?></td><td class="num"><?= e($b['size_text']) ?></td><td><?= e($b['done_by']) ?></td>
      <td><?php if ($b['local'] && can('backup', 'export')): ?><a class="btn small" href="<?= e(url('backup_file', ['id' => $b['id']])) ?>"><?= e(__('تنزيل')) ?></a><?php elseif (!$b['local']): ?><small class="muted"><?= e(__('على Drive القديم')) ?></small><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= e(__('مفيش نسخ لسه.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php $local = array_filter($rows, fn($b) => $b['local']); if (is_manager() && $local): ?>
<form method="post" action="<?= e(url('backup')) ?>" class="card form-grid" data-confirm="<?= e(__('الصفوف الناقصة من النسخة هتتضاف للجدول ده. متأكد؟')) ?>">
  <h2 class="span2"><?= e(__('استرجاع جدول واحد (للأدمن بس)')) ?></h2>
  <?= csrf_field() ?><input type="hidden" name="op" value="restore">
  <label><?= e(__('النسخة')) ?> <select name="id" required><?php foreach ($local as $b): ?><option value="<?= (int)$b['id'] ?>">#<?= (int)$b['id'] ?> · <?= e($b['at']) ?> · <?= e($b['description']) ?></option><?php endforeach; ?></select></label>
  <label><?= e(__('الجدول')) ?> <select name="table" required><?php foreach ($tables as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></select></label>
  <label><?= e(__('اكتب «استرجاع» للتأكيد')) ?> <input name="confirm" required autocomplete="off"></label>
  <div class="span2"><button class="btn primary"><?= e(__('استرجاع')) ?></button> <small class="muted"><?= e(__('بيضيف الصفوف اللي مش موجودة بس. مفيش أي صف بيتمسح أو يتعدّل.')) ?></small></div>
</form>
<?php endif; ?>
