<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = getenv('HR_CONFIG') ?: APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration missing.');
}
$GLOBALS['CONFIG'] = require $configFile;

date_default_timezone_set(config('timezone', 'Africa/Cairo'));
mb_internal_encoding('UTF-8');

$storage = config('storage_dir');
foreach (['logs', 'sessions', 'tmp', 'faces', 'uploads/attendance', 'uploads/profile'] as $d) {
    if (!is_dir("$storage/$d")) {
        @mkdir("$storage/$d", 0770, true);
    }
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $storage . '/logs/php-error.log');
error_reporting(E_ALL);

require __DIR__ . '/lib/i18n.php';
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/crud.php';
require __DIR__ . '/lib/attendance_calc.php';
require __DIR__ . '/lib/payroll.php';
require __DIR__ . '/lib/XlsxReader.php';
require __DIR__ . '/lib/XlsxWriter.php';
require __DIR__ . '/lib/emp_import.php';
require __DIR__ . '/lib/face.php';

function config(string $key, $default = null)
{
    return $GLOBALS['CONFIG'][$key] ?? $default;
}
