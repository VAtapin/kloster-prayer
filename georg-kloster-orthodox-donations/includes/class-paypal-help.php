<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

final class PayPal_Help
{
    public static function render(): void
    {
        echo '<details class="gkod-paypal-help"><summary>'.esc_html__('PayPal setup — step-by-step instructions','georg-kloster-orthodox-donations').'</summary>';
        echo '<p>'.esc_html__('Live payments require the recipient\'s PayPal Business account. Email, account password, App ID and Stripe whsec are not substitutes for PayPal credentials. The plugin uses REST API Orders v2, not legacy email payment buttons or IPN.','georg-kloster-orthodox-donations').'</p><ol>';
        self::step(__('Open Apps & Credentials in the PayPal Developer Dashboard and sign in to the recipient\'s account. Select Sandbox for testing or Live for real payments.','georg-kloster-orthodox-donations'));
        echo '<li>';self::link('https://developer.paypal.com/dashboard/applications/',__('Open Apps & Credentials','georg-kloster-orthodox-donations'));echo '</li>';
        self::step(__('Open your REST API app or create one using Create App. For Sandbox, select the test seller\'s Business account; for Live, use the intended recipient\'s live app.','georg-kloster-orthodox-donations'));
        self::step(__('On the app page, copy the Client ID and reveal the Secret. Paste them into the matching plugin fields. Select the same Live or Sandbox mode. Client ID, Secret and Webhook ID must all belong to the same app and environment.','georg-kloster-orthodox-donations'));
        self::step(__('In the same app, find Webhooks and click Add Webhook. Paste the exact webhook URL shown below. It must be public HTTPS on port 443, without password protection, POST blocking or an intermediate security page.','georg-kloster-orthodox-donations'));
        echo '<li><code>'.esc_html(Payments::api_url('paypal/webhook')).'</code></li>';
        self::step(__('Select PAYMENT.CAPTURE.COMPLETED and PAYMENT.CAPTURE.REFUNDED, then save the webhook. The first confirms payment; the second records refunds. All events, CHECKOUT.ORDER.APPROVED and IPN are not needed for this integration.','georg-kloster-orthodox-donations'));
        self::step(__('Copy the new webhook\'s ID from Webhooks into the plugin\'s Webhook ID field. This is not the Client ID or an individual WH-… event ID. Enable PayPal and save. The saved Secret is hidden; a blank field keeps the previous secret.','georg-kloster-orthodox-donations'));
        self::step(__('Test with Sandbox first: under Testing Tools → Sandbox Accounts, find separate Personal buyer and Business seller accounts. Submit a test prayer request and pay with the test buyer, not your real account. Check the Paid status, diagnostics event and emails if mail is configured. Sandbox does not transfer real money.','georg-kloster-orthodox-donations'));
        self::step(__('To go live, replace Client ID, Secret and Webhook ID with the Live app\'s values and select Live. The plugin stores one set of credentials, not two. Always enter a new Secret when changing apps or environments; a blank field preserves the old one.','georg-kloster-orthodox-donations'));
        echo '</ol><p>'.esc_html__('Webhooks Simulator generates artificial events. PayPal does not support verifying these through verify-webhook-signature, which the plugin uses. A signature failure for such an event does not prove the integration is broken. Test a real Sandbox payment and event delivery in the same app.','georg-kloster-orthodox-donations').'</p>';
        echo '<p>'.esc_html__('If payment or webhooks fail, check the app/environment for all credentials, the exact URL and HTTPS/POST access. Update the PayPal webhook after a domain move. Disabled PayPal needs no configuration. Diagnostics checks whether credentials exist, not whether PayPal accepts them. Never publish the Secret in emails, screenshots or messages.','georg-kloster-orthodox-donations').'</p><p>';
        self::link('https://developer.paypal.com/api/get-started/',__('PayPal documentation: account and credentials','georg-kloster-orthodox-donations'));echo ' · ';
        self::link('https://developer.paypal.com/api/rest/webhooks/rest/',__('PayPal documentation: webhooks and signature verification','georg-kloster-orthodox-donations'));
        echo '</p></details>';
    }

    private static function step(string $text): void
    {
        echo '<li>'.esc_html($text).'</li>';
    }

    private static function link(string $url,string $label): void
    {
        echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.esc_html($label).'</a>';
    }
}
