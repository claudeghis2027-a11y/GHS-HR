<div class="page-head"><div><h1><?= e(__('مراجعة صور بصمة الوجه')) ?></h1><div class="sub"><?= e(__('الصورة الجديدة بتظهر جنب الصورة الرسمية من ملف الموظف. الصور دي بيشوفها السوبر أدمن بس، وكل مرة بتتعرض بتتسجل. الرفض بيمسح الصورة والبصمة الرقمية فورًا، وبيحوّل البصمات اللي اتعملت بيها لمراجعة الـ HR.')) ?></div></div></div>
<?php if (!$rows): ?><div class="card center muted"><?= e(__('مفيش صور مستنية.')) ?></div><?php endif; ?>
<?php foreach ($rows as $r): $m = json_decode((string)$r['metrics_json'], true) ?: []; ?>
<div class="card">
  <div class="head-row"><h2><?= (int)$r['employee_id'] ?> - <?= e($r['name_ar']) ?> <small class="muted"><?= e($r['dept']) ?></small></h2>
    <small class="muted"><?= e(__('اترفعت %s', substr($r['submitted_at'], 0, 16))) ?> · <span class="badge <?= $r['has_approved'] ? 'ochre' : 'blue' ?>"><?= e(__($r['has_approved'] ? 'تغيير لصورة متوافق عليها' : 'أول صورة')) ?></span> · <?= e(__('بصمات اتعملت بيها: %d', (int)$r['punches'])) ?></small></div>
  <div class="compare">
    <figure><img class="face-img" src="<?= e(url('face_img', ['kind' => 'profile', 'id' => $r['id']])) ?>" alt="<?= e(__('الصورة الجديدة')) ?>"><figcaption><?= e(__('الصورة الجديدة')) ?></figcaption></figure>
    <figure><?php if ($r['official_photo']): ?><img class="face-img" src="<?= e(url('face_img', ['kind' => 'official', 'id' => $r['employee_id']])) ?>" alt="<?= e(__('الصورة الرسمية')) ?>"><?php else: ?><div class="face-img empty"><?= e(__('مفيش صورة رسمية في ملف الموظف')) ?></div><?php endif; ?><figcaption><?= e(__('الصورة الرسمية')) ?></figcaption></figure>
  </div>
  <p class="muted"><?= e(__('الجودة: إضاءة %s · وضوح %s · حركة الراس %s', $m['brightness'] ?? '-', $m['sharpness'] ?? '-', $m['yaw_range'] ?? '-')) ?></p>
  <form method="post" action="<?= e(url('face_review')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="approve"><button class="btn primary"><?= e(__('موافقة')) ?></button></form>
  <form method="post" action="<?= e(url('face_review')) ?>" class="inline" data-confirm="<?= e(__('الرفض هيمسح الصورة ويحوّل البصمات لمراجعة الـ HR. متأكد؟')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="reject"><input name="reason" placeholder="<?= e(__('سبب الرفض (إلزامي)')) ?>" maxlength="500" required><button class="btn danger"><?= e(__('رفض')) ?></button></form>
</div>
<?php endforeach; ?>
