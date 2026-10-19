<?php
declare(strict_types=1);
/*
 * Developer tool for the bilingual UI.
 *   php bin/i18n_tool.php list <file...>          list Arabic string literals (token-accurate)
 *   php bin/i18n_tool.php wrap <file...>          wrap Arabic literals in __() (skips SQL, consts, raw-list)
 *   php bin/i18n_tool.php keys                    print every __('…') key used under app/ + public/
 *   php bin/i18n_tool.php check                   keys missing from app/lang/en.php (exit 1 if any)
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/bootstrap.php';
$ROOT = dirname(__DIR__);
$cmd = $argv[1] ?? '';
$files = array_slice($argv, 2);

function is_ar(string $s): bool { return (bool)preg_match('/\p{Arabic}/u', $s); }

/** Values that are data (DB values, Excel headers/values), never UI text. */
const RAW = [
    'تنبيه', 'عام', 'رفع ملف Excel', 'طلب تعديل من الموظف', 'صورة سابقة', 'صورة جديدة', 'إضافة موظف جديد', 'تم الإنشاء',
    'الراتب الأساسي', 'نعم', 'لا', 'كاش', 'فيزا', 'الموظفين', 'القوائم', 'تعليمات', 'كل الموظفين', 'تقرير حضور',
];

function each_ar_literal(string $code, callable $fn): string
{
    $toks = token_get_all($code);
    $out = '';
    $prevSig = [];
    $inConst = false;
    foreach ($toks as $i => $t) {
        if (is_array($t)) {
            [$id, $text] = $t;
            if ($id === T_CONST) $inConst = true;
            if ($id === T_CONSTANT_ENCAPSED_STRING && is_ar($text)) {
                $out .= $fn($text, $inConst, $prevSig);
            } else {
                $out .= $text;
            }
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $prevSig[] = $text;
        } else {
            if ($t === ';') $inConst = false;
            $out .= $t;
            $prevSig[] = $t;
        }
        if (count($prevSig) > 4) array_shift($prevSig);
    }
    return $out;
}

function unquote(string $lit): string
{
    return $lit[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($lit, 1, -1)) : stripcslashes(substr($lit, 1, -1));
}

switch ($cmd) {
    case 'list':
        foreach ($files as $f) {
            each_ar_literal(file_get_contents($f), function ($lit, $inConst, $prev) use ($f) {
                echo basename($f), ($inConst ? ' [const]' : ''), ' | ', end($prev), ' | ', $lit, "\n";
                return $lit;
            });
        }
        break;
    case 'wrap':
        foreach ($files as $f) {
            $n = 0;
            $code = each_ar_literal(file_get_contents($f), function ($lit, $inConst, $prev) use (&$n) {
                $v = unquote($lit);
                $last = end($prev);
                if ($inConst || in_array($v, RAW, true) || preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|VALUES)\b/', $v)
                    || $last === '__(' || $last === '(' && prev($prev) === '__') return $lit;
                $n++;
                return "__($lit)";
            });
            file_put_contents($f, $code);
            echo basename($f), ": wrapped $n\n";
        }
        break;
    case 'keys':
    case 'check':
        $keys = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app'));
        $all = array_merge(iterator_to_array($it), [new SplFileInfo($ROOT . '/public/index.php')]);
        foreach ($all as $file) {
            if (!str_ends_with((string)$file, '.php') || str_contains((string)$file, '/lang/')) continue;
            $toks = token_get_all(file_get_contents((string)$file));
            foreach ($toks as $i => $t) {
                if (is_array($t) && $t[0] === T_STRING && in_array($t[1], ['__', 'tr'], true)) {
                    for ($j = $i + 1; $j < count($toks) && (!is_array($toks[$j]) || $toks[$j][0] === T_WHITESPACE) && $toks[$j] !== '('; $j++);
                    for ($j++; $j < count($toks) && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE; $j++);
                    if (isset($toks[$j]) && is_array($toks[$j]) && $toks[$j][0] === T_CONSTANT_ENCAPSED_STRING) $keys[unquote($toks[$j][1])] = true;
                }
            }
        }
        // labels/titles/options declared in resources.php and other data arrays are translated at render time
        foreach (require $ROOT . '/app/resources.php' as $r) {
            $keys[$r['title']] = true;
            foreach ($r['fields'] as $f) {
                $keys[$f['label']] = true;
                if (!empty($f['hint'])) $keys[$f['hint']] = true;
                foreach ($f['options'] ?? [] as $o) if (is_ar((string)$o)) $keys[$o] = true;
            }
        }
        $extra = require $ROOT . '/app/lang/extra_keys.php';
        foreach ($extra as $k) $keys[$k] = true;
        $keys = array_keys(array_filter($keys, fn($_, $k) => is_ar((string)$k), ARRAY_FILTER_USE_BOTH));
        if ($cmd === 'keys') { foreach ($keys as $k) echo $k, "\n"; break; }
        $en = require $ROOT . '/app/lang/en.php';
        $missing = array_values(array_filter($keys, fn($k) => !isset($en[$k])));
        $unused = array_values(array_diff(array_keys($en), $keys));
        echo count($keys), " keys, ", count($missing), " missing, ", count($unused), " unused\n";
        foreach ($missing as $m) echo "MISSING: ", var_export($m, true), "\n";
        exit($missing ? 1 : 0);
    default:
        fwrite(STDERR, "usage: list|wrap|keys|check\n");
        exit(2);
}
