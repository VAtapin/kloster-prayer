<?php
declare(strict_types=1);
define('ABSPATH',dirname(__DIR__).'/tmp/admin-check-core/wordpress/');
require dirname(__DIR__).'/georg-kloster-orthodox-donate/includes/class-payments.php';
use KlosterPrayer\Payments;
$origin='';$base='https://old.example/wp-json/';
function get_option($key,$default=''){global $origin;return $key==='gkod_api_origin'?$origin:$default;}
function rest_url($path){global $base;return $base.$path;}
function wp_parse_url($url){return parse_url($url);}
assert(Payments::normalize_api_origin(' https://NEW.example/ ')==='https://new.example');
foreach(['http://example.org','https://user:pass@example.org','https://example.org/path','https://example.org?q=1','https://example.org#x','https://example.org\\evil','javascript:alert(1)','https://localhost'] as $bad){
    try{Payments::normalize_api_origin($bad);throw new RuntimeException('Accepted invalid origin');}catch(InvalidArgumentException $e){}
}
assert(Payments::api_url('stripe/webhook')==='https://old.example/wp-json/georg-kloster-orthodox-donate/v1/stripe/webhook');
$base='https://new.example/sub/wp-json/';
assert(Payments::api_url('stripe/webhook')==='https://new.example/sub/wp-json/georg-kloster-orthodox-donate/v1/stripe/webhook');
$origin='https://api.example:8443';
assert(Payments::api_url('paypal/return')==='https://api.example:8443/sub/wp-json/georg-kloster-orthodox-donate/v1/paypal/return');
$base='https://new.example/sub/?rest_route=/';
assert(Payments::api_url('paypal/webhook')==='https://api.example:8443/sub/?rest_route=/georg-kloster-orthodox-donate/v1/paypal/webhook');
$origin='';
assert(Payments::api_url('paypal/webhook')==='https://new.example/sub/?rest_route=/georg-kloster-orthodox-donate/v1/paypal/webhook');
$origin='bad';
assert(Payments::api_url('paypal/webhook')==='https://new.example/sub/?rest_route=/georg-kloster-orthodox-donate/v1/paypal/webhook');
echo "API domain validation, automatic migration, override, reset, subdirectory and plain permalinks OK\n";
