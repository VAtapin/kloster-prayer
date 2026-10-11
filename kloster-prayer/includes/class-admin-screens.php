<?php
declare(strict_types=1);
namespace KlosterPrayer;
if (!defined('ABSPATH')) exit;
require_once DIR.'/includes/class-paypal-help.php';
require_once DIR.'/includes/class-stripe-help.php';

final class Admin_Screens
{
    public static function settings(): void
    {
        $enabled=(array)get_option('klp_payment_methods',['stripe']);
        echo ('<div class="wrap klp-admin"><h1>'.esc_html__('Payment settings', 'kloster-prayer').'</h1>');
        if((Input::get('klp_notice') !== null))echo ('<div class="notice notice-success is-dismissible"><p>'.esc_html__('Settings saved.', 'kloster-prayer').'</p></div>');
        echo '<form method="post">';wp_nonce_field('klp_admin_action', '_klp_nonce');
        echo '<section class="postbox"><div class="inside"><h2>'.esc_html__('Prayer request notifications', 'kloster-prayer').'</h2><label for="klp-notification-email">'.esc_html__('Recipient email', 'kloster-prayer').'</label><p><input class="regular-text" id="klp-notification-email" type="email" name="notification_email" maxlength="190" value="'.esc_attr((string)get_option('klp_notification_email','')).'"></p><p class="description">'.esc_html__('A separate email is sent to this address after a new prayer request is paid. Leave blank to disable notifications; the administrator email is not used. Older orders are not emailed automatically. Failed notifications are retried by WordPress cron using the currently configured address.', 'kloster-prayer').'</p></div></section>';
        echo '<section class="postbox"><div class="inside"><h2>'.esc_html(__('API domain', 'kloster-prayer')).'</h2><input class="regular-text" type="url" name="api_origin" aria-label="'.esc_attr(__('API domain', 'kloster-prayer')).'" placeholder="https://example.org" value="'.esc_attr((string)get_option('klp_api_origin','')).'"><p class="description">'.esc_html(__('Optional. Leave blank to use the WordPress domain automatically. Enter an HTTPS domain only, without /wp-json/ or other paths. This domain must serve the same WordPress site with valid HTTPS.', 'kloster-prayer')).'</p><p>'.esc_html(__('After changing domains, save the settings and update the webhook URLs in Stripe and PayPal. The plugin does not change them automatically. Keep the old domain available for unfinished payments.', 'kloster-prayer')).'</p></div></section>';
        echo '<input type="hidden" name="klp_action" value="save_settings"><div class="klp-payment-settings">';
        foreach(['stripe'=>'Stripe','paypal'=>'PayPal'] as $provider=>$title){
            echo '<section class="postbox"><h2>'.esc_html($title).'</h2><div class="inside"><p><label><input type="checkbox" name="methods[]" value="'.esc_attr($provider).'" '.checked(in_array($provider,$enabled,true),true,false).('> '.esc_html__('Enable', 'kloster-prayer').' ').esc_html($title).'</label></p>';
            if($provider==='paypal')echo ('<p><label for="paypal_mode">'.esc_html__('Mode', 'kloster-prayer').'</label><select id="paypal_mode" name="paypal_mode"><option value="live" ').selected(get_option('klp_paypal_mode','live'),'live',false).'>Live</option><option value="sandbox" '.selected(get_option('klp_paypal_mode'),'sandbox',false).'>Sandbox</option></select></p>';
            $fields=$provider==='stripe'?['stripe_secret'=>'Secret Key','stripe_webhook_secret'=>'Webhook Secret']:['paypal_client_id'=>'Client ID','paypal_secret'=>'Secret','paypal_webhook_id'=>'Webhook ID'];
            foreach($fields as $key=>$label){$secret=str_contains($key,'secret');$value=(string)get_option('klp_'.$key,'');
                $placeholder=$secret&&$value!==''?__('Saved — leave blank to keep unchanged', 'kloster-prayer'):'';
                echo '<p><label for="'.esc_attr($key).'">'.esc_html($label).'</label><input id="'.esc_attr($key).'" name="'.esc_attr($key).'" type="'.($secret?'password':'text').'" value="'.esc_attr($secret?'':$value).'" autocomplete="off" placeholder="'.esc_attr($placeholder).'"></p>';
            }
            echo '<p><label>Webhook URL</label><code>'.esc_html(Payments::api_url($provider.'/webhook')).('</code></p><p class="description">'.esc_html__('Configuration is required only for enabled payment methods.', 'kloster-prayer').'</p>');
            echo '<p class="description">' . esc_html__('For regular support, also enable these webhook events:', 'kloster-prayer') . '</p><p><code>' . esc_html($provider === 'stripe' ? 'invoice.payment_succeeded, invoice.payment_failed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, charge.refunded' : 'PAYMENT.SALE.COMPLETED, PAYMENT.SALE.REFUNDED, BILLING.SUBSCRIPTION.CREATED, BILLING.SUBSCRIPTION.ACTIVATED, BILLING.SUBSCRIPTION.CANCELLED, BILLING.SUBSCRIPTION.EXPIRED, BILLING.SUBSCRIPTION.SUSPENDED, BILLING.SUBSCRIPTION.PAYMENT.FAILED') . '</code></p>';
            if($provider==='paypal')PayPal_Help::render();
            if($provider==='stripe')Stripe_Help::render();
            echo '</div></section>';
        }
        echo '</div>';submit_button((__('Save settings', 'kloster-prayer')));echo '</form></div>';
    }

