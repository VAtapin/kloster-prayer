<?php
declare(strict_types=1);
require __DIR__.'/admin-render.php';
ob_start();GeorgKlosterOrthodoxDonate\Admin_Screens::settings();$html=ob_get_clean();
assert(str_contains($html,'gkod-paypal-help'));
foreach(['Client ID','Secret','Webhook ID','Required for one-time donations and prayer requests:','PAYMENT.CAPTURE.COMPLETED','PAYMENT.CAPTURE.REFUNDED','Sandbox','Live','Business','Personal','Webhooks Simulator','verify-webhook-signature'] as $text)assert(str_contains($html,$text));
assert(str_contains($html,htmlspecialchars(GeorgKlosterOrthodoxDonate\Payments::api_url('paypal/webhook'),ENT_QUOTES)));
foreach(['https://developer.paypal.com/dashboard/applications/','https://developer.paypal.com/api/get-started/','https://developer.paypal.com/api/rest/webhooks/rest/'] as $url){
    assert(str_contains($html,'href="'.$url.'" target="_blank" rel="noopener noreferrer"'));
}
echo "PayPal help: settings, credentials, modes, events, actual URL and safe official links OK\n";
