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
        if ($order->intention !== 'none' || !in_array($interval, ['month','year'], true)) return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donations'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $ok = $wpdb->insert(Database::table('subscriptions'), ['public_id' => wp_generate_uuid4(), 'donor_user_id' => (int) $order->donor_user_id, 'initial_order_id' => $order->id, 'provider' => $order->payment_provider, 'amount_cents' => $order->amount_cents, 'billing_interval' => $interval, 'status' => 'pending', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
        if ($ok !== 1 || (int) $wpdb->insert_id < 1) return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        $id = (int) $wpdb->insert_id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        if ($wpdb->update(Database::table('orders'), ['subscription_id' => $id], ['id' => $order->id]) === false) {
            self::state($id, 'failed');
            return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the local subscription/order relation before creating a remote checkout.
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT subscription_id FROM %i WHERE id=%d', Database::table('orders'), (int) $order->id)) !== $id) {
            self::state($id, 'failed');
            return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        }
        $sub = self::get($id);
        if (!$sub) {
            self::state($id, 'failed');
            return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        }
        $result = Recurring_Gateways::checkout($sub, $order);
        if (is_wp_error($result)) { self::state($id, 'failed'); return $result; }
        $data = ['provider_id' => $result['provider_id'], 'checkout_id' => $result['checkout_id'], 'plan_id' => $result['plan_id'] ?? null];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        if ($wpdb->update(Database::table('subscriptions'), $data, ['id' => $id]) === false) {
            // The provider may already have created a checkout. Attempt to expire/cancel it before returning an error.
            $remote = clone $sub;
            $remote->provider_id = $result['provider_id'];
            $remote->checkout_id = $result['checkout_id'];
            Recurring_Gateways::cancel($remote);
            self::state($id, 'failed');
            return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm provider identifiers are persisted before returning a checkout URL.
        $stored = $wpdb->get_row($wpdb->prepare('SELECT provider_id,checkout_id,plan_id FROM %i WHERE id=%d', Database::table('subscriptions'), $id));
        if (!$stored || $wpdb->last_error !== '' || (string) ($stored->checkout_id ?? '') !== (string) ($data['checkout_id'] ?? '') || (string) ($stored->provider_id ?? '') !== (string) ($data['provider_id'] ?? '')) {
            $remote = clone $sub;
            $remote->provider_id = $result['provider_id'];
            $remote->checkout_id = $result['checkout_id'];
            Recurring_Gateways::cancel($remote);
            self::state($id, 'failed');
            return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        }
        $sub->plan_id = $stored->plan_id;
        $sub->provider_id = $result['provider_id'];
        $sub->checkout_id = $result['checkout_id'];
        $sub->plan_id = $result['plan_id'] ?? null;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        if ($sub->provider === 'stripe') {
            if ($wpdb->update(Database::table('orders'), ['payment_id' => $result['checkout_id']], ['id' => $order->id]) === false) {
                Recurring_Gateways::cancel($sub);
                self::state($id, 'failed');
                return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the webhook lookup key is stored on the initial order.
            if ((string) $wpdb->get_var($wpdb->prepare('SELECT payment_id FROM %i WHERE id=%d', Database::table('orders'), (int) $order->id)) !== (string) $result['checkout_id']) {
                Recurring_Gateways::cancel($sub);
                self::state($id, 'failed');
                return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
            }
        }
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

    private static function bind(object $sub, string $remote_id): bool|\WP_Error
    {
        global $wpdb;
        if ($remote_id === '' || strlen($remote_id) > 191 || ($sub->provider_id && $sub->provider_id !== $remote_id)) return false;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $changed = $wpdb->query($wpdb->prepare('UPDATE %i SET provider_id=%s WHERE id=%d AND (provider_id IS NULL OR provider_id=%s)', Database::table('subscriptions'), $remote_id, $sub->id, $remote_id));
        if ($changed === false) return new \WP_Error('database');
        $bound = self::get((int) $sub->id);
        if ($wpdb->last_error !== '') return new \WP_Error('database');
        return ($bound->provider_id ?? '') === $remote_id;
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
        if ($wpdb->last_error !== '') return new \WP_Error('database');
        if (!$sub || ($provider === 'paypal' && ($details['plan_id'] ?? '') !== $sub->plan_id)) return null;
        $bound = self::bind($sub, $remote_id);
        if (is_wp_error($bound)) return $bound;
        if (!$bound) return null;
        $loaded = self::get((int) $sub->id);
        return $wpdb->last_error !== '' ? new \WP_Error('database') : $loaded;
    }

    private static function cycle(object $sub, string $transaction, int $cents, string $currency, string $payment_reference = '', int $event_time = 0): bool|\WP_Error
    {
        global $wpdb;
        if ($cents !== (int) $sub->amount_cents || strtoupper($currency) !== 'EUR' || $transaction === '' || strlen($transaction) > 191) return false;
        $orders = Database::table('orders');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE payment_provider=%s AND payment_id=%s', $orders, $sub->provider, $transaction));
        if ($wpdb->last_error !== '') return new \WP_Error('database');
        if ($existing) {
            if ((int) $existing->subscription_id !== (int) $sub->id || (int) $existing->amount_cents !== $cents) return false;
            if ($existing->payment_status === 'refunded') return true; // Do not turn a refunded cycle back into paid.
            if ($existing->payment_status === 'pending' && !Payments::mark_paid((int) $existing->id, $transaction)) return new \WP_Error('database');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
            $status = $wpdb->get_var($wpdb->prepare('SELECT payment_status FROM %i WHERE id=%d', $orders, $existing->id));
            if ($wpdb->last_error !== '') return new \WP_Error('database');
            if ($status !== 'paid') return false;
            return self::state((int) $sub->id, 'active', $event_time) ?: new \WP_Error('database');
        }
        // Claim the initial pending order atomically; later invoices create new records.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $claimed = $wpdb->query($wpdb->prepare("UPDATE %i SET payment_id=%s,payment_reference=%s WHERE id=%d AND subscription_id=%d AND payment_status='pending' AND (payment_id IS NULL OR payment_id=%s)", $orders, $transaction, $payment_reference ?: null, $sub->initial_order_id, $sub->id, $sub->checkout_id ?: ''));
        if ($claimed === false) return new \WP_Error('database');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $initial = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $orders, $sub->initial_order_id));
        if ($wpdb->last_error !== '') return new \WP_Error('database');
        if ($initial && $initial->payment_id === $transaction) $order = $initial;
        else {
            $template = $initial;
            if (!$template) return false;
            $order = Payments::create_order(['form_id' => 0, 'language' => $template->language, 'intention' => 'none', 'names' => '', 'note' => '', 'donor_name' => $template->donor_name, 'donor_email' => $template->donor_email, 'provider' => $sub->provider, 'service_ids' => [], 'return_url' => $template->return_url, 'donation_cents' => $cents, 'campaign_id' => (int) $template->campaign_id, 'trusted_renewal' => true, 'donor_user_id' => (int) $sub->donor_user_id, 'subscription_id' => (int) $sub->id, 'payment_id' => $transaction, 'payment_reference' => $payment_reference ?: null]);
            if (is_wp_error($order)) {
                $creation_error = $order->get_error_code();
                // A concurrent delivery can win the unique provider/payment key. Retry safely.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
                $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE payment_provider=%s AND payment_id=%s AND subscription_id=%d', $orders, $sub->provider, $transaction, $sub->id));
                if ($wpdb->last_error !== '') return new \WP_Error('database');
                if (!$order && $creation_error === 'database') return new \WP_Error('database');
            }
        }
        if (!$order) return false;
        if (!Payments::mark_paid((int) $order->id, $transaction)) return new \WP_Error('database');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        $status = $wpdb->get_var($wpdb->prepare('SELECT payment_status FROM %i WHERE id=%d', $orders, $order->id));
        if ($wpdb->last_error !== '') return new \WP_Error('database');
        if ($status !== 'paid') return false;
        return self::state((int) $sub->id, 'active', $event_time) ?: new \WP_Error('database');
    }

    public static function stripe(array $event): ?\WP_REST_Response
    {
        global $wpdb;
        $type = (string) ($event['type'] ?? ''); $o = (array) ($event['data']['object'] ?? []);
        if ($type === 'checkout.session.completed' && ($o['mode'] ?? '') === 'subscription') {
            if (empty($o['metadata']['gkod_subscription'])) return self::response(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
            $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE provider='stripe' AND checkout_id=%s", Database::table('subscriptions'), (string) ($o['id'] ?? '')));
            if ($wpdb->last_error !== '') return self::response(false, 503);
            if (!$sub || ($o['metadata']['gkod_subscription'] ?? '') !== $sub->public_id || !is_int($o['amount_total'] ?? null) || $o['amount_total'] !== (int) $sub->amount_cents || ($o['currency'] ?? '') !== 'eur') return self::response(false);
            $bound = self::bind($sub, (string) ($o['subscription'] ?? ''));
            if (is_wp_error($bound)) return self::response(false, 503);
            if (!$bound) return self::response(false);
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
            $result = self::cycle($sub, (string) ($o['id'] ?? ''), $o['amount_paid'], (string) ($o['currency'] ?? ''), $reference, (int) ($event['created'] ?? 0));
            return self::response(is_wp_error($result) ? false : $result, is_wp_error($result) ? 503 : 400);
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
            if (($o['state'] ?? '') !== 'completed' || $cents === null) return self::response(false);
            $result = self::cycle($sub, (string) ($o['id'] ?? ''), $cents, (string) ($o['amount']['currency'] ?? ''), '', (int) strtotime($event['create_time'] ?? 'now'));
            return self::response(is_wp_error($result) ? false : $result, is_wp_error($result) ? 503 : 400);
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
        if ($wpdb->last_error !== '') return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        $initial = $sub ? $wpdb->get_row($wpdb->prepare('SELECT donor_email FROM %i WHERE id=%d', Database::table('orders'), (int) $sub->initial_order_id)) : null;
        if ($wpdb->last_error !== '') return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        $account_owner = $sub && is_user_logged_in() && (int) $sub->donor_user_id > 0 && (int) $sub->donor_user_id === get_current_user_id();
        $link_owner = $initial && Donors::has_access_to_email((string) $initial->donor_email);
        if (!$sub || (!$account_owner && !$link_owner && !current_user_can('manage_options'))) return new \WP_Error('forbidden', __('Forbidden', 'georg-kloster-orthodox-donations'));
        if ($sub->status === 'cancelled') return true;
        $result = Recurring_Gateways::cancel($sub);
        if (is_wp_error($result)) return $result;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        if ($wpdb->update(Database::table('subscriptions'), ['status' => 'cancelled', 'updated_at' => current_time('mysql')], ['id' => $id]) === false) return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Subscription ownership and provider lifecycle/idempotency require current custom ledger state.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Record cancellation of an unpaid initial order without changing confirmed payments.
        if ($wpdb->query($wpdb->prepare("UPDATE %i SET payment_status='cancelled' WHERE id=%d AND payment_status='pending'", Database::table('orders'), $sub->initial_order_id)) === false) return new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        return true;
    }
}
