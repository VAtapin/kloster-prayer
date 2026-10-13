<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

/** Subscription lifecycle. Webhook entry points verify signatures before calling this class. */
final class Subscriptions
{
    public static function init(): void
    {
        add_action('gkod_prepare_recurring_plan', [Recurring_Gateways::class, 'prepare_plan'], 10, 4);
        add_action('gkod_sync_recurring_refunds', [Payment_Refunds::class, 'sync_refunds'], 10, 4);
    }

    public static function get(int $id): ?object
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('subscriptions'), $id));
    }

    private static function remote(string $provider, string $id): ?object
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE provider=%s AND provider_id=%s', Database::table('subscriptions'), $provider, $id));
        return $wpdb->last_error !== '' ? new \WP_Error('database') : $row;
    }

    public static function checkout(object $order, string $interval): string|\WP_Error
    {
        global $wpdb;
        if (!is_user_logged_in() || $order->intention !== 'none' || (int) $order->donor_user_id !== get_current_user_id() || !in_array($interval, ['month','year'], true)) return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donate'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $ok = $wpdb->insert(Database::table('subscriptions'), ['public_id' => wp_generate_uuid4(), 'donor_user_id' => get_current_user_id(), 'initial_order_id' => $order->id, 'provider' => $order->payment_provider, 'amount_cents' => $order->amount_cents, 'billing_interval' => $interval, 'status' => 'pending', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
        if (!$ok) return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donate'));
        $id = (int) $wpdb->insert_id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        if ($wpdb->update(Database::table('orders'), ['subscription_id' => $id], ['id' => $order->id]) === false) return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donate'));
        $sub = self::get($id);
        $result = Recurring_Gateways::checkout($sub, $order);
        if (is_wp_error($result)) { self::state($id, 'failed'); return $result; }
        $data = ['provider_id' => $result['provider_id'], 'checkout_id' => $result['checkout_id'], 'plan_id' => $result['plan_id'] ?? null];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        if ($wpdb->update(Database::table('subscriptions'), $data, ['id' => $id]) === false) return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donate'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        if ($sub->provider === 'stripe') $wpdb->update(Database::table('orders'), ['payment_id' => $result['checkout_id']], ['id' => $order->id]);
        return (string) $result['url'];
    }

    private static function state(int $id, string $status, int $event_time = 0): bool
    {
        global $wpdb;
        // A delayed active/payment event must never resurrect a cancelled agreement.
        $event_time = $event_time ?: time();
        if ($status === 'cancelled') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
            return $wpdb->update(Database::table('subscriptions'), ['status' => 'cancelled', 'updated_at' => current_time('mysql')], ['id' => $id]) !== false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        return $wpdb->query($wpdb->prepare("UPDATE %i SET status=%s,updated_at=%s,last_event_at=%d WHERE id=%d AND status<>'cancelled' AND last_event_at<=%d", Database::table('subscriptions'), $status, current_time('mysql'), $event_time, $id, $event_time)) !== false;
    }

    private static function bind(object $sub, string $remote_id): bool
    {
        global $wpdb;
        if ($remote_id === '' || strlen($remote_id) > 191 || ($sub->provider_id && $sub->provider_id !== $remote_id)) return false;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $changed = $wpdb->query($wpdb->prepare('UPDATE %i SET provider_id=%s WHERE id=%d AND (provider_id IS NULL OR provider_id=%s)', Database::table('subscriptions'), $remote_id, $sub->id, $remote_id));
        if ($changed === false) return false;
        return (self::get((int) $sub->id)->provider_id ?? '') === $remote_id;
    }

    private static function discover(string $provider, string $remote_id, string $reference = ''): ?object
    {
        global $wpdb;
        if ($remote_id === '' || strlen($remote_id) > 191) return null;
        $known = self::remote($provider, $remote_id);
        if ($known) return $known;
        // A payment may arrive before Checkout/activation. Read the actual remote agreement.
        $details = Recurring_Gateways::request($provider, 'GET', ($provider === 'stripe' ? '/v1/subscriptions/' : '/v1/billing/subscriptions/') . rawurlencode($remote_id));
        if (is_wp_error($details)) return $details;
        if (($details['id'] ?? '') !== $remote_id) return new \WP_Error('binding');
        $actual = $provider === 'stripe' ? (string) ($details['metadata']['gkod_subscription'] ?? '') : (string) ($details['custom_id'] ?? '');
        if ($actual === '' || ($reference !== '' && !hash_equals($reference, $actual))) return null;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $sub = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE provider=%s AND public_id=%s', Database::table('subscriptions'), $provider, $actual));
        if (!$sub || ($provider === 'paypal' && ($details['plan_id'] ?? '') !== $sub->plan_id) || !self::bind($sub, $remote_id)) return null;
        return self::get((int) $sub->id);
    }

    private static function cycle(object $sub, string $transaction, int $cents, string $currency, string $payment_reference = '', int $event_time = 0): bool
    {
        global $wpdb;
        if ($cents !== (int) $sub->amount_cents || strtoupper($currency) !== 'EUR' || $transaction === '' || strlen($transaction) > 191) return false;
        $orders = Database::table('orders');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE payment_provider=%s AND payment_id=%s', $orders, $sub->provider, $transaction));
        if ($existing) {
            if ((int) $existing->subscription_id !== (int) $sub->id || (int) $existing->amount_cents !== $cents) return false;
            if ($existing->payment_status === 'refunded') return true; // Do not turn a refunded cycle back into paid.
            if ($existing->payment_status === 'pending') Payments::mark_paid((int) $existing->id, $transaction);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
            return $wpdb->get_var($wpdb->prepare('SELECT payment_status FROM %i WHERE id=%d', $orders, $existing->id)) === 'paid' && self::state((int) $sub->id, 'active', $event_time);
        }
        // Claim the initial pending order atomically; later invoices create new records.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $wpdb->query($wpdb->prepare("UPDATE %i SET payment_id=%s,payment_reference=%s WHERE id=%d AND subscription_id=%d AND payment_status='pending' AND (payment_id IS NULL OR payment_id=%s)", $orders, $transaction, $payment_reference ?: null, $sub->initial_order_id, $sub->id, $sub->checkout_id ?: ''));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $initial = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $orders, $sub->initial_order_id));
        if ($initial && $initial->payment_id === $transaction) $order = $initial;
        else {
            $template = $initial;
            if (!$template) return false;
            $order = Payments::create_order(['form_id' => 0, 'language' => $template->language, 'intention' => 'none', 'names' => '', 'note' => '', 'donor_name' => $template->donor_name, 'donor_email' => $template->donor_email, 'provider' => $sub->provider, 'service_ids' => [], 'return_url' => $template->return_url, 'donation_cents' => $cents, 'campaign_id' => (int) $template->campaign_id, 'trusted_renewal' => true, 'donor_user_id' => (int) $sub->donor_user_id, 'subscription_id' => (int) $sub->id, 'payment_id' => $transaction, 'payment_reference' => $payment_reference ?: null]);
            if (is_wp_error($order)) {
                // A concurrent delivery can win the unique provider/payment key. Retry safely.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
                $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE payment_provider=%s AND payment_id=%s AND subscription_id=%d', $orders, $sub->provider, $transaction, $sub->id));
            }
        }
        if (!$order) return false;
        Payments::mark_paid((int) $order->id, $transaction);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        return $wpdb->get_var($wpdb->prepare('SELECT payment_status FROM %i WHERE id=%d', $orders, $order->id)) === 'paid' && self::state((int) $sub->id, 'active', $event_time);
    }

    public static function stripe(array $event): ?\WP_REST_Response
    {
        global $wpdb;
        $type = (string) ($event['type'] ?? ''); $o = (array) ($event['data']['object'] ?? []);
        if ($type === 'checkout.session.completed' && ($o['mode'] ?? '') === 'subscription') {
            if (empty($o['metadata']['gkod_subscription'])) return self::response(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
            $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE provider='stripe' AND checkout_id=%s", Database::table('subscriptions'), (string) ($o['id'] ?? '')));
            if (!$sub || ($o['metadata']['gkod_subscription'] ?? '') !== $sub->public_id || !is_int($o['amount_total'] ?? null) || $o['amount_total'] !== (int) $sub->amount_cents || ($o['currency'] ?? '') !== 'eur' || !self::bind($sub, (string) ($o['subscription'] ?? ''))) return self::response(false);
            // Activation is not income. Only invoice.payment_succeeded records a payment.
            return self::response(true);
        }
        if (in_array($type, ['invoice.payment_succeeded','invoice.payment_failed'], true)) {
            $remote = (string) ($o['parent']['subscription_details']['subscription'] ?? $o['subscription'] ?? '');
            if ($remote === '') return self::response(true);
            $sub = self::discover('stripe', $remote, (string) ($o['parent']['subscription_details']['metadata']['gkod_subscription'] ?? $o['subscription_details']['metadata']['gkod_subscription'] ?? ''));
            if (is_wp_error($sub)) return self::response(false, 503);
            if (!$sub) return self::response(true); // An unrelated subscription on the same merchant account.
            if ($type === 'invoice.payment_failed') return self::response(self::state((int) $sub->id, 'past_due', (int) ($event['created'] ?? 0)), 503);
            if (($o['status'] ?? '') !== 'paid' || !is_int($o['amount_paid'] ?? null) || !empty($o['paid_out_of_band'])) return self::response(false);
            $reference = (string) ($o['payment_intent'] ?? $o['payments']['data'][0]['payment']['payment_intent'] ?? '');
            if ($reference === '') {
                $payments = Recurring_Gateways::request('stripe', 'GET', '/v1/invoice_payments?invoice=' . rawurlencode((string) ($o['id'] ?? '')));
                if (is_wp_error($payments)) return self::response(false, 503);
                $reference = (string) ($payments['data'][0]['payment']['payment_intent'] ?? '');
                if ($reference === '') return self::response(false, 503);
            }
            return self::response(self::cycle($sub, (string) ($o['id'] ?? ''), $o['amount_paid'], (string) ($o['currency'] ?? ''), $reference, (int) ($event['created'] ?? 0)));
        }
        if (str_starts_with($type, 'customer.subscription.')) {
            $sub = self::discover('stripe', (string) ($o['id'] ?? ''), (string) ($o['metadata']['gkod_subscription'] ?? ''));
            if (is_wp_error($sub)) return self::response(false, 503);
            if (!$sub) return self::response(true);
            $state = $type === 'customer.subscription.deleted' ? 'cancelled' : match ($o['status'] ?? '') { 'active' => 'active', 'past_due','unpaid' => 'past_due', 'canceled','incomplete_expired' => 'cancelled', default => 'pending' };
            return self::response(self::state((int) $sub->id, $state, (int) ($event['created'] ?? 0)), 503);
        }
        return null;
    }

    public static function paypal(array $event): ?\WP_REST_Response
    {
        $type = (string) ($event['event_type'] ?? ''); $o = (array) ($event['resource'] ?? []);
        if ($type === 'PAYMENT.SALE.COMPLETED') {
            $sub = self::discover('paypal', (string) ($o['billing_agreement_id'] ?? ''));
            if (is_wp_error($sub)) return self::response(false, 503);
            if (!$sub) return self::response(true);
            $cents = Payments::donation_cents((string) ($o['amount']['total'] ?? ''));
            return self::response(($o['state'] ?? '') === 'completed' && $cents !== null && self::cycle($sub, (string) ($o['id'] ?? ''), $cents, (string) ($o['amount']['currency'] ?? ''), '', (int) strtotime($event['create_time'] ?? 'now')));
        }
        if (str_starts_with($type, 'BILLING.SUBSCRIPTION.')) {
            $sub = self::discover('paypal', (string) ($o['id'] ?? ''), (string) ($o['custom_id'] ?? ''));
            if (is_wp_error($sub)) return self::response(false, 503);
            if (!$sub) return self::response(true);
            $state = match ($type) { 'BILLING.SUBSCRIPTION.CANCELLED','BILLING.SUBSCRIPTION.EXPIRED' => 'cancelled', 'BILLING.SUBSCRIPTION.ACTIVATED' => 'active', 'BILLING.SUBSCRIPTION.SUSPENDED','BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'past_due', default => 'pending' };
            return self::response(self::state((int) $sub->id, $state, (int) strtotime($event['create_time'] ?? 'now')), 503);
        }
        return null;
    }

    private static function response(bool $ok, int $error = 400): \WP_REST_Response
    {
        return new \WP_REST_Response($ok ? ['received' => true] : ['error' => 'Subscription payment could not be verified'], $ok ? 200 : $error);
    }

    public static function cancel(int $id): bool|\WP_Error
    {
        global $wpdb;
        $sub = self::get($id);
        if (!$sub || !is_user_logged_in() || ((int) $sub->donor_user_id !== get_current_user_id() && !current_user_can('manage_options'))) return new \WP_Error('forbidden', __('Forbidden', 'georg-kloster-orthodox-donate'));
        if ($sub->status === 'cancelled') return true;
        $result = Recurring_Gateways::cancel($sub);
        if (is_wp_error($result)) return $result;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        if ($wpdb->update(Database::table('subscriptions'), ['status' => 'cancelled', 'updated_at' => current_time('mysql')], ['id' => $id]) === false) return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donate'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $wpdb->query($wpdb->prepare("UPDATE %i SET payment_status='cancelled' WHERE id=%d AND payment_status='pending'", Database::table('orders'), $sub->initial_order_id));
        return true;
    }
}
