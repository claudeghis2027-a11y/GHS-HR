<?php
declare(strict_types=1);

/*
 * Bilingual UI (Arabic RTL / English LTR).
 * Source strings are the Arabic texts themselves; app/lang/en.php maps them to English.
 * __('… %s …', $x) translates, then fills placeholders (sprintf).
 */
const LANGS = ['ar' => ['name' => 'العربية', 'dir' => 'rtl'], 'en' => ['name' => 'English', 'dir' => 'ltr']];
const LANG_COOKIE = 'ghishr_lang';

function lang(): string
{
    if (isset($GLOBALS['LANG'])) return $GLOBALS['LANG'];
    $l = $GLOBALS['USER']['locale'] ?? null;
    if (!$l) $l = is_string($_COOKIE[LANG_COOKIE] ?? null) ? $_COOKIE[LANG_COOKIE] : null;
    if (!$l && PHP_SAPI !== 'cli') $l = preg_match('/^\s*en/i', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ? 'en' : 'ar';
    return isset(LANGS[$l]) ? $l : 'ar';
}

function set_lang(string $l): void
{
    $GLOBALS['LANG'] = isset(LANGS[$l]) ? $l : 'ar';
}

function dir_attr(): string { return LANGS[lang()]['dir']; }
function is_rtl(): bool { return dir_attr() === 'rtl'; }

function __(string $s, ...$args): string
{
    static $en = null;
    if (lang() === 'en') {
        $en ??= require __DIR__ . '/../lang/en.php';
        $s = $en[$s] ?? $s;
    }
    return $args ? vsprintf($s, $args) : $s;
}

function month_name(int $m): string { return __(AR_MONTHS[$m]); }
function day_name(int $isoDow): string { return __(AR_DAYS[$isoDow]); }

/** Strings the browser script needs, in the current language (rendered as a JSON data block, not as script). */
function js_strings(): array
{
    $keys = [
        'بص للكاميرا على طول', 'لف راسك يمين', 'لف راسك شمال', 'بص قدام تاني',
        'المتصفح ده مش بيدعم الكاميرا. استخدم Chrome أو Safari حديث.', 'مقدرناش نفتح الكاميرا. اسمح للمتصفح بالكاميرا وجرّب تاني.',
        'بنفحص الصورة…', 'المتصفح ده مش بيدعم تحديد الموقع.', 'بنحدد موقعك…', 'بنسجل…',
        'مقدرناش نحدد موقعك (%s). فعّل الـ GPS واسمح بالموقع وجرّب تاني.', 'متأكد من الحذف؟',
        'بنحدد موقعك… الدقة الحالية ±%s متر', 'تم: الدقة ±%s متر. راجع المكان على الخريطة وبعدين احفظ.',
        'الدقة ضعيفة (±%s متر). اطلع مكان مفتوح أو استنى شوية وجرّب تاني، أو كبّر نصف القطر.',
    ];
    $out = [];
    foreach ($keys as $k) $out[$k] = __($k);
    return $out;
}
