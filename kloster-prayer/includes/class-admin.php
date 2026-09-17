<?php
declare(strict_types=1);

namespace KlosterPrayer;

final class Admin
{
    public static function init(): void
    {
        if (!is_admin()) return;
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'handle_actions']);
        add_action('admin_post_klp_csv', [self::class, 'export_csv']);
        add_filter('plugin_row_meta', [self::class, 'external_meta'], 10, 2);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if (str_contains($hook, 'kloster-prayer')) { wp_enqueue_style('klp-admin', plugins_url('assets/css/admin.css', FILE), [], VERSION); wp_enqueue_style('klp-admin-native', plugins_url('assets/css/admin-native.css', FILE), ['klp-admin'], VERSION); wp_enqueue_media(); wp_enqueue_script('klp-admin-images', plugins_url('assets/js/admin-images.js', FILE), ['jquery','media-views'], VERSION, true); }
        });
    }

    public static function menu(): void
    {
        add_menu_page('Kloster', 'Kloster – Записки', 'manage_options', 'kloster-prayer', [self::class, 'orders'], 'dashicons-heart', 26);
        add_submenu_page('kloster-prayer', 'Записки', 'Записки', 'manage_options', 'kloster-prayer', [self::class, 'orders']);
        add_submenu_page('kloster-prayer', 'Требы', 'Требы', 'manage_options', 'kloster-prayer-services', [self::class, 'services']);
        add_submenu_page('kloster-prayer', 'Формы', 'Формы', 'manage_options', 'kloster-prayer-forms', [self::class, 'forms']);
        add_submenu_page('kloster-prayer', 'Отчёты', 'Отчёты', 'manage_options', 'kloster-prayer-reports', [self::class, 'reports']);
        add_submenu_page('kloster-prayer', 'Настройки', 'Настройки', 'manage_options', 'kloster-prayer-settings', [self::class, 'settings']);
        add_submenu_page('kloster-prayer', 'Диагностика', 'Диагностика', 'manage_options', 'kloster-prayer-diagnostics', [self::class, 'diagnostics']);
    }

    public static function handle_actions(): void
    {
        if (!current_user_can('manage_options') || empty($_POST['klp_action'])) return;
        check_admin_referer('klp_admin_action', '_klp_nonce');
        global $wpdb;
        $action = sanitize_key((string) $_POST['klp_action']);
        if ($action === 'refresh_customer') {
            $id=absint($_POST['order_id']??0);$ok=Payments::refresh_customer($id);
            wp_safe_redirect(add_query_arg(['page'=>'kloster-prayer','view'=>$id,'customer_sync'=>$ok?'ok':'unavailable'],admin_url('admin.php')));exit;
        }
        if ($action === 'catalog_bulk') {
            $kind=in_array($_POST['catalog']??'', ['forms','services'],true)?$_POST['catalog']:'forms';
            $operation=($_POST['action']??'-1')!=='-1'?$_POST['action']:($_POST['action2']??'');
            if(in_array($operation,['enable','disable'],true))foreach(array_filter(array_map('absint',(array)($_POST['ids']??[]))) as $item_id) $wpdb->update(Database::table($kind),['is_active'=>$operation==='enable'?1:0,'updated_at'=>current_time('mysql')],['id'=>$item_id]);
            self::redirect('kloster-prayer-'.$kind,'updated');
        }
        if ($action === 'orders_bulk') $action=($_POST['action']??'-1')!=='-1'?sanitize_key($_POST['action']):sanitize_key($_POST['action2']??'');
        if ($action === 'generate_pdf') {
            $ids = array_filter(array_map('absint', (array) ($_POST['order_ids'] ?? [])));
            if (!$ids) {
                require_once DIR.'/includes/class-orders-table.php';
                [$where,$args]=Orders_Table::where($_POST);
                $query='SELECT id FROM '.Database::table('orders').' WHERE '.$where;
                $ids=array_map('intval',$wpdb->get_col($args?$wpdb->prepare($query,...$args):$query));
                if (!$ids) wp_die('Нет записок для выбранного фильтра.');
            }
            $filters = ['order_ids' => $ids];
            foreach (['payment_status','intention','printed'] as $key) if (!empty($_POST[$key])) $filters[$key] = sanitize_key((string) $_POST[$key]);
            wp_safe_redirect(Pdf::url($filters)); exit;
        }
        if ($action === 'resend_confirmation') {
            $ok = Payments::resend_confirmation(absint($_POST['order_id'] ?? 0));
            self::redirect('kloster-prayer', $ok ? 'email_sent' : 'email_failed');
        }
        if ($action === 'save_settings') {
            if (!empty($_POST['stripe_secret'])) update_option('klp_stripe_secret', sanitize_text_field((string) wp_unslash($_POST['stripe_secret'])));
            if (!empty($_POST['stripe_webhook_secret'])) update_option('klp_stripe_webhook_secret', sanitize_text_field((string) wp_unslash($_POST['stripe_webhook_secret'])));
            update_option('klp_paypal_mode', in_array($_POST['paypal_mode'] ?? '', ['live', 'sandbox'], true) ? $_POST['paypal_mode'] : 'live');
            update_option('klp_paypal_client_id', sanitize_text_field((string) wp_unslash($_POST['paypal_client_id'] ?? '')));
            if (!empty($_POST['paypal_secret'])) update_option('klp_paypal_secret', sanitize_text_field((string) wp_unslash($_POST['paypal_secret'])));
            update_option('klp_paypal_webhook_id', sanitize_text_field((string) wp_unslash($_POST['paypal_webhook_id'] ?? '')));
            $methods = array_values(array_intersect(['stripe', 'paypal'], (array) ($_POST['methods'] ?? [])));
            update_option('klp_payment_methods', $methods ?: ['stripe']);
            self::redirect('kloster-prayer-settings', 'saved');
        }
        if ($action === 'save_service') {
            $id = absint($_POST['id'] ?? 0); $price = (int) round(((float) str_replace(',', '.', (string) ($_POST['price'] ?? '0'))) * 100);
            $data = ['slug' => sanitize_title((string) ($_POST['slug'] ?? '')), 'title_ru' => sanitize_text_field((string) wp_unslash($_POST['title_ru'] ?? '')), 'title_de' => sanitize_text_field((string) wp_unslash($_POST['title_de'] ?? '')), 'title_uk' => sanitize_text_field((string) wp_unslash($_POST['title_uk'] ?? '')), 'title_pl' => sanitize_text_field((string) wp_unslash($_POST['title_pl'] ?? '')), 'description_ru' => sanitize_textarea_field((string) wp_unslash($_POST['description_ru'] ?? '')), 'description_de' => sanitize_textarea_field((string) wp_unslash($_POST['description_de'] ?? '')), 'description_uk' => sanitize_textarea_field((string) wp_unslash($_POST['description_uk'] ?? '')), 'description_pl' => sanitize_textarea_field((string) wp_unslash($_POST['description_pl'] ?? '')), 'image_id' => absint($_POST['image_id'] ?? 0), 'intention_mode' => in_array($_POST['intention_mode'] ?? '', ['both','health','rest','none'], true) ? $_POST['intention_mode'] : 'both', 'pricing_mode' => in_array($_POST['pricing_mode'] ?? '', ['flat','per_person','per_sheet'], true) ? $_POST['pricing_mode'] : 'flat', 'price_cents' => max(0, $price), 'names_per_sheet' => max(1, absint($_POST['names_per_sheet'] ?? 10)), 'is_active' => isset($_POST['is_active']) ? 1 : 0, 'sort_order' => (int) ($_POST['sort_order'] ?? 0), 'updated_at' => current_time('mysql')];
            $data = self::preserve_translations($data);
            if ($id) $wpdb->update(Database::table('services'), $data, ['id' => $id]); else { $data['created_at'] = current_time('mysql'); $wpdb->insert(Database::table('services'), $data); }
            self::redirect('kloster-prayer-services', 'saved');
        }
        if ($action === 'save_form') {
            $id = absint($_POST['id'] ?? 0);
            $current_intention = $id ? (string) $wpdb->get_var($wpdb->prepare('SELECT default_intention FROM ' . Database::table('forms') . ' WHERE id=%d', $id)) : 'health';
            $default_intention = isset($_POST['default_intention']) ? (($_POST['default_intention'] === 'rest') ? 'rest' : 'health') : $current_intention;
            $layouts=['standard','compact','wide','two_columns','cards','minimal']; $layout=in_array($_POST['layout']??'',$layouts,true)?$_POST['layout']:'standard';
            $data = ['slug' => sanitize_title((string) ($_POST['slug'] ?? '')), 'title_ru' => sanitize_text_field((string) wp_unslash($_POST['title_ru'] ?? '')), 'title_de' => sanitize_text_field((string) wp_unslash($_POST['title_de'] ?? '')), 'title_uk' => sanitize_text_field((string) wp_unslash($_POST['title_uk'] ?? '')), 'title_pl' => sanitize_text_field((string) wp_unslash($_POST['title_pl'] ?? '')), 'intro_ru' => sanitize_textarea_field((string) wp_unslash($_POST['intro_ru'] ?? '')), 'intro_de' => sanitize_textarea_field((string) wp_unslash($_POST['intro_de'] ?? '')), 'intro_uk' => sanitize_textarea_field((string) wp_unslash($_POST['intro_uk'] ?? '')), 'intro_pl' => sanitize_textarea_field((string) wp_unslash($_POST['intro_pl'] ?? '')), 'layout' => $layout, 'default_intention' => $default_intention, 'is_active' => isset($_POST['is_active']) ? 1 : 0, 'updated_at' => current_time('mysql')];
            $data = self::preserve_translations($data);
            if ($id) $wpdb->update(Database::table('forms'), $data, ['id' => $id]); else { $data['created_at'] = current_time('mysql'); $wpdb->insert(Database::table('forms'), $data); $id = (int) $wpdb->insert_id; }
            $wpdb->delete(Database::table('form_services'), ['form_id' => $id]);
            foreach (array_map('absint', (array) ($_POST['services'] ?? [])) as $sort => $service_id) $wpdb->insert(Database::table('form_services'), ['form_id' => $id, 'service_id' => $service_id, 'sort_order' => $sort]);
            self::redirect('kloster-prayer-forms', 'saved');
        }
        if (in_array($action, ['mark_printed','mark_unprinted','delete_order','archive_order','restore_order'], true)) {
            $ids = array_filter(array_map('absint', (array) ($_POST['order_ids'] ?? [])));
            foreach ($ids as $id) {
                Order_Workflow::change($id, $action);
            }
            self::redirect('kloster-prayer', 'updated');
        }
    }

    private static function redirect(string $page, string $notice): never
    {
        wp_safe_redirect(add_query_arg(['page' => $page, 'klp_notice' => $notice], admin_url('admin.php'))); exit;
    }

    private static function header(string $title): void
    {
        echo '<div class="wrap klp-admin"><h1>' . esc_html($title) . '</h1>';
        if (!empty($_GET['klp_notice'])) echo '<div class="notice notice-success is-dismissible"><p>Сохранено.</p></div>';
    }

    public static function orders(): void
    {
        global $wpdb; self::header('Молитвенные записки');
        $view=absint($_GET['view']??0);
        if($view){
            $detail=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('orders').' WHERE id=%d',$view));
            if($detail){$detail_items=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Database::table('order_items').' WHERE order_id=%d',$view));$address=json_decode((string)$detail->billing_address,true)?:[]; ?>
            <div class="klp-card"><h2>Оплата и записка №<?= (int)$detail->id?></h2><div class="klp-stats"><div><small>Жертвователь</small><strong><?=esc_html($detail->billing_name?:$detail->donor_name?:'—')?></strong></div><div><small>E-mail</small><strong><?=esc_html($detail->billing_email?:$detail->donor_email)?></strong></div><div><small>Телефон</small><strong><?=esc_html($detail->billing_phone?:'—')?></strong></div><div><small>Сумма</small><strong><?=number_format_i18n($detail->amount_cents/100,2)?> €</strong></div></div><table class="widefat striped"><tbody><tr><th>Требы</th><td><?=esc_html(implode(', ',array_column($detail_items,'service_title')))?></td></tr><tr><th>Тип</th><td><?=$detail->intention==='rest'?'Об упокоении':'О здравии'?></td></tr><tr><th>Адрес плательщика</th><td><?=esc_html(implode(', ',array_filter([$address['line1']??'',$address['line2']??'',$address['postal_code']??'',$address['city']??'',$address['state']??'',$address['country']??''])))?:'—'?></td></tr><tr><th>Stripe / PayPal ID</th><td><code><?=esc_html($detail->payment_id?:'—')?></code></td></tr><tr><th>Оплачено</th><td><?=esc_html($detail->paid_at?:'—')?></td></tr><tr><th>Имена</th><td><?=nl2br(esc_html($detail->names))?></td></tr><tr><th>Примечание</th><td><?=nl2br(esc_html($detail->note?:'—'))?></td></tr></tbody></table><p><a class="button" href="<?=esc_url(admin_url('admin.php?page=kloster-prayer'))?>">← К списку записок</a></p></div><?php }
        }
        if ($view) {
            if(isset($detail)&&$detail) Order_Workflow::detail($detail);
            if(isset($_GET['customer_sync']))echo '<div class="notice notice-info"><p>'.($_GET['customer_sync']==='ok'?'Данные обновлены из Stripe.':'Stripe не вернул данные плательщика.').'</p></div>';
            if(isset($detail)&&$detail&&$detail->payment_provider==='stripe'){
                echo '<form method="post">';wp_nonce_field('klp_admin_action', '_klp_nonce');
                echo '<input type="hidden" name="klp_action" value="refresh_customer"><input type="hidden" name="order_id" value="'.(int)$view.'"><button class="button">Получить данные плательщика из Stripe</button></form>';
            }
            if(isset($detail)&&$detail&&$detail->payment_status==='paid'){
                echo '<form method="post" class="klp-detail-actions">';wp_nonce_field('klp_admin_action', '_klp_nonce');
                echo '<input type="hidden" name="klp_action" value="resend_confirmation"><input type="hidden" name="order_id" value="'.(int)$view.'"><button class="button">Отправить подтверждение повторно</button> <a class="button" href="'.esc_url(Pdf::url(['order_ids'=>[$view]])).'">PDF записки</a></form>';
            }
            echo '</div>'; return;
        }
        require_once DIR.'/includes/class-orders-table.php';
        $table=new Orders_Table();$table->prepare_items();
        echo '<p>Пустые бланки PDF: ';
        foreach (['ru'=>'RU', 'de'=>'DE'] as $language=>$label) {
            foreach (['health'=>'О здравии', 'rest'=>'Об упокоении'] as $intention=>$title) {
                echo '<a class="button" href="'.esc_url(Pdf::url(['blank'=>'1','language'=>$language,'intention'=>$intention])).'">'.esc_html($label.' · '.$title).'</a> ';
            }
        }
        echo '</p>';
        echo '<form method="get"><input type="hidden" name="page" value="kloster-prayer">';
        $table->search_box('Поиск записок','klp-orders');echo '</form>';
        echo '<form method="post">';wp_nonce_field('klp_admin_action', '_klp_nonce');
        echo '<input type="hidden" name="page" value="kloster-prayer"><input type="hidden" name="klp_action" value="orders_bulk">';
        foreach(['provider','month','service_id','s'] as $key) if(isset($_GET[$key]))echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr(sanitize_text_field($_GET[$key])).'">';
        $table->display();echo '</form></div>';
    }

    public static function services(): void { self::services_native(); }
    public static function forms(): void { self::forms_native(); }

    private static function services_native(): void
    {
        global $wpdb; $id=absint($_GET['edit']??0); $editing=$id||isset($_GET['new']);
        if (!$editing) { self::catalog('services'); return; }
        $r=$id?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('services').' WHERE id=%d',$id)):null; self::header($r?'Редактировать требу':'Добавить требу'); ?>
        <form method="post" class="klp-native-editor"><?php wp_nonce_field('klp_admin_action', '_klp_nonce');?><input type="hidden" name="klp_action" value="save_service"><input type="hidden" name="id" value="<?= (int)($r->id??0) ?>"><table class="form-table" role="presentation"><tbody>
        <?php foreach(self::editor_languages() as $code=>$label):?><tr><th colspan="2"><h2><?=esc_html($label)?></h2></th></tr><tr><th><label for="title_<?=$code?>">Название</label></th><td><input class="regular-text" id="title_<?=$code?>" name="title_<?=$code?>" value="<?=esc_attr($r->{'title_'.$code}??'')?>"></td></tr><tr><th><label for="description_<?=$code?>">Описание</label></th><td><textarea class="large-text" rows="2" id="description_<?=$code?>" name="description_<?=$code?>"><?=esc_textarea($r->{'description_'.$code}??'')?></textarea></td></tr><?php endforeach;?>
        <tr><th><label for="slug">Код</label></th><td><input class="regular-text code" id="slug" name="slug" required value="<?=esc_attr($r->slug??'')?>"></td></tr><tr><th>Фоновое изображение</th><td><input type="hidden" name="image_id" id="klp-image-id" value="<?= (int)($r->image_id??0) ?>"><?php Service_Images::preview($r); ?><button type="button" class="button" id="klp-image-select">Выбрать из медиатеки</button> <button type="button" class="button-link-delete" id="klp-image-clear">Сбросить к стандартному</button><p class="description">Без выбора используется встроенный тематический фон.</p></td></tr>
        <tr><th>Назначение</th><td><select name="intention_mode"><?php foreach(['both'=>'Оба','health'=>'Только о здравии','rest'=>'Только об упокоении','none'=>'Без типа'] as $v=>$l):?><option value="<?=$v?>" <?=selected($r->intention_mode??'both',$v,false)?>><?=$l?></option><?php endforeach;?></select></td></tr><tr><th>Расчёт</th><td><select name="pricing_mode"><?php foreach(['flat'=>'Фиксированно','per_person'=>'За имя','per_sheet'=>'За записку'] as $v=>$l):?><option value="<?=$v?>" <?=selected($r->pricing_mode??'flat',$v,false)?>><?=$l?></option><?php endforeach;?></select></td></tr><tr><th>Пожертвование, EUR</th><td><input name="price" type="number" min="0" step="0.01" value="<?=esc_attr(isset($r->price_cents)?number_format($r->price_cents/100,2,'.',''):'')?>"></td></tr><tr><th>Имён в записке</th><td><input name="names_per_sheet" type="number" min="1" value="<?= (int)($r->names_per_sheet??10) ?>"></td></tr><tr><th>Порядок</th><td><input name="sort_order" type="number" value="<?= (int)($r->sort_order??10) ?>"></td></tr><tr><th>Статус</th><td><label><input type="checkbox" name="is_active" value="1" <?=checked($r->is_active??1,1,false)?>> Активна</label></td></tr></tbody></table><?php submit_button($r?'Сохранить изменения':'Добавить требу');?><a class="button" href="<?=esc_url(admin_url('admin.php?page=kloster-prayer-services'))?>">Отмена</a></form></div><?php
    }

    private static function forms_native(): void
    {
        global $wpdb; $id=absint($_GET['edit']??0); $editing=$id||isset($_GET['new']);
        if (!$editing) { self::catalog('forms'); return; }
        $r=$id?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Database::table('forms').' WHERE id=%d',$id)):null;$services=$wpdb->get_results('SELECT * FROM '.Database::table('services').' ORDER BY sort_order');$selected=$r?array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT service_id FROM '.Database::table('form_services').' WHERE form_id=%d ORDER BY sort_order',$id))):[];self::header($r?'Редактировать форму':'Добавить форму');?><form method="post" class="klp-native-editor"><?php wp_nonce_field('klp_admin_action', '_klp_nonce');?><input type="hidden" name="klp_action" value="save_form"><input type="hidden" name="id" value="<?= (int)($r->id??0) ?>"><table class="form-table" role="presentation"><tbody><?php foreach(self::editor_languages() as $code=>$label):?><tr><th colspan="2"><h2><?=esc_html($label)?></h2></th></tr><tr><th>Название</th><td><input class="regular-text" name="title_<?=$code?>" value="<?=esc_attr($r->{'title_'.$code}??'')?>"></td></tr><tr><th>Введение</th><td><textarea class="large-text" rows="2" name="intro_<?=$code?>"><?=esc_textarea($r->{'intro_'.$code}??'')?></textarea></td></tr><?php endforeach;?><tr><th>Код</th><td><input class="regular-text code" required name="slug" value="<?=esc_attr($r->slug??'')?>"></td></tr><tr><th>Требы в форме</th><td><fieldset class="klp-service-checklist"><?php foreach($services as $s):?><label><input type="checkbox" name="services[]" value="<?=$s->id?>" <?=checked(in_array((int)$s->id,$selected,true),true,false)?>> <?=esc_html($s->title_ru)?> <span>— <?=esc_html($s->title_de)?></span></label><?php endforeach;?></fieldset></td></tr><tr><th>Шаблон</th><td><select name="layout"><?php foreach(['standard'=>'Классический','compact'=>'Компактный','wide'=>'Широкий: 3 карточки','two_columns'=>'Две колонки','cards'=>'Карточки с изображениями','minimal'=>'Минималистичный'] as $v=>$l):?><option value="<?=$v?>" <?=selected($r->layout??'standard',$v,false)?>><?=$l?></option><?php endforeach;?></select><p class="description">Внешняя ширина всегда 100% блока шорткода; шаблон меняет внутреннюю компоновку.</p></td></tr><tr><th>Тип по умолчанию</th><td><select name="default_intention"><option value="health" <?=selected($r->default_intention??'health','health',false)?>>О здравии</option><option value="rest" <?=selected($r->default_intention??'health','rest',false)?>>Об упокоении</option></select></td></tr><tr><th>Статус</th><td><label><input type="checkbox" name="is_active" value="1" <?=checked($r->is_active??1,1,false)?>> Активна</label></td></tr></tbody></table><?php submit_button($r?'Сохранить изменения':'Добавить форму');?><a class="button" href="<?=esc_url(admin_url('admin.php?page=kloster-prayer-forms'))?>">Отмена</a></form></div><?php
    }

    public static function reports(): void { Admin_Screens::reports(); }
    public static function settings(): void { Admin_Screens::settings(); }

    public static function export_csv(): never
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('klp_csv');
        global $wpdb;
        $rows=$wpdb->get_results('SELECT * FROM '.Database::table('orders').' ORDER BY created_at DESC',ARRAY_A);
        nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="kloster-orders-'.wp_date('Ymd-His').'.csv"');
        $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,['ID','Дата','Статус','Оплата','Сумма EUR','Тип','Имена','Примечание','Имя жертвователя','E-mail','Распечатано','Имя плательщика','E-mail плательщика','Телефон','Адрес плательщика'],';','"','');
        foreach($rows as $row)fputcsv($out,[$row['id'],$row['created_at'],$row['payment_status'],$row['payment_provider'],number_format(((int)$row['amount_cents'])/100,2,',',''),$row['intention'],$row['names'],$row['note'],$row['donor_name'],$row['donor_email'],$row['printed_at'],$row['billing_name']??'',$row['billing_email']??'',$row['billing_phone']??'',implode(', ',(array)(json_decode((string)($row['billing_address']??''),true)?:[]))],';','"','');
        fclose($out); exit;
    }

    public static function diagnostics(): void
    {
        global $wpdb; self::header('Диагностика');
        $enabled=(array)get_option('klp_payment_methods',['stripe']);
        $checks=[
            'PHP 8.4 или новее'=>version_compare(PHP_VERSION,'8.4','>='),
            'WordPress 7.1 или новее'=>version_compare((string)get_bloginfo('version'),'7.1','>='),
            'Stripe Secret Key'=>trim((string)get_option('klp_stripe_secret'))!=='',
            'Stripe Webhook Secret'=>trim((string)get_option('klp_stripe_webhook_secret'))!=='',
            'PayPal Client ID'=>trim((string)get_option('klp_paypal_client_id'))!=='',
            'PayPal Secret'=>trim((string)get_option('klp_paypal_secret'))!=='',
            'PayPal Webhook ID'=>trim((string)get_option('klp_paypal_webhook_id'))!=='',
            'Планировщик повторных писем'=>wp_next_scheduled('klp_retry_confirmations')!==false,
            'Таблицы базы данных'=>$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',Database::table('orders')))===Database::table('orders'),
            'Dompdf'=>is_file(DIR.'/includes/lib/dompdf/autoload.inc.php'),
        ];
        $events=$wpdb->get_results('SELECT provider,event_type,order_id,processed,error_message,created_at FROM '.Database::table('payment_events').' ORDER BY id DESC LIMIT 20'); ?>
        <div class="klp-card"><table class="widefat striped"><tbody><?php foreach($checks as $name=>$ok): $inactive=(str_starts_with($name,'Stripe')&&!in_array('stripe',$enabled,true))||(str_starts_with($name,'PayPal')&&!in_array('paypal',$enabled,true)); ?><tr><th><?=esc_html($name)?></th><td><strong style="color:<?=$inactive?'#646970':($ok?'#15803d':'#b91c1c')?>"><?=$inactive?'Отключён — настройка не требуется':($ok?'Готово':'Проверьте настройку')?></strong></td></tr><?php endforeach;?></tbody></table></div>
        <div class="klp-card"><h2>Последние события оплаты</h2><table class="widefat striped"><thead><tr><th>Дата</th><th>Провайдер</th><th>Событие</th><th>Записка</th><th>Результат</th></tr></thead><tbody><?php foreach($events as $event):?><tr><td><?=esc_html($event->created_at)?></td><td><?=esc_html($event->provider)?></td><td><?=esc_html($event->event_type)?></td><td><?= (int)$event->order_id?></td><td><?=$event->processed?'Обработано':esc_html($event->error_message?:'Ошибка')?></td></tr><?php endforeach;if(!$events):?><tr><td colspan="5">Событий пока нет.</td></tr><?php endif;?></tbody></table></div></div><?php
    }


    public static function external_meta(array $links, string $file): array
    {
        if ($file !== plugin_basename(FILE)) return $links;
        foreach ($links as &$html) {
            $tag = new \WP_HTML_Tag_Processor($html);
            while ($tag->next_tag('A')) {
                $url=(string)$tag->get_attribute('href');
                if (str_starts_with($url,'https://atapin.de') || str_starts_with($url,'http://atapin.de')) {
                    $tag->set_attribute('target','_blank'); $tag->set_attribute('rel','noopener noreferrer');
                }
            }
            $html=$tag->get_updated_html();
        }
        return $links;
    }

    public static function editor_languages(): array
    {
        $supported=['ru'=>'Русский','de'=>'Deutsch','uk'=>'Українська','pl'=>'Polski'];
        $locales=[];
        if(function_exists('pll_languages_list')) $locales=pll_languages_list(['fields'=>'locale','hide_empty'=>0]);
        if(!$locales) {
            $wpml=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
            if(is_array($wpml)) foreach($wpml as $code=>$language) $locales[]=$language['default_locale']??$code;
        }
        if(!$locales) $locales=[get_locale()];
        $result=[];
        foreach($locales as $locale){$code=substr(strtolower((string)$locale),0,2);if(isset($supported[$code]))$result[$code]=$supported[$code];}
        return $result ?: ['de'=>'Deutsch'];
    }

    private static function preserve_translations(array $data): array
    {
        if (empty($_POST['id'])) return $data;
        foreach(['ru','de','uk','pl'] as $code)foreach(['title','intro','description'] as $field){
            $key=$field.'_'.$code;
            if(!array_key_exists($key,$_POST)) unset($data[$key]);
        }
        return $data;
    }

    private static function catalog(string $kind): void
    {
        require_once DIR.'/includes/class-catalog-table.php';
        self::header($kind==='forms'?'Формы':'Требы');
        echo '<a class="page-title-action" href="'.esc_url(admin_url('admin.php?page=kloster-prayer-'.$kind.'&new=1')).'">Добавить новую</a><hr class="wp-header-end">';
        $table=new Catalog_Table($kind); $table->prepare_items(); $table->views();
        echo '<form method="get"><input type="hidden" name="page" value="kloster-prayer-'.esc_attr($kind).'">';
        $table->search_box('Поиск','klp-catalog');echo '</form>';
        echo '<form method="post">';wp_nonce_field('klp_admin_action', '_klp_nonce');
        echo '<input type="hidden" name="klp_action" value="catalog_bulk"><input type="hidden" name="catalog" value="'.esc_attr($kind).'">';
        $table->display();echo '</form></div>';
    }

    private static function field(string $name,string $label,mixed $value,string $type='text'): string { $display=$type==='password'?'':(string)$value; $placeholder=$type==='password'&&$value!==''?' placeholder="Сохранено — оставьте пустым, чтобы не менять"':''; return '<label>'.esc_html($label).'<input class="regular-text" type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($display).'"'.$placeholder.'></label>'; }
    private static function textarea(string $name,string $label,mixed $value): string { return '<label>'.esc_html($label).'<textarea name="'.esc_attr($name).'" rows="3">'.esc_textarea((string)$value).'</textarea></label>'; }
}
