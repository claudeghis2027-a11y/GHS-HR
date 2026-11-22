<?php $q = $per ? ['period' => $per['id']] : ['from' => $from, 'to' => $to]; $st = ['open' => 'مفتوحة', 'closed' => 'مقفولة']; ?>
<div class="page-head">
  <div><h1><?= e(__('تقرير الحضور الشهري')) ?></h1>
    <div class="sub"><?= e($per ? $per['name'] . ' · ' : '') ?><?= e(__('من %s لحد %s', $from, $to)) ?> · <?= e(__('%d موظف', count($rows))) ?></div></div>
</div>
<form method="get" class="filters card">
  <input type="hidden" name="r" value="att_report">
  <label><?= e(__('الفترة')) ?> <select name="period" data-autosubmit><option value=""><?= e(__('— فترة مخصصة —')) ?></option>
    <?php foreach ($periods as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= $per && (int)$per['id'] === (int)$pp['id'] ? ' selected' : '' ?>><?= e(($pp['name'] ?: '#' . $pp['id']) . ' (' . $pp['date_from'] . ' → ' . $pp['date_to'] . ') — ' . __($st[$pp['status']] ?? $pp['status'])) ?></option><?php endforeach; ?>
  </select></label>
  <label><?= e(__('من تاريخ')) ?> <input type="date" name="from" value="<?= e($from) ?>"></label>
  <label><?= e(__('لحد تاريخ')) ?> <input type="date" name="to" value="<?= e($to) ?>"></label>
  <select name="dept"><option value=""><?= e(__('كل الأقسام')) ?></option><?php foreach ($depts as $id => $n): ?><option value="<?= (int)$id ?>" <?= $dept === $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
  <button class="btn small"><?= e(__('عرض التقرير')) ?></button>
  <?php if (can('attendance', 'export')): ?><?= export_links('att_report', $q + ['dept' => $dept]) ?><?php endif; ?>
  <a class="btn small" href="<?= e(url('report', ['rep' => 'attendanceGrid', 'from' => $from, 'to' => $to])) ?>"><?= e(__('شبكة الأيام')) ?></a>
  <?php if ($per && can('period_close')): ?><a class="btn small" href="<?= e(url('period', ['id' => $per['id']])) ?>"><?= e(__($per['status'] === 'closed' ? 'نسخة الإقفال / إعادة فتح الفترة' : 'إقفال الفترة')) ?></a><?php endif; ?>
</form>
<?php if ($per && $per['status'] === 'closed'): ?><div class="flash ok"><?= e(__('الفترة دي مقفولة. الأرقام المعتمدة هي نسخة الإقفال في صفحة الفترة؛ الجدول هنا حساب حي.')) ?></div><?php endif; ?>
<?php if (!$per): ?><p class="muted"><?= e(__('ده حساب حي لمدى مخصص، مش فترة رسمية.')) ?></p><?php endif; ?>
<?php if ($noRule): ?><p class="muted"><?= e(__('تشخيصات: أيام غياب بلا قاعدة خصم: %d', $noRule)) ?></p><?php endif; ?>
<div class="table-wrap">
<table>
  <thead><tr><th><?= e(__('الكود')) ?></th><th><?= e(__('الاسم')) ?></th><th><?= e(__('القسم')) ?></th><th><?= e(__('أيام عمل')) ?></th><th><?= e(__('حضور')) ?></th>
    <?php foreach (att_breakdown_headers() as $h): ?><th><?= e($h) ?></th><?php endforeach; ?><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="22" class="muted center"><?= e(__('لا توجد بيانات.')) ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $t = $r['t']; ?>
    <tr>
      <td class="num"><?= (int)$r['emp']['id'] ?></td><td><?= e($r['emp']['name_ar']) ?></td><td><?= e($r['emp']['dept_name']) ?></td>
      <td class="num"><?= $t['work_days'] ?></td><td class="num"><?= $t['present'] ?></td>
      <?php foreach (att_breakdown_values($t) as $i => $v): $last = $i === count(ATT_BREAKDOWN) - 1; ?><td class="num"><?= $last ? '<strong>' : '' ?><?= e(is_float($v) ? num($v) : (string)$v) ?><?= $last ? '</strong>' : '' ?></td><?php endforeach; ?>
      <td><a href="<?= e(url('att_employee', ['emp' => $r['emp']['id'], 'm' => $ym])) ?>"><?= e(__('التفاصيل')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
