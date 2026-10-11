<?php
declare(strict_types=1);
namespace KlosterPrayer;

final class Support_Pages
{
    public static function init(): void
    {
        add_shortcode('kloster_support', [self::class, 'form']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_klp_cancel_subscription', [self::class, 'cancel_action']);
        add_action('admin_post_nopriv_klp_cancel_subscription', [self::class, 'cancel_action']);
    }

    public static function menu(): void
    {
        add_submenu_page('kloster-prayer', __('Regular support', 'kloster-prayer'), __('Regular support', 'kloster-prayer'), 'manage_options', 'kloster-prayer-support', [self::class, 'admin']);
    }

    public static function form(array|string $atts = []): string
    {
        $atts = shortcode_atts(['lang' => '', 'campaign_id' => 0, 'layout' => 'compact', 'header' => 'yes'], $atts, 'kloster_support');
        $lang = in_array($atts['lang'], ['en','ru','de','uk','pl'], true) ? $atts['lang'] : I18n::language();
        if (!is_user_logged_in()) return I18n::in_language(static fn(): string => '<p><a href="' . esc_url(wp_login_url(get_permalink())) . '">' . esc_html__('Log in to start regular support.', 'kloster-prayer') . '</a></p>', $lang);
        Donation_Pages::private_page();
        $atts['support'] = 'yes';
        return Public_Form::donation_shortcode($atts);
    }

    public static function cancel_action(): void
    {
        if (!is_user_logged_in()) wp_die(esc_html__('Forbidden', 'kloster-prayer'), '', ['response' => 403]);
        $id = absint(Input::post('subscription_id') ?? 0);
        check_admin_referer('klp_cancel_subscription_' . $id);
        $result = Subscriptions::cancel($id);
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()), '', ['response' => $result->get_error_code() === 'forbidden' ? 403 : 502]);
        wp_safe_redirect(wp_get_referer() ?: home_url('/')); exit;
    }

    public static function subscriptions(bool $admin): string
    {
        if (!is_user_logged_in() || ($admin && !current_user_can('manage_options'))) return '';
        Donation_Pages::private_page();
        global $wpdb;
        $page = min(1000, max(1, absint(Input::get('support_page') ?? 1))); $limit = $admin ? 100 : 20;
        $query = $admin ? $wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', Database::table('subscriptions'), $limit + 1, ($page - 1) * $limit) : $wpdb->prepare('SELECT * FROM %i WHERE donor_user_id=%d ORDER BY id DESC LIMIT %d OFFSET %d', Database::table('subscriptions'), get_current_user_id(), $limit + 1, ($page - 1) * $limit);
        $rows = $wpdb->get_results($query);
        $statuses = ['pending' => __('Pending', 'kloster-prayer'), 'active' => __('Active', 'kloster-prayer'), 'past_due' => __('Payment overdue', 'kloster-prayer'), 'failed' => __('Error', 'kloster-prayer'), 'cancelled' => __('Canceled', 'kloster-prayer')];
        $html = '<section class="klp-donation-history"><h2>' . esc_html__('Regular support', 'kloster-prayer') . '</h2><table><thead><tr><th>' . esc_html__('Amount', 'kloster-prayer') . '</th><th>' . esc_html__('Status', 'kloster-prayer') . '</th><th>' . esc_html__('Payment method', 'kloster-prayer') . '</th><th>' . esc_html__('Actions', 'kloster-prayer') . '</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, $limit) as $row) {
            $html .= '<tr><td data-label="' . esc_attr__('Amount', 'kloster-prayer') . '">' . esc_html(number_format_i18n($row->amount_cents / 100, 2) . ' EUR') . ' / ' . esc_html($row->billing_interval === 'year' ? __('Yearly', 'kloster-prayer') : __('Monthly', 'kloster-prayer')) . ($admin ? '<br>#' . (int) $row->id . ' / ' . esc_html__('User', 'kloster-prayer') . ' #' . (int) $row->donor_user_id : '') . '</td><td data-label="' . esc_attr__('Status', 'kloster-prayer') . '">' . esc_html($statuses[$row->status] ?? $row->status) . '</td><td data-label="' . esc_attr__('Payment method', 'kloster-prayer') . '">' . esc_html(ucfirst($row->provider)) . '</td><td data-label="' . esc_attr__('Actions', 'kloster-prayer') . '">';
            if ($row->status !== 'cancelled') $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="klp_cancel_subscription"><input type="hidden" name="subscription_id" value="' . (int) $row->id . '">' . wp_nonce_field('klp_cancel_subscription_' . (int) $row->id, '_wpnonce', true, false) . '<button type="submit">' . esc_html__('Cancel regular support', 'kloster-prayer') . '</button></form>';
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($page > 1) $html .= '<a href="' . esc_url(add_query_arg('support_page', $page - 1)) . '">' . esc_html__('Previous', 'kloster-prayer') . '</a> ';
        if (count($rows) > $limit) $html .= '<a href="' . esc_url(add_query_arg('support_page', $page + 1)) . '">' . esc_html__('Next', 'kloster-prayer') . '</a>';
        return $html . '</section>';
    }

    public static function admin(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'kloster-prayer'), '', ['response' => 403]);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- subscriptions() escapes every value and generates nonce-protected forms; post KSES would remove those forms.
        echo '<div class="wrap">' . self::subscriptions(true) . '</div>';
    }
}
