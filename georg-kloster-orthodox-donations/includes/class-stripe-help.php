<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

final class Stripe_Help
{
    public static function render(): void
    {
        echo '<details class="gkod-stripe-help"><summary>'.esc_html__('Stripe setup — step-by-step instructions','georg-kloster-orthodox-donations').'</summary><ol>';
        self::step(__('Sign in to the Stripe account that will receive donations. Choose a Sandbox or test environment first. The API key and webhook must belong to the same account and environment.','georg-kloster-orthodox-donations'));
        echo '<li>';self::link('https://dashboard.stripe.com/apikeys',__('Open Stripe API keys','georg-kloster-orthodox-donations'));echo '</li>';
        self::step(__('Reveal or create a Secret key on the API keys page and paste it into Secret Key. Test keys start with sk_test_, live keys with sk_live_. A pk_ publishable key, account password or whsec_ signing secret cannot replace it.','georg-kloster-orthodox-donations'));
        echo '<li>';self::link('https://dashboard.stripe.com/webhooks',__('Open Stripe Webhooks / Workbench','georg-kloster-orthodox-donations'));echo '</li>';
        self::step(__('In Workbench → Webhooks, choose Create an event destination (or Add destination / Add endpoint in older dashboards). Choose Your account, the Snapshot payload format and Webhook endpoint as the destination. This plugin does not process Thin events.','georg-kloster-orthodox-donations'));
        self::step(__('Paste the exact Endpoint URL below. It must be publicly accessible over HTTPS and accept POST requests without login, redirects or a security challenge. Do not enter the WordPress settings-page URL.','georg-kloster-orthodox-donations'));
        echo '<li><code>'.esc_html(Payments::api_url('stripe/webhook')).'</code></li>';
        self::step(__('For one-time donations and prayer requests, select checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed and charge.refunded. For regular support, additionally select the following subscription and invoice events. Save the destination.','georg-kloster-orthodox-donations'));
        echo '<li><code>invoice.payment_succeeded, invoice.payment_failed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted</code></li>';
        self::step(__('Open the saved destination and reveal its Signing secret, starting with whsec_. Paste it into Webhook Secret. Use the secret of this exact endpoint and environment, not a Stripe CLI listener secret. Enable Stripe and save the plugin settings.','georg-kloster-orthodox-donations'));
        self::step(__('A blank Secret Key or Webhook Secret field preserves the saved value. When switching to Live, replace both values with the Live account key and Live endpoint signing secret. The plugin stores one set of Stripe credentials.','georg-kloster-orthodox-donations'));
        self::step(__('Submit a real checkout from a page on your test site. In the test environment, use a Stripe test card from the official testing guide, not a real card. Verify Paid status and configured emails. For regular support, also test a payment cycle, cancellation and refunds.','georg-kloster-orthodox-donations'));
        echo '<li>';self::link('https://docs.stripe.com/testing',__('Stripe test cards and testing guide','georg-kloster-orthodox-donations'));echo '</li>';
        self::step(__('In the destination, inspect event deliveries and their HTTP responses. A successful delivery should return 2xx. Fix the cause before resending a failed event: 400 can indicate a wrong signing secret, payload format or payment mismatch; 404 indicates a wrong endpoint URL; 403 or a security page can indicate blocked POST requests.','georg-kloster-orthodox-donations'));
        self::step(__('Opening the webhook URL in a browser sends GET and is not a payment test. A Dashboard sample event may not match a saved plugin order. Test a checkout created by this plugin and inspect its event delivery. A success return URL alone does not confirm payment.','georg-kloster-orthodox-donations'));
        self::step(__('After a domain move, update the destination URL in Stripe. Never publish API keys or signing secrets. Plugin diagnostics checks whether settings exist, not whether Stripe accepts them.','georg-kloster-orthodox-donations'));
        echo '</ol><p>';self::link('https://docs.stripe.com/keys',__('Stripe documentation: API keys','georg-kloster-orthodox-donations'));echo ' · ';self::link('https://docs.stripe.com/webhooks',__('Stripe documentation: webhook setup and signatures','georg-kloster-orthodox-donations'));echo '</p></details>';
    }

    private static function step(string $text): void { echo '<li>'.esc_html($text).'</li>'; }
    private static function link(string $url,string $label): void
    {
        echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.esc_html($label).'</a>';
    }
}
