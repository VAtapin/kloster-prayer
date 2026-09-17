<?php
declare(strict_types=1);
namespace KlosterPrayer;

final class Admin_Screens
{
    public static function settings(): void
    {
        $enabled=(array)get_option('klp_payment_methods',['stripe']);
        echo '<div class="wrap klp-admin"><h1>Настройки оплаты</h1>';
        if(isset($_GET['klp_notice']))echo '<div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>';
        echo '<form method="post">';wp_nonce_field('klp_admin_action', '_klp_nonce');
        echo '<input type="hidden" name="klp_action" value="save_settings"><div class="klp-payment-settings">';
        foreach(['stripe'=>'Stripe','paypal'=>'PayPal'] as $provider=>$title){
            echo '<section class="postbox"><h2>'.esc_html($title).'</h2><div class="inside"><p><label><input type="checkbox" name="methods[]" value="'.esc_attr($provider).'" '.checked(in_array($provider,$enabled,true),true,false).'> Включить '.esc_html($title).'</label></p>';
            if($provider==='paypal')echo '<p><label for="paypal_mode">Режим</label><select id="paypal_mode" name="paypal_mode"><option value="live" '.selected(get_option('klp_paypal_mode','live'),'live',false).'>Live</option><option value="sandbox" '.selected(get_option('klp_paypal_mode'),'sandbox',false).'>Sandbox</option></select></p>';
            $fields=$provider==='stripe'?['stripe_secret'=>'Secret Key','stripe_webhook_secret'=>'Webhook Secret']:['paypal_client_id'=>'Client ID','paypal_secret'=>'Secret','paypal_webhook_id'=>'Webhook ID'];
            foreach($fields as $key=>$label){$secret=str_contains($key,'secret');$value=(string)get_option('klp_'.$key,'');
                echo '<p><label for="'.esc_attr($key).'">'.esc_html($label).'</label><input id="'.esc_attr($key).'" name="'.esc_attr($key).'" type="'.($secret?'password':'text').'" value="'.esc_attr($secret?'':$value).'" autocomplete="off"'.($secret&&$value!==''?' placeholder="Сохранено — оставьте пустым, чтобы не менять"':'').'></p>';
            }
            echo '<p><label>Webhook URL</label><code>'.esc_html(rest_url('kloster-prayer/v1/'.$provider.'/webhook')).'</code></p><p class="description">Настройка нужна только для включённого способа оплаты.</p></div></section>';
        }
        echo '</div>';submit_button('Сохранить настройки');echo '</form></div>';
    }

    private static function link(array $filters, string $label): string
    {
        return '<a href="'.esc_url(add_query_arg(array_merge(['page'=>'kloster-prayer'],$filters),admin_url('admin.php'))).'">'.esc_html($label).'</a>';
    }

