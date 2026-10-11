<?php
declare(strict_types=1);
// Real WordPress/SQLite integration. Only the documented disposable review site is accepted.
$site = realpath($argv[1] ?? '');
if (!$site || !str_contains(str_replace('\\', '/', $site), '/tmp/wporg-review/wordpress')) throw new RuntimeException('Local review WordPress required');
$source = realpath($argv[2] ?? dirname(__DIR__));
if (!$source || !is_file($source . '/kloster-prayer/kloster-prayer.php')) throw new RuntimeException('Plugin source required');
// Clone the disposable database; leave the existing review site unchanged.
$database = 'donation-check-' . bin2hex(random_bytes(6)) . '.sqlite';
$db = new PDO('sqlite:' . $site . '/wp-content/database/.ht.sqlite');
$db->exec('VACUUM INTO ' . $db->quote($site . '/wp-content/database/' . $database));
unset($db);
define('DB_FILE', $database);
define('WP_PLUGIN_DIR', $source);
require $site . '/wp-load.php';
if (!class_exists(KlosterPrayer\Payments::class)) require $source . '/kloster-prayer/kloster-prayer.php';
require_once $source . '/kloster-prayer/includes/class-orders-table.php';
function donation_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
use KlosterPrayer\Database;
use KlosterPrayer\Payments;
use KlosterPrayer\Public_Form;
global $wpdb;
Database::activate();
Public_Form::init();
wp_set_current_user(1);
$mail = []; $http = [];
add_filter('pre_wp_mail', static function ($value, $args) use (&$mail) { $mail[] = $args; return true; }, 10, 2);
add_filter('pre_http_request', static function ($value, $args, $url) use (&$http) {
    $http[] = [$url, $args];
    if (str_ends_with($url, '/v1/checkout/sessions')) $body = ['id'=>'cs_donation_fixture', 'url'=>'https://checkout.stripe.com/c/pay/donation_fixture'];
    elseif (str_ends_with($url, '/v1/oauth2/token')) $body = ['access_token'=>'local-fixture-token'];
    elseif (str_ends_with($url, '/v2/checkout/orders')) $body = ['id'=>'PAYPAL-DONATION', 'links'=>[['rel'=>'payer-action','href'=>'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-DONATION']]];
    else return new WP_Error('blocked', 'Unexpected external call blocked');
    return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
}, 10, 3);
update_option('klp_payment_methods', ['stripe','paypal']);
update_option('klp_stripe_secret','local-fixture');
update_option('klp_stripe_webhook_secret','local-fixture-signing');
update_option('klp_paypal_client_id','local-fixture');
update_option('klp_paypal_secret','local-fixture');
update_option('klp_paypal_mode','sandbox');
update_option('klp_notification_email','office@example.invalid');
foreach (['0.50'=>50,'0,50'=>50,'12'=>1200,'12,3'=>1230,'12.34'=>1234,'999999.99'=>99999999,' 1.00 '=>100] as $raw=>$expected) donation_assert(Payments::donation_cents((string)$raw)===$expected, 'Exact decimal cents');
foreach (['','0','0.49','-1','+1','1e3','1.234','1,234.56','1000000','NaN','1.00 trailing',"1\n2"] as $raw) donation_assert(Payments::donation_cents($raw)===null, 'Reject invalid amount: '.$raw);
foreach (['ru','de','uk','pl'] as $lang) {
    $form = do_shortcode('[kloster_donation lang="'.$lang.'"]');
    donation_assert(str_contains($form,'name="donation_amount"') && !str_contains($form,'name="names"') && !str_contains($form,'name="services[]"') && !str_contains($form,'name="intention"'), 'Donation form without prayer inputs');
    donation_assert(str_contains($form,esc_html(KlosterPrayer\I18n::donation_text('submit',$lang))), 'Localized donation form');
}
$pair=do_shortcode('[kloster_donation][kloster_donation]');
preg_match_all('/aria-describedby="([^"]+)"/', $pair, $ids);
donation_assert(count($ids[1])===2 && count(array_unique($ids[1]))===2, 'Multiple forms have unique help IDs');
donation_assert(str_contains(do_shortcode('[kloster_form]'),'name="names"'), 'Prayer form preserved');
class DonationJson extends RuntimeException { public function __construct(public array $json) { parent::__construct('AJAX response'); } }
$die=static fn()=>static function () { throw new DonationJson(json_decode(ob_get_contents() ?: '{}',true) ?: []); };
add_filter('wp_die_ajax_handler',$die);
add_filter('wp_doing_ajax','__return_true');
$_SERVER['REMOTE_ADDR']='127.0.0.11';
function donation_ajax(array $input): array {
    $_POST=wp_slash($input); $_REQUEST=$_POST; ob_start();
    try { Payments::create_payment(); throw new RuntimeException('Missing AJAX response'); }
    catch (DonationJson $e) { return $e->json; }
    finally { ob_end_clean(); $_POST=[]; $_REQUEST=[]; }
}
$base=['nonce'=>wp_create_nonce('klp_payment'), 'request_type'=>'donation', 'donation_amount'=>'12,34', 'donor_email'=>'donor@example.invalid', 'language'=>'ru', 'provider'=>'stripe'];
$count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Database::table('orders'));
foreach ([['donation_amount'=>'0.49'],['donation_amount'=>'1e3'],['donation_amount'=>['12']],['donor_email'=>'bad'],['services'=>['1']],['names'=>'Мария'],['provider'=>'none'],['request_type'=>'unknown']] as $bad) donation_assert(!(donation_ajax(array_replace($base,$bad))['success']??true), 'Server rejects malformed/mixed requests');
donation_assert((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Database::table('orders'))===$count,'Invalid requests create no order');
delete_transient('klp_rate_'.hash('sha256',KlosterPrayer\Input::client_ip().wp_salt('nonce')));
$result=donation_ajax($base);
donation_assert(($result['success']??false) && str_starts_with($result['data']['redirect'],'https://checkout.stripe.com/'),'Stripe donation AJAX checkout');
$order=$wpdb->get_row('SELECT * FROM '.Database::table('orders').' ORDER BY id DESC LIMIT 1');
donation_assert($order->intention==='none' && $order->names==='' && (int)$order->amount_cents===1234 && $order->form_id===null,'Stored standalone donation');
donation_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE order_id=%d',Database::table('order_items'),$order->id))===0,'No fictitious prayer services');
$checkout=end($http)[1]['body'];
donation_assert($checkout['line_items[0][price_data][unit_amount]']==='1234' && $checkout['line_items[0][price_data][product_data][name]']===KlosterPrayer\I18n::donation_text('title','ru'),'Stripe amount and donation label');
$_GET=['kloster_payment'=>'success','kloster_order'=>$order->public_id];
donation_assert(!str_contains(Public_Form::result_shortcode(),KlosterPrayer\I18n::donation_text('thanks','ru')),'Return URL cannot claim an unpaid donation');
function donation_event(object $order, int $amount, string $id): WP_REST_Response {
    $payload=wp_json_encode(['id'=>$id,'type'=>'checkout.session.completed','data'=>['object'=>['id'=>$order->payment_id,'metadata'=>['order_id'=>(string)$order->id],'payment_intent'=>'pi_donation_fixture','payment_status'=>'paid','currency'=>'eur','amount_total'=>$amount]]]);
    $r=new WP_REST_Request('POST','/kloster-prayer/v1/stripe/webhook'); $r->set_body($payload); $time=time();
    $r->set_header('stripe-signature','t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$payload,'local-fixture-signing'));
    return Payments::stripe_webhook($r);
}
donation_assert(donation_event($order,1,'evt_donation_bad')->get_status()===400 && !$mail,'Wrong amount rejected without mail');
donation_assert(donation_event($order,1234,'evt_donation_paid')->get_status()===200 && count($mail)===2,'Signed donation webhook confirms and sends two emails');
donation_assert($wpdb->get_var($wpdb->prepare('SELECT payment_reference FROM %i WHERE id=%d',Database::table('orders'),$order->id))==='pi_donation_fixture','Verified checkout stores the refund reference');
donation_assert($mail[0]['subject']===KlosterPrayer\I18n::donation_text('subject','ru') && !str_contains($mail[0]['message'],'Имена'),'Donor confirmation describes donation');
donation_assert(!str_contains($mail[1]['message'],'<h2') && !str_contains($mail[1]['message'],'О здравии'),'Recipient notification omits prayer names/type');
$delivered=count($mail); donation_event($order,1234,'evt_donation_paid'); donation_assert(count($mail)===$delivered,'Duplicate webhook does not resend');
$_GET=['kloster_payment'=>'success','kloster_order'=>$order->public_id];
donation_assert(str_contains(Public_Form::result_shortcode(),KlosterPrayer\I18n::donation_text('thanks','ru')),'Verified donation success text');
$_GET=['kloster_payment'=>'cancelled','kloster_order'=>$order->public_id];
donation_assert(str_contains(Public_Form::result_shortcode(),KlosterPrayer\I18n::donation_text('thanks','ru')),'Paid donation remains confirmed even with a cancelled return parameter');
$_GET=['order_ids'=>(string)$order->id];
donation_assert((new ReflectionMethod(KlosterPrayer\Pdf::class,'cards'))->invoke(null)===[], 'Donations excluded from prayer PDF');
$_GET=['intention'=>'none']; $table=new KlosterPrayer\Orders_Table();$table->prepare_items();
donation_assert(in_array((int)$order->id,array_map(static fn($r)=>(int)$r->id,$table->items),true),'Admin donation filter');
$_GET=['view'=>(string)$order->id]; ob_start(); KlosterPrayer\Admin::orders(); $detail=ob_get_clean();
donation_assert(!str_contains($detail,'action=klp_pdf') && str_contains($detail,KlosterPrayer\I18n::donation_text('title')),'Donation detail has correct type and no prayer PDF action');
$_GET=['month'=>wp_date('Y-m')]; ob_start();KlosterPrayer\Admin_Screens::reports();$reports=ob_get_clean();
donation_assert(str_contains($reports,'donor@example.invalid') && $wpdb->last_error==='','Existing donor reports include donation');
$_GET=[];
delete_transient('klp_rate_'.hash('sha256',KlosterPrayer\Input::client_ip().wp_salt('nonce')));
$result=donation_ajax(array_replace($base,['provider'=>'paypal','donation_amount'=>'5.00']));
donation_assert(($result['success']??false) && str_contains($result['data']['redirect'],'sandbox.paypal.com'),'PayPal donation AJAX checkout');
$paypal=json_decode(end($http)[1]['body'],true);
donation_assert($paypal['purchase_units'][0]['amount']['value']==='5.00' && $paypal['purchase_units'][0]['description']===KlosterPrayer\I18n::donation_text('title','ru'),'PayPal amount and donation label');
$pending=$wpdb->get_row('SELECT * FROM '.Database::table('orders').' ORDER BY id DESC LIMIT 1');
$_GET=['kloster_payment'=>'cancelled','kloster_order'=>$pending->public_id];
donation_assert(str_contains(Public_Form::result_shortcode(),KlosterPrayer\I18n::donation_text('cancelled','ru')),'Unpaid donation cancellation text');
$_GET=[];
update_option('klp_payment_methods',['stripe']);
donation_assert(!(donation_ajax(array_replace($base,['provider'=>'paypal']))['success']??true),'Disabled provider rejected');
donation_assert($wpdb->last_error==='','No database errors');
echo "PASS donations: exact amounts, four languages, AJAX validation, Stripe/PayPal checkout, signed webhook, idempotency, email, protected admin/filter, reports and PDF exclusion; HTTP/mail intercepted\n";
