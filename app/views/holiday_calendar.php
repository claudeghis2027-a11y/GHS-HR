<?php $st = ['ACTIVE' => ['نشطة', 'green'], 'CANCELLED' => ['ملغاة', 'red'], 'SUPERSEDED' => ['تاريخ أصلي مُرحَّل', 'ochre'], 'SUPPRESSED' => ['ملغاة (اتنقلت المناسبة)', 'red']]; $canEdit = can('rules', 'update'); ?>
<div class="page-head">
  <div><h1><?= e(__('تقويم العطلات الرسمية')) ?></h1><div class="sub"><?= e(__('كل مناسبة في صف واحد: التاريخ الرسمي ويوم الإجازة الفعلي')) ?></div></div>
  <form method="get" class="filters">
    <input type="hidden" name="r" value="holiday_calendar">
    <select name="year"><?php foreach ($years as $y): ?><option<?= $y === $year ? ' selected' : '' ?>><?= e($y) ?></option><?php endforeach; ?></select>
    <button class="btn small"><?= e(__('عرض')) ?></button>
    <a class="btn small" href="<?= e(url('list', ['res' => 'holidays'])) ?>"><?= e(__('كل السجلات / إضافة يدوي')) ?></a>
  </form>
</div>
<p class="muted"><?= e(__('المصدر الرسمي: رئاسة الجمهورية (القائمة السنوية) + وزارة العمل/مجلس الوزراء (قرارات الترحيل — الأحدث يفوز). الإجازات المسجّلة يدويًا مصدر منفصل وبتغلب التقويم ده في الحساب، إلا لو المناسبة اتنقلت من اليوم ده.')) ?>
  <?= e(__('آخر تحديث: %s', $lastSync !== '' ? substr($lastSync, 0, 16) : '—')) ?></p>
<div class="stats"><div class="stat"><span><?= count($rows) ?></span><?= e(__('مناسبة')) ?></div><div class="stat green"><span><?= count(array_filter($rows, fn($r) => $r['status'] === 'ACTIVE')) ?></span><?= e(__('فعّالة')) ?></div></div>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('المناسبة')) ?></th><th><?= e(__('يوم الإجازة الفعلي')) ?></th><th><?= e(__('التاريخ الرسمي')) ?></th><th><?= e(__('الحالة')) ?></th><?php if ($canEdit): ?><th><?= e(__('الإجراء')) ?></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): [$sl, $sc] = $st[$r['status']] ?? [$r['status'], '']; ?>
    <tr><td><?= e($r['title']) ?><div class="muted small"><?= e(($r['origin'] === 'manual' ? __('إدخال يدوي') : __('متزامنة') . ' · ' . ($r['source'] !== '' ? $r['source'] : '—')) . ($r['synced_at'] !== '' ? ' · ' . substr($r['synced_at'], 0, 16) : '') . ($r['note'] !== '' ? ' · ' . $r['note'] : '')) ?></div></td>
      <td class="num"><?= e($r['effective']) ?> <small class="muted"><?= e(day_name((int)date('N', strtotime($r['effective'])))) ?></small></td>
      <td class="num"><?= $r['official'] !== $r['effective'] ? e($r['official']) . ' ↪' : e($r['official']) ?></td><td><span class="badge <?= $sc ?>"><?= e(__($sl)) ?></span></td>
      <?php if ($canEdit): ?><td>
        <?php if ($r['origin'] === 'synced' && $r['id']): ?>
        <form method="post" action="<?= e(url('holiday_calendar')) ?>" class="inline">
          <?= csrf_field() ?><input type="hidden" name="op" value="observed"><input type="hidden" name="year" value="<?= e($year) ?>">
          <input type="hidden" name="original_date" value="<?= e($r['official']) ?>"><input type="hidden" name="title" value="<?= e($r['title']) ?>">
          <input type="date" name="observed_date" required value="<?= e($r['effective']) ?>"><button class="btn small"><?= e(__('تعديل التاريخ الفعلي')) ?></button>
        </form>
        <?php endif; ?>
        <?php if ($r['origin'] === 'synced' && $r['id'] && in_array($r['status'], ['ACTIVE', 'CANCELLED'], true)): $c = $r['status'] === 'ACTIVE'; ?>
        <form method="post" action="<?= e(url('holiday_calendar')) ?>" class="inline"<?= $c ? ' data-confirm="' . e(__('إلغاء إجازة «%s» بتاريخ %s؟ السجل هيفضل محفوظ في التاريخ.', $r['title'], $r['effective'])) . '"' : '' ?>>
          <?= csrf_field() ?><input type="hidden" name="op" value="<?= $c ? 'cancel' : 'reactivate' ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="year" value="<?= e($year) ?>">
          <?php if ($c): ?><input name="reason" maxlength="500" placeholder="<?= e(__('سبب الإلغاء (اختياري)')) ?>"><?php endif; ?><button class="btn small<?= $c ? ' danger' : '' ?>"><?= e(__($c ? 'إلغاء' : 'إعادة تفعيل')) ?></button>
        </form>
        <?php endif; ?>
        <?php if ($r['origin'] === 'manual'): ?><span class="muted">—</span><?php endif; ?>
      </td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted"><?= e(__('مفيش مناسبات مسجلة للسنة دي.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted"><?= e(__('المدى: %s → %s · مناسبات متزامنة: %d · يدوية: %d — القائمة بتعرض كل المناسبات المسجّلة للسنة دي. المصادر بتنشر الإجازات على دفعات، فاحتمال تكون القائمة مش مكتملة لحد ما الدولة تعلن الباقي.', $year . '-01-01', $year . '-12-31', $coverage['synced'], $coverage['manual'])) ?></p>
<?php if ($canEdit): ?>
<form method="post" action="<?= e(url('holiday_calendar')) ?>" class="card form-grid" data-confirm="<?= e(__('هيتسجل يوم الإجازة الفعلي، والتاريخ الأصلي هيتعلّم إنه اتنقل. متأكد؟')) ?>">
  <h2 class="span2"><?= e(__('نقل مناسبة ليوم بديل (أو تسجيل قرار جديد)')) ?></h2>
  <?= csrf_field() ?><input type="hidden" name="op" value="observed"><input type="hidden" name="year" value="<?= e($year) ?>">
  <label><?= e(__('التاريخ الأصلي')) ?> * <input type="date" name="original_date" required></label>
  <label><?= e(__('يوم الإجازة الفعلي')) ?> * <input type="date" name="observed_date" required></label>
  <label><?= e(__('المناسبة')) ?> <input name="title" maxlength="255" placeholder="<?= e(__('إجازة رسمية')) ?>"></label>
  <label><?= e(__('المصدر')) ?> <input name="source" maxlength="255" placeholder="<?= e(__('وزارة العمل / مجلس الوزراء')) ?>"></label>
  <label class="span2"><?= e(__('ملاحظة')) ?> <input name="note" maxlength="500"></label>
  <div class="span2"><button class="btn primary"><?= e(__('حفظ')) ?></button> <small class="muted"><?= e(__('لو المناسبة اتنقلت قبل كده، اليوم البديل القديم بيتلغى والقرار الأحدث هو اللي بيتطبق.')) ?></small></div>
</form>
<?php endif; ?>
