<?php
/** @var string $content */
$u = $GLOBALS['USER'] ?? null;
$route = $_GET['r'] ?? '';
$nav = [];
if ($u) {
    $nav[] = ['الرئيسية', url(), $route === ''];
    if ($u['employee_id']) {
        $nav[] = ['حسابي', null, false];
        $nav[] = ['تسجيل الحضور', url('checkin'), $route === 'checkin'];
        if (face_enabled()) $nav[] = ['صورة البصمة', url('face_enroll'), $route === 'face_enroll'];
        $nav[] = ['حضوري', url('my_attendance'), $route === 'my_attendance'];
        $nav[] = ['مرتبي', url('my_salary'), $route === 'my_salary'];
        $nav[] = ['الإشعارات', url('inbox'), $route === 'inbox' || $route === 'notif_view', function_exists('inbox_unread') ? inbox_unread((int)$u['employee_id']) : 0];
        $nav[] = ['بياناتي', url('my_profile'), $route === 'my_profile'];
        $nav[] = ['إجازاتي', url('my', ['res' => 'leaves']), $route === 'my' && ($_GET['res'] ?? '') === 'leaves'];
        $nav[] = ['مستنداتي', url('my', ['res' => 'employee_documents']), $route === 'my' && ($_GET['res'] ?? '') === 'employee_documents'];
        $nav[] = ['سلفي', url('my', ['res' => 'advances']), $route === 'my' && ($_GET['res'] ?? '') === 'advances'];
    }
    $groups = [
        ['الموظفين', [['الموظفين', 'list', 'employees'], ['طلبات تعديل البيانات', 'requests', null], ['رفع من Excel', 'emp_import', null], ['الأقسام', 'list', 'departments'], ['المسميات الوظيفية', 'list', 'job_titles'], ['التخصصات', 'list', 'specializations'], ['سجل التعديلات', 'list', 'employee_change_log']]],
        ['الحضور', [['اقفال شهري', 'att_report', null], ['إعفاءات التأخير والخصومات', 'att_adjust', null], ['شبكة حضور الفترة', 'report', 'attendanceGrid'], ['مراجعة بصمات الوجه', 'face_hr', null], ['سجل البصمة', 'list', 'attendance'], ['الإجازات', 'list', 'leaves'], ['الأذونات', 'list', 'permission_requests'], ['تعديلات الحضور', 'list', 'attendance_adjustments'], ['نسيان البصمة', 'list', 'forgot_punch_log'], ['إعفاءات التأخير', 'list', 'late_exemptions'], ['الجزاءات', 'list', 'extra_deductions'], ['سجل محاولات بصمة الوجه', 'list', 'face_match_log']]],
        ['القواعد', [['نقاط الحضور', 'list', 'attendance_points'], ['القواعد', 'sched_rules', null], ['أرشيف قواعد المواعيد', 'list', 'schedule_rules_archive'], ['مواعيد خاصة', 'list', 'employee_schedules'], ['قواعد الخصم', 'list', 'deduction_rules'], ['تقويم العطلات الرسمية', 'holiday_calendar', null], ['العطلات الرسمية', 'list', 'holidays'], ['استثناءات الانصراف', 'list', 'checkout_exemptions'], ['السنوات الدراسية', 'list', 'academic_years']]],
        ['المرتبات', [['كشف المرتبات', 'payroll', null], ['فترات المرتبات والإقفال', 'periods', null], ['الرواتب الأساسية', 'basic_salaries', null], ['اعتماد العلاوة', 'raises', null], ['حركات المرتب', 'list', 'salary_tx'], ['أنواع الحركات', 'list', 'salary_tx_types'], ['السلف', 'advances_report', null], ['خصم الصيف', 'summer', null], ['سجل خصم الصيف', 'list', 'summer_deduction_log'], ['إعدادات المرتبات للسنة', 'list', 'payroll_year_settings'], ['سياسة خصم الربعين', 'quarter_policy', null]]],
        ['الشئون القانونية', [['التحقيقات', 'inv', null], ['مستندات الموظفين', 'list', 'employee_documents'], ['تقارير المستندات', 'doc_reports', null], ['أنواع المستندات', 'list', 'document_types']]],
        ['التقارير والرسائل', [['مركز التقارير', 'reports', null], ['رسائل واتساب', 'whatsapp', null], ['إدارة الإشعارات', 'list', 'notifications']]],
        ['الرقابة', [['سجل التدقيق', 'audit', null], ['النسخ الاحتياطي', 'backup', null]]],
    ];
    // Each item shows by its own (fine) permission; a group shows when any of its items does.
    $pageMod = ['requests' => 'employees', 'emp_import' => 'employees', 'att_report' => 'attendance', 'att_adjust' => 'attendance_adjust', 'face_hr' => 'attendance', 'holiday_calendar' => 'rules', 'sched_rules' => 'rules',
        'payroll' => 'payroll', 'periods' => 'period_close', 'basic_salaries' => 'salary', 'raises' => 'salary', 'advances_report' => 'advances', 'summer' => 'salary', 'quarter_policy' => 'salary',
        'doc_reports' => 'docs_reports', 'inv' => 'investigations', 'audit' => 'audit', 'whatsapp' => 'whatsapp', 'backup' => 'backup'];
    foreach ($groups as [$label, $items]) {
        $shown = [];
        foreach ($items as [$t, $r, $res]) {
            if ($r === 'list') { if (!can(resource($res)['module'])) continue; }
            elseif ($r === 'report') { if (!report_allowed($res)) continue; }
            elseif ($r === 'reports') { if (!reports_catalog()) continue; }
            elseif (!can($pageMod[$r] ?? $r)) continue;
            if ($r === 'requests' && !is_manager()) continue;
            if ($r === 'emp_import' && !can('employees', 'create') && !can('employees', 'update')) continue;
            if ($r === 'face_hr' && !can('attendance', 'update')) continue;
            $cnt = $r === 'face_hr' ? (int)q_val("SELECT COUNT(*) FROM employee_review_state WHERE face_review_status = 'hr_review'") : 0;
            $href = $r === 'list' ? url($r, ['res' => $res]) : ($r === 'report' ? url($r, ['rep' => $res]) : url($r));
            $on = $route === $r && ($res === null || ($_GET[$r === 'report' ? 'rep' : 'res'] ?? '') === $res);
            $shown[] = [$t, $href, $on, $cnt];
        }
        if ($shown) { $nav[] = [$label, null, false]; array_push($nav, ...$shown); }
    }
    if (is_manager()) {
        $nav[] = ['إدارة النظام', null, false];
        $nav[] = ['مراجعة صور البصمة', url('face_review'), $route === 'face_review', (int)q_val("SELECT COUNT(*) FROM face_profiles WHERE status = 'pending'")];
        $nav[] = ['إحصائيات بصمة الوجه', url('face_stats'), $route === 'face_stats'];
        $nav[] = ['المستخدمين', url('users'), $route === 'users'];
        $nav[] = ['الصلاحيات', url('roles'), $route === 'roles'];
        $nav[] = ['الإعدادات', url('settings'), $route === 'settings'];
        $nav[] = ['البنوك', url('list', ['res' => 'banks']), in_array($route, ['list', 'form'], true) && ($_GET['res'] ?? '') === 'banks'];
        $nav[] = ['استيراد ملفات Drive', url('drive_import'), $route === 'drive_import'];
        if (is_super()) $nav[] = ['إعادة ضبط البيانات', url('data_reset'), $route === 'data_reset'];
        $nav[] = ['دليل التصميم', url('styleguide'), $route === 'styleguide'];
    }
    $displayName = $u['display_name'] ?: ($u['employee_id'] ? q_val('SELECT name_ar FROM employees WHERE id = ?', [$u['employee_id']]) : $u['username']);
}
?><!doctype html>
<html lang="<?= e(lang()) ?>" dir="<?= e(dir_attr()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1E4CA1">
<title><?= e(__($title ?? '') . ' — ' . __('مدارس جرين هايتس')) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('img/icon-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('img/icon-180.png')) ?>">
<link rel="preload" href="<?= e(asset(lang() === 'ar' ? 'fonts/plex-arabic-400.woff2' : 'fonts/plex-latin-400.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('print.css')) ?>" media="print">
<script src="<?= e(asset('app.js')) ?>" defer></script>
<script type="application/json" id="i18n"><?= json_encode(js_strings(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="<?= $u ? 'app' : 'guest' ?>">
<?php if ($u): ?>
<header class="topbar">
  <button class="menu-btn" type="button" data-toggle-nav aria-label="<?= e(__('القائمة')) ?>">☰</button>
  <a class="brand" href="<?= e(url()) ?>">
    <img src="<?= e(asset('img/logo-480.png')) ?>" alt="Green Heights Schools" width="80" height="40">
    <span class="brand-text"><strong><?= e(__('الموارد البشرية')) ?></strong><span><?= e(__('مدارس جرين هايتس')) ?></span></span>
  </a>
  <span class="spacer"></span>
  <?php if (($route ?? '') !== 'checkin'): // one print entry for every page; a page with more rows than it shows hands over a full printable version
      if (!empty($printUrl)): ?><a class="btn small ghost" href="<?= e($printUrl) ?>" target="_blank" rel="noopener" title="<?= e(__('اطبع الصفحة دي أو احفظها PDF')) ?>">🖨 <?= e(__('طباعة / PDF')) ?></a>
      <?php else: ?><button type="button" class="btn small ghost" data-print title="<?= e(__('اطبع الصفحة دي أو احفظها PDF')) ?>">🖨 <?= e(__('طباعة / PDF')) ?></button><?php endif; ?>
  <?php endif; ?>
  <?php require __DIR__ . '/_lang_switch.php'; ?>
  <div class="user-chip">
    <span class="avatar" aria-hidden="true"><?= e(mb_substr((string)$displayName, 0, 1)) ?></span>
    <span class="who"><b><?= e($displayName) ?></b><span><?= e(__($u['role_name'])) ?><?= $u['employee_id'] ? ' · ' . e(__('كود %d', (int)$u['employee_id'])) : '' ?></span></span>
  </div>
  <form method="post" action="<?= e(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn small ghost"><?= e(__('خروج')) ?></button></form>
</header>
<div class="shell">
  <nav class="side" id="side" aria-label="<?= e(__('القائمة')) ?>">
    <?php foreach ($nav as $item): [$t, $href, $active] = $item; $count = $item[3] ?? 0; ?>
      <?php if ($href === null): ?><div class="nav-h"><?= e(__($t)) ?></div>
      <?php else: ?><a href="<?= e($href) ?>" class="<?= $active ? 'on' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e(__($t)) ?><?php if ($count): ?><span class="nav-count"><?= (int)$count ?></span><?php endif; ?></a><?php endif; ?>
    <?php endforeach; ?>
    <div class="side-foot"><a href="<?= e(url('password')) ?>"><?= e(__('تغيير كلمة المرور')) ?></a></div>
  </nav>
  <main class="main">
    <div class="ph-wrap" hidden><?php $phTitle = __((string)($title ?? '')); require __DIR__ . '/_print_head.php'; ?></div>
<?php else: ?>
<main class="<?= !empty($bare) ? '' : 'guest-main' ?>">
<?php endif; ?>
    <?php foreach (take_flashes() as [$type, $msg]): ?>
      <div class="flash <?= $type === 'ok' ? 'ok' : 'err' ?>" role="status"><?= e($msg) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
<?php if ($u): ?></div><?php endif; ?>
<?php if ($u && $u['employee_id'] && empty($u['is_staff'])): // old employee app: every screen carries the employee code and the time it was opened
  $wm = (int)$u['employee_id'] . ' · ' . date('Y-m-d H:i'); ?>
<div class="wm" aria-hidden="true"><?php for ($i = 0; $i < 40; $i++): ?><span><?= e($wm) ?></span><?php endfor; ?></div>
<?php endif; ?>
</body>
</html>
