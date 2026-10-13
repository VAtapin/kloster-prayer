<?php
declare(strict_types=1);
// Only the disposable review site accepted by the shared bootstrap is used.
// Sample records stay in its cloned database. No payment or mail is sent.
require dirname(__DIR__).'/tests/bootstrap-wordpress.php';
use GeorgKlosterOrthodoxDonate\{Database,Campaigns,Donation_Pages,Support_Pages,Public_Form};
wp_set_current_user(1);
add_filter('pre_http_request',static fn()=>new WP_Error('offline','Screenshot fixture'),10,3);
add_filter('pre_wp_mail',static fn()=>true);
add_filter('locale',static fn()=>'ru_RU');
add_filter('determine_locale',static fn()=>'ru_RU');
unload_textdomain('default',true);
foreach(['ru_RU.mo','admin-ru_RU.mo']as$catalog)load_textdomain('default',WP_LANG_DIR.'/'.$catalog,'ru_RU');
$GLOBALS['wp_locale']=new WP_Locale();
unload_textdomain('georg-kloster-orthodox-donations',true);
load_textdomain('georg-kloster-orthodox-donations',GeorgKlosterOrthodoxDonate\DIR.'/languages/georg-kloster-orthodox-donations-ru_RU.mo','ru_RU');
update_option('gkod_payment_methods',['stripe','paypal']);
global $wpdb;
foreach(['campaign_expenses','campaigns','subscriptions','orders']as$table){
    $wpdb->query($wpdb->prepare('DELETE FROM %i',Database::table($table)));
}
$campaign=Campaigns::save(['title_ru'=>'Ремонт кровли храма','description_ru'=>'Помогите восстановить кровлю и сохранить храм для молитвы.','goal'=>'5000.00','status'=>'open']);
if(!is_int($campaign))throw new RuntimeException('Sample campaign could not be created');
$wpdb->insert(Database::table('orders'),['public_id'=>wp_generate_uuid4(),'campaign_id'=>$campaign,'donor_user_id'=>1,'language'=>'en','intention'=>'none','names'=>'','donor_email'=>'demo@example.invalid','amount_cents'=>185000,'payment_status'=>'paid','payment_provider'=>'stripe','created_at'=>'2026-10-10 12:00:00','paid_at'=>'2026-10-10 12:00:00']);
$order=(int)$wpdb->insert_id;
Campaigns::expense(['campaign_id'=>$campaign,'expense_amount'=>'450.00','expense_description'=>'Обследование кровли и материалы','expense_date'=>'2026-10-11']);
$wpdb->insert(Database::table('subscriptions'),['public_id'=>wp_generate_uuid4(),'donor_user_id'=>1,'initial_order_id'=>$order,'provider'=>'stripe','amount_cents'=>1500,'billing_interval'=>'month','status'=>'active','created_at'=>'2026-10-10 12:00:00','updated_at'=>'2026-10-10 12:00:00']);
$directory=dirname(__DIR__).'/tmp/directory-screenshots';wp_mkdir_p($directory);
$pages=[
    '1'=>['public',Public_Form::donation_shortcode(['lang'=>'ru'])],
    '2'=>['public',Public_Form::shortcode(['slug'=>'gebetsanliegen','lang'=>'ru','layout'=>'compact'])],
    '3'=>['public',Campaigns::report_shortcode(['id'=>$campaign,'lang'=>'ru']).Public_Form::donation_shortcode(['campaign_id'=>$campaign,'lang'=>'ru','header'=>'no'])],
    '4'=>['public',Support_Pages::form(['lang'=>'ru'])],
];
if(!defined('WP_ADMIN'))define('WP_ADMIN',true);
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
set_current_screen('georg-kloster-orthodox-donations-campaigns');
$_GET=['campaign_id'=>$campaign];ob_start();Donation_Pages::admin();$pages['5']=['admin',ob_get_clean()];
$_GET=[];ob_start();Support_Pages::admin();$pages['6']=['admin',ob_get_clean()];
foreach($pages as$number=>[$kind,$html]){
    if(!$html)throw new RuntimeException('Empty screenshot '.$number);
    file_put_contents($directory.'/screenshot-'.$number.'.html','<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Plugin screenshot</title></head><body class="'.($kind==='admin'?'wp-admin wp-core-ui':'').'">'.$html.'</body></html>');
}
if($wpdb->last_error!=='')throw new RuntimeException($wpdb->last_error);
echo "PASS six screenshots rendered by current plugin on isolated WordPress; sample data only, no external calls\n";
