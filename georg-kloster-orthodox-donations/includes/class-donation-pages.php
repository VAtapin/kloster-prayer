<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

/** Campaign administration and account-owned donation history. */
final class Donation_Pages
{
    public static function private_page(): void
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache integration flag; prevents caching private donation data.
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!headers_sent()) nocache_headers();
    }

    public static function init(): void
    {
        add_shortcode('gkod_donation_history', [self::class, 'history']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'actions']);
    }

    public static function menu(): void
    {
        add_submenu_page('georg-kloster-orthodox-donations', __('Campaigns', 'georg-kloster-orthodox-donations'), __('Campaigns', 'georg-kloster-orthodox-donations'), 'manage_options', 'georg-kloster-orthodox-donations-campaigns', [self::class, 'admin']);
    }

    public static function actions(): void
    {
        $action = Input::post('gkod_campaign_action');
        if (!$action) return;
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        check_admin_referer('gkod_campaign_action');
        $result = $action === 'save' ? Campaigns::save(Input::post_all()) : ($action === 'expense' ? Campaigns::expense(Input::post_all()) : new \WP_Error('invalid', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations')));
        if (is_wp_error($result) || $result === false) wp_die(esc_html(is_wp_error($result) ? $result->get_error_message() : __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations')));
        wp_safe_redirect(admin_url('admin.php?page=georg-kloster-orthodox-donations-campaigns')); exit;
    }

    public static function admin(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response'=>403]);
        require_once DIR.'/includes/class-donation-admin-table.php';
        $id=absint(Input::get('campaign_id')??0);$campaign=$id?Campaigns::get($id):null;
        echo '<div class="wrap gkod-admin"><h1>'.esc_html__('Campaigns','georg-kloster-orthodox-donations').'</h1>';
        if (!$id && Input::get('new')===null) {
            echo '<a class="page-title-action" href="'.esc_url(add_query_arg(['page'=>'georg-kloster-orthodox-donations-campaigns','new'=>1],admin_url('admin.php'))).'">'.esc_html__('Add new','georg-kloster-orthodox-donations').'</a><hr class="wp-header-end">';
            $table=new Donation_Admin_Table('campaigns');$table->prepare_items();$table->display();echo '</div>';return;
        }
        if ($id && !$campaign) { echo '<p>'.esc_html__('Campaign not found.','georg-kloster-orthodox-donations').'</p></div>';return; }
        $lang=I18n::content_language();
        echo '<h2>'.esc_html($campaign?__('Edit campaign','georg-kloster-orthodox-donations'):__('New campaign','georg-kloster-orthodox-donations')).'</h2><form method="post">';wp_nonce_field('gkod_campaign_action');
        echo '<input type="hidden" name="gkod_campaign_action" value="save"><input type="hidden" name="campaign_id" value="'.(int)($campaign->id??0).'">';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::editor_row('title_'.$lang,__('Title','georg-kloster-orthodox-donations'),$campaign->{'title_'.$lang}??'','text',true);
        self::editor_row('description_'.$lang,__('Description','georg-kloster-orthodox-donations'),$campaign->{'description_'.$lang}??'','textarea');
        self::editor_row('goal',__('Goal','georg-kloster-orthodox-donations').' (EUR)',$campaign?number_format($campaign->goal_cents/100,2,'.',''):'','text',true);
        self::editor_row('deadline',__('Deadline','georg-kloster-orthodox-donations'),$campaign->deadline??'','date');
        echo '<tr><th scope="row"><label for="status">'.esc_html__('Status','georg-kloster-orthodox-donations').'</label></th><td><select name="status" id="status">';
        foreach (self::statuses() as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($campaign->status??'draft',$value,false).'>'.esc_html($label).'</option>';
        echo '</select><p class="description">'.esc_html__('Closing a campaign prevents new submissions. Existing regular support continues until cancelled.','georg-kloster-orthodox-donations').'</p></td></tr></tbody></table>';
        submit_button(__('Save campaign','georg-kloster-orthodox-donations'));echo '</form><p><a class="button" href="'.esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-campaigns')).'">'.esc_html__('Cancel','georg-kloster-orthodox-donations').'</a></p>';
        if ($campaign) {
            echo '<h2>'.esc_html__('Record expense','georg-kloster-orthodox-donations').'</h2><p>'.esc_html__('Expenses are entered separately; payment providers do not report how funds are spent.','georg-kloster-orthodox-donations').'</p><form method="post">';wp_nonce_field('gkod_campaign_action');
            echo '<input type="hidden" name="gkod_campaign_action" value="expense"><input type="hidden" name="campaign_id" value="'.(int)$campaign->id.'"><table class="form-table" role="presentation"><tbody>';
            self::editor_row('expense_amount',__('Amount','georg-kloster-orthodox-donations').' (EUR)','','text',true);
            self::editor_row('expense_description',__('Description','georg-kloster-orthodox-donations'),'','text',true);
            self::editor_row('expense_date',__('Date','georg-kloster-orthodox-donations'),wp_date('Y-m-d'),'date',true);
            echo '</tbody></table>';submit_button(__('Record expense','georg-kloster-orthodox-donations'));echo '</form>';
            $table=new Donation_Admin_Table('campaign_expenses',(int)$campaign->id);$table->prepare_items();$table->display();
        }
        echo '</div>';
    }

    private static function editor_row(string $name,string $label,string $value,string $type,bool $required=false): void
    {
        echo '<tr><th scope="row"><label for="'.esc_attr($name).'">'.esc_html($label).'</label></th><td>';
        if ($type==='textarea') echo '<textarea class="large-text" rows="5" id="'.esc_attr($name).'" name="'.esc_attr($name).'">'.esc_textarea($value).'</textarea>';
        else echo '<input class="regular-text" type="'.esc_attr($type).'" id="'.esc_attr($name).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'" '.($required?'required':'').'>';
        echo '</td></tr>';
    }

    private static function statuses(): array
    {
        return ['draft' => __('Draft', 'georg-kloster-orthodox-donations'), 'open' => __('Open', 'georg-kloster-orthodox-donations'), 'closed' => __('Closed', 'georg-kloster-orthodox-donations')];
    }

    public static function history(array|string $atts = []): string
    {
        $atts = shortcode_atts(['lang' => ''], $atts, 'gkod_donation_history');
        $lang = in_array($atts['lang'], ['en','ru','de','uk','pl'], true) ? $atts['lang'] : I18n::language();
        return I18n::in_language(static fn(): string => self::history_html($lang), $lang);
    }

    private static function history_html(string $lang): string
    {
        if (!is_user_logged_in()) return '<p><a href="' . esc_url(wp_login_url(get_permalink())) . '">' . esc_html__('Log in to view your donations.', 'georg-kloster-orthodox-donations') . '</a></p>';
        self::private_page();
        global $wpdb;
        $page = min(1000, max(1, absint(Input::get('donation_page') ?? 1)));
        // Ownership is the authenticated WordPress user, never a posted ID or email.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Account-owned custom payment ledger; read current status/refunds without stale private history.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,campaign_id,amount_cents,refunded_cents,currency,payment_status,created_at FROM %i WHERE donor_user_id=%d ORDER BY id DESC LIMIT 21 OFFSET %d', Database::table('orders'), get_current_user_id(), ($page - 1) * 20));
        $html = '<div class="gkod-donation-history"><h2>' . esc_html__('Your donations', 'georg-kloster-orthodox-donations') . '</h2><p>' . esc_html__('Only payments made while signed in to this account are shown. Guest payments are not linked by email.', 'georg-kloster-orthodox-donations') . '</p><table><thead><tr><th>' . esc_html__('Date', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Campaign', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Amount', 'georg-kloster-orthodox-donations') . '</th><th>' . esc_html__('Status', 'georg-kloster-orthodox-donations') . '</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 20) as $order) {
            $campaign = $order->campaign_id ? Campaigns::get((int) $order->campaign_id) : null;
            $statuses = ['pending' => __('Pending', 'georg-kloster-orthodox-donations'), 'paid' => __('Paid', 'georg-kloster-orthodox-donations'), 'failed' => __('Error', 'georg-kloster-orthodox-donations'), 'refunded' => __('Refunded', 'georg-kloster-orthodox-donations'), 'cancelled' => __('Canceled', 'georg-kloster-orthodox-donations')];
            $status = $order->payment_status === 'paid' && $order->refunded_cents > 0 ? __('Partially refunded', 'georg-kloster-orthodox-donations') : ($statuses[$order->payment_status] ?? $order->payment_status);
            $amount = esc_html(number_format_i18n($order->amount_cents / 100, 2) . ' ' . $order->currency);
            if ($order->refunded_cents > 0) $amount .= '<br>' . esc_html__('Refunded', 'georg-kloster-orthodox-donations') . ': ' . esc_html(number_format_i18n($order->refunded_cents / 100, 2) . ' ' . $order->currency);
            $html .= '<tr><td data-label="' . esc_attr__('Date', 'georg-kloster-orthodox-donations') . '">' . esc_html($order->created_at) . '</td><td data-label="' . esc_attr__('Campaign', 'georg-kloster-orthodox-donations') . '">' . esc_html($campaign && $campaign->status !== 'draft' ? Campaigns::title($campaign, $lang) : I18n::donation_text('title')) . '</td><td data-label="' . esc_attr__('Amount', 'georg-kloster-orthodox-donations') . '"><span>' . $amount . '</span></td><td data-label="' . esc_attr__('Status', 'georg-kloster-orthodox-donations') . '">' . esc_html($status) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($page > 1) $html .= '<a href="' . esc_url(add_query_arg('donation_page', $page - 1)) . '">' . esc_html__('Previous', 'georg-kloster-orthodox-donations') . '</a> ';
        if (count($rows) > 20) $html .= '<a href="' . esc_url(add_query_arg('donation_page', $page + 1)) . '">' . esc_html__('Next', 'georg-kloster-orthodox-donations') . '</a>';
        return $html . '</div>' . (class_exists(Support_Pages::class) ? Support_Pages::subscriptions(false) : '');
    }
}
