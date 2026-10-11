<?php
declare(strict_types=1);
namespace KlosterPrayer;

/** Campaign administration and account-owned donation history. */
final class Donation_Pages
{
    public static function private_page(): void
    {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!headers_sent()) nocache_headers();
    }

    public static function init(): void
    {
        add_shortcode('kloster_donation_history', [self::class, 'history']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'actions']);
    }

    public static function menu(): void
    {
        add_submenu_page('kloster-prayer', __('Campaigns', 'kloster-prayer'), __('Campaigns', 'kloster-prayer'), 'manage_options', 'kloster-prayer-campaigns', [self::class, 'admin']);
    }

    public static function actions(): void
    {
        $action = Input::post('klp_campaign_action');
        if (!$action) return;
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'kloster-prayer'), '', ['response' => 403]);
        check_admin_referer('klp_campaign_action');
        $result = $action === 'save' ? Campaigns::save(Input::post_all()) : ($action === 'expense' ? Campaigns::expense(Input::post_all()) : new \WP_Error('invalid', __('The request could not be processed. Please try again.', 'kloster-prayer')));
        if (is_wp_error($result) || $result === false) wp_die(esc_html(is_wp_error($result) ? $result->get_error_message() : __('The request could not be processed. Please try again.', 'kloster-prayer')));
        wp_safe_redirect(admin_url('admin.php?page=kloster-prayer-campaigns')); exit;
    }

    public static function admin(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'kloster-prayer'), '', ['response' => 403]);
        global $wpdb;
        $campaign = Campaigns::get(absint(Input::get('campaign_id') ?? 0));
        echo '<div class="wrap"><h1>' . esc_html__('Campaigns', 'kloster-prayer') . '</h1><table class="widefat striped"><thead><tr><th>' . esc_html__('Campaign', 'kloster-prayer') . '</th><th>' . esc_html__('Status', 'kloster-prayer') . '</th><th>' . esc_html__('Report', 'kloster-prayer') . '</th></tr></thead><tbody>';
        foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT 100', Database::table('campaigns'))) as $row) {
            echo '<tr><td><a href="' . esc_url(add_query_arg(['page' => 'kloster-prayer-campaigns', 'campaign_id' => (int) $row->id], admin_url('admin.php'))) . '">' . esc_html(Campaigns::title($row)) . '</a><br><code>[kloster_campaigns id="' . (int) $row->id . '"]</code></td><td>' . esc_html(self::statuses()[$row->status]) . '</td><td>' . wp_kses_post(Campaigns::summary($row)) . '</td></tr>';
        }
        echo '</tbody></table><h2>' . esc_html($campaign ? __('Edit campaign', 'kloster-prayer') : __('New campaign', 'kloster-prayer')) . '</h2><form method="post">'; wp_nonce_field('klp_campaign_action');
        echo '<input type="hidden" name="klp_campaign_action" value="save"><input type="hidden" name="campaign_id" value="' . (int) ($campaign->id ?? 0) . '">';
        foreach (['en','ru','de','uk','pl'] as $lang) {
            echo '<p><label>' . esc_html__('Title', 'kloster-prayer') . ' (' . esc_html(strtoupper($lang)) . ') <input class="large-text" name="title_' . esc_attr($lang) . '" maxlength="250" ' . ($lang === 'en' ? 'required' : '') . ' value="' . esc_attr($campaign->{'title_' . $lang} ?? '') . '"></label></p><p><label>' . esc_html__('Description', 'kloster-prayer') . ' (' . esc_html(strtoupper($lang)) . ')<textarea class="large-text" name="description_' . esc_attr($lang) . '" rows="2">' . esc_textarea($campaign->{'description_' . $lang} ?? '') . '</textarea></label></p>';
        }
        echo '<p><label>' . esc_html__('Goal', 'kloster-prayer') . ' (EUR) <input name="goal" inputmode="decimal" required value="' . esc_attr($campaign ? number_format($campaign->goal_cents / 100, 2, '.', '') : '') . '"></label></p><p><label>' . esc_html__('Deadline', 'kloster-prayer') . ' <input name="deadline" type="date" value="' . esc_attr($campaign->deadline ?? '') . '"></label></p><p><label>' . esc_html__('Status', 'kloster-prayer') . ' <select name="status">';
        foreach (self::statuses() as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected($campaign->status ?? 'draft', $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label></p><p>' . esc_html__('Closing a campaign prevents new submissions. Existing regular support continues until cancelled.', 'kloster-prayer') . '</p>'; submit_button(__('Save campaign', 'kloster-prayer')); echo '</form>';
        if ($campaign) {
            echo '<h2>' . esc_html__('Record expense', 'kloster-prayer') . '</h2><p>' . esc_html__('Expenses are entered separately; payment providers do not report how funds are spent.', 'kloster-prayer') . '</p><form method="post">'; wp_nonce_field('klp_campaign_action');
            echo '<input type="hidden" name="klp_campaign_action" value="expense"><input type="hidden" name="campaign_id" value="' . (int) $campaign->id . '"><p><label>' . esc_html__('Amount', 'kloster-prayer') . ' (EUR) <input name="expense_amount" inputmode="decimal" required></label></p><p><label>' . esc_html__('Description', 'kloster-prayer') . ' <input class="regular-text" name="expense_description" maxlength="250" required></label></p><p><label>' . esc_html__('Date', 'kloster-prayer') . ' <input name="expense_date" type="date" required value="' . esc_attr(wp_date('Y-m-d')) . '"></label></p>'; submit_button(__('Record expense', 'kloster-prayer')); echo '</form><table class="widefat striped"><tbody>';
            foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE campaign_id=%d ORDER BY id DESC LIMIT 100', Database::table('campaign_expenses'), $campaign->id)) as $expense) echo '<tr><td>' . esc_html($expense->incurred_on) . '</td><td>' . esc_html($expense->description) . '</td><td>' . esc_html(number_format_i18n($expense->amount_cents / 100, 2) . ' EUR') . '</td></tr>';
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private static function statuses(): array
    {
        return ['draft' => __('Draft', 'kloster-prayer'), 'open' => __('Open', 'kloster-prayer'), 'closed' => __('Closed', 'kloster-prayer')];
    }

    public static function history(array|string $atts = []): string
    {
        $atts = shortcode_atts(['lang' => ''], $atts, 'kloster_donation_history');
        $lang = in_array($atts['lang'], ['en','ru','de','uk','pl'], true) ? $atts['lang'] : I18n::language();
        return I18n::in_language(static fn(): string => self::history_html($lang), $lang);
    }

    private static function history_html(string $lang): string
    {
        if (!is_user_logged_in()) return '<p><a href="' . esc_url(wp_login_url(get_permalink())) . '">' . esc_html__('Log in to view your donations.', 'kloster-prayer') . '</a></p>';
        self::private_page();
        global $wpdb;
        $page = min(1000, max(1, absint(Input::get('donation_page') ?? 1)));
        // Ownership is the authenticated WordPress user, never a posted ID or email.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,campaign_id,amount_cents,refunded_cents,currency,payment_status,created_at FROM %i WHERE donor_user_id=%d ORDER BY id DESC LIMIT 21 OFFSET %d', Database::table('orders'), get_current_user_id(), ($page - 1) * 20));
        $html = '<div class="klp-donation-history"><h2>' . esc_html__('Your donations', 'kloster-prayer') . '</h2><p>' . esc_html__('Only payments made while signed in to this account are shown. Guest payments are not linked by email.', 'kloster-prayer') . '</p><table><thead><tr><th>' . esc_html__('Date', 'kloster-prayer') . '</th><th>' . esc_html__('Campaign', 'kloster-prayer') . '</th><th>' . esc_html__('Amount', 'kloster-prayer') . '</th><th>' . esc_html__('Status', 'kloster-prayer') . '</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 20) as $order) {
            $campaign = $order->campaign_id ? Campaigns::get((int) $order->campaign_id) : null;
            $statuses = ['pending' => __('Pending', 'kloster-prayer'), 'paid' => __('Paid', 'kloster-prayer'), 'failed' => __('Error', 'kloster-prayer'), 'refunded' => __('Refunded', 'kloster-prayer'), 'cancelled' => __('Canceled', 'kloster-prayer')];
            $status = $order->payment_status === 'paid' && $order->refunded_cents > 0 ? __('Partially refunded', 'kloster-prayer') : ($statuses[$order->payment_status] ?? $order->payment_status);
            $amount = esc_html(number_format_i18n($order->amount_cents / 100, 2) . ' ' . $order->currency);
            if ($order->refunded_cents > 0) $amount .= '<br>' . esc_html__('Refunded', 'kloster-prayer') . ': ' . esc_html(number_format_i18n($order->refunded_cents / 100, 2) . ' ' . $order->currency);
            $html .= '<tr><td data-label="' . esc_attr__('Date', 'kloster-prayer') . '">' . esc_html($order->created_at) . '</td><td data-label="' . esc_attr__('Campaign', 'kloster-prayer') . '">' . esc_html($campaign && $campaign->status !== 'draft' ? Campaigns::title($campaign, $lang) : I18n::donation_text('title')) . '</td><td data-label="' . esc_attr__('Amount', 'kloster-prayer') . '"><span>' . $amount . '</span></td><td data-label="' . esc_attr__('Status', 'kloster-prayer') . '">' . esc_html($status) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($page > 1) $html .= '<a href="' . esc_url(add_query_arg('donation_page', $page - 1)) . '">' . esc_html__('Previous', 'kloster-prayer') . '</a> ';
        if (count($rows) > 20) $html .= '<a href="' . esc_url(add_query_arg('donation_page', $page + 1)) . '">' . esc_html__('Next', 'kloster-prayer') . '</a>';
        return $html . '</div>' . (class_exists(Support_Pages::class) ? Support_Pages::subscriptions(false) : '');
    }
}
