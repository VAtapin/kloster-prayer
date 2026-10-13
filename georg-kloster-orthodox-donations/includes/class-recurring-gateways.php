<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

/** Recurring APIs reuse the plugin's configured payment credentials. */
final class Recurring_Gateways
{
    public static function request(string $provider, string $method, string $path, array $body = [], string $idempotency = ''): array|\WP_Error
    {
        if ($provider === 'stripe') {
            $secret = trim((string) get_option('gkod_stripe_secret'));
            if ($secret === '') return new \WP_Error('configuration', __('Stripe is not configured.', 'georg-kloster-orthodox-donations'));
            $base = 'https://api.stripe.com'; $headers = ['Authorization' => 'Bearer ' . $secret];
            if ($idempotency !== '') $headers['Idempotency-Key'] = $idempotency;
        } else {
            $token = Payments::paypal_access_token();
            if (is_wp_error($token)) return new \WP_Error('paypal_auth', __('PayPal is not configured or authentication failed.', 'georg-kloster-orthodox-donations'));
            $base = get_option('gkod_paypal_mode', 'live') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
            $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Prefer' => 'return=representation'];
            if ($idempotency !== '') $headers['PayPal-Request-Id'] = $idempotency;
        }
        $args = ['method' => $method, 'headers' => $headers, 'timeout' => 25];
        if ($body) $args['body'] = $provider === 'stripe' ? $body : wp_json_encode($body);
        $response = wp_remote_request($base . $path, $args);
        if (is_wp_error($response)) return new \WP_Error('network', __('The payment provider is unavailable. Please try again.', 'georg-kloster-orthodox-donations'));
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) return new \WP_Error('provider', __('The payment provider could not complete this operation.', 'georg-kloster-orthodox-donations'));
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($decoded)) return new \WP_Error('response', __('Invalid payment provider response.', 'georg-kloster-orthodox-donations'));
        $decoded['_http_status'] = $status;
        return $decoded;
    }

    public static function plan_key(int $amount, string $interval): string
    {
        return 'gkod_plan_' . hash('sha256', (string) get_option('gkod_paypal_client_id') . '|' . get_option('gkod_paypal_mode', 'live') . '|' . $amount . '|' . $interval);
    }

    /** Product/plan provisioning is done by WP-Cron, not the public checkout request. */
    public static function prepare_plan(int $amount, string $interval, string $key, int $attempt = 0): void
    {
        if ($key !== self::plan_key($amount, $interval) || !in_array($interval, ['month','year'], true) || $amount < 50 || $amount > 99999999 || get_option($key)) return;
        $product = self::request('paypal', 'POST', '/v1/catalogs/products', ['name' => __('Regular support', 'georg-kloster-orthodox-donations'), 'type' => 'SERVICE'], substr(hash('sha256', $key . '-product'), 0, 36));
        $plan = is_wp_error($product) || empty($product['id']) ? new \WP_Error('product') : self::request('paypal', 'POST', '/v1/billing/plans', [
            'product_id' => $product['id'], 'name' => __('Regular support', 'georg-kloster-orthodox-donations'), 'status' => 'ACTIVE',
            'billing_cycles' => [['frequency' => ['interval_unit' => strtoupper($interval), 'interval_count' => 1], 'tenure_type' => 'REGULAR', 'sequence' => 1, 'total_cycles' => 0, 'pricing_scheme' => ['fixed_price' => ['value' => number_format($amount / 100, 2, '.', ''), 'currency_code' => 'EUR']]]],
            'payment_preferences' => ['auto_bill_outstanding' => false, 'payment_failure_threshold' => 2],
        ], substr(hash('sha256', $key . '-plan'), 0, 36));
        if (!is_wp_error($plan) && !empty($plan['id']) && ($plan['status'] ?? '') === 'ACTIVE') update_option($key, (string) $plan['id'], false);
        elseif ($attempt < 3) wp_schedule_single_event(time() + 300 * ($attempt + 1), 'gkod_prepare_recurring_plan', [$amount, $interval, $key, $attempt + 1]);
    }

    public static function checkout(object $subscription, object $order): array|\WP_Error
    {
        $success = add_query_arg(['gkod_payment' => 'success', 'gkod_order' => $order->public_id], $order->return_url ?: home_url('/'));
        $cancel = add_query_arg(['gkod_payment' => 'cancelled', 'gkod_order' => $order->public_id], $order->return_url ?: home_url('/'));
        if ($subscription->provider === 'stripe') {
            $response = self::request('stripe', 'POST', '/v1/checkout/sessions', [
                'mode' => 'subscription', 'success_url' => $success, 'cancel_url' => $cancel, 'customer_email' => $order->donor_email,
                'client_reference_id' => $order->public_id, 'metadata[gkod_subscription]' => $subscription->public_id,
                'subscription_data[metadata][gkod_subscription]' => $subscription->public_id,
                'line_items[0][quantity]' => '1', 'line_items[0][price_data][currency]' => 'eur',
                'line_items[0][price_data][unit_amount]' => (string) $subscription->amount_cents,
                'line_items[0][price_data][recurring][interval]' => $subscription->billing_interval,
                'line_items[0][price_data][product_data][name]' => I18n::in_language(static fn() => __('Regular support', 'georg-kloster-orthodox-donations'), $order->language),
            ], $subscription->public_id);
            if (is_wp_error($response)) return $response;
            $url = (string) ($response['url'] ?? '');
            return !empty($response['id']) && self::allowed_url($url, 'stripe') ? ['checkout_id' => $response['id'], 'provider_id' => null, 'url' => $url] : new \WP_Error('response', __('Invalid payment provider response.', 'georg-kloster-orthodox-donations'));
        }
        $key = self::plan_key((int) $subscription->amount_cents, $subscription->billing_interval);
        $plan = get_option($key);
        if (!$plan) {
            $args = [(int) $subscription->amount_cents, $subscription->billing_interval, $key, 0];
            if (!wp_next_scheduled('gkod_prepare_recurring_plan', $args)) wp_schedule_single_event(time(), 'gkod_prepare_recurring_plan', $args);
            return new \WP_Error('preparing', __('PayPal is preparing recurring support. Please try again shortly.', 'georg-kloster-orthodox-donations'));
        }
        $response = self::request('paypal', 'POST', '/v1/billing/subscriptions', [
            'plan_id' => $plan, 'custom_id' => $subscription->public_id,
            'application_context' => ['return_url' => $success, 'cancel_url' => $cancel, 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'SUBSCRIBE_NOW'],
        ], $subscription->public_id);
        if (is_wp_error($response)) return $response;
        foreach ($response['links'] ?? [] as $link) if (($link['rel'] ?? '') === 'approve' && !empty($response['id']) && ($response['plan_id'] ?? '') === $plan && self::allowed_url((string) ($link['href'] ?? ''), 'paypal')) return ['checkout_id' => null, 'provider_id' => $response['id'], 'plan_id' => (string) $plan, 'url' => $link['href']];
        return new \WP_Error('response', __('Invalid payment provider response.', 'georg-kloster-orthodox-donations'));
    }

    private static function allowed_url(string $url, string $provider): bool
    {
        $parts = wp_parse_url($url);
        $host = $provider === 'stripe' ? 'checkout.stripe.com' : (get_option('gkod_paypal_mode', 'live') === 'sandbox' ? 'www.sandbox.paypal.com' : 'www.paypal.com');
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === $host && !isset($parts['user']) && !isset($parts['pass']);
    }

    public static function cancel(object $subscription): bool|\WP_Error
    {
        if (!$subscription->provider_id && !$subscription->checkout_id) return true;
        if ($subscription->provider === 'stripe') {
            $path = $subscription->provider_id ? '/v1/subscriptions/' . rawurlencode($subscription->provider_id) : '/v1/checkout/sessions/' . rawurlencode((string) $subscription->checkout_id) . '/expire';
            $response = self::request('stripe', $subscription->provider_id ? 'DELETE' : 'POST', $path);
            if (is_wp_error($response)) return $response;
            return ($response['id'] ?? '') === ($subscription->provider_id ?: $subscription->checkout_id) && ($response['status'] ?? '') === ($subscription->provider_id ? 'canceled' : 'expired') ? true : new \WP_Error('response', __('Invalid payment provider response.', 'georg-kloster-orthodox-donations'));
        }
        if (!$subscription->provider_id) return true; // A failed provisioning attempt has no remote agreement.
        $response = self::request('paypal', 'POST', '/v1/billing/subscriptions/' . rawurlencode($subscription->provider_id) . '/cancel', ['reason' => 'Requested by the account owner']);
        return is_wp_error($response) ? $response : (($response['_http_status'] ?? 0) === 204 ? true : new \WP_Error('response', __('Invalid payment provider response.', 'georg-kloster-orthodox-donations')));
    }
}
