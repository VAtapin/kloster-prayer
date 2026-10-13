<?php
declare(strict_types=1);
require __DIR__.'/campaigns.php';
use GeorgKlosterOrthodoxDonate\Campaigns;
use GeorgKlosterOrthodoxDonate\Donation_Pages;
use GeorgKlosterOrthodoxDonate\I18n;
use GeorgKlosterOrthodoxDonate\Admin;
if(!defined('WP_ADMIN'))define('WP_ADMIN',true);
// UI translations use the normal WordPress domain loader, independently of content language.
load_textdomain('georg-kloster-orthodox-donations',GeorgKlosterOrthodoxDonate\DIR.'/languages/georg-kloster-orthodox-donations-ru_RU.mo');
$current='ru';add_filter('wpml_current_language',static function()use(&$current){return $current;});
add_filter('wpml_default_language',static fn()=>'ru');
$ru=Campaigns::save(['title_ru'=>'Русское название','description_ru'=>'Русское описание','goal'=>'100.00','status'=>'draft']);
donation_assert(is_int($ru),'Create a Russian-only campaign without an English title');
donation_assert(Campaigns::get($ru)->title_en==='','No automatic English copy');
$current='de';Campaigns::save(['campaign_id'=>$ru,'title_de'=>'Deutscher Titel','description_de'=>'Beschreibung','goal'=>'100.00','status'=>'open']);
donation_assert(Campaigns::get($ru)->title_ru==='Русское название','Saving German preserves Russian');
$current='ru';Campaigns::save(['campaign_id'=>$ru,'title_ru'=>'Новое название','goal'=>'100.00','status'=>'open']);
donation_assert(Campaigns::get($ru)->title_de==='Deutscher Titel'&&Campaigns::get($ru)->description_ru==='Русское описание','Saving Russian preserves German and omitted descriptions');
foreach(['ru','de'] as $lang){
    $current=$lang;donation_assert(array_keys(Admin::editor_languages())===[$lang],'Only current WPML language in existing editors');
    $_GET=['campaign_id'=>$ru];ob_start();Donation_Pages::admin();$html=ob_get_clean();
    foreach(['en','ru','de','uk','pl'] as $code)donation_assert(str_contains($html,'name="title_'.$code.'"')===($code===$lang),'Campaign shows only current language');
    donation_assert(str_contains($html,'class="form-table"')&&str_contains($html,'for="title_'.$lang.'"'),'Standard form table and connected labels');
    foreach(['forms','services'] as $kind){
        $_GET=['edit'=>1];ob_start();Admin::$kind();$form=ob_get_clean();
        donation_assert(str_contains($form,'name="title_'.$lang.'"')&&!str_contains($form,'name="title_'.($lang==='ru'?'de':'ru').'"'),'Existing editor is single-language');
        donation_assert(!str_contains($form,'gkod-native-editor')&&str_contains($form,'class="form-table"'),'Existing editor uses core form classes');
        file_put_contents(dirname(__DIR__).'/tmp/native-'.$kind.'-'.$lang.'.html',$form);
    }
    $_GET=['campaign_id'=>$ru];ob_start();Donation_Pages::admin();$html=ob_get_clean();
    file_put_contents(dirname(__DIR__).'/tmp/native-campaign-'.$lang.'.html',$html);
}
$current='all';donation_assert(I18n::content_language()==='ru','WPML all resolves to site default without displaying multiple languages');
$current=null;donation_assert(I18n::content_language()===I18n::language(),'Without a language plugin content follows WordPress');
$current='de';$_GET=['help_lang'=>'pl'];ob_start();GeorgKlosterOrthodoxDonate\Shortcode_Help::page();$help=ob_get_clean();
donation_assert(!str_contains($help,'<nav')&&!str_contains($help,'lang=&quot;pl'),'Help ignores custom language override and does not force shortcode language');
donation_assert(str_contains($help,'Помощь'),'Help stays in the WordPress UI language when content language changes');
$_GET=[];ob_start();Donation_Pages::admin();$list=ob_get_clean();
donation_assert(str_contains($list,'wp-list-table')&&str_contains($list,'tablenav')&&!str_contains($list,'name="title_ru"'),'Native campaign list is separate from its editor');
file_put_contents(dirname(__DIR__).'/tmp/native-campaign-list.html',$list);
ob_start();GeorgKlosterOrthodoxDonate\Support_Pages::admin();$support=ob_get_clean();
donation_assert(str_contains($support,'wp-list-table')&&str_contains($support,'no-items')&&str_contains($support,'tablenav'),'Empty support list uses native empty state and navigation');
file_put_contents(dirname(__DIR__).'/tmp/native-support-empty.html',$support);
global $wpdb;
$wpdb->insert(GeorgKlosterOrthodoxDonate\Database::table('subscriptions'),['public_id'=>wp_generate_uuid4(),'donor_user_id'=>1,'initial_order_id'=>1,'provider'=>'stripe','amount_cents'=>1000,'billing_interval'=>'month','status'=>'active','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
ob_start();GeorgKlosterOrthodoxDonate\Support_Pages::admin();$support=ob_get_clean();
donation_assert(str_contains($support,'name="subscription_id"')&&str_contains($support,'_wpnonce'),'Native support table keeps protected cancellation');
file_put_contents(dirname(__DIR__).'/tmp/native-support-filled.html',$support);
require_once GeorgKlosterOrthodoxDonate\DIR.'/includes/class-donation-admin-table.php';
for($i=0;$i<22;$i++)Campaigns::save(['title_ru'=>'Сбор '.$i,'goal'=>'10.00','status'=>'draft']);
$_GET=['paged'=>2];$table=new GeorgKlosterOrthodoxDonate\Donation_Admin_Table('campaigns');$table->prepare_items();
donation_assert(count($table->items)>0&&count($table->items)<=20,'Native pagination exposes records after the first page');
donation_assert($wpdb->last_error==='','No database errors');
echo "PASS native admin: current WPML content language, hidden translation preservation, Russian-only creation, core forms/lists, empty state, pagination and cancellation nonce\n";
