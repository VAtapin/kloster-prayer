<?php
// Standalone fixtures use the same compiled catalog and gettext scope as WordPress.
if (!defined('WPINC')) define('WPINC', 'wp-includes');
require_once ABSPATH . 'wp-includes/compat.php';
require_once ABSPATH . 'wp-includes/pomo/mo.php';
require_once dirname(__DIR__) . '/kloster-prayer/includes/class-i18n.php';
$GLOBALS['fixture_gettext_filters'] = [];
function fixture_translate(string $source, string $domain = 'kloster-prayer'): string {
    static $mo = null;
    if ($domain !== 'kloster-prayer') return $source;
    if (!$mo) { $mo = new MO(); $mo->import_from_file(dirname(__DIR__) . '/kloster-prayer/languages/kloster-prayer-ru_RU.mo'); }
    $value = $mo->translate($source);
    foreach ($GLOBALS['fixture_gettext_filters'] as $filter) $value = $filter($value, $source, $domain);
    return $value;
}
if (!function_exists('__')) { function __($v,$domain='kloster-prayer'){return fixture_translate($v,$domain);} }
if (!function_exists('esc_attr__')) { function esc_attr__($v,$domain='kloster-prayer'){return esc_attr(__($v,$domain));} }
if (!function_exists('determine_locale')) { function determine_locale(){return 'ru_RU';} }
if (!function_exists('add_filter')) { function add_filter($hook,$callback,...$args){if($hook==='gettext')$GLOBALS['fixture_gettext_filters'][]=$callback;} }
if (!function_exists('remove_filter')) { function remove_filter($hook,$callback,...$args){if($hook==='gettext')$GLOBALS['fixture_gettext_filters']=array_filter($GLOBALS['fixture_gettext_filters'],static fn($f)=>$f!==$callback);} }