    public static function reports(): void
    {
        global $wpdb;
        $month=preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',(string)($_GET['month']??''))?$_GET['month']:wp_date('Y-m');
        $from=$month.'-01 00:00:00';$to=date('Y-m-d H:i:s',strtotime($month.'-01 +1 month'));$orders=Database::table('orders');$items=Database::table('order_items');
        $summary=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) total,SUM(payment_status='paid') paid,SUM(printed_at IS NOT NULL) printed,SUM(CASE WHEN payment_status='paid' THEN amount_cents ELSE 0 END) revenue FROM {$orders} WHERE created_at >= %s AND created_at < %s",$from,$to));
        echo '<div class="wrap klp-admin"><h1>Отчёты</h1><form method="get" class="klp-filters"><input type="hidden" name="page" value="kloster-prayer-reports"><label for="klp-month">Месяц</label><input id="klp-month" type="month" name="month" value="'.esc_attr($month).'"><button class="button">Показать</button></form><div class="klp-stats">';
        foreach([['Получено записок',(string)(int)$summary->total,[]],['Оплачено',(string)(int)$summary->paid,['payment_status'=>'paid']],['Распечатано',(string)(int)$summary->printed,['printed'=>'yes']],['Получено пожертвований',number_format_i18n((int)$summary->revenue/100,2).' €',['payment_status'=>'paid']]] as [$label,$value,$filters])echo '<div><small>'.esc_html($label).'</small><strong>'.self::link(['month'=>$month]+$filters,$value).'</strong></div>';
        echo '</div><div class="klp-report-groups">';
        $providers=$wpdb->get_results($wpdb->prepare("SELECT payment_provider,COUNT(*) total,SUM(CASE WHEN payment_status='paid' THEN amount_cents ELSE 0 END) revenue FROM {$orders} WHERE created_at >= %s AND created_at < %s GROUP BY payment_provider",$from,$to));
        echo '<section><h2>По способам оплаты</h2><table class="widefat striped"><thead><tr><th>Способ</th><th>Записок</th><th>Оплачено</th></tr></thead><tbody>';
        foreach($providers as $p){$filter=['month'=>$month,'provider'=>$p->payment_provider];echo '<tr><td>'.self::link($filter,ucfirst($p->payment_provider)).'</td><td>'.self::link($filter,(string)$p->total).'</td><td>'.self::link($filter+['payment_status'=>'paid'],number_format_i18n($p->revenue/100,2).' €').'</td></tr>';}
        echo '</tbody></table></section><section><h2>По требам</h2><table class="widefat striped"><thead><tr><th>Треба</th><th>Записок</th><th>Оплачено</th></tr></thead><tbody>';
        $services=$wpdb->get_results($wpdb->prepare("SELECT i.service_id,i.service_title,COUNT(DISTINCT i.order_id) total,SUM(CASE WHEN o.payment_status='paid' THEN i.subtotal_cents ELSE 0 END) revenue FROM {$items} i INNER JOIN {$orders} o ON o.id=i.order_id WHERE o.created_at >= %s AND o.created_at < %s GROUP BY i.service_id,i.service_title ORDER BY revenue DESC",$from,$to));
        foreach($services as $s){$filter=['month'=>$month,'service_id'=>$s->service_id];echo '<tr><td>'.self::link($filter,$s->service_title).'</td><td>'.self::link($filter,(string)$s->total).'</td><td>'.self::link($filter+['payment_status'=>'paid'],number_format_i18n($s->revenue/100,2).' €').'</td></tr>';}
        echo '</tbody></table></section></div><h2>Пожертвования и плательщики</h2>';
        $page=max(1,absint($_GET['paged']??1));$per=30;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$orders} WHERE created_at >= %s AND created_at < %s ORDER BY id DESC LIMIT %d OFFSET %d",$from,$to,$per,($page-1)*$per));
        echo '<table class="wp-list-table widefat striped klp-donors"><thead><tr><th>Записка</th><th>Плательщик</th><th>E-mail</th><th>Адрес</th><th>Требы</th><th>Способ</th><th>Сумма</th><th>Статус</th></tr></thead><tbody>';
        foreach($rows as $o){$filter=['view'=>$o->id];$address=json_decode((string)$o->billing_address,true)?:[];$titles=$wpdb->get_col($wpdb->prepare("SELECT service_title FROM {$items} WHERE order_id=%d",$o->id));
            echo '<tr><td>'.self::link($filter,'#'.$o->id).'</td><td>'.self::link($filter,$o->billing_name?:$o->donor_name?:'—').'</td><td>'.self::link($filter,$o->billing_email?:$o->donor_email?:'—').'</td><td>'.self::link($filter,implode(', ',array_filter($address,'is_scalar'))?:'—').'</td><td>'.self::link($filter,implode(', ',$titles)).'</td><td>'.self::link($filter,ucfirst($o->payment_provider)).'</td><td>'.self::link($filter,number_format_i18n($o->amount_cents/100,2).' €').'</td><td>'.self::link($filter,['paid'=>'Оплачено','pending'=>'Ожидает','failed'=>'Ошибка','refunded'=>'Возвращено','cancelled'=>'Отменено'][$o->payment_status]??$o->payment_status).'</td></tr>';
        }
        if(!$rows)echo '<tr><td colspan="8">За выбранный месяц записок нет.</td></tr>';
        echo '</tbody></table><div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(['total'=>(int)ceil((int)$summary->total/$per),'current'=>$page])).'</div></div></div>';
    }
}
