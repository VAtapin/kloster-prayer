<?php
declare(strict_types=1);
namespace KlosterPrayer;

/** Verified refunds for both one-time donations and recurring payments. */
final class Payment_Refunds
{
    private static function record(string $provider, string $reference, string $refund_id, int $cents, string $currency): bool
    {
        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE payment_provider=%s AND (payment_id=%s OR payment_reference=%s)', Database::table('orders'), $provider, $reference, $reference));
        if (!$order || !in_array($order->payment_status, ['paid','refunded'], true) || $refund_id === '' || strlen($refund_id) > 191 || $cents <= 0 || $cents > (int) $order->amount_cents || strtoupper($currency) !== 'EUR') return false;
        $table = Database::table('payment_refunds');
        $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE provider=%s AND refund_id=%s', $table, $provider, $refund_id));
        if ($wpdb->last_error !== '') return false;
        if ($old && ((int) $old->order_id !== (int) $order->id || (int) $old->amount_cents !== $cents)) return false;
        if (!$old && !$wpdb->insert($table, ['provider' => $provider, 'refund_id' => $refund_id, 'order_id' => $order->id, 'amount_cents' => $cents, 'created_at' => current_time('mysql')])) return false;
        $sum = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM %i WHERE order_id=%d', $table, $order->id));
        if ($wpdb->last_error !== '') return false;
        // Preserve legacy full-refund states: older records did not store refunded amounts.
        $previous = $order->payment_status === 'refunded' && !(int) $order->refunded_cents ? (int) $order->amount_cents : (int) $order->refunded_cents;
        $sum = min((int) $order->amount_cents, max($sum, $previous));
        // Concurrent refund deliveries must never lower an already recorded total.
        $ok = $wpdb->query($wpdb->prepare("UPDATE %i SET payment_status=CASE WHEN GREATEST(refunded_cents,%d)>=amount_cents THEN 'refunded' ELSE 'paid' END,refunded_cents=GREATEST(refunded_cents,%d) WHERE id=%d AND payment_status IN ('paid','refunded')", Database::table('orders'), $sum, $sum, $order->id)) !== false;
        if ($ok && !$old) Order_Workflow::log((int) $order->id, 'refund', __('Refunded', 'kloster-prayer') . ': ' . number_format_i18n($cents / 100, 2) . ' EUR');
        return $ok;
    }

    public static function stripe(array $event): ?\WP_REST_Response
    {
        if (($event['type'] ?? '') !== 'charge.refunded') return null;
        global $wpdb;
        $o = (array) ($event['data']['object'] ?? []); $ref = (string) ($o['payment_intent'] ?? '');
        if ($ref === '') return self::response(false);
        $known = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE payment_provider='stripe' AND payment_reference=%s", Database::table('orders'), $ref));
        if ($wpdb->last_error !== '') return self::response(false, 503);
        if (!$known) {
            $id = (int) ($o['metadata']['order_id'] ?? 0);
            $order = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id=%d AND payment_provider='stripe'", Database::table('orders'), $id)) : null;
            if (!$order) return self::response(true); // Unrelated merchant charge.
            $session = Recurring_Gateways::request('stripe', 'GET', '/v1/checkout/sessions/' . rawurlencode((string) $order->payment_id));
            if (is_wp_error($session)) return self::response(false, 503);
            if (($session['id'] ?? '') !== $order->payment_id || ($session['payment_intent'] ?? '') !== $ref || ($session['currency'] ?? '') !== 'eur' || ($session['amount_total'] ?? null) !== (int) $order->amount_cents) return self::response(false);
            if ($wpdb->update(Database::table('orders'), ['payment_reference' => $ref], ['id' => $order->id]) === false) return self::response(false, 503);
        }
        foreach ($o['refunds']['data'] ?? [] as $refund) {
            if (($refund['status'] ?? '') !== 'succeeded') continue;
            if ((isset($refund['charge']) && $refund['charge'] !== ($o['id'] ?? '')) || (isset($refund['payment_intent']) && $refund['payment_intent'] !== $ref)) return self::response(false);
            if (!is_int($refund['amount'] ?? null) || !self::record('stripe', $ref, (string) ($refund['id'] ?? ''), $refund['amount'], (string) ($refund['currency'] ?? ''))) return self::response(false);
        }
        if (!empty($o['refunds']['has_more'])) wp_schedule_single_event(time(), 'klp_sync_recurring_refunds', [(string) ($o['id'] ?? ''), $ref, '', 0]);
        return self::response(true);
    }

