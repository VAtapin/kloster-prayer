<?php
declare(strict_types=1);
// Both actual plugin packages are active during normal WordPress startup.
$site=realpath($argv[1]??'');$source=realpath($argv[2]??'');
if(!$site||!str_contains(str_replace('\\','/',$site),'/tmp/wporg-review/wordpress')||!$source||!str_contains(str_replace('\\','/',$source),'/tmp/coexistence-check'))throw new RuntimeException('Disposable coexistence fixture required');
$file='coexistence-'.bin2hex(random_bytes(6)).'.sqlite';
$db=new PDO('sqlite:'.$site.'/wp-content/database/.ht.sqlite');$db->exec('VACUUM INTO '.$db->quote($site.'/wp-content/database/'.$file));unset($db);
$db=new PDO('sqlite:'.$site.'/wp-content/database/'.$file);
$active=['kloster-prayer/kloster-prayer.php','georg-kloster-orthodox-donate/georg-kloster-orthodox-donate.php'];
if(($argv[3]??'')==='reverse')$active=array_reverse($active);
$options=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%_options' LIMIT 1")->fetchColumn();
if(!is_string($options)||!preg_match('/^[a-zA-Z0-9_]+$/',$options))throw new RuntimeException('Options fixture missing');
$statement=$db->prepare('UPDATE "'.$options.'" SET option_value=? WHERE option_name=\'active_plugins\'');$statement->execute([serialize($active)]);unset($db);
define('DB_FILE',$file);define('WP_PLUGIN_DIR',$source);define('WP_ADMIN',true);
require $site.'/wp-load.php';
set_exception_handler(static function(Throwable $error):void{fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});
function coexist_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
coexist_assert(class_exists(KlosterPrayer\Database::class)&&class_exists(GeorgKlosterOrthodoxDonate\Database::class),'Both PHP namespaces load');
KlosterPrayer\Database::activate();GeorgKlosterOrthodoxDonate\Database::activate();
coexist_assert(KlosterPrayer\Database::table('orders')!==GeorgKlosterOrthodoxDonate\Database::table('orders'),'Separate database tables');
global $shortcode_tags;
foreach(['form','prayer_form','donation','service','payment_result','campaigns','campaign_report','donation_history','support']as$tag){
    coexist_assert(isset($shortcode_tags['kloster_'.$tag],$shortcode_tags['gkod_'.$tag]),'Separate shortcode: '.$tag);
    coexist_assert(str_starts_with($shortcode_tags['kloster_'.$tag][0],'KlosterPrayer\\')&&str_starts_with($shortcode_tags['gkod_'.$tag][0],'GeorgKlosterOrthodoxDonate\\'),'Correct shortcode ownership');
}
wp_set_current_user(1);
update_option('klp_stripe_secret','legacy-isolated');update_option('gkod_stripe_secret','parish-isolated');
coexist_assert(get_option('klp_stripe_secret')==='legacy-isolated'&&get_option('gkod_stripe_secret')==='parish-isolated','Separate payment settings');
do_action('wp_enqueue_scripts');
$old=do_shortcode('[kloster_donation]');$new=do_shortcode('[gkod_donation]');
coexist_assert(str_contains($old,'class="klp-form ')&&!str_contains($old,'class="gkod-form '),'Old form remains old');
coexist_assert(str_contains($new,'class="gkod-form ')&&!str_contains($new,'class="klp-form '),'New form has isolated selectors');
coexist_assert(wp_script_is('kloster-prayer','enqueued')&&wp_script_is('georg-kloster-orthodox-donate','enqueued'),'Both script handles coexist');
require_once ABSPATH.'wp-admin/includes/plugin.php';
do_action('admin_menu');global $menu;
$slugs=array_column($menu,2);coexist_assert(in_array('kloster-prayer',$slugs,true)&&in_array('georg-kloster-orthodox-donate',$slugs,true),'Separate admin menus');
$routes=rest_get_server()->get_routes();
coexist_assert(isset($routes['/kloster-prayer/v1/stripe/webhook'],$routes['/georg-kloster-orthodox-donate/v1/stripe/webhook']),'Separate signed webhook routes');
coexist_assert(has_action('wp_ajax_klp_create_payment')!==false&&has_action('wp_ajax_gkod_create_payment')!==false,'Separate payment AJAX handlers');
coexist_assert(wp_script_is('kloster-prayer','registered')&&wp_script_is('georg-kloster-orthodox-donate','registered'),'Separate script registration');
file_put_contents(dirname(__DIR__).'/tmp/coexistence-forms.html',$old.$new);
$scripts=wp_scripts();
file_put_contents(dirname(__DIR__).'/tmp/coexistence-config.js',$scripts->get_data('kloster-prayer','data')."\n".$scripts->get_data('georg-kloster-orthodox-donate','data'));
echo "PASS coexistence: both startup orders, namespaces, tables, settings, nine shortcodes, form selectors, scripts, menus and webhook routes\n";
