<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

/** WordPress-native donor directory and staff-only donor detail/actions. */
final class Donor_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_gkod_send_thank_you', [self::class, 'send_thank_you']);
    }

    public static function menu(): void
    {
        add_submenu_page('georg-kloster-orthodox-donations', esc_html__('Donors', 'georg-kloster-orthodox-donations'), esc_html__('Donors', 'georg-kloster-orthodox-donations'), 'manage_options', 'georg-kloster-orthodox-donations-donors', [self::class, 'page']);
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        global $wpdb;
        echo '<div class="wrap gkod-admin"><h1>' . esc_html__('Donors', 'georg-kloster-orthodox-donations') . '</h1><hr class="wp-header-end">';
        $notice = sanitize_key(Input::get('gkod_notice') ?? '');
        if ($notice === 'thank_you_sent') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Thank-you letter sent.', 'georg-kloster-orthodox-donations') . '</p></div>';
        elseif ($notice === 'thank_you_failed') echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Thank-you letter could not be sent.', 'georg-kloster-orthodox-donations') . '</p></div>';
        $donor_id = absint(Input::get('donor') ?? 0);
        if ($donor_id) {
            self::detail($donor_id);
            echo '</div>';
            return;
        }
        $search = sanitize_text_field(Input::get('s') ?? '');
        $page = min(1000, max(1, absint(Input::get('paged') ?? 1)));
        $per_page = 30;
        $where = "donor_email<>''";
        $args = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (donor_email LIKE %s OR donor_name LIKE %s OR billing_name LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Staff directory aggregates the current custom payment ledger by donor email.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT LOWER(donor_email)) FROM %i WHERE ' . $where, ...array_merge([Database::table('orders')], $args)));
        $args_with_page = array_merge([Database::table('orders')], $args, [$per_page + 1, ($page - 1) * $per_page]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The directory reads existing order data; payer addresses remain in the payment ledger.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT MAX(donor_email) email,MAX(COALESCE(NULLIF(billing_name,''),NULLIF(donor_name,''))) name,COUNT(*) donation_count,SUM(CASE WHEN payment_status='paid' THEN amount_cents-refunded_cents ELSE 0 END) total_paid,MAX(created_at) latest FROM %i WHERE {$where} GROUP BY LOWER(donor_email) ORDER BY latest DESC LIMIT %d OFFSET %d", ...$args_with_page));
        echo '<form method="get"><input type="hidden" name="page" value="georg-kloster-orthodox-donations-donors"><p class="search-box"><label class="screen-reader-text" for="gkod-donor-search">' . esc_html__('Search donors', 'georg-kloster-orthodox-donations') . '</label><input id="gkod-donor-search" type="search" name="s" value="' . esc_attr($search) . '"><button class="button">' . esc_html__('Search', 'georg-kloster-orthodox-donations') . '</button></p></form>';
        echo '<p>' . esc_html(sprintf(__('Donors: %s', 'georg-kloster-orthodox-donations'), number_format_i18n($total))) . '</p><table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__('Donor', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Email address', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Donations', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Paid total', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Latest donation', 'georg-kloster-orthodox-donations') . '</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, $per_page) as $row) {
            $donor = Donors::ensure((string) $row->email);
            if (!$donor) continue;
            $url = add_query_arg(['page' => 'georg-kloster-orthodox-donations-donors', 'donor' => (int) $donor->id], admin_url('admin.php'));
            echo '<tr><td><a href="' . esc_url($url) . '">' . esc_html($row->name ?: '—') . '</a></td><td><a href="' . esc_url($url) . '">' . esc_html($row->email) . '</a></td><td>' . (int) $row->donation_count . '</td><td>' . esc_html(number_format_i18n((int) $row->total_paid / 100, 2) . ' EUR') . '</td><td>' . esc_html((string) $row->latest) . '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="5">' . esc_html__('No donors found.', 'georg-kloster-orthodox-donations') . '</td></tr>';
        echo '</tbody></table><div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(['base' => add_query_arg(['page' => 'georg-kloster-orthodox-donations-donors', 's' => $search, 'paged' => '%#%'], admin_url('admin.php')), 'total' => (int) ceil($total / $per_page), 'current' => $page])) . '</div></div></div>';
    }

    private static function detail(int $donor_id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read a selected donor identity and existing transaction details for staff.
        $donor = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('donors'), $donor_id));
        if (!$donor) { echo '<p>' . esc_html__('Donor not found.', 'georg-kloster-orthodox-donations') . '</p>'; return; }
        $email = (string) $donor->email;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Addresses are read from the payment data already held for each donation, not copied to the donor table.
        $latest = $wpdb->get_row($wpdb->prepare("SELECT billing_name,billing_phone,billing_address FROM %i WHERE LOWER(donor_email)=LOWER(%s) ORDER BY (billing_address IS NOT NULL AND billing_address NOT IN ('','{}')) DESC,(billing_phone IS NOT NULL AND billing_phone<>'') DESC,id DESC LIMIT 1", Database::table('orders'), $email));
        $address = json_decode((string) ($latest->billing_address ?? ''), true) ?: [];
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-donors')) . '">← ' . esc_html__('Back to donors', 'georg-kloster-orthodox-donations') . '</a></p><h2>' . esc_html($latest->billing_name ?? '') . '</h2><p><strong>' . esc_html__('Email address', 'georg-kloster-orthodox-donations') . ':</strong> ' . esc_html($email) . '</p>';
        if ($latest && $latest->billing_phone) echo '<p><strong>' . esc_html__('Phone', 'georg-kloster-orthodox-donations') . ':</strong> ' . esc_html($latest->billing_phone) . '</p>';
        $address_text = implode(', ', array_filter(array_map('strval', [$address['line1'] ?? '', $address['line2'] ?? '', $address['postal_code'] ?? '', $address['city'] ?? '', $address['state'] ?? '', $address['country'] ?? ''])));
        if ($address_text !== '') echo '<p><strong>' . esc_html__('Billing address', 'georg-kloster-orthodox-donations') . ':</strong> ' . esc_html($address_text) . '</p>';
        echo '<p>' . esc_html((int) $donor->wp_user_id > 0 ? __('Linked to a WordPress account.', 'georg-kloster-orthodox-donations') : __('Uses a personal donor link.', 'georg-kloster-orthodox-donations')) . '</p>';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Full payment history for this email is displayed from the existing custom order ledger.
        $orders = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE LOWER(donor_email)=LOWER(%s) ORDER BY id DESC', Database::table('orders'), $email));
        echo '<h2>' . esc_html__('Donations', 'georg-kloster-orthodox-donations') . '</h2><table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__('Date', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Donation', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Amount', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Status', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Thank-you letter', 'georg-kloster-orthodox-donations') . '</th></tr></thead><tbody>';
        foreach ($orders as $order) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the service titles from the linked order items.
            $titles = $wpdb->get_col($wpdb->prepare('SELECT service_title FROM %i WHERE order_id=%d ORDER BY id', Database::table('order_items'), (int) $order->id));
            $campaign = $order->campaign_id ? Campaigns::get((int) $order->campaign_id) : null;
            $purpose = $campaign ? Campaigns::title($campaign, $order->language) : ($titles ? implode(', ', $titles) : I18n::donation_text('title', $order->language));
            echo '<tr><td>' . esc_html((string) $order->created_at) . ' (#' . (int) $order->id . ')</td><td>' . esc_html($purpose) . '</td><td>' . esc_html(number_format_i18n((int) $order->amount_cents / 100, 2) . ' ' . $order->currency) . '</td><td>' . esc_html($order->payment_status) . '</td><td>';
            if ($order->payment_status === 'paid') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="gkod_send_thank_you"><input type="hidden" name="donor_id" value="' . $donor_id . '"><input type="hidden" name="order_id" value="' . (int) $order->id . '">' . wp_nonce_field('gkod_send_thank_you_' . (int) $order->id, '_wpnonce', true, false) . '<button class="button" type="submit">' . esc_html__('Send thank-you letter', 'georg-kloster-orthodox-donations') . '</button></form>';
            } else echo '—';
            echo '</td></tr>';
        }
        if (!$orders) echo '<tr><td colspan="5">' . esc_html__('No donations found.', 'georg-kloster-orthodox-donations') . '</td></tr>';
        echo '</tbody></table>';
    }

    public static function send_thank_you(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        $order_id = absint(Input::post('order_id') ?? 0);
        check_admin_referer('gkod_send_thank_you_' . $order_id);
        $donor_id = absint(Input::post('donor_id') ?? 0);
        if (!Donors::order_belongs_to($donor_id, $order_id)) wp_die(esc_html__('The donation does not belong to this donor.', 'georg-kloster-orthodox-donations'), '', ['response' => 400]);
        $result = Thank_You::send_order($order_id);
        $notice = $result === true ? 'thank_you_sent' : 'thank_you_failed';
        wp_safe_redirect(add_query_arg(['page' => 'georg-kloster-orthodox-donations-donors', 'donor' => $donor_id, 'gkod_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
