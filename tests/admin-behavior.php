<?php
declare(strict_types=1);
require __DIR__.'/admin-render.php';
function wp_has_noncharacters($s){return false;}
function wp_kses_uri_attributes(){return ['href','src'];}
foreach(['attribute-token','text-replacement','span','decoder','tag-processor'] as $class)require_once ABSPATH.'wp-includes/html-api/class-wp-html-'.$class.'.php';
$links=['Version 1.1.1','Author: <a href="https://atapin.de/">atapin.de</a>','<a href="https://atapin.de/">Plugin page</a>'];
$result=GeorgKlosterOrthodoxDonate\Admin::external_meta($links,'georg-kloster-orthodox-donate/georg-kloster-orthodox-donate.php');
if(substr_count(implode('',$result),'target="_blank"')!==2)throw new Exception('External link targets');
if(GeorgKlosterOrthodoxDonate\Admin::external_meta($links,'another/plugin.php')!==$links)throw new Exception('Other plugin changed');
require_once GeorgKlosterOrthodoxDonate\DIR.'/includes/class-payments.php';
function sanitize_email($s){return $s;}function is_wp_error($r){return false;}
$GLOBALS['response']=['code'=>200,'body'=>json_encode(['id'=>'cs_live_fixture','customer_details'=>['name'=>'Fixture Donor','email'=>'donor@example.org','address'=>['city'=>'Berlin']]])];
function wp_remote_get($url,$args){return $GLOBALS['response'];}function wp_remote_retrieve_response_code($r){return $r['code'];}function wp_remote_retrieve_body($r){return $r['body'];}
class SyncFixtureDB extends FixtureDB{public $updates=[];public function update($table,$data,$where){$this->updates[]=$data;return 1;}}
$wpdb=new SyncFixtureDB();
if(!GeorgKlosterOrthodoxDonate\Payments::refresh_customer(1))throw new Exception('Customer refresh failed');
if(($wpdb->updates[0]['billing_name']??'')!=='Fixture Donor')throw new Exception('Customer missing');
if(isset($wpdb->updates[0]['payment_status']))throw new Exception('Customer read changed payment state');
$GLOBALS['response']['code']=401;$wpdb->updates=[];
if(GeorgKlosterOrthodoxDonate\Payments::refresh_customer(1)||$wpdb->updates)throw new Exception('Failed response wrote data');
$GLOBALS['response']=['code'=>200,'body'=>'{"id":"cs_live_other","customer_details":{"name":"Wrong person"}}'];
if(GeorgKlosterOrthodoxDonate\Payments::refresh_customer(1)||$wpdb->updates)throw new Exception('Mismatched session accepted');
[$where,$args]=GeorgKlosterOrthodoxDonate\Orders_Table::where(['month'=>'2026-09','provider'=>'stripe','service_id'=>2,'payment_status'=>'paid','s'=>'Maria']);
if(!str_contains($where,'payment_provider=%s')||!str_contains($where,'service_id=%d')||!in_array('2026-10-01',$args,true))throw new Exception('Report filters lost');
echo "External links, customer refresh, report filters OK\n";
