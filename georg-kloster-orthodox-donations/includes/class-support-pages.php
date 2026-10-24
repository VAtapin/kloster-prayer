<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

final class Support_Pages
{
    public static function init(): void
    {
        add_shortcode('gkod_support', [self::class, 'form']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_gkod_cancel_subscription', [self::class, 'cancel_action']);
        add_action('admin_post_nopriv_gkod_cancel_subscription', [self::class, 'cancel_action']);
    }

    public static function menu(): void
    {
        add_submenu_page('georg-kloster-orthodox-donations', esc_html__('Regular support', 'georg-kloster-orthodox-donations'), esc_html__('Regular support', 'georg-kloster-orthodox-donations'), 'manage_options', 'georg-kloster-orthodox-donations-support', [self::class, 'admin']);
    }

    public static function form(array|string $atts = []): string
    {
        $atts = shortcode_atts(['lang' => '', 'campaign_id' => 0, 'layout' => 'compact', 'header' => 'yes'], $atts, 'gkod_support');
        $lang = in_array($atts['lang'], ['en','ru','de','uk','pl'], true) ? $atts['lang'] : I18n::language();
        $atts['support'] = 'yes';
        return Public_Form::donation_shortcode($atts);
    }

    public static function cancel_action(): void
    {
        $id = absint(Input::post('subscription_id') ?? 0);
        check_admin_referer('gkod_cancel_subscription_' . $id);
        $result = Subscriptions::cancel($id);
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()), '', ['response' => $result->get_error_code() === 'forbidden' ? 403 : 502]);
        wp_safe_redirect(wp_get_referer() ?: home_url('/')); exit;
    }

    public static function subscriptions(bool $admin): string
    {
        if ($admin && !current_user_can('manage_options')) return '';
        $email = $admin ? '' : Donors::current_email();
        if (!$admin && $email === '') return '';
        Donation_Pages::private_page();
        global $wpdb;
        $page = min(1000, max(1, absint(Input::get('support_page') ?? 1))); $limit = $admin ? 100 : 20;
        $query = $admin
            ? $wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', Database::table('subscriptions'), $limit + 1, ($page - 1) * $limit)
            : $wpdb->prepare('SELECT DISTINCT s.* FROM %i s INNER JOIN %i o ON o.id=s.initial_order_id WHERE LOWER(o.donor_email)=LOWER(%s) OR (s.donor_user_id>0 AND s.donor_user_id=%d) ORDER BY s.id DESC LIMIT %d OFFSET %d', Database::table('subscriptions'), Database::table('orders'), $email, get_current_user_id(), $limit + 1, ($page - 1) * $limit);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Both branches above use complete literal queries with identifiers, ownership and pagination bound by prepare(). Account-owned custom subscription ledger; cancellation controls need current lifecycle state.
        $rows = $wpdb->get_results($query);
        if ($wpdb->last_error !== '') {
            $message = esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations');
            return $admin ? '<p class="notice notice-error">' . $message . '</p>' : '<p role="alert">' . $message . '</p>';
        }
        if (!is_array($rows)) $rows=[];
        $statuses = ['pending' => __('Pending', 'georg-kloster-orthodox-donations'), 'active' => __('Active', 'georg-kloster-orthodox-donations'), 'past_due' => __('Payment overdue', 'georg-kloster-orthodox-donations'), 'failed' => __('Error', 'georg-kloster-orthodox-donations'), 'cancelled' => __('Canceled', 'georg-kloster-orthodox-donations')];
        $html = '<section class="gkod-donation-history"><h2>' . esc_html__('Regular support', 'georg-kloster-orthodox-donations') . '</h2><table><thead><tr><th>' . esc_html__('Amount', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Status', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Payment method', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Actions', 'georg-kloster-orthodox-donations') . '</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, $limit) as $row) {
            $html .= '<tr><td data-label="' . esc_attr__('Amount', 'georg-kloster-orthodox-donations') . '">' . esc_html(number_format_i18n($row->amount_cents / 100, 2) . ' EUR') . ' / ' . esc_html($row->billing_interval === 'year' ? __('Yearly', 'georg-kloster-orthodox-donations') : __('Monthly', 'georg-kloster-orthodox-donations')) . ($admin ? '<br>#' . (int) $row->id . ' / ' . esc_html__('User', 'georg-kloster-orthodox-donations') . ' #' . (int) $row->donor_user_id : '') . '</td><td data-label="' . esc_attr__('Status', 'georg-kloster-orthodox-donations') . '">' . esc_html($statuses[$row->status] ?? $row->status) . '</td><td data-label="' . esc_attr__('Payment method', 'georg-kloster-orthodox-donations') . '">' . esc_html(ucfirst($row->provider)) . '</td><td data-label="' . esc_attr__('Actions', 'georg-kloster-orthodox-donations') . '">';
            if ($row->status !== 'cancelled') $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="gkod_cancel_subscription"><input type="hidden" name="subscription_id" value="' . (int) $row->id . '">' . wp_nonce_field('gkod_cancel_subscription_' . (int) $row->id, '_wpnonce', true, false) . '<button class="button" type="submit">' . esc_html__('Cancel regular support', 'georg-kloster-orthodox-donations') . '</button></form>';
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($page > 1) $html .= '<a href="' . esc_url(add_query_arg('support_page', $page - 1)) . '">' . esc_html__('Previous', 'georg-kloster-orthodox-donations') . '</a> ';
        if (count($rows) > $limit) $html .= '<a href="' . esc_url(add_query_arg('support_page', $page + 1)) . '">' . esc_html__('Next', 'georg-kloster-orthodox-donations') . '</a>';
        return $html . '</section>';
    }

    public static function admin(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        require_once DIR.'/includes/class-donation-admin-table.php';
        echo '<div class="wrap gkod-admin"><h1>'.esc_html__('Regular support','georg-kloster-orthodox-donations').'</h1><hr class="wp-header-end">';
        $table=new Donation_Admin_Table('subscriptions');$table->prepare_items();$table->display();echo '</div>';
    }

    public static function cancel_form(object $subscription): string
    {
        if ($subscription->status==='cancelled') return '';
        return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="gkod_cancel_subscription"><input type="hidden" name="subscription_id" value="'.(int)$subscription->id.'">'.wp_nonce_field('gkod_cancel_subscription_'.(int)$subscription->id,'_wpnonce',true,false).'<button type="submit" class="button">'.esc_html__('Cancel regular support','georg-kloster-orthodox-donations').'</button></form>';
    }
}
