<?php
declare(strict_types=1);
define('ABSPATH',dirname(__DIR__).'/tmp/admin-check-core/wordpress/');
require dirname(__DIR__).'/georg-kloster-orthodox-donate/includes/class-database.php';
require dirname(__DIR__).'/georg-kloster-orthodox-donate/includes/class-payments.php';
require dirname(__DIR__).'/georg-kloster-orthodox-donate/includes/class-notifications.php';
require dirname(__DIR__).'/georg-kloster-orthodox-donate/includes/class-order-workflow.php';
use KlosterPrayer\Notifications as N;
function check($value,$message){if(!$value)throw new RuntimeException($message);}
$recipient='';$deliveries=[];$mail_ok=true;$throws=false;
function get_option($key,$default=''){global $recipient;check($key==='gkod_notification_email','Must not use admin_email');return $recipient;}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
function __($v,$domain){check($domain==='georg-kloster-orthodox-donate','Text domain');return $v;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_html__($v,$d){return esc_html(__($v,$d));}
function esc_url($v){return esc_html($v);}
function number_format_i18n($v,$d){return number_format($v,$d,'.','');}
function get_bloginfo($v){return 'Fixture Church';}
function admin_url($p){return 'https://example.org/wp-admin/'.$p;}
function add_query_arg($a,$u){return $u.'?'.http_build_query($a);}
function current_time($v){return gmdate('Y-m-d H:i:s');}
function get_current_user_id(){return 0;}
function wp_mail($to,$subject,$body,$headers){global $deliveries,$mail_ok,$throws;$deliveries[]=compact('to','subject','body','headers');if($throws)throw new RuntimeException('mail failure');return $mail_ok;}
class NotificationDB {
 public $prefix='wp_'; public $queue=[]; public $history=[]; public $orders=[];public $rows_affected=0;
 function prepare($sql,...$args){while(str_contains($sql,'%i')){$table=array_shift($args);$sql=preg_replace('/%i/','`'.str_replace('`','``',(string)$table).'`',$sql,1);}return [$sql,$args];}
 function query($q){[$sql,$a]=$q;
  if(str_starts_with($sql,'INSERT IGNORE')){if(isset($this->queue[$a[0]]))return 0;$this->queue[$a[0]]=['order_id'=>$a[0],'sent_at'=>null,'locked_at'=>null,'last_attempt_at'=>null,'attempts'=>0];return 1;}
  if(str_contains($sql,"SET payment_status='paid'")){[$payment,$date,$id]=$a;$this->rows_affected=0;if($this->orders[$id]->payment_status!=='paid'){$this->orders[$id]->payment_status='paid';$this->rows_affected=1;}return $this->rows_affected;}
  $id=$a[2];if(!isset($this->queue[$id]))return 0;$r=&$this->queue[$id];if($r['sent_at']!==null||($r['locked_at']!==null&&$r['locked_at']>=$a[3]))return 0;$r['locked_at']=$a[0];$r['last_attempt_at']=$a[1];$r['attempts']++;return 1;
 }
 function get_row($q){return $this->orders[$q[1][0]]??null;}
 function get_col($q){if(str_contains($q[0],'order_items'))return ['Молебен <script>'];return array_keys(array_filter($this->queue,fn($r)=>$r['sent_at']===null&&($r['locked_at']===null||$r['locked_at']<$q[1][0])&&($r['last_attempt_at']===null||$r['last_attempt_at']<$q[1][1])));}
 function update($t,$data,$where){$id=$where['order_id'];$this->queue[$id]=array_merge($this->queue[$id],$data);return 1;}
 function insert($t,$data){$this->history[]=$data;return 1;}
}
$wpdb=new NotificationDB();
$order=(object)['id'=>1,'payment_status'=>'paid','donor_name'=>'<b>Donor</b>','donor_email'=>'donor@example.org','created_at'=>'2026-10-07 12:00:00','names'=>'Мария, Иоанн','note'=>'<script>bad</script>','amount_cents'=>2000,'currency'=>'EUR','payment_provider'=>'stripe','intention'=>'health','confirmation_sent_at'=>'2026-10-07 12:01:00'];
$wpdb->orders[1]=$order;
check(N::validate_recipient(' office@example.org ')==='office@example.org','Trim valid email');
foreach(["bad",'a@example.org,b@example.org',"a@example.org\r\nBcc: b@example.org"] as $bad){try{N::validate_recipient($bad);throw new RuntimeException('Accepted invalid email');}catch(InvalidArgumentException $e){}}
N::enqueue(1);N::retry();check(!$wpdb->queue&&!$deliveries,'Empty settings disable notifications');
$recipient='office@example.org';check(!N::send(1),'No historical backfill');
N::enqueue(1);N::enqueue(1);check(count($wpdb->queue)===1,'Unique order');
$mail_ok=false;check(!N::send(1),'Mail failure');check($wpdb->queue[1]['sent_at']===null&&$wpdb->queue[1]['locked_at']===null,'Failed send released for retry');
N::retry();check(count($deliveries)===1,'Backoff prevents immediate cron retry');
$wpdb->queue[1]['last_attempt_at']='2000-01-01 00:00:00';$mail_ok=true;N::retry();
check($wpdb->queue[1]['sent_at']!==null,'Successful retry recorded');check(count($deliveries)===2,'Two attempts');
check($deliveries[1]['to']==='office@example.org','Configured recipient, not donor/admin');check(str_contains($deliveries[1]['body'],'&lt;script&gt;'),'Escaped content');check(!str_contains($deliveries[1]['body'],'<script>'),'No HTML injection');check(str_contains($deliveries[1]['body'],"Мария<br />\nИоанн"),'Name list');check(str_contains($deliveries[1]['body'],'view=1'),'Protected admin link');
N::enqueue(1);N::send(1);N::retry();check(count($deliveries)===2,'No duplicate completed notification');
$wpdb->orders[2]=clone $order;$wpdb->orders[2]->id=2;$wpdb->orders[2]->payment_status='pending';N::enqueue(2);check(!N::send(2)&&count($deliveries)===2,'No unpaid notification');
$wpdb->orders[2]->payment_status='paid';$wpdb->queue[2]['locked_at']=gmdate('Y-m-d H:i:s');check(!N::send(2),'Concurrent worker blocked');
$wpdb->queue[2]['locked_at']='2000-01-01 00:00:00';$throws=true;check(!N::send(2),'Mail exception retained for retry');$throws=false;
$recipient='';check(!N::send(2),'Disabling pauses pending notifications');$recipient='new@example.org';check(N::send(2),'Stale lease recovered');check(end($deliveries)['to']==='new@example.org','Pending mail uses current configured recipient');
$wpdb->orders[3]=clone $order;$wpdb->orders[3]->id=3;$wpdb->orders[3]->payment_status='pending';
$paid=new ReflectionMethod(KlosterPrayer\Payments::class,'mark_paid');$paid->invoke(null,3,'cs_test_fixture');$count=count($deliveries);$paid->invoke(null,3,'cs_test_fixture');check(count($deliveries)===$count,'Duplicate webhook did not resend');check(isset($wpdb->queue[3])&&$wpdb->queue[3]['sent_at']!==null,'Paid transition queues and sends');check($wpdb->orders[3]->confirmation_sent_at===$order->confirmation_sent_at,'Donor confirmation untouched');
echo "PASS recipient validation, opt-out, HTML, retry/backoff, exception recovery, paid-only delivery, duplicate webhook and donor isolation\n";
