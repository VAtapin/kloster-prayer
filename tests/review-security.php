<?php
declare(strict_types=1);
// Two-stage real clean WordPress installation, never a production database.
$site=realpath($argv[1]??'');$source=realpath($argv[2]??dirname(__DIR__));
if(!$site||!str_contains(str_replace('\\','/',$site),'/tmp/wporg-review/wordpress')||!$source)throw new RuntimeException('Disposable site required');
$database=$argv[3]??('security-clean-'.bin2hex(random_bytes(6)).'.sqlite');
if(!preg_match('/^security-clean-[a-f0-9]{12}\.sqlite$/',$database))throw new RuntimeException('Invalid test database');
$setup=!isset($argv[3]);if($setup)define('WP_INSTALLING',true);
define('DB_FILE',$database);define('WP_PLUGIN_DIR',$source);define('WP_HTTP_BLOCK_EXTERNAL',true);
$warnings=[];set_error_handler(static function($severity,$message,$file,$line)use(&$warnings){if(error_reporting()&$severity)$warnings[]=$message.' at '.$file.':'.$line;return false;});
set_exception_handler(static function(Throwable$error):void{fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});
require $site.'/wp-load.php';
if(!WP_DEBUG)throw new RuntimeException('WP_DEBUG is required');
add_filter('pre_wp_mail',static fn()=>true);
add_filter('pre_http_request',static fn()=>new WP_Error('offline','No external calls in security review'),10,3);
require_once ABSPATH.'wp-admin/includes/upgrade.php';require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/template.php';require_once ABSPATH.'wp-admin/includes/screen.php';
$slug='georg-kloster-orthodox-donations/georg-kloster-orthodox-donations.php';
if($setup){
    wp_install('Security review','review_admin','review@example.invalid',false,'','local-review-only-password');
    $result=activate_plugin($slug);if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
    file_put_contents(dirname(__DIR__).'/tmp/security-review-database.txt',$database);
    echo "PASS fresh WordPress install and plugin activation with WP_DEBUG enabled\n";exit;
}
use GeorgKlosterOrthodoxDonate\{Admin,Database,Donation_Pages,Input,Order_Workflow,Payments,Catalog_Table,Orders_Table,Donation_Admin_Table,Pdf,Support_Pages};
require_once GeorgKlosterOrthodoxDonate\DIR.'/includes/class-catalog-table.php';
require_once GeorgKlosterOrthodoxDonate\DIR.'/includes/class-orders-table.php';
require_once GeorgKlosterOrthodoxDonate\DIR.'/includes/class-donation-admin-table.php';
function security_ensure(bool$condition,string$message):void{if(!$condition)throw new RuntimeException($message);}
if(($argv[4]??'')==='csv'){
    wp_set_current_user(1);global$wpdb;
    $wpdb->query($wpdb->prepare('UPDATE %i SET donor_name=%s,note=%s',Database::table('orders'),'=1+1','@SUM(A1:A2)'));
    $_GET=['_wpnonce'=>wp_create_nonce('gkod_csv')];$_REQUEST=$_GET;Admin::export_csv();
}
add_filter('wp_die_handler',static fn()=>static function($message){throw new RuntimeException('rejected');});
wp_set_current_user(1);update_option('gkod_notification_email','original@example.invalid');
$_POST=['gkod_action'=>'save_settings','notification_email'=>'changed@example.invalid','_gkod_nonce'=>'invalid'];
$_REQUEST=$_POST;
try{Admin::handle_actions();throw new RuntimeException('Bad nonce accepted');}catch(RuntimeException$error){security_ensure($error->getMessage()==='rejected','Admin nonce rejection');}
security_ensure(get_option('gkod_notification_email')==='original@example.invalid','No mutation with invalid nonce');
$_GET=['_wpnonce'=>'invalid'];
$_REQUEST=$_GET;
foreach([[Admin::class,'export_csv'],[Pdf::class,'download']]as$callback){
    try{$callback();throw new RuntimeException('Download nonce accepted');}catch(RuntimeException$error){security_ensure($error->getMessage()==='rejected','CSV/PDF nonce rejection');}
}
$_POST=['gkod_campaign_action'=>'save','title_ru'=>'Forged','goal'=>'10.00','status'=>'open','_wpnonce'=>'invalid'];
$_REQUEST=$_POST;
try{Donation_Pages::actions();throw new RuntimeException('Bad campaign nonce accepted');}catch(RuntimeException$error){security_ensure($error->getMessage()==='rejected','Campaign nonce rejection');}
$existing=get_user_by('login','review_subscriber');
$subscriber=$existing?$existing->ID:wp_insert_user(['user_login'=>'review_subscriber','user_pass'=>'local-review-only-password','user_email'=>'subscriber@example.invalid','role'=>'subscriber']);
wp_set_current_user((int)$subscriber);$_POST=['gkod_action'=>'save_settings','notification_email'=>'changed@example.invalid','_gkod_nonce'=>wp_create_nonce('gkod_admin_action')];Admin::handle_actions();
security_ensure(get_option('gkod_notification_email')==='original@example.invalid','Subscriber cannot change settings');
foreach([[Admin::class,'export_csv'],[Pdf::class,'download']]as$callback){
    try{$callback();throw new RuntimeException('Subscriber download accepted');}catch(RuntimeException$error){security_ensure($error->getMessage()==='rejected','CSV/PDF capability rejection');}
}
$_POST=['subscription_id'=>1,'_wpnonce'=>'invalid'];
$_REQUEST=$_POST;
try{Support_Pages::cancel_action();throw new RuntimeException('Cancellation nonce accepted');}catch(RuntimeException$error){security_ensure($error->getMessage()==='rejected','Cancellation nonce rejection');}
$_POST=['gkod_campaign_action'=>'save','title_ru'=>'Forged','goal'=>'10.00','status'=>'open','_wpnonce'=>wp_create_nonce('gkod_campaign_action')];
$_REQUEST=$_POST;
try{Donation_Pages::actions();throw new RuntimeException('Subscriber campaign access accepted');}catch(RuntimeException$error){security_ensure($error->getMessage()==='rejected','Campaign capability rejection');}
$_POST=['value'=>['nested'=>'bad'],'text'=>"<script>bad</script>\nName"];
security_ensure(Input::post('value')==='','Malformed scalar input rejected');security_ensure(!str_contains(Input::textarea('text')??'','<script>'),'Input sanitization');
wp_set_current_user(1);$_GET=['period'=>'unset'];$_POST=[];
global$wpdb;$service=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM %i LIMIT 1',Database::table('services')));
foreach(['health','rest']as$intention){
    $order=Payments::create_order(['language'=>'ru','provider'=>'stripe','intention'=>$intention,'names'=>'Name','note'=>'','donor_name'=>'','donor_email'=>'demo@example.invalid','form_id'=>0,'service_ids'=>[$service],'return_url'=>home_url('/')]);
    security_ensure(!is_wp_error($order),'Sample order');$wpdb->update(Database::table('orders'),['payment_status'=>'paid','names'=>'<script data-review>bad</script>'],['id'=>$order->id]);
}
$payload='<script data-review>bad</script>" onmouseover="bad';
$inject=static fn($translation,$source,$domain)=>$domain==='georg-kloster-orthodox-donations'?$payload:$translation;add_filter('gettext',$inject,99,3);
ob_start();Order_Workflow::calendar();$calendar=ob_get_clean();security_ensure(!str_contains($calendar,'<script data-review>'),'Calendar escapes translated labels and saved names');
foreach([new Catalog_Table('forms'),new Orders_Table(),new Donation_Admin_Table('campaigns')]as$table){foreach($table->get_columns()as$key=>$label)if($key!=='cb')security_ensure(!str_contains($label,'<script data-review>'),'List-table heading escaped');}
$table=new Catalog_Table('forms');security_ensure(!str_contains($table->column_default((object)['is_active'=>1],'status'),'<script data-review>'),'Catalog status escaped');
$table=new Orders_Table();$checkbox=$table->column_cb((object)['id'=>1]);security_ensure(!str_contains($checkbox,'" onmouseover="'),'Checkbox label escaped for attributes');
foreach([new Catalog_Table('forms'),new Orders_Table()]as$table){$method=new ReflectionMethod($table,'get_bulk_actions');foreach($method->invoke($table)as$label)security_ensure(!str_contains($label,'<script data-review>'),'Bulk-action text escaped');}
remove_filter('gettext',$inject,99);
$csv=new ReflectionMethod(Admin::class,'csv_cell');
foreach(['=1+1','+cmd','-cmd','@SUM(A1:A2)',"\t=1+1",'  =1+1',"\n=1+1"]as$value)security_ensure($csv->invoke(null,$value)==="'".$value,'CSV formula escaped');
foreach(['Name','15.00',"John\nMary",'2026-10-13']as$value)security_ensure($csv->invoke(null,$value)===$value,'Ordinary CSV cells preserved');
security_ensure($wpdb->last_error==='','Database queries succeeded');security_ensure($warnings===[],'WP_DEBUG warnings: '.implode('; ',$warnings));
echo "PASS clean WP_DEBUG startup, invalid nonces, subscriber rejection, input sanitization, translated/stored XSS, table labels and checkbox attributes\n";
