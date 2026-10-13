<?php
declare(strict_types=1);
// Real disposable WordPress + SQLite. Run only against the local review site.
require __DIR__.'/bootstrap-wordpress.php';
use GeorgKlosterOrthodoxDonate\Database;
use GeorgKlosterOrthodoxDonate\Payments;
use GeorgKlosterOrthodoxDonate\Input;
function review_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
global $wpdb;
$deliveries=[];
add_filter('pre_wp_mail',static function($value,$mail)use(&$deliveries){$deliveries[]=$mail;return true;},10,2);
add_filter('pre_http_request',static function($value,$args,$url){
    if(str_ends_with($url,'/v1/oauth2/token'))return ['headers'=>[],'body'=>'{"access_token":"local-fixture-token"}','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
    if(str_ends_with($url,'/v1/notifications/verify-webhook-signature'))return ['headers'=>[],'body'=>'{"verification_status":"SUCCESS"}','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
    return new WP_Error('local_review','External calls disabled during review');
},10,3);
wp_set_current_user(1);
Database::activate();
update_option('gkod_notification_email','office@example.invalid');
update_option('gkod_stripe_webhook_secret','local-fixture-signing-secret');
update_option('gkod_paypal_client_id','local-fixture-client');update_option('gkod_paypal_secret','local-fixture-secret');update_option('gkod_paypal_webhook_id','local-fixture-webhook');
$order=(new ReflectionMethod(Payments::class,'create_order'))->invoke(null,['language'=>'ru','provider'=>'stripe','intention'=>'health','names'=>"Мария\nИоанн",'note'=>"Первая строка\n<script>fixture</script>",'donor_name'=>'Review donor','donor_email'=>'donor@example.invalid','form_id'=>0,'service_ids'=>[(int)$wpdb->get_var('SELECT id FROM '.Database::table('services').' LIMIT 1')],'return_url'=>home_url('/')]);
review_assert(!is_wp_error($order),'Create real database order');
$_GET=['month'=>['malformed'],'s'=>'<b>search</b>'];
review_assert(Input::get('month')===''&&Input::get('s')==='search','Reject array-shaped scalar input and remove markup');
$_POST=wp_slash(['names'=>"Мария\nИоанн",'intro_ru'=>"Первая строка\nВторая строка",'ids'=>['1',['nested']]]);
review_assert(Input::textarea('names')==="Мария\nИоанн"&&Input::textarea('intro_ru')==="Первая строка\nВторая строка",'Textarea line breaks retained');
review_assert(Input::post_list('ids')===['1',''],'Nested list input rejected');
$_POST=[];$_GET=[];$_REQUEST=[];
$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Database::table('orders'));
class ReviewWpDie extends RuntimeException {}
$die=static fn()=>static function($message){throw new ReviewWpDie((string)$message);};
add_filter('wp_die_handler',$die);add_filter('wp_die_ajax_handler',$die);
$_POST=['nonce'=>'invalid'];$_REQUEST=$_POST;
add_filter('wp_doing_ajax','__return_true');
try{Payments::create_payment();throw new RuntimeException('Missing AJAX nonce enforcement');}catch(ReviewWpDie $e){}
remove_filter('wp_doing_ajax','__return_true');
review_assert((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Database::table('orders'))===$before,'Rejected AJAX nonce cannot create order');
$_POST=['gkod_action'=>'save_settings','_gkod_nonce'=>'invalid'];$_REQUEST=$_POST;
try{GeorgKlosterOrthodoxDonate\Admin::handle_actions();throw new RuntimeException('Missing admin nonce enforcement');}catch(ReviewWpDie $e){}
review_assert(get_option('gkod_notification_email')==='office@example.invalid','Rejected admin nonce cannot save settings');
wp_set_current_user(0);GeorgKlosterOrthodoxDonate\Admin::handle_actions();wp_set_current_user(1);
review_assert(get_option('gkod_notification_email')==='office@example.invalid','Anonymous admin action cannot save settings');
remove_filter('wp_die_handler',$die);remove_filter('wp_die_ajax_handler',$die);
$_POST=[];$_GET=[];$_REQUEST=[];
$order->payment_id='cs_test_review_'.$order->id;
$wpdb->update(Database::table('orders'),['payment_id'=>$order->payment_id],['id'=>$order->id]);
function stripe_event(object $order,int $amount,string $id): WP_REST_Response {
    $payload=wp_json_encode(['id'=>$id.'_'.$order->id,'type'=>'checkout.session.completed','data'=>['object'=>['id'=>$order->payment_id,'metadata'=>['order_id'=>(string)$order->id],'payment_status'=>'paid','currency'=>'eur','amount_total'=>$amount,'customer_details'=>['email'=>'donor@example.invalid','name'=>'Review payer']]]]);
    $request=new WP_REST_Request('POST','/georg-kloster-orthodox-donate/v1/stripe/webhook');$request->set_body($payload);
    $time=time();$request->set_header('stripe-signature','t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$payload,'local-fixture-signing-secret'));
    return Payments::stripe_webhook($request);
}
review_assert(stripe_event($order,1,'evt_review_wrong')->get_status()===400,'Mismatched amount rejected');
review_assert(stripe_event($order,(int)$order->amount_cents,'evt_review_paid')->get_status()===200,'Signed payment accepted');
$count=count($deliveries);review_assert($count===2,'Separate donor and recipient emails');
review_assert(stripe_event($order,(int)$order->amount_cents,'evt_review_paid')->get_status()===200&&count($deliveries)===$count,'Webhook idempotency');
$saved=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',Database::table('orders'),$order->id));
review_assert($saved->payment_status==='paid'&&$saved->note===$order->note,'Paid status and comment retained');
GeorgKlosterOrthodoxDonate\Order_Workflow::change((int)$order->id,'archive_order');
$_GET=['archive'=>'yes'];$_REQUEST=$_GET;
$table=new GeorgKlosterOrthodoxDonate\Orders_Table();$table->prepare_items();
review_assert(count($table->items)>=1,'Archive query on real database');
review_assert(str_contains($table->column_default($saved,'names'),'&lt;script&gt;'),'Comment escaping');
$_GET=['view'=>$order->id];$_REQUEST=$_GET;ob_start();GeorgKlosterOrthodoxDonate\Admin::orders();$html=ob_get_clean();
review_assert(str_contains($html,'Первая строка')&&!str_contains($html,'<script>fixture</script>'),'Detail escaping');
$_GET=['order_ids'=>(string)$order->id];$cards=(new ReflectionMethod(GeorgKlosterOrthodoxDonate\Pdf::class,'cards'))->invoke(null);
review_assert(count($cards)>=1&&$cards[0]['note']===$order->note,'Archived PDF real query');
update_option('gkod_db_version','1.2.4');Database::maybe_upgrade();
review_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE id=%d',Database::table('orders'),$order->id))===1,'Upgrade preserves order');
review_assert($wpdb->last_error==='','No database errors');
$form=do_shortcode('[gkod_form]');
review_assert(str_contains($form,'gkod-form')&&$wpdb->last_error==='','Real shortcode and service join');
$_GET=['month'=>wp_date('Y-m')];ob_start();GeorgKlosterOrthodoxDonate\Admin_Screens::reports();$reports=ob_get_clean();
review_assert(str_contains($reports,'cs_test')===false&&$wpdb->last_error==='','Real monthly reports');
echo "PASS real WordPress install/activation, schema upgrade, signed webhook/binding, duplicate delivery, archive, detail/comment escaping and archived PDF query; no live network or mail\n";
