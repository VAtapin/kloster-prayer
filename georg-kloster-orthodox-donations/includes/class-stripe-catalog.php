<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

use WP_Error;

final class Stripe_Catalog
{
    /** Synchronize only selected services to the Stripe account/environment of the saved key. */
    public static function sync_selected(array $ids): array
    {
        global $wpdb;
        $result = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (!$ids) {
            $result['failed'] = 1;
            $result['errors'][] = __('Select at least one service to synchronize.', 'georg-kloster-orthodox-donations');
            return $result;
        }
        $secret = trim((string) get_option('gkod_stripe_secret'));
        if (!preg_match('/^sk_(test|live)_/', $secret, $matches)) {
            $result['failed'] = 1;
            $result['errors'][] = __('A valid Stripe Secret Key is required to synchronize products.', 'georg-kloster-orthodox-donations');
            return $result;
        }

        $live = $matches[1] === 'live' ? 1 : 0;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $services = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE id IN (' . $placeholders . ') ORDER BY sort_order,id',
            ...array_merge([Database::table('services')], $ids)
        ));
        if (!$services) {
            $result['failed'] = 1;
            $result['errors'][] = __('No selected services were found.', 'georg-kloster-orthodox-donations');
            return $result;
        }
        foreach ($services as $service) {
            $synced = self::sync_service($service, $secret, $live);
            if (is_wp_error($synced)) {
                $result['failed']++;
                $result['errors'][] = sprintf('%1$s: %2$s', self::service_title($service), $synced->get_error_message());
                continue;
            }
            $result[$synced === 'created' ? 'created' : 'updated']++;
        }
        return $result;
    }

    /** Whether the mapping displayed in the service table matches its current content and price. */
    public static function is_current(object $service): bool
    {
        return !empty($service->stripe_product_id)
            && !empty($service->stripe_price_id)
            && (int) ($service->stripe_amount_cents ?? 0) === (int) $service->price_cents
            && !empty($service->stripe_catalog_hash)
            && hash_equals((string) $service->stripe_catalog_hash, self::catalog_hash($service));
    }

    /** Return catalog-backed Checkout items, or null to keep the aggregate-price fallback. */
    public static function checkout_items(int $order_id, int $live, int $expected_total, string $key_hash): ?array
    {
        global $wpdb;
        $items = $wpdb->get_results($wpdb->prepare(
            'SELECT i.quantity,i.unit_price_cents,i.subtotal_cents,c.price_id,c.amount_cents,c.product_id,c.catalog_hash,s.* FROM %i i LEFT JOIN %i c ON c.service_id=i.service_id AND c.livemode=%d AND c.key_hash=%s LEFT JOIN %i s ON s.id=i.service_id WHERE i.order_id=%d ORDER BY i.id',
            Database::table('order_items'), Database::table('service_stripe_products'), $live, $key_hash, Database::table('services'), $order_id
        ));
        if (!$items) return null;
        $lines = [];
        $total = 0;
        foreach ($items as $item) {
            if (empty($item->price_id) || empty($item->product_id) || (int) $item->amount_cents !== (int) $item->unit_price_cents || empty($item->catalog_hash) || !hash_equals((string) $item->catalog_hash, self::catalog_hash($item))) return null;
            $lines[] = ['price' => (string) $item->price_id, 'quantity' => (int) $item->quantity];
            $total += (int) $item->subtotal_cents;
        }
        return $total === $expected_total ? $lines : null;
    }

    private static function sync_service(object $service, string $secret, int $live): string|WP_Error
    {
        global $wpdb;
        $table = Database::table('service_stripe_products');
        $key_hash = hash('sha256', $secret);
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE service_id=%d AND livemode=%d AND key_hash=%s', $table, (int) $service->id, $live, $key_hash));
        $name = self::service_title($service);
        $data = [
            'name' => $name,
            'description' => trim((string) ($service->description_de ?: $service->description_ru)),
            'active' => (int) $service->is_active ? 'true' : 'false',
            'metadata[gkod_service_id]' => (string) $service->id,
            'metadata[gkod_service_slug]' => (string) $service->slug,
            'metadata[gkod_pricing_mode]' => (string) $service->pricing_mode,
        ];
        $image = Service_Images::url($service);
        $data['images'] = $image !== '' ? [$image] : '';
        $unit_label = self::unit_label((string) $service->pricing_mode);
        if ($unit_label !== '') $data['unit_label'] = $unit_label;
        else $data['unit_label'] = '';
        $catalog_hash = self::catalog_hash($service);

        if (!$existing) {
            $data['default_price_data'] = ['currency' => 'eur', 'unit_amount' => (int) $service->price_cents];
            $created = self::request('POST', 'products', $data, $secret, 'gkod_service_' . (int) $service->id . '_' . $live);
            if (is_wp_error($created)) return $created;
            if (empty($created['id']) || empty($created['default_price'])) return new WP_Error('stripe_product_response', __('Stripe did not return a product and its price.', 'georg-kloster-orthodox-donations'));
            $row = [
                'service_id' => (int) $service->id, 'livemode' => $live, 'key_hash' => $key_hash,
                'product_id' => sanitize_text_field((string) $created['id']),
                'price_id' => sanitize_text_field((string) $created['default_price']),
                'amount_cents' => (int) $service->price_cents, 'catalog_hash' => $catalog_hash,
                'synced_at' => current_time('mysql'),
            ];
            if (!$wpdb->replace($table, $row)) return new WP_Error('stripe_catalog_store', __('The Stripe product was created but its link could not be saved in WordPress.', 'georg-kloster-orthodox-donations'));
            return 'created';
        }

        $updated = self::request('POST', 'products/' . rawurlencode((string) $existing->product_id), $data, $secret);
        if (is_wp_error($updated)) return $updated;
        $price_id = (string) $existing->price_id;
        if ((int) $existing->amount_cents !== (int) $service->price_cents) {
            $new_price = self::request('POST', 'prices', [
                'product' => (string) $existing->product_id,
                'currency' => 'eur',
                'unit_amount' => (int) $service->price_cents,
                'active' => 'true',
            ], $secret, 'gkod_price_' . (int) $service->id . '_' . $live . '_' . sanitize_key((string) $existing->price_id) . '_' . (int) $service->price_cents);
            if (is_wp_error($new_price)) return $new_price;
            if (empty($new_price['id'])) return new WP_Error('stripe_price_response', __('Stripe did not return a price ID.', 'georg-kloster-orthodox-donations'));
            $price_id = (string) $new_price['id'];
            $default = self::request('POST', 'products/' . rawurlencode((string) $existing->product_id), ['default_price' => $price_id], $secret);
            if (is_wp_error($default)) return $default;
            $archived = self::request('POST', 'prices/' . rawurlencode((string) $existing->price_id), ['active' => 'false'], $secret);
            if (is_wp_error($archived)) return $archived;
        }
        $saved = $wpdb->update($table, [
            'price_id' => sanitize_text_field($price_id),
            'amount_cents' => (int) $service->price_cents,
            'catalog_hash' => $catalog_hash,
            'synced_at' => current_time('mysql'),
        ], ['service_id' => (int) $service->id, 'livemode' => $live]);
        if ($saved === false) return new WP_Error('stripe_catalog_store', __('The Stripe product was updated but its link could not be saved in WordPress.', 'georg-kloster-orthodox-donations'));
        return 'updated';
    }

    private static function request(string $method, string $path, array $body, string $secret, string $idempotency_key = ''): array|WP_Error
    {
        $headers = ['Authorization' => 'Bearer ' . $secret];
        if ($idempotency_key !== '') $headers['Idempotency-Key'] = $idempotency_key;
        $response = wp_remote_request('https://api.stripe.com/v1/' . ltrim($path, '/'), [
            'method' => $method, 'timeout' => 15, 'headers' => $headers, 'body' => $body,
        ]);
        if (is_wp_error($response)) return $response;
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300 || !is_array($json)) {
            $message = (string) ($json['error']['message'] ?? __('Stripe API request failed.', 'georg-kloster-orthodox-donations'));
            return new WP_Error('stripe_catalog_api', sanitize_text_field($message));
        }
        return $json;
    }

    private static function service_title(object $service): string
    {
        return trim((string) ($service->title_de ?: $service->title_ru ?: $service->slug));
    }

    private static function catalog_hash(object $service): string
    {
        $description = trim((string) ($service->description_de ?: $service->description_ru));
        return hash('sha256', wp_json_encode([
            self::service_title($service), $description, (string) $service->slug, (int) $service->is_active,
            Service_Images::url($service), self::unit_label((string) $service->pricing_mode),
        ]));
    }

    private static function unit_label(string $pricing_mode): string
    {
        return match ($pricing_mode) {
            'per_person' => I18n::in_language(static fn() => __('names', 'georg-kloster-orthodox-donations'), 'de'),
            'per_sheet' => I18n::in_language(static fn() => __('prayer slips', 'georg-kloster-orthodox-donations'), 'de'),
            default => '',
        };
    }
}
