<?php
declare(strict_types=1);
require __DIR__.'/admin-render.php';
foreach(['en','ru','de','uk','pl'] as $language){
    $html=GeorgKlosterOrthodoxDonate\I18n::in_language(static function(){ob_start();GeorgKlosterOrthodoxDonate\Admin_Screens::settings();return ob_get_clean();},$language);
    foreach(['gkod-stripe-help','Required for one-time donations and prayer requests:','Snapshot','Thin','Your account','sk_test_','sk_live_','pk_','whsec_','checkout.session.completed','charge.refunded','invoice.payment_succeeded','invoice.payment_failed','customer.subscription.created','customer.subscription.updated','customer.subscription.deleted','400','404','403'] as $text)assert(str_contains($html,$text));
    assert(strpos($html,'checkout.session.completed') < strpos($html,'<details class="gkod-stripe-help">'),'Required one-time event must be visible outside collapsed help');
    assert(str_contains($html,esc_html(GeorgKlosterOrthodoxDonate\Payments::api_url('stripe/webhook'))));
    foreach(['https://dashboard.stripe.com/apikeys','https://dashboard.stripe.com/webhooks','https://docs.stripe.com/testing','https://docs.stripe.com/keys','https://docs.stripe.com/webhooks'] as $url)assert(str_contains($html,'href="'.$url.'" target="_blank" rel="noopener noreferrer"'));
}
echo "PASS Stripe help: five languages, actual webhook URL, official links, credentials, snapshot events, testing and troubleshooting\n";
