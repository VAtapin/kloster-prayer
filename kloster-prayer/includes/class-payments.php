<?php
declare(strict_types=1);

namespace KlosterPrayer;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Payments
{
    /** Empty means follow WordPress automatically. Only an HTTPS origin is accepted. */
    public static function normalize_api_origin(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        $parts = parse_url($value);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || !filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !str_contains($parts['host'], '.')
            || preg_match('/[\s\\\\]/', $value)) {
            throw new \InvalidArgumentException('Invalid HTTPS origin');
        }
        return 'https://'.strtolower($parts['host']).(isset($parts['port'])?':'.$parts['port']:'');
    }

    public static function api_url(string $path): string
    {
        $url = rest_url('kloster-prayer/v1/'.ltrim($path, '/'));
        try { $origin = self::normalize_api_origin((string)get_option('klp_api_origin', '')); }
        catch (\InvalidArgumentException $e) { return $url; }
        // Keep WordPress subdirectory, REST prefix and query-based permalink routing.
        return $origin === '' ? $url : (string)preg_replace('~^https?://[^/]+~', $origin, $url, 1);
    }

    public static function init(): void
    {
        add_action('klp_retry_confirmations', [self::class, 'retry_confirmations']);
        add_action('klp_cleanup_pending', [self::class, 'cleanup_pending']);
        if (!wp_next_scheduled('klp_retry_confirmations')) wp_schedule_event(time() + 300, 'hourly', 'klp_retry_confirmations');
        if (!wp_next_scheduled('klp_cleanup_pending')) wp_schedule_event(time() + 600, 'daily', 'klp_cleanup_pending');
        add_action('rest_api_init', static function (): void {
            register_rest_route('kloster-prayer/v1', '/stripe/webhook', ['methods' => 'POST', 'callback' => [self::class, 'stripe_webhook'], 'permission_callback' => '__return_true']);
            register_rest_route('kloster-prayer/v1', '/paypal/webhook', ['methods' => 'POST', 'callback' => [self::class, 'paypal_webhook'], 'permission_callback' => '__return_true']);
            register_rest_route('kloster-prayer/v1', '/paypal/return', ['methods' => 'GET', 'callback' => [self::class, 'paypal_return'], 'permission_callback' => '__return_true']);
        });
    }

    public static function create_payment(): void
    {
        check_ajax_referer('klp_payment', 'nonce');
        $language = in_array($_POST['language'] ?? '', ['de', 'ru', 'uk', 'pl'], true) ? (string) $_POST['language'] : I18n::language();
        if (!empty($_POST['website'])) {
            wp_send_json_error(['message' => I18n::text('error', $language)], 422);
        }
        $rate_key = 'klp_rate_' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('nonce'));
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 10) {
            wp_send_json_error(['message' => I18n::text('error', $language)], 429);
        }
        set_transient($rate_key, $attempts + 1, MINUTE_IN_SECONDS);
        $provider = sanitize_key((string) ($_POST['provider'] ?? 'stripe'));
        $intention = sanitize_key((string) ($_POST['intention'] ?? 'health'));
        $names = self::clean_names((string) wp_unslash($_POST['names'] ?? ''));
        $email = sanitize_email((string) wp_unslash($_POST['donor_email'] ?? ''));
        $service_ids = array_values(array_unique(array_map('absint', (array) ($_POST['services'] ?? []))));
        $enabled_methods = (array) get_option('klp_payment_methods', ['stripe', 'paypal']);
        if (!$names || !is_email($email) || strlen($email) > 190 || !$service_ids || count($service_ids) > 30 || !in_array($provider, $enabled_methods, true) || !in_array($intention, ['health', 'rest'], true)) {
            wp_send_json_error(['message' => I18n::text('required', $language)], 422);
        }

        $order = self::create_order([
            'form_id' => absint($_POST['form_id'] ?? 0), 'language' => $language, 'intention' => $intention,
            'names' => implode("\n", $names), 'note' => sanitize_textarea_field((string) wp_unslash($_POST['note'] ?? '')),
            'donor_name' => sanitize_text_field((string) wp_unslash($_POST['donor_name'] ?? '')), 'donor_email' => $email,
            'provider' => $provider, 'service_ids' => $service_ids, 'return_url' => self::safe_return_url(),
        ]);
        if (is_wp_error($order)) {
            wp_send_json_error(['message' => $order->get_error_message()], 422);
        }

        $redirect = $provider === 'stripe' ? self::stripe_checkout($order) : self::paypal_checkout($order);
        if (is_wp_error($redirect)) {
            self::set_status((int) $order->id, 'failed');
            wp_send_json_error(['message' => $redirect->get_error_message()], 502);
        }
        wp_send_json_success(['redirect' => $redirect]);
    }

    /** Shared parser for new submissions and legacy PDF records. Never split spaces inside names. */
    public static function split_names(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;，;\\r\\n\\x{2028}\\x{2029}]+/u', $raw) ?: []), static fn(string $name): bool => $name !== ''));
    }

    private static function clean_names(string $raw): array
    {
        $lines = self::split_names(sanitize_textarea_field($raw));
        $lines = array_values(array_filter(array_map(static fn(string $name): string => trim(wp_strip_all_tags($name)), $lines)));
        return array_slice($lines, 0, 300);
    }

    private static function create_order(array $data): object
    {
        global $wpdb;
        $services_table = Database::table('services');
        $placeholders = implode(',', array_fill(0, count($data['service_ids']), '%d'));
        $services = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$services_table} WHERE is_active=1 AND id IN ({$placeholders})", ...$data['service_ids']));
        if (!$services || count($services) !== count($data['service_ids'])) {
            return new WP_Error('invalid_services', I18n::text('error', $data['language']));
        }
        $name_count = count(explode("\n", $data['names']));
        $amount = 0;
        $items = [];
        foreach ($services as $service) {
            if (!in_array($service->intention_mode, ['both', 'none', $data['intention']], true)) {
                return new WP_Error('invalid_intention', I18n::text('error', $data['language']));
            }
            $quantity = match ($service->pricing_mode) {
                'per_person' => $name_count,
                'per_sheet' => max(1, (int) ceil($name_count / max(1, (int) $service->names_per_sheet))),
                default => 1,
            };
            $subtotal = (int) $service->price_cents * $quantity;
            $amount += $subtotal;
            $items[] = [$service, $quantity, $subtotal];
        }
        if ($amount < 50) {
            return new WP_Error('invalid_amount', I18n::text('error', $data['language']));
        }
        $uuid = wp_generate_uuid4();
        $ok = $wpdb->insert(Database::table('orders'), [
            'public_id' => $uuid, 'form_id' => $data['form_id'] ?: null, 'language' => $data['language'],
            'intention' => $data['intention'], 'names' => $data['names'], 'note' => $data['note'],
            'donor_name' => $data['donor_name'], 'donor_email' => $data['donor_email'], 'amount_cents' => $amount,
            'currency' => 'EUR', 'payment_status' => 'pending', 'payment_provider' => $data['provider'], 'created_at' => current_time('mysql'),
            'return_url' => $data['return_url'],
        ]);
        if (!$ok) {
            return new WP_Error('database', I18n::text('error', $data['language']));
        }
        $order_id = (int) $wpdb->insert_id;
        foreach ($items as [$service, $quantity, $subtotal]) {
            $title_key = 'title_' . $data['language'];
            $wpdb->insert(Database::table('order_items'), [
                'order_id' => $order_id, 'service_id' => (int) $service->id, 'service_title' => $service->{$title_key},
                'pricing_mode' => $service->pricing_mode, 'unit_price_cents' => (int) $service->price_cents,
                'quantity' => $quantity, 'sheet_count' => $service->pricing_mode === 'per_sheet' ? $quantity : max(1, (int) ceil($name_count / 10)),
                'subtotal_cents' => $subtotal,
            ]);
        }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::table('orders') . ' WHERE id=%d', $order_id));
    }

    private static function return_url(object $order, string $status): string
    {
        $base = !empty($order->return_url) ? (string) $order->return_url : home_url('/');
        return add_query_arg(['kloster_payment' => $status, 'kloster_order' => $order->public_id], $base);
    }

    private static function safe_return_url(): string
    {
        $referer = wp_get_referer();
        if (!$referer || !str_starts_with($referer, home_url('/'))) return home_url('/');
        return remove_query_arg(['kloster_payment', 'kloster_order'], $referer);
    }

    private static function stripe_checkout(object $order): string|WP_Error
    {
        $secret = trim((string) get_option('klp_stripe_secret'));
        if ($secret === '') return new WP_Error('stripe_config', 'Stripe is not configured.');
        $body = [
            'mode' => 'payment', 'success_url' => self::return_url($order, 'success'), 'cancel_url' => self::return_url($order, 'cancelled'),
            'customer_email' => $order->donor_email, 'client_reference_id' => $order->public_id,
            'billing_address_collection' => 'required', 'phone_number_collection[enabled]' => 'true',
            'metadata[order_id]' => (string) $order->id, 'metadata[public_id]' => $order->public_id,
            'payment_intent_data[metadata][order_id]' => (string) $order->id,
            'line_items[0][quantity]' => '1', 'line_items[0][price_data][currency]' => 'eur',
            'line_items[0][price_data][unit_amount]' => (string) $order->amount_cents,
            'line_items[0][price_data][product_data][name]' => self::payment_label($order),
        ];
        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', ['timeout' => 25, 'headers' => ['Authorization' => 'Bearer ' . $secret], 'body' => $body]);
        return self::payment_url_from_response($response, 'url', (int) $order->id, 'stripe');
    }

    public static function refresh_customer(int $id): bool
    {
        global $wpdb;
        $order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('orders').' WHERE id=%d',$id));
        if(!$order || $order->payment_provider!=='stripe' || !preg_match('/^cs_(live|test)_[A-Za-z0-9]+$/',(string)$order->payment_id))return false;
        $secret=trim((string)get_option('klp_stripe_secret'));
        if($secret==='')return false;
        $response=wp_remote_get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($order->payment_id),['timeout'=>20,'headers'=>['Authorization'=>'Bearer '.$secret]]);
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return false;
        $session=json_decode(wp_remote_retrieve_body($response),true);
        if(($session['id']??'')!==$order->payment_id)return false;
        $details=(array)($session['customer_details']??[]);
        if(!$details)return false;
        // A read of customer data must never change payment state or send email.
        self::store_customer_details($id,$details);
        return true;
    }

    private static function paypal_access_token(): string|WP_Error
    {
        $client = trim((string) get_option('klp_paypal_client_id'));
        $secret = trim((string) get_option('klp_paypal_secret'));
        if ($client === '' || $secret === '') return new WP_Error('paypal_config', 'PayPal is not configured.');
        $base = get_option('klp_paypal_mode', 'live') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $response = wp_remote_post($base . '/v1/oauth2/token', ['timeout' => 25, 'headers' => ['Authorization' => 'Basic ' . base64_encode($client . ':' . $secret)], 'body' => ['grant_type' => 'client_credentials']]);
        if (is_wp_error($response)) return $response;
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        return !empty($json['access_token']) ? $json['access_token'] : new WP_Error('paypal_auth', 'PayPal authentication failed.');
    }

    private static function paypal_checkout(object $order): string|WP_Error
    {
        $token = self::paypal_access_token();
        if (is_wp_error($token)) return $token;
        $base = get_option('klp_paypal_mode', 'live') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $return = add_query_arg(['order' => $order->public_id], self::api_url('paypal/return'));
        $payload = ['intent' => 'CAPTURE', 'purchase_units' => [[
            'reference_id' => $order->public_id, 'custom_id' => (string) $order->id,
            'description' => self::payment_label($order),
            'amount' => ['currency_code' => 'EUR', 'value' => number_format($order->amount_cents / 100, 2, '.', '')],
        ]], 'payment_source' => ['paypal' => ['experience_context' => ['return_url' => $return, 'cancel_url' => self::return_url($order, 'cancelled'), 'shipping_preference' => 'NO_SHIPPING']]]];
        $response = wp_remote_post($base . '/v2/checkout/orders', ['timeout' => 25, 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'PayPal-Request-Id' => $order->public_id], 'body' => wp_json_encode($payload)]);
        if (is_wp_error($response)) return $response;
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!empty($json['id'])) self::set_payment_id((int) $order->id, (string) $json['id']);
        foreach (($json['links'] ?? []) as $link) if (($link['rel'] ?? '') === 'payer-action') return (string) $link['href'];
        return new WP_Error('paypal_create', 'PayPal order creation failed.');
    }

    private static function payment_url_from_response(array|WP_Error $response, string $key, int $order_id, string $provider): string|WP_Error
    {
        if (is_wp_error($response)) return $response;
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) >= 300 || empty($json[$key])) return new WP_Error($provider . '_create', $json['error']['message'] ?? ucfirst($provider) . ' payment creation failed.');
        if (!empty($json['id'])) self::set_payment_id($order_id, (string) $json['id']);
        return (string) $json[$key];
    }

    private static function payment_label(object $order): string
    {
        global $wpdb;
        $items = $wpdb->get_col($wpdb->prepare('SELECT service_title FROM ' . Database::table('order_items') . ' WHERE order_id=%d ORDER BY id', (int) $order->id));
        $prefix = $order->language === 'ru' ? 'Молитвенная записка: ' : 'Gebetsanliegen: ';
        return mb_substr($prefix . implode(', ', $items), 0, 120);
    }

    public static function stripe_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_body();
        $signature = (string) $request->get_header('stripe-signature');
        if (!self::verify_stripe_signature($payload, $signature, (string) get_option('klp_stripe_webhook_secret'))) return new WP_REST_Response(['error' => 'Invalid signature'], 400);
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id'])) return new WP_REST_Response(['error' => 'Invalid payload'], 400);
        $object = $event['data']['object'] ?? [];
        $order_id = (int) ($object['metadata']['order_id'] ?? $object['client_reference_id'] ?? 0);
        if (!self::record_event('stripe', (string) $event['id'], (string) ($event['type'] ?? ''), $order_id, $payload)) return new WP_REST_Response(['received' => true], 200);
        if (($event['type'] ?? '') === 'checkout.session.completed' && ($object['payment_status'] ?? '') === 'paid') {
            self::store_customer_details($order_id, (array) ($object['customer_details'] ?? []));
            self::mark_paid($order_id, (string) ($object['id'] ?? ''));
        }
        if (($event['type'] ?? '') === 'charge.refunded' && $order_id > 0) self::set_status($order_id, 'refunded');
        return new WP_REST_Response(['received' => true], 200);
    }

    private static function verify_stripe_signature(string $payload, string $header, string $secret): bool
    {
        if ($secret === '' || $header === '') return false;
        $parts = []; foreach (explode(',', $header) as $part) { [$key, $value] = array_pad(explode('=', trim($part), 2), 2, ''); $parts[$key][] = $value; }
        $timestamp = (int) ($parts['t'][0] ?? 0); if (!$timestamp || abs(time() - $timestamp) > 300) return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($parts['v1'] ?? [] as $signature) if (hash_equals($expected, $signature)) return true;
        return false;
    }

    public static function paypal_return(WP_REST_Request $request): void
    {
        global $wpdb;
        $public_id = sanitize_text_field((string) $request->get_param('order'));
        $token_id = sanitize_text_field((string) $request->get_param('token'));
        $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::table('orders') . ' WHERE public_id=%s', $public_id));
        if (!$order || !$token_id) { wp_safe_redirect(home_url('/')); exit; }
        $token = self::paypal_access_token();
        if (!is_wp_error($token)) {
            $base = get_option('klp_paypal_mode', 'live') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
            $response = wp_remote_post($base . '/v2/checkout/orders/' . rawurlencode($token_id) . '/capture', ['timeout' => 25, 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], 'body' => '{}']);
            $json = !is_wp_error($response) ? json_decode((string) wp_remote_retrieve_body($response), true) : [];
            if (($json['status'] ?? '') === 'COMPLETED') self::mark_paid((int) $order->id, $token_id);
        }
        wp_safe_redirect(self::return_url($order, 'success')); exit;
    }

    public static function paypal_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $event_id = (string) ($payload['id'] ?? '');
        if ($event_id === '') return new WP_REST_Response(['error' => 'Invalid payload'], 400);
        $verified = self::verify_paypal_webhook($request, $payload);
        if (!$verified) return new WP_REST_Response(['error' => 'Invalid signature'], 400);
        $resource = $payload['resource'] ?? [];
        $order_id = (int) ($resource['custom_id'] ?? 0);
        if (!$order_id && !empty($resource['supplementary_data']['related_ids']['order_id'])) {
            global $wpdb;
            $order_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Database::table('orders') . ' WHERE payment_provider=\'paypal\' AND payment_id=%s', (string) $resource['supplementary_data']['related_ids']['order_id']));
        }
        if (self::record_event('paypal', $event_id, (string) ($payload['event_type'] ?? ''), $order_id, $request->get_body())) {
            if (($payload['event_type'] ?? '') === 'PAYMENT.CAPTURE.COMPLETED') self::mark_paid($order_id, (string) ($resource['id'] ?? ''));
            if (($payload['event_type'] ?? '') === 'PAYMENT.CAPTURE.REFUNDED' && $order_id > 0) self::set_status($order_id, 'refunded');
        }
        return new WP_REST_Response(['received' => true], 200);
    }

    private static function verify_paypal_webhook(WP_REST_Request $request, array $event): bool
    {
        $webhook_id = trim((string) get_option('klp_paypal_webhook_id')); $token = self::paypal_access_token();
        if ($webhook_id === '' || is_wp_error($token)) return false;
        $base = get_option('klp_paypal_mode', 'live') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $body = ['auth_algo' => $request->get_header('paypal-auth-algo'), 'cert_url' => $request->get_header('paypal-cert-url'), 'transmission_id' => $request->get_header('paypal-transmission-id'), 'transmission_sig' => $request->get_header('paypal-transmission-sig'), 'transmission_time' => $request->get_header('paypal-transmission-time'), 'webhook_id' => $webhook_id, 'webhook_event' => $event];
        $response = wp_remote_post($base . '/v1/notifications/verify-webhook-signature', ['timeout' => 25, 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], 'body' => wp_json_encode($body)]);
        $json = !is_wp_error($response) ? json_decode((string) wp_remote_retrieve_body($response), true) : [];
        return ($json['verification_status'] ?? '') === 'SUCCESS';
    }

    private static function record_event(string $provider, string $event_id, string $type, int $order_id, string $payload): bool
    {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . Database::table('payment_events') . ' (provider,event_id,event_type,order_id,payload_hash,processed,created_at) VALUES (%s,%s,%s,%d,%s,1,%s)', $provider, $event_id, $type, $order_id ?: null, hash('sha256', $payload), current_time('mysql')));
    }

    private static function set_payment_id(int $order_id, string $payment_id): void { global $wpdb; $wpdb->update(Database::table('orders'), ['payment_id' => $payment_id], ['id' => $order_id]); }
    private static function set_status(int $order_id, string $status): void { global $wpdb; $changed=$wpdb->update(Database::table('orders'), ['payment_status' => $status], ['id' => $order_id]); if($changed) Order_Workflow::log($order_id,'payment_status','Статус оплаты: '.$status); }

    private static function store_customer_details(int $order_id, array $details): void
    {
        if ($order_id <= 0) return;
        global $wpdb;
        $wpdb->update(Database::table('orders'), [
            'billing_name' => sanitize_text_field((string) ($details['name'] ?? '')),
            'billing_email' => sanitize_email((string) ($details['email'] ?? '')),
            'billing_phone' => sanitize_text_field((string) ($details['phone'] ?? '')),
            'billing_address' => wp_json_encode((array) ($details['address'] ?? []), JSON_UNESCAPED_UNICODE),
        ], ['id' => $order_id]);
    }

    private static function mark_paid(int $order_id, string $payment_id): void
    {
        global $wpdb;
        if ($order_id <= 0) return;
        $table = Database::table('orders');
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET payment_status='paid',payment_id=COALESCE(NULLIF(%s,''),payment_id),paid_at=COALESCE(paid_at,%s) WHERE id=%d AND payment_status<>'paid'", $payment_id, current_time('mysql'), $order_id));
        if ($wpdb->rows_affected > 0) Order_Workflow::log($order_id,'paid','Оплата подтверждена');
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $order_id));
        if ($order && !$order->confirmation_sent_at) self::send_confirmation($order);
    }

    public static function retry_confirmations(): void
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . Database::table('orders') . " WHERE payment_status='paid' AND confirmation_sent_at IS NULL ORDER BY paid_at ASC LIMIT 25");
        foreach ($rows as $order) self::send_confirmation($order);
    }

    public static function cleanup_pending(): void
    {
        global $wpdb;
        $cutoff = wp_date('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("UPDATE " . Database::table('orders') . " SET payment_status='cancelled' WHERE payment_status='pending' AND created_at < %s", $cutoff));
    }

    public static function resend_confirmation(int $order_id): bool
    {
        global $wpdb;
        $table = Database::table('orders');
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND payment_status='paid'", $order_id));
        if (!$order) return false;
        $wpdb->update($table, ['confirmation_sent_at' => null], ['id' => $order_id]);
        self::send_confirmation($order);
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT confirmation_sent_at FROM {$table} WHERE id=%d", $order_id));
    }

    private static function send_confirmation(object $order): void
    {
        global $wpdb;
        $orders = Database::table('orders');
        $claimed = $wpdb->query($wpdb->prepare("UPDATE {$orders} SET confirmation_sent_at=%s WHERE id=%d AND confirmation_sent_at IS NULL", current_time('mysql'), (int) $order->id));
        if (!$claimed) return;
        $lang = in_array($order->language, ['de','ru','uk','pl'], true) ? $order->language : 'de';
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $logo_id = (int) get_theme_mod('custom_logo');
        $logo_url = $logo_id ? (string) wp_get_attachment_image_url($logo_id, 'medium') : (string) get_site_icon_url(512);
        $subjects=['de'=>'Ihr Gebetsanliegen ist eingegangen','ru'=>'Ваша молитвенная записка получена','uk'=>'Вашу молитовну записку отримано','pl'=>'Otrzymaliśmy Twoją intencję modlitewną']; $greets=['de'=>'Guten Tag','ru'=>'Здравствуйте','uk'=>'Вітаємо','pl'=>'Dzień dobry'];
        $subject=$subjects[$lang]; $greeting=$greets[$lang].($order->donor_name?', '.esc_html($order->donor_name):'').'!';
        $items = $wpdb->get_col($wpdb->prepare('SELECT service_title FROM ' . Database::table('order_items') . ' WHERE order_id=%d', (int) $order->id));
        $intention = I18n::text($order->intention === 'rest' ? 'rest' : 'health', $lang);
        $amount = number_format_i18n(((int) $order->amount_cents) / 100, 2) . ' €';
        $labels=['de'=>['Gebetsanliegen','Gebetsart','Spendensumme','Namen'],'ru'=>['Треба','Тип записки','Сумма пожертвования','Имена'],'uk'=>['Треба','Тип записки','Сума пожертви','Імена'],'pl'=>['Intencja','Rodzaj modlitwy','Kwota darowizny','Imiona']][$lang];
        $footer = ['de'=>'Mit Dankbarkeit und Gebet,<br>','ru'=>'С благодарностью и молитвой,<br>','uk'=>'Із вдячністю та молитвою,<br>','pl'=>'Z wdzięcznością i modlitwą,<br>'][$lang] . esc_html($site_name);
        $body = '<!doctype html><html><body style="margin:0;background:#f3f5f7;font-family:Arial,sans-serif;color:#202936"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:28px 12px"><table role="presentation" width="620" cellspacing="0" cellpadding="0" style="max-width:620px;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 8px 30px rgba(25,40,55,.10)"><tr><td align="center" style="padding:28px 30px 22px;border-top:5px solid #198fd9"><img src="https://georg-kloster.ru/wp-content/uploads/2023/03/logo2t.png" width="360" alt="Kloster St. Georg" style="display:block;max-width:100%;height:auto"></td></tr><tr><td style="padding:8px 42px 38px"><h1 style="margin:0 0 18px;font-size:25px;line-height:1.3;text-align:center">'.esc_html($subject).'</h1><p style="font-size:16px;line-height:1.65">'.$greeting.'</p><p style="font-size:16px;line-height:1.65">'.esc_html(I18n::text('thanks',$lang)).'</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0;border:1px solid #dce4ea;border-radius:5px"><tr><td style="padding:12px 16px;background:#f5f9fc"><strong>'.esc_html($labels[0]).'</strong></td><td style="padding:12px 16px;background:#f5f9fc;text-align:right">'.esc_html(implode(', ',$items)).'</td></tr><tr><td style="padding:12px 16px;border-top:1px solid #dce4ea"><strong>'.esc_html($labels[1]).'</strong></td><td style="padding:12px 16px;border-top:1px solid #dce4ea;text-align:right">'.esc_html($intention).'</td></tr><tr><td style="padding:12px 16px;border-top:1px solid #dce4ea"><strong>'.esc_html($labels[2]).'</strong></td><td style="padding:12px 16px;border-top:1px solid #dce4ea;text-align:right"><strong>'.esc_html($amount).'</strong></td></tr></table><h2 style="font-size:17px;margin:25px 0 10px">'.esc_html($labels[3]).'</h2><div style="padding:17px 20px;background:#f8fafb;border-left:4px solid #198fd9;font-size:16px;line-height:1.7">'.nl2br(esc_html($order->names)).'</div><p style="margin:28px 0 0;color:#53606d;font-size:15px;line-height:1.6">'.$footer.'</p></td></tr><tr><td style="padding:17px 30px;background:#202936;color:#cfd6dc;text-align:center;font-size:12px">Götschendorf · <a href="https://georg-kloster.ru/" style="color:#fff">georg-kloster.ru</a></td></tr></table></td></tr></table></body></html>';
        $body = str_replace(['https://georg-kloster.ru/wp-content/uploads/2023/03/logo2t.png','Kloster St. Georg','Götschendorf','https://georg-kloster.ru/','georg-kloster.ru'], [esc_url($logo_url),esc_attr($site_name),esc_html($site_name),esc_url(home_url('/')),esc_html((string) (wp_parse_url(home_url('/'),PHP_URL_HOST) ?: $site_name))], $body);
        if (!wp_mail($order->donor_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8'])) { $wpdb->update($orders, ['confirmation_sent_at' => null], ['id' => (int) $order->id]); Order_Workflow::log((int)$order->id,'email_failed','Не удалось передать письмо почтовой системе'); } else { Order_Workflow::log((int)$order->id,'email_sent','Подтверждение передано почтовой системе'); }
    }
}
