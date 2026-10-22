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
        add_submenu_page('georg-kloster-orthodox-donations', esc_html__('Campaigns', 'georg-kloster-orthodox-donations'), esc_html__('Campaigns', 'georg-kloster-orthodox-donations'), 'manage_options', 'georg-kloster-orthodox-donations-campaigns', [self::class, 'admin']);
    }

    public static function actions(): void
    {
        $action = Input::post('gkod_campaign_action');
        if (!$action) return;
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        check_admin_referer('gkod_campaign_action');
        $post=Input::post_all();
        foreach (['en','de','ru','uk','pl'] as $language) {
            $key = 'description_' . $language;
            if (array_key_exists($key, $_POST)) $post[$key] = Input::post_html($key);
        }
        $result = match ($action) {
            'save' => Campaigns::save($post),
            'expense', 'expense_update' => Campaigns::expense($post),
            'expense_delete' => Campaigns::delete_expense(absint($post['campaign_id']??0),absint($post['expense_id']??0)),
            default => new \WP_Error('invalid', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations')),
        };
        if (is_wp_error($result) || $result === false) wp_die(esc_html(is_wp_error($result) ? $result->get_error_message() : __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations')));
        $redirect = ['page'=>'georg-kloster-orthodox-donations-campaigns'];
        if (in_array($action, ['expense','expense_update','expense_delete'], true)) $redirect['campaign_id'] = absint($post['campaign_id'] ?? 0);
        wp_safe_redirect(add_query_arg($redirect, admin_url('admin.php'))); exit;
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
        $can_upload = current_user_can('upload_files');
        if ($can_upload) wp_enqueue_media();
        echo '<h2>'.esc_html($campaign?__('Edit campaign','georg-kloster-orthodox-donations'):__('New campaign','georg-kloster-orthodox-donations')).'</h2><form method="post">';wp_nonce_field('gkod_campaign_action');
        echo '<input type="hidden" name="gkod_campaign_action" value="save"><input type="hidden" name="campaign_id" value="'.(int)($campaign->id??0).'">';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::editor_row('title_'.$lang,__('Title','georg-kloster-orthodox-donations'),$campaign->{'title_'.$lang}??'','text',true);
        echo '<tr><th scope="row"><label for="description_'.esc_attr($lang).'">'.esc_html__('Description','georg-kloster-orthodox-donations').'</label></th><td>';
        wp_editor((string)($campaign->{'description_'.$lang}??''),'description_'.$lang,['textarea_name'=>'description_'.$lang,'textarea_rows'=>9,'media_buttons'=>true,'teeny'=>false,'quicktags'=>true]);
        echo '</td></tr>';
        $image_id=(int)($campaign->image_id??0);$image_url=$image_id?wp_get_attachment_image_url($image_id,'medium'):'';
        if($can_upload)echo '<tr><th scope="row"><label>'.esc_html(I18n::text('campaign_image')).'</label></th><td><input type="hidden" id="gkod-campaign-image-id" name="image_id" value="'.$image_id.'"><div id="gkod-campaign-image-preview">'.($image_url?'<img src="'.esc_url($image_url).'" alt="" style="max-width:320px;height:auto">':'').'</div><p><button type="button" class="button" id="gkod-campaign-image-select">'.esc_html(I18n::text('choose_image')).'</button> <button type="button" class="button" id="gkod-campaign-image-remove">'.esc_html(I18n::text('remove_image')).'</button></p></td></tr>';
        self::editor_row('goal',__('Goal','georg-kloster-orthodox-donations').' (EUR)',$campaign?number_format($campaign->goal_cents/100,2,'.',''):'','text',true);
        self::editor_row('minimum',I18n::text('minimum_donation').' (EUR)',$campaign?number_format(max(1000,(int)($campaign->minimum_cents??1000))/100,2,'.',''):'10.00','number',true);
        foreach ([1,2,3] as $index) self::editor_row('preset_'.$index,sprintf(I18n::text('suggested_amount'),$index).' (EUR)',$campaign?number_format(($campaign->{'preset_'.$index.'_cents'}??[1=>1000,2=>2000,3=>10000][$index])/100,2,'.',''):[1=>'10.00',2=>'20.00',3=>'100.00'][$index],'number',true);
        self::editor_row('deadline',__('Deadline','georg-kloster-orthodox-donations'),$campaign->deadline??'','date');
        echo '<tr><th scope="row"><label for="status">'.esc_html__('Status','georg-kloster-orthodox-donations').'</label></th><td><select name="status" id="status">';
        foreach (self::statuses() as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($campaign->status??'draft',$value,false).'>'.esc_html($label).'</option>';
        echo '</select><p class="description">'.esc_html__('Closing a campaign prevents new submissions. Existing regular support continues until cancelled.','georg-kloster-orthodox-donations').'</p></td></tr></tbody></table>';
        submit_button(__('Save campaign','georg-kloster-orthodox-donations'));echo '</form><p><a class="button" href="'.esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-campaigns')).'">'.esc_html__('Cancel','georg-kloster-orthodox-donations').'</a></p>';
        if($campaign){$full='[gkod_campaigns id="'.(int)$campaign->id.'"]';$compact='[gkod_campaigns id="'.(int)$campaign->id.'" layout="compact" url="/spenden-2/"]';echo '<h2>'.esc_html__('Embed this campaign','georg-kloster-orthodox-donations').'</h2><p>'.esc_html__('Full campaign','georg-kloster-orthodox-donations').': <code>'.esc_html($full).'</code></p><p>'.esc_html__('Compact campaign block','georg-kloster-orthodox-donations').': <code>'.esc_html($compact).'</code></p><p class="description">'.esc_html__('Replace the URL in the compact shortcode with the page that contains the full campaign and donation form.','georg-kloster-orthodox-donations').'</p>';}
        if ($campaign) {
            global $wpdb;$expense_id=absint(Input::get('expense_id')??0);$expense=null;
            if($expense_id)$expense=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND campaign_id=%d',Database::table('campaign_expenses'),$expense_id,(int)$campaign->id));
            echo '<h2>'.esc_html($expense?__('Edit expense','georg-kloster-orthodox-donations'):__('Record expense','georg-kloster-orthodox-donations')).'</h2><p>'.esc_html__('Expenses are entered separately; payment providers do not report how funds are spent.','georg-kloster-orthodox-donations').'</p><form method="post">';wp_nonce_field('gkod_campaign_action');
            echo '<input type="hidden" name="gkod_campaign_action" value="'.($expense?'expense_update':'expense').'"><input type="hidden" name="campaign_id" value="'.(int)$campaign->id.'"><input type="hidden" name="expense_id" value="'.(int)($expense->id??0).'"><table class="form-table" role="presentation"><tbody>';
            self::editor_row('expense_amount',__('Amount','georg-kloster-orthodox-donations').' (EUR)',$expense?number_format($expense->amount_cents/100,2,'.',''):'','number',true,'0.01');
            self::editor_row('expense_description',__('Description','georg-kloster-orthodox-donations'),$expense->description??'','text',true);
            self::editor_row('expense_date',__('Date','georg-kloster-orthodox-donations'),$expense->incurred_on??wp_date('Y-m-d'),'date',true);
            echo '</tbody></table>';submit_button($expense?__('Save changes','georg-kloster-orthodox-donations'):__('Record expense','georg-kloster-orthodox-donations'));if($expense)echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-campaigns&campaign_id='.(int)$campaign->id)).'">'.esc_html__('Cancel','georg-kloster-orthodox-donations').'</a>';echo '</form>';
            $table=new Donation_Admin_Table('campaign_expenses',(int)$campaign->id);$table->prepare_items();$table->display();
        }
        echo '</div>';
    }

    private static function editor_row(string $name,string $label,string $value,string $type,bool $required=false,string $minimum='0.50'): void
    {
        echo '<tr><th scope="row"><label for="'.esc_attr($name).'">'.esc_html($label).'</label></th><td>';
        if ($type==='textarea') echo '<textarea class="large-text" rows="5" id="'.esc_attr($name).'" name="'.esc_attr($name).'">'.esc_textarea($value).'</textarea>';
        else echo '<input class="regular-text" type="'.esc_attr($type).'" id="'.esc_attr($name).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'" '.($type==='number'?'step="0.01" min="'.esc_attr($minimum).'" ':'').($required?'required':'').'>';
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
