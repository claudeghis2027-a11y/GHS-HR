<?php
declare(strict_types=1);

// Front controller. Not web-served as a file: nginx passes every non-asset /hr/ request here via FastCGI.
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/pages.php';
require APP_ROOT . '/app/pages_face.php';

send_security_headers();

if (config('force_https', true) && !is_https()) {
    header('Location: https://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

start_secure_session();

$route = is_string($_GET['r'] ?? null) ? $_GET['r'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) http_error(400);
if ($method === 'POST') csrf_verify();

// Public routes
if ($route === 'login') { $method === 'POST' ? page_login_post() : page_login(); exit; }
if ($route === 'lang' && $method === 'POST') { page_lang_post(); exit; }

$GLOBALS['USER'] = current_user();
if (!$GLOBALS['USER']) {
    if ($route !== '' && $route !== 'logout') flash('err', __('سجّل دخولك الأول.'));
    redirect(url('login'));
}

// Forced password change blocks everything else.
if (user()['must_change_password'] && !in_array($route, ['password', 'logout'], true)) redirect(url('password'));

// Employees (self-service accounts) must accept the face-processing consent and submit a reference
// photo before anything else. Staff accounts linked to an employee are gated on the check-in pages only.
if (my_employee_id() !== null && !in_array($route, ['face_consent', 'face_enroll', 'password', 'logout'], true)
    && (!is_staff() || $route === 'checkin') && ($gate = face_gate_route(my_employee_id()))) {
    redirect(url($gate));
}

// route => [GET handler, POST handler]
$routes = [
    ''              => ['page_dashboard', null],
    'logout'        => [null, 'page_logout'],
    'password'      => ['page_password', 'page_password_post'],
    // generic data screens
    'list'          => [fn() => crud_list(res_key()), null],
    'form'          => [fn() => crud_form(res_key()), fn() => crud_save(res_key())],
    'delete'        => [null, fn() => crud_delete(res_key())],
    'my'            => [fn() => crud_list(res_key(), true), null],
    // employee self-service
    'checkin'       => ['page_checkin', 'page_checkin_post'],
    'my_attendance' => ['page_my_attendance', null],
    'my_salary'     => ['page_my_salary', null],
    'my_profile'    => ['page_my_profile', 'page_my_profile_post'],
    'inbox'         => ['page_inbox', 'page_inbox_post'],
    'photo'         => ['page_photo', null],
    'face_consent'  => ['page_face_consent', 'page_face_consent_post'],
    'face_enroll'   => ['page_face_enroll', 'page_face_enroll_post'],
    'face_review'   => ['page_face_review', 'page_face_review_post'],
    'face_img'      => ['page_face_img', null],
    'face_hr'       => ['page_face_hr', 'page_face_hr_post'],
    'face_stats'    => ['page_face_stats', null],
    'styleguide'    => ['page_styleguide', null],
    'emp_photo'     => [null, 'page_emp_photo_post'],
    // staff
    'att_report'    => ['page_att_report', null],
    'att_employee'  => ['page_att_employee', null],
    'payroll'       => ['page_payroll', 'page_payroll_close'],
    'requests'      => ['page_requests', 'page_requests_post'],
    'emp_import'    => ['page_emp_import', 'page_emp_import_post'],
    'emp_template'  => ['page_emp_template', null],
    'audit'         => ['page_audit', null],
    // super admin
    'users'         => ['page_users', 'page_users_post'],
    'roles'         => ['page_roles', 'page_roles_post'],
    'settings'      => ['page_settings', 'page_settings_post'],
];

if (!isset($routes[$route])) http_error(404);
$handler = $routes[$route][$method === 'POST' ? 1 : 0];
if ($handler === null) http_error($method === 'POST' ? 400 : 404);

try {
    $handler();
} catch (Throwable $ex) {
    error_log(safe_error($ex));
    http_error(500, __('حصل خطأ غير متوقع.'));
}

function res_key(): string
{
    $k = $_GET['res'] ?? '';
    return is_string($k) && preg_match('/^[a-z_]{1,40}$/', $k) ? $k : '';
}
