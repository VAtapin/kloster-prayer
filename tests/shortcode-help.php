<?php
declare(strict_types=1);
require __DIR__.'/donations.php';
use GeorgKlosterOrthodoxDonate\Shortcode_Help;
use GeorgKlosterOrthodoxDonate\I18n;
GeorgKlosterOrthodoxDonate\Campaigns::init();GeorgKlosterOrthodoxDonate\Donation_Pages::init();GeorgKlosterOrthodoxDonate\Support_Pages::init();
global $shortcode_tags;
$registered=array_keys(array_filter($shortcode_tags,static fn($tag)=>str_starts_with($tag,'gkod_'),ARRAY_FILTER_USE_KEY));
$documented=array_keys(Shortcode_Help::entries());sort($registered);sort($documented);
donation_assert($registered===$documented,'Every registered shortcode has a guide entry');
foreach(Shortcode_Help::entries() as $tag=>$entry){
    $callback=$shortcode_tags[$tag];$method=new ReflectionMethod($callback[0],$callback[1]);
    $source=implode('',array_slice(file($method->getFileName()),$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
    $actual=[];
    if(preg_match('/shortcode_atts\(\[(.*?)\]/s',$source,$match))preg_match_all("/'([a-z_]+)'\\s*=>/",$match[1],$actual);
    $keys=$actual[1]??[];$expected=$entry[1];sort($keys);sort($expected);
    donation_assert($keys===$expected,'Guide parameters match actual handler: '.$tag);
}
$titles=['en'=>'Help → Shortcodes','ru'=>'Помощь → Шорткоды','de'=>'Hilfe → Shortcodes','uk'=>'Допомога → Шорткоди','pl'=>'Pomoc → Shortcode’y'];
foreach($titles as $lang=>$title){
    $_GET=['help_lang'=>'ignored'];$html=I18n::in_language(static function(){ob_start();Shortcode_Help::page();return ob_get_clean();},$lang);
    donation_assert(str_contains($html,esc_html($title)),'Guide is translated: '.$lang);
    foreach($documented as $tag)donation_assert(str_contains($html,'['.$tag),'Shortcode present: '.$tag);
    donation_assert(substr_count($html,'class="button gkod-copy-shortcode"')===15,'All examples are copyable');
    donation_assert(!str_contains($html,'lang=&quot;')&&!str_contains($html,'<nav'),'No forced shortcode language or custom language switcher');
    donation_assert(str_contains($html,'readonly')&&str_contains($html,'role="status"'),'Manual and accessible copy feedback');
    if($lang==='ru')file_put_contents(dirname(__DIR__).'/tmp/shortcode-help-preview.html',$html);
}
$_GET=['help_lang'=>'<script>unsafe</script>'];ob_start();Shortcode_Help::page();$html=ob_get_clean();
donation_assert(!str_contains($html,'unsafe'),'Invalid language is not reflected');
$_GET=['help_lang'=>'ru'];
require_once ABSPATH.'wp-admin/includes/plugin.php';
if(!defined('WP_ADMIN'))define('WP_ADMIN',true);
GeorgKlosterOrthodoxDonate\Admin::init();GeorgKlosterOrthodoxDonate\Admin::menu();
$hook=get_plugin_page_hookname('georg-kloster-orthodox-donate-help','georg-kloster-orthodox-donate');
do_action('admin_enqueue_scripts',$hook);
donation_assert(wp_script_is('gkod-admin-shortcodes','enqueued'),'Copy script loads on the actual translated admin hook');
wp_set_current_user(0);remove_filter('wp_doing_ajax','__return_true');
$handler=static fn()=>static function(){throw new RuntimeException('help-forbidden');};add_filter('wp_die_handler',$handler);
try{Shortcode_Help::page();throw new RuntimeException('Missing help access check');}catch(RuntimeException $e){donation_assert($e->getMessage()==='help-forbidden','Guide rejects unauthenticated access');}
remove_filter('wp_die_handler',$handler);$_GET=[];
echo "PASS shortcode guide: all registered tags, five languages, examples, actual admin hook, safe language selection and access control\n";
