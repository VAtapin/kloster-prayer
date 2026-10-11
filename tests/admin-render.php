<?php
// Isolated render fixture. Uses the real WordPress WP_List_Table implementation;
// only storage, authentication and environment functions are replaced.
declare(strict_types=1);
define('ABSPATH',dirname(__DIR__).'/tmp/admin-check-core/wordpress/');
define('KlosterPrayer\\DIR',dirname(__DIR__).'/kloster-prayer');
define('KlosterPrayer\\FILE',KlosterPrayer\DIR.'/kloster-prayer.php');
require KlosterPrayer\DIR.'/includes/class-payments.php';
require KlosterPrayer\DIR.'/includes/class-notifications.php';
require KlosterPrayer\DIR.'/includes/class-service-images.php';
require __DIR__.'/bootstrap-i18n.php';
function sanitize_file_name($s){return preg_replace('/[^a-z0-9-]/','',$s);}
function plugins_url($path,$file){return '../kloster-prayer/'.$path;}
function wp_get_attachment_image_url($id,$size){return false;}
$_SERVER['HTTP_HOST']='localhost';$_SERVER['REQUEST_URI']='/admin';$_SERVER['HTTPS']='off';
$GLOBALS['fixture_languages']=['ru_RU','de_DE'];
function pll_languages_list($args){return $GLOBALS['fixture_languages'];}
function get_locale(){return 'ru_RU';}function determine_locale(){return get_locale();}
function __($v,$domain='kloster-prayer'){return fixture_translate($v,$domain);}function _x($v,...$args){return $v;}function _e($v,...$args){echo $v;}function _n($a,$b,$n){return $n==1?$a:$b;}
function esc_html__($v,...$args){return esc_html(__($v,...$args));}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}function esc_html($v){return esc_attr($v);}function esc_textarea($v){return esc_attr($v);}function esc_url($v){return esc_attr($v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}function sanitize_text_field($v){return strip_tags((string)$v);}function wp_unslash($v){return $v;}function absint($v){return abs((int)$v);}function wp_parse_args($args,$defaults=[]){return array_merge($defaults,(array)$args);}
function add_filter($hook,$callback,...$args){if($hook==='gettext')$GLOBALS['fixture_gettext_filters'][]=$callback;}function add_action(...$args){}function apply_filters($hook,$value,...$args){return $value;}function current_user_can(...$args){return true;}
function wp_nonce_field(...$args){echo '<input type="hidden" name="_wpnonce" value="fixture">';}function wp_create_nonce($a){return 'fixture';}function wp_nonce_url($url,...$args){return $url;}
function admin_url($path=''){return 'http://localhost/wp-admin/'.$path;}function rest_url($path=''){return 'https://example.org/wp-json/'.$path;}function plugin_basename($f){return 'kloster-prayer/kloster-prayer.php';}
function add_query_arg($args,$url=''){if(!is_array($args))return (string)$url;return ($url?:'http://localhost/admin').(str_contains($url,'?')?'&':'?').http_build_query($args);}function remove_query_arg($args,$url=''){return $url?:'http://localhost/admin';}function set_url_scheme($u){return $u;}
function selected($a,$b,$echo=true){$s=(string)$a===(string)$b?'selected="selected"':'';if($echo)echo $s;return $s;}function checked($a,$b,$echo=true){$s=(string)$a===(string)$b?'checked="checked"':'';if($echo)echo $s;return $s;}
function number_format_i18n($v,$d=0){return number_format((float)$v,$d,',','.');}function wp_date($f,...$a){return date($f,strtotime('2026-09-08'));}function current_time($v){return '2026-09-08 12:00:00';}
function get_option($key,$default=false){return ['klp_payment_methods'=>['stripe'],'klp_stripe_secret'=>'fixture-key','klp_stripe_webhook_secret'=>'fixture-webhook'][$key]??$default;}
function get_bloginfo($key){return $key==='version'?'7.1':'Fixture';}function wp_next_scheduled($key){return 1;}
function wp_json_encode($v){return json_encode($v);}function wp_kses_post($v){return $v;}function paginate_links($args){return '';}
function submit_button($text='Apply',$type='primary',$name='submit',$wrap=true,$attrs=[]){$html='<input type="submit" class="button button-'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($text).'">';echo $wrap?'<p class="submit">'.$html.'</p>':$html;}
function _admin_search_query(){echo esc_attr($_REQUEST['s']??'');}function wp_doing_ajax(){return false;}function wp_removable_query_args(){return [];}
function get_user_option(...$a){return false;}function get_user_setting($a,$default=''){return $default;}function get_hidden_columns($s){return [];}function wp_strip_all_tags($s){return strip_tags($s);}
class FixtureScreen {public $id='kloster-prayer';public $base='kloster-prayer';public function render_screen_reader_content($key){} }
function convert_to_screen($s){return new FixtureScreen();}
class FixtureDB {
    public $prefix='wp_';public $queries=[];
    public function prepare($q,...$args){$this->queries[]=[$q,$args];while(str_contains($q,'%i')){$table=array_shift($args);$q=preg_replace('/%i/','`'.str_replace('`','``',(string)$table).'`',$q,1);}return $q;}
    public function esc_like($s){return addcslashes($s,'_%');}
    public function get_var($q){if(str_contains($q,'SHOW TABLES'))return 'wp_klp_orders';return 2;}
    public function get_col($q){return str_contains($q,'service_id FROM')?[1,2]:['Акафист'];}
    public function get_row($q){if(str_contains($q,'SUM('))return (object)['total'=>2,'active'=>2,'inactive'=>0,'paid'=>1,'printed'=>0,'revenue'=>1500];return $this->row();}
    public function row(){return (object)['id'=>1,'slug'=>'akathist','title_ru'=>'Акафист','title_de'=>'Akathistos','title_uk'=>'Акафіст','title_pl'=>'Akatyst','intro_ru'=>'Подайте имена для церковной молитвы.','intro_de'=>'Reichen Sie Namen für das kirchliche Gebet ein.','layout'=>'cards','is_active'=>1,'default_intention'=>'health','price_cents'=>1500,'names_per_sheet'=>10,'sort_order'=>10,'intention_mode'=>'both','pricing_mode'=>'per_sheet','image_id'=>0,'updated_at'=>'2026-09-08 12:00:00','created_at'=>'2026-09-08 12:00:00','billing_name'=>'','donor_name'=>'','billing_email'=>'','donor_email'=>'donor@example.org','billing_phone'=>'','billing_address'=>'{}','payment_id'=>'cs_live_fixture','payment_provider'=>'stripe','payment_status'=>'paid','amount_cents'=>1500,'paid_at'=>'2026-09-08 12:00:00','names'=>"Марии\nИоанна",'note'=>'','intention'=>'health','printed_at'=>null,'service_title'=>'Акафист','service_id'=>1,'total'=>1,'revenue'=>1500,'provider'=>'stripe','event_type'=>'checkout.session.completed','order_id'=>1,'processed'=>1,'error_message'=>''];}
    public function get_results($q){return [$this->row()];}
}
$wpdb=new FixtureDB();
require KlosterPrayer\DIR.'/includes/class-order-workflow.php';require KlosterPrayer\DIR.'/includes/class-database.php';require_once KlosterPrayer\DIR.'/includes/class-i18n.php';require KlosterPrayer\DIR.'/includes/class-admin.php';require KlosterPrayer\DIR.'/includes/class-admin-screens.php';require KlosterPrayer\DIR.'/includes/class-pdf.php';
$pages=['detail'=>['orders',['view'=>1]],'settings'=>['settings',[]],'diagnostics'=>['diagnostics',[]],'reports'=>['reports',[]],'forms'=>['forms',[]],'services'=>['services',[]],'edit-form'=>['forms',['edit'=>1]],'edit-service'=>['services',['edit'=>1]],'orders'=>['orders',[]]];
foreach($pages as $name=>[$method,$query]){
    $_GET=$query;$_POST=[];$_REQUEST=$query;ob_start();KlosterPrayer\Admin::$method();$body=ob_get_clean();
    if(str_contains($name,'edit-')){if(str_contains($body,'name="title_uk"')||str_contains($body,'name="title_pl"'))throw new Exception('Inactive languages displayed');}
    if($name==='diagnostics'&&!str_contains($body,'Отключён — настройка не требуется'))throw new Exception('Inactive PayPal error');
    if($name==='reports'&&!preg_match('/<a[^>]+view%5D|view=1[^>]*>—<\/a>/',$body))throw new Exception('Missing donor not linked');
    $html='<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="admin-check-core/wordpress/wp-includes/css/dashicons.css"><link rel="stylesheet" href="admin-check-core/wordpress/wp-includes/css/buttons.css"><link rel="stylesheet" href="admin-check-core/wordpress/wp-admin/css/edit.css"><link rel="stylesheet" href="admin-check-core/wordpress/wp-admin/css/common.css"><link rel="stylesheet" href="admin-check-core/wordpress/wp-admin/css/forms.css"><link rel="stylesheet" href="admin-check-core/wordpress/wp-admin/css/list-tables.css"><link rel="stylesheet" href="../kloster-prayer/assets/css/admin.css"><link rel="stylesheet" href="../kloster-prayer/assets/css/admin-native.css"><style>body{background:#f0f0f1;margin:0;padding:32px 20px 20px 180px;font:13px Arial;color:#1d2327}aside{position:fixed;left:0;top:0;bottom:0;width:160px;background:#1d2327;color:white;padding-top:40px}aside p{padding:8px 15px}</style></head><body class="wp-admin wp-core-ui"><aside><p>WordPress</p><p>Записки</p><p>Требы</p><p>Формы</p><p>Отчёты</p><p>Настройки</p><p>Диагностика</p></aside>'.$body.'</body></html>';
    file_put_contents(dirname(__DIR__).'/tmp/admin-'.$name.'.html',$html);echo $name." OK\n";
}
$GLOBALS['fixture_languages']=['uk','pl_PL'];if(array_keys(KlosterPrayer\Admin::editor_languages())!==['uk','pl'])throw new Exception('Language activation');
$preserve=new ReflectionMethod(KlosterPrayer\Admin::class,'preserve_translations');$_POST=['id'=>1,'title_ru'=>'changed'];$saved=$preserve->invoke(null,['title_ru'=>'changed','title_uk'=>'','title_pl'=>'']);if(isset($saved['title_uk'])||isset($saved['title_pl']))throw new Exception('Hidden translations overwritten');
echo "Language activation and preservation OK\n";