    public static function paypal(array $event): ?\WP_REST_Response
    {
        $type = (string) ($event['event_type'] ?? ''); $o = (array) ($event['resource'] ?? []);
        if (!in_array($type, ['PAYMENT.SALE.REFUNDED','PAYMENT.CAPTURE.REFUNDED'], true)) return null;
        if ($type === 'PAYMENT.CAPTURE.REFUNDED' && ($o['status'] ?? '') !== 'COMPLETED') return self::response(true);
        $reference = $type === 'PAYMENT.SALE.REFUNDED' ? (string) ($o['sale_id'] ?? '') : (string) ($o['capture_id'] ?? '');
        if ($type === 'PAYMENT.CAPTURE.REFUNDED' && $reference === '') {
            // PayPal refund resources identify the capture through the parent link.
            foreach ($o['links'] ?? [] as $link) {
                if (($link['rel'] ?? '') !== 'up') continue;
                $url = wp_parse_url((string) ($link['href'] ?? ''));
                $host = get_option('klp_paypal_mode','live') === 'sandbox' ? 'api-m.sandbox.paypal.com' : 'api-m.paypal.com';
                if (is_array($url) && ($url['scheme'] ?? '') === 'https' && ($url['host'] ?? '') === $host && preg_match('~^/v2/payments/captures/([A-Za-z0-9-]+)$~D', $url['path'] ?? '', $match)) $reference = $match[1];
            }
        }
        $amount = $o['amount'] ?? [];
        $cents = Payments::donation_cents((string) ($amount[$type === 'PAYMENT.SALE.REFUNDED' ? 'total' : 'value'] ?? ''), 1);
        $currency = (string) ($amount[$type === 'PAYMENT.SALE.REFUNDED' ? 'currency' : 'currency_code'] ?? '');
        if ($reference === '' || $cents === null) return self::response(false);
        global $wpdb;
        $known = $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE payment_provider='paypal' AND (payment_id=%s OR payment_reference=%s)", Database::table('orders'), $reference, $reference));
        if ($wpdb->last_error !== '') return self::response(false, 503);
        if (!$known && $type === 'PAYMENT.SALE.REFUNDED') return self::response(true);
        if (!$known && $type === 'PAYMENT.CAPTURE.REFUNDED') {
            $capture = Recurring_Gateways::request('paypal', 'GET', '/v2/payments/captures/' . rawurlencode($reference));
            if (is_wp_error($capture)) return self::response(false, 503);
            $related = (string) ($capture['supplementary_data']['related_ids']['order_id'] ?? '');
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE payment_provider='paypal' AND payment_id=%s", Database::table('orders'), $related));
            if (!$order) return self::response(true);
            if (($capture['id'] ?? '') !== $reference || ($capture['status'] ?? '') !== 'COMPLETED' || ($capture['amount']['currency_code'] ?? '') !== 'EUR' || Payments::donation_cents((string) ($capture['amount']['value'] ?? '')) !== (int) $order->amount_cents) return self::response(false);
            if ($wpdb->update(Database::table('orders'), ['payment_reference' => $reference], ['id' => $order->id]) === false) return self::response(false, 503);
        }
        return self::response(self::record('paypal', $reference, (string) ($o['id'] ?? ''), $cents, $currency));
    }

    public static function sync_refunds(string $charge, string $reference, string $cursor = '', int $attempt = 0): void
    {
        $path = '/v1/refunds?charge=' . rawurlencode($charge) . '&limit=100' . ($cursor !== '' ? '&starting_after=' . rawurlencode($cursor) : '');
        $result = Recurring_Gateways::request('stripe', 'GET', $path);
        if (is_wp_error($result)) {
            if ($attempt < 3) wp_schedule_single_event(time() + 300, 'klp_sync_recurring_refunds', [$charge, $reference, $cursor, $attempt + 1]);
            return;
        }
        foreach ($result['data'] ?? [] as $row) {
            if (($row['status'] ?? '') !== 'succeeded') continue;
            if (($row['charge'] ?? '') !== $charge || !is_int($row['amount'] ?? null) || !self::record('stripe', $reference, (string) ($row['id'] ?? ''), $row['amount'], (string) ($row['currency'] ?? ''))) return;
        }
        if (!empty($result['has_more']) && !empty($result['data'])) {
            $rows = $result['data']; $last = end($rows);
            if (!empty($last['id']) && $last['id'] !== $cursor) wp_schedule_single_event(time() + 1, 'klp_sync_recurring_refunds', [$charge, $reference, (string) $last['id'], 0]);
        }
    }

    private static function response(bool $ok, int $error = 400): \WP_REST_Response
    {
        return new \WP_REST_Response($ok ? ['received' => true] : ['error' => 'Refund could not be verified'], $ok ? 200 : $error);
    }
}