    private static function link(array $filters, string $label): string
    {
        return '<a href="'.esc_url(add_query_arg(array_merge(['page'=>'kloster-prayer'],$filters),admin_url('admin.php'))).'">'.esc_html($label).'</a>';
    }

    public static function reports(): void
    {
        global $wpdb;
        $month=preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',(string)(Input::get('month')??''))?Input::get('month'):wp_date('Y-m');
        $from=$month.'-01 00:00:00';$to=gmdate('Y-m-d H:i:s',strtotime($month.'-01 +1 month'));$orders=Database::table('orders');$items=Database::table('order_items');
        $summary=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) total,SUM(payment_status='paid') paid,SUM(printed_at IS NOT NULL) printed,SUM(CASE WHEN payment_status='paid' THEN amount_cents-refunded_cents ELSE 0 END) revenue FROM %i WHERE created_at >= %s AND created_at < %s", $orders,$from,$to));
        echo ('<div class="wrap klp-admin"><h1>'.esc_html__('Reports', 'kloster-prayer').'</h1><form method="get" class="klp-filters"><input type="hidden" name="page" value="kloster-prayer-reports"><label for="klp-month">'.esc_html__('Month', 'kloster-prayer').'</label><input id="klp-month" type="month" name="month" value="').esc_attr($month).('"><button class="button">'.esc_html__('Show', 'kloster-prayer').'</button></form><div class="klp-stats">');
        foreach([[(__('Requests received', 'kloster-prayer')),(string)(int)$summary->total,[]],[(__('Paid', 'kloster-prayer')),(string)(int)$summary->paid,['payment_status'=>'paid']],[(__('Printed', 'kloster-prayer')),(string)(int)$summary->printed,['printed'=>'yes']],[(__('Donations received', 'kloster-prayer')),number_format_i18n((int)$summary->revenue/100,2).' €',['payment_status'=>'paid']]] as [$label,$value,$filters])echo '<div><small>'.esc_html($label).'</small><strong>'.wp_kses_post(self::link(['month'=>$month]+$filters,$value)).'</strong></div>';
        echo '</div><div class="klp-report-groups">';
        $providers=$wpdb->get_results($wpdb->prepare("SELECT payment_provider,COUNT(*) total,SUM(CASE WHEN payment_status='paid' THEN amount_cents-refunded_cents ELSE 0 END) revenue FROM %i WHERE created_at >= %s AND created_at < %s GROUP BY payment_provider", $orders,$from,$to));
        echo ('<section><h2>'.esc_html__('By payment method', 'kloster-prayer').'</h2><table class="widefat striped"><thead><tr><th>'.esc_html__('Method', 'kloster-prayer').'</th><th>'.esc_html__('Requests', 'kloster-prayer').'</th><th>'.esc_html__('Paid', 'kloster-prayer').'</th></tr></thead><tbody>');
        foreach($providers as $p){$filter=['month'=>$month,'provider'=>$p->payment_provider];echo '<tr><td>'.wp_kses_post(self::link($filter,ucfirst($p->payment_provider))).'</td><td>'.wp_kses_post(self::link($filter,(string)$p->total)).'</td><td>'.wp_kses_post(self::link($filter+['payment_status'=>'paid'],number_format_i18n($p->revenue/100,2).' €')).'</td></tr>';}
        echo ('</tbody></table></section><section><h2>'.esc_html__('By service', 'kloster-prayer').'</h2><table class="widefat striped"><thead><tr><th>'.esc_html__('Service', 'kloster-prayer').'</th><th>'.esc_html__('Requests', 'kloster-prayer').'</th><th>'.esc_html__('Paid', 'kloster-prayer').'</th></tr></thead><tbody>');
        $services=$wpdb->get_results($wpdb->prepare("SELECT i.service_id,i.service_title,COUNT(DISTINCT i.order_id) total,SUM(CASE WHEN o.payment_status='paid' THEN i.subtotal_cents ELSE 0 END) revenue FROM %i i INNER JOIN %i o ON o.id=i.order_id WHERE o.created_at >= %s AND o.created_at < %s GROUP BY i.service_id,i.service_title ORDER BY revenue DESC", $items, $orders,$from,$to));
        foreach($services as $s){$filter=['month'=>$month,'service_id'=>$s->service_id];echo '<tr><td>'.wp_kses_post(self::link($filter,$s->service_title)).'</td><td>'.wp_kses_post(self::link($filter,(string)$s->total)).'</td><td>'.wp_kses_post(self::link($filter+['payment_status'=>'paid'],number_format_i18n($s->revenue/100,2).' €')).'</td></tr>';}
        echo ('</tbody></table></section></div><h2>'.esc_html__('Donations and donors', 'kloster-prayer').'</h2>');
        $page=max(1,absint(Input::get('paged')??1));$per=30;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE created_at >= %s AND created_at < %s ORDER BY id DESC LIMIT %d OFFSET %d", $orders,$from,$to,$per,($page-1)*$per));
        echo ('<table class="wp-list-table widefat striped klp-donors"><thead><tr><th>'.esc_html__('Request', 'kloster-prayer').'</th><th>'.esc_html__('Payer', 'kloster-prayer').'</th><th>E-mail</th><th>'.esc_html__('Address', 'kloster-prayer').'</th><th>'.esc_html__('Services', 'kloster-prayer').'</th><th>'.esc_html__('Method', 'kloster-prayer').'</th><th>'.esc_html__('Amount', 'kloster-prayer').'</th><th>'.esc_html__('Status', 'kloster-prayer').'</th></tr></thead><tbody>');
        foreach($rows as $o){$filter=['view'=>$o->id];$address=json_decode((string)$o->billing_address,true)?:[];$titles=$wpdb->get_col($wpdb->prepare("SELECT service_title FROM %i WHERE order_id=%d", $items,$o->id));
            echo '<tr><td>'.wp_kses_post(self::link($filter,'#'.$o->id)).'</td><td>'.wp_kses_post(self::link($filter,$o->billing_name?:$o->donor_name?:'—')).'</td><td>'.wp_kses_post(self::link($filter,$o->billing_email?:$o->donor_email?:'—')).'</td><td>'.wp_kses_post(self::link($filter,implode(', ',array_filter($address,'is_scalar'))?:'—')).'</td><td>'.wp_kses_post(self::link($filter,implode(', ',$titles))).'</td><td>'.wp_kses_post(self::link($filter,ucfirst($o->payment_provider))).'</td><td>'.wp_kses_post(self::link($filter,number_format_i18n($o->amount_cents/100,2).' €')).'</td><td>'.wp_kses_post(self::link($filter,['paid'=>(__('Paid', 'kloster-prayer')),'pending'=>(__('Pending', 'kloster-prayer')),'failed'=>(__('Error', 'kloster-prayer')),'refunded'=>(__('Refunded', 'kloster-prayer')),'cancelled'=>(__('Canceled', 'kloster-prayer'))][$o->payment_status]??$o->payment_status)).'</td></tr>';
        }
        if(!$rows)echo ('<tr><td colspan="8">'.esc_html__('No requests for the selected month.', 'kloster-prayer').'</td></tr>');
        echo '</tbody></table><div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(['total'=>(int)ceil((int)$summary->total/$per),'current'=>$page])).'</div></div></div>';
    }
}
