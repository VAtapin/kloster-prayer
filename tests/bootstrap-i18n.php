<?php
// Standalone fixtures use the same compiled catalog and gettext scope as WordPress.
if (!defined('WPINC')) define('WPINC', 'wp-includes');
require_once ABSPATH . 'wp-includes/compat.php';
require_once ABSPATH . 'wp-includes/pomo/mo.php';
require_once dirname(__DIR__) . '/georg-kloster-orthodox-donations/includes/class-i18n.php';
$GLOBALS['fixture_gettext_filters'] = [];
function fixture_translate(string $source, string $domain = 'georg-kloster-orthodox-donations'): string {
    static $mo = null;
    if ($domain !== 'georg-kloster-orthodox-donations') return $source;
    if (!$mo) { $mo = new MO(); $mo->import_from_file($GLOBALS['fixture_mo_path'] ?? dirname(__DIR__) . '/georg-kloster-orthodox-donations/languages/georg-kloster-orthodox-donations-ru_RU.mo'); }
    $value = $mo->translate($source);
    if (!is_string($value)) $value = $source;
    foreach ($GLOBALS['fixture_gettext_filters'] as $filter) $value = $filter($value, $source, $domain);
    return $value;
}
if (!function_exists('__')) { function __($v,$domain='georg-kloster-orthodox-donations'){return fixture_translate($v,$domain);} }
if (!function_exists('esc_attr__')) { function esc_attr__($v,$domain='georg-kloster-orthodox-donations'){return esc_attr(__($v,$domain));} }
if (!function_exists('determine_locale')) { function determine_locale(){return 'ru_RU';} }
if (!function_exists('add_filter')) { function add_filter($hook,$callback,...$args){if($hook==='gettext')$GLOBALS['fixture_gettext_filters'][]=$callback;} }
if (!function_exists('remove_filter')) { function remove_filter($hook,$callback,...$args){if($hook==='gettext')$GLOBALS['fixture_gettext_filters']=array_filter($GLOBALS['fixture_gettext_filters'],static fn($f)=>$f!==$callback);} }
