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
        elseif ($notice === 'thank_you_test_sent') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Test copy sent to the configured test address.', 'georg-kloster-orthodox-donations') . '</p></div>';
        elseif ($notice === 'thank_you_failed') echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Thank-you letter could not be sent.', 'georg-kloster-orthodox-donations') . '</p></div>';
        $donor_id = absint(Input::get('donor') ?? 0);
        if ($donor_id) {
            self::detail($donor_id);
            echo '</div>';
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        require_once DIR . '/includes/class-donors-table.php';
        $table = new Donors_Table();
        $table->prepare_items();
        echo '<form method="get"><input type="hidden" name="page" value="georg-kloster-orthodox-donations-donors">';
        $table->search_box(__('Search donors', 'georg-kloster-orthodox-donations'), 'gkod-donors');
        echo '</form>';
        $table->display();
        echo '</div>';
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
        if ($wpdb->last_error !== '') wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        $address = json_decode((string) ($latest->billing_address ?? ''), true) ?: [];
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-donors')) . '">← ' . esc_html__('Back to donors', 'georg-kloster-orthodox-donations') . '</a></p><h2>' . esc_html($latest->billing_name ?? '') . '</h2><p><strong>' . esc_html__('Email address', 'georg-kloster-orthodox-donations') . ':</strong> ' . esc_html($email) . '</p>';
        if ($latest && $latest->billing_phone) echo '<p><strong>' . esc_html__('Phone', 'georg-kloster-orthodox-donations') . ':</strong> ' . esc_html($latest->billing_phone) . '</p>';
        $address_text = implode(', ', array_filter(array_map('strval', [$address['line1'] ?? '', $address['line2'] ?? '', $address['postal_code'] ?? '', $address['city'] ?? '', $address['state'] ?? '', $address['country'] ?? ''])));
        if ($address_text !== '') echo '<p><strong>' . esc_html__('Billing address', 'georg-kloster-orthodox-donations') . ':</strong> ' . esc_html($address_text) . '</p>';
        echo '<p>' . esc_html((int) $donor->wp_user_id > 0 ? __('Linked to a WordPress account.', 'georg-kloster-orthodox-donations') : __('Uses a personal donor link.', 'georg-kloster-orthodox-donations')) . '</p>';
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        require_once DIR . '/includes/class-donors-table.php';
        $table = new Donor_Orders_Table($email, $donor_id);
        $table->prepare_items();
        $table->display();
    }

    public static function send_thank_you(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        $order_id = absint(Input::post('order_id') ?? 0);
        check_admin_referer('gkod_send_thank_you_' . $order_id, 'gkod_nonce_gkod_send_thank_you');
        $donor_id = absint(Input::post('donor_id') ?? 0);
        if (!Donors::order_belongs_to($donor_id, $order_id)) wp_die(esc_html__('The donation does not belong to this donor.', 'georg-kloster-orthodox-donations'), '', ['response' => 400]);
        $template_id = sanitize_key((string) (Input::post('template_id') ?? ''));
        if ($template_id !== '' && !isset(Thank_You_Template_Admin::templates()[$template_id])) wp_die(esc_html__('Template not found.', 'georg-kloster-orthodox-donations'), '', ['response' => 404]);
        $result = Thank_You::send_order($order_id, $template_id);
        $notice = $result === true ? 'thank_you_sent' : 'thank_you_failed';
        wp_safe_redirect(add_query_arg(['page' => 'georg-kloster-orthodox-donations-donors', 'donor' => $donor_id, 'gkod_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public static function template_selector(): string
    {
        $templates = Thank_You_Template_Admin::templates();
        $selected_id = Thank_You_Template_Admin::selected_id();
        $html = '<select name="template_id" aria-label="' . esc_attr__('Thank-you letter template', 'georg-kloster-orthodox-donations') . '">';
        foreach ($templates as $id => $template) {
            $label = (string) $template['template_name'];
            $html .= '<option value="' . esc_attr((string) $id) . '" ' . selected($selected_id, $id, false) . '>' . esc_html($label) . '</option>';
        }
        return $html . '</select>';
    }
}
