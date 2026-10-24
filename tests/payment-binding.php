<?php
declare(strict_types=1);
define('ABSPATH',dirname(__DIR__).'/tmp/admin-check-core/wordpress/');
class WP_Error { public function __construct(public string $code = '', public string $message = '') {} }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_parse_url($url) { return parse_url($url); }
function get_option($key, $default = false) { return $key === 'gkod_paypal_mode' ? 'sandbox' : $default; }
function wp_remote_retrieve_response_code($response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response): string { return (string) ($response['body'] ?? ''); }
require dirname(__DIR__).'/georg-kloster-orthodox-donations/includes/class-payments.php';
$order=(object)['id'=>7,'public_id'=>'request-7','payment_id'=>'PAYPAL-ORDER-7','payment_provider'=>'paypal','amount_cents'=>4000];
$capture=['id'=>'CAPTURE-7','status'=>'COMPLETED','custom_id'=>'7','amount'=>['value'=>'40.00','currency_code'=>'EUR'],'supplementary_data'=>['related_ids'=>['order_id'=>'PAYPAL-ORDER-7']]];
$matches=new ReflectionMethod(GeorgKlosterOrthodoxDonate\Payments::class,'paypal_capture_matches');
assert($matches->invoke(null,$order,$capture));
foreach(['wrong order'=>['supplementary_data'=>['related_ids'=>['order_id'=>'OTHER']]],'wrong id'=>['custom_id'=>'8'],'wrong amount'=>['amount'=>['value'=>'1.00','currency_code'=>'EUR']],'wrong currency'=>['amount'=>['value'=>'40.00','currency_code'=>'USD']],'pending'=>['status'=>'PENDING'],'malformed amount'=>['amount'=>['value'=>'4e1','currency_code'=>'EUR']]] as $change)assert(!$matches->invoke(null,$order,array_replace($capture,$change)));
$return=new ReflectionMethod(GeorgKlosterOrthodoxDonate\Payments::class,'paypal_return_matches');
$response=['id'=>'PAYPAL-ORDER-7','status'=>'COMPLETED','purchase_units'=>[['reference_id'=>'request-7','payments'=>['captures'=>[$capture]]]]];
assert($return->invoke(null,$order,$response));
assert(!$return->invoke(null,$order,array_replace($response,['id'=>'OTHER'])));
$bad=$response;$bad['purchase_units'][0]['reference_id']='another-request';assert(!$return->invoke(null,$order,$bad));
$bad=$response;$bad['purchase_units'][0]['payments']['captures'][0]['amount']['value']='1.00';assert(!$return->invoke(null,$order,$bad));
$order->payment_provider='stripe';assert(!$matches->invoke(null,$order,$capture));
$order->payment_id='cs_test_fixture';
$stripe=new ReflectionMethod(GeorgKlosterOrthodoxDonate\Payments::class,'stripe_session_matches');
$stripe_binding=new ReflectionMethod(GeorgKlosterOrthodoxDonate\Payments::class,'stripe_session_belongs_to_order');
$session=['id'=>'cs_test_fixture','payment_status'=>'paid','currency'=>'eur','amount_total'=>4000,'client_reference_id'=>'request-7','metadata'=>['order_id'=>'7','public_id'=>'request-7']];
assert($stripe->invoke(null,$order,$session));
assert($stripe_binding->invoke(null,$order,array_replace($session,['payment_status'=>'unpaid'])));
foreach([['id'=>'cs_test_other'],['amount_total'=>100],['currency'=>'usd'],['payment_status'=>'unpaid'],['client_reference_id'=>'other'],['metadata'=>['order_id'=>'8','public_id'=>'request-7']]] as $change)assert(!$stripe->invoke(null,$order,array_replace($session,$change)));
$checkout_url = new ReflectionMethod(GeorgKlosterOrthodoxDonate\Payments::class, 'checkout_url_allowed');
assert($checkout_url->invoke(null, 'https://checkout.stripe.com/c/pay/cs_test_fixture', 'stripe'));
assert($checkout_url->invoke(null, 'https://www.sandbox.paypal.com/checkoutnow?token=fixture', 'paypal'));
foreach (['http://checkout.stripe.com/c/pay/session', 'https://checkout.stripe.com.attacker.example/c/pay/session', 'https://user@checkout.stripe.com/c/pay/session', 'https://www.paypal.com/checkoutnow?token=fixture'] as $url) {
    $provider = str_contains($url, 'paypal.com') ? 'paypal' : 'stripe';
    assert(!$checkout_url->invoke(null, $url, $provider));
}
$payment_url = new ReflectionMethod(GeorgKlosterOrthodoxDonate\Payments::class, 'payment_url_from_response');
foreach ([['response'=>['code'=>200],'body'=>'not-json'], ['response'=>['code'=>500],'body'=>'{"id":"cs_bad","url":"https://checkout.stripe.com/c/pay/cs_bad"}'], ['response'=>['code'=>302],'body'=>'{"id":"cs_bad","url":"https://checkout.stripe.com/c/pay/cs_bad"}']] as $response) {
    assert($payment_url->invoke(null, $response, 'url', 1, 'stripe') instanceof WP_Error);
}
echo "PASS PayPal binding, capture status, amount/currency, IDs and return verification\n";
