<?php
declare(strict_types=1);
require __DIR__.'/admin-render.php';
foreach(['en','ru','de','uk','pl'] as $language){
    $html=GeorgKlosterOrthodoxDonate\I18n::in_language(static function(){ob_start();GeorgKlosterOrthodoxDonate\Admin_Screens::settings();return ob_get_clean();},$language);
    foreach(['gkod-stripe-help','Snapshot','Thin','Your account','sk_test_','sk_live_','pk_','whsec_','checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','charge.refunded','invoice.payment_succeeded','invoice.payment_failed','customer.subscription.created','customer.subscription.updated','customer.subscription.deleted','400','404','403'] as $text)assert(str_contains($html,$text));
    $label=GeorgKlosterOrthodoxDonate\I18n::in_language(static fn()=>__('Select all events for one-time donations, prayer requests and regular support:','georg-kloster-orthodox-donations'),$language);
    assert(str_contains($html,$label),'Complete event list has a translated visible label');
    $help=strpos($html,'<details class="gkod-stripe-help">');
    assert(strpos($html,'checkout.session.completed') < $help && strpos($html,'checkout.session.async_payment_succeeded') < $help && strpos($html,'checkout.session.async_payment_failed') < $help && strpos($html,'customer.subscription.deleted') < $help,'All required Stripe events must be visible outside collapsed help');
    assert(str_contains($html,esc_html(GeorgKlosterOrthodoxDonate\Payments::api_url('stripe/webhook'))));
    foreach(['https://dashboard.stripe.com/apikeys','https://dashboard.stripe.com/webhooks','https://docs.stripe.com/testing','https://docs.stripe.com/keys','https://docs.stripe.com/webhooks'] as $url)assert(str_contains($html,'href="'.$url.'" target="_blank" rel="noopener noreferrer"'));
}
echo "PASS Stripe help: five languages, actual webhook URL, official links, credentials, snapshot events, testing and troubleshooting\n";
