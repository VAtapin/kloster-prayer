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
            if (str_contains($hook, 'kloster-prayer')) {
                wp_enqueue_style('klp-admin', plugins_url('assets/css/admin.css', FILE), [], VERSION);
                wp_enqueue_style('klp-admin-native', plugins_url('assets/css/admin-native.css', FILE), ['klp-admin'], VERSION);
                wp_enqueue_media();
                wp_enqueue_script('klp-admin-images', plugins_url('assets/js/admin-images.js', FILE), ['jquery','media-views'], VERSION, true);
                wp_localize_script('klp-admin-images', 'klosterPrayerAdmin', [
                    'empty' => __('No image selected.', 'kloster-prayer'),
                    'open' => __('Open background image', 'kloster-prayer'),
                    'title' => __('Service background image', 'kloster-prayer'),
                    'view' => __('View image ↗', 'kloster-prayer'),
                    'use' => __('Use image', 'kloster-prayer'),
                ]);
            }
        });
    }

    public static function menu(): void
    {
        add_menu_page('Kloster', (__('Kloster – Prayer notes', 'kloster-prayer')), 'manage_options', 'kloster-prayer', [self::class, 'orders'], 'dashicons-heart', 26);
        add_submenu_page('kloster-prayer', (__('Prayer notes', 'kloster-prayer')), (__('Prayer notes', 'kloster-prayer')), 'manage_options', 'kloster-prayer', [self::class, 'orders']);
        add_submenu_page('kloster-prayer', (__('Services', 'kloster-prayer')), (__('Services', 'kloster-prayer')), 'manage_options', 'kloster-prayer-services', [self::class, 'services']);
        add_submenu_page('kloster-prayer', (__('Forms', 'kloster-prayer')), (__('Forms', 'kloster-prayer')), 'manage_options', 'kloster-prayer-forms', [self::class, 'forms']);
        add_submenu_page('kloster-prayer', (__('Reports', 'kloster-prayer')), (__('Reports', 'kloster-prayer')), 'manage_options', 'kloster-prayer-reports', [self::class, 'reports']);
        add_submenu_page('kloster-prayer', (__('Settings', 'kloster-prayer')), (__('Settings', 'kloster-prayer')), 'manage_options', 'kloster-prayer-settings', [self::class, 'settings']);
        add_submenu_page('kloster-prayer', (__('Diagnostics', 'kloster-prayer')), (__('Diagnostics', 'kloster-prayer')), 'manage_options', 'kloster-prayer-diagnostics', [self::class, 'diagnostics']);
    }

    public static function handle_actions(): void
    {
        if (!current_user_can('manage_options') || empty(Input::post('klp_action'))) return;
        check_admin_referer('klp_admin_action', '_klp_nonce');
        global $wpdb;
        $action = sanitize_key((string) Input::post('klp_action'));
        if ($action === 'refresh_customer') {
            $id=absint(Input::post('order_id')??0);$ok=Payments::refresh_customer($id);
            wp_safe_redirect(add_query_arg(['page'=>'kloster-prayer','view'=>$id,'customer_sync'=>$ok?'ok':'unavailable'],admin_url('admin.php')));exit;
        }
        if ($action === 'catalog_bulk') {
            $kind=in_array(Input::post('catalog')??'', ['forms','services'],true)?Input::post('catalog'):'forms';
            $operation=(Input::post('action')??'-1')!=='-1'?Input::post('action'):(Input::post('action2')??'');
            if(in_array($operation,['enable','disable'],true))foreach(array_filter(array_map('absint',(array)(Input::post_list('ids')??[]))) as $item_id) $wpdb->update(Database::table($kind),['is_active'=>$operation==='enable'?1:0,'updated_at'=>current_time('mysql')],['id'=>$item_id]);
            self::redirect('kloster-prayer-'.$kind,'updated');
        }
        if ($action === 'orders_bulk') $action=(Input::post('action')??'-1')!=='-1'?sanitize_key(Input::post('action')):sanitize_key(Input::post('action2')??'');
        if ($action === 'generate_pdf') {
            $ids = array_filter(array_map('absint', (array) (Input::post_list('order_ids') ?? [])));
            if (!$ids) {
                require_once DIR.'/includes/class-orders-table.php';
                [$where,$args]=Orders_Table::where(Input::post_all());
                $query='SELECT id FROM %i WHERE '.$where;
                // WHERE is built only from fixed clauses and placeholders in Orders_Table::where().
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- All identifiers and filter values are bound below.
                $ids=array_map('intval',$wpdb->get_col($wpdb->prepare($query,...array_merge([Database::table('orders')],$args))));
                if (!$ids) wp_die((esc_html__('No requests match the selected filter.', 'kloster-prayer')));
            }
            $filters = ['order_ids' => $ids];
            foreach (['archive','payment_status','intention','printed'] as $key) if (!empty($_POST[$key])) $filters[$key] = sanitize_key((string) $_POST[$key]);
            wp_safe_redirect(Pdf::url($filters)); exit;
        }
        if ($action === 'resend_confirmation') {
            $ok = Payments::resend_confirmation(absint(Input::post('order_id') ?? 0));
            self::redirect('kloster-prayer', $ok ? 'email_sent' : 'email_failed');
        }
        if ($action === 'save_settings') {
            if (isset($_POST['notification_email'])) {
                try { $recipient = Notifications::validate_recipient((string) Input::post('notification_email')); }
                catch (\InvalidArgumentException $e) { wp_die(esc_html__('Enter one valid notification email or leave the field blank.', 'kloster-prayer')); }
                update_option('klp_notification_email', $recipient);
            }
            if (isset($_POST['api_origin'])) {
                try { $origin = Payments::normalize_api_origin((string)Input::post('api_origin')); }
                catch (\InvalidArgumentException $e) { wp_die(esc_html(__('Enter an HTTPS domain without a path, query or credentials, for example https://example.org. Leave blank for automatic selection.', 'kloster-prayer'))); }
                update_option('klp_api_origin', $origin);
            }
            if (!empty(Input::post('stripe_secret'))) update_option('klp_stripe_secret', sanitize_text_field((string) Input::post('stripe_secret')));
            if (!empty(Input::post('stripe_webhook_secret'))) update_option('klp_stripe_webhook_secret', sanitize_text_field((string) Input::post('stripe_webhook_secret')));
            update_option('klp_paypal_mode', in_array(Input::post('paypal_mode') ?? '', ['live', 'sandbox'], true) ? Input::post('paypal_mode') : 'live');
            update_option('klp_paypal_client_id', sanitize_text_field((string) Input::post('paypal_client_id') ?? ''));
            if (!empty(Input::post('paypal_secret'))) update_option('klp_paypal_secret', sanitize_text_field((string) Input::post('paypal_secret')));
            update_option('klp_paypal_webhook_id', sanitize_text_field((string) Input::post('paypal_webhook_id') ?? ''));
            $methods = array_values(array_intersect(['stripe', 'paypal'], (array) (Input::post_list('methods') ?? [])));
            update_option('klp_payment_methods', $methods ?: ['stripe']);
            self::redirect('kloster-prayer-settings', 'saved');
        }
        if ($action === 'save_service') {
            $id = absint(Input::post('id') ?? 0); $price = (int) round(((float) str_replace(',', '.', (string) (Input::post('price') ?? '0'))) * 100);
            $data = ['slug' => sanitize_title((string) (Input::post('slug') ?? '')), 'title_ru' => sanitize_text_field((string) Input::post('title_ru') ?? ''), 'title_de' => sanitize_text_field((string) Input::post('title_de') ?? ''), 'title_uk' => sanitize_text_field((string) Input::post('title_uk') ?? ''), 'title_pl' => sanitize_text_field((string) Input::post('title_pl') ?? ''), 'description_ru' => sanitize_textarea_field((string) Input::textarea('description_ru') ?? ''), 'description_de' => sanitize_textarea_field((string) Input::textarea('description_de') ?? ''), 'description_uk' => sanitize_textarea_field((string) Input::textarea('description_uk') ?? ''), 'description_pl' => sanitize_textarea_field((string) Input::textarea('description_pl') ?? ''), 'image_id' => absint(Input::post('image_id') ?? 0), 'intention_mode' => in_array(Input::post('intention_mode') ?? '', ['both','health','rest','none'], true) ? Input::post('intention_mode') : 'both', 'pricing_mode' => in_array(Input::post('pricing_mode') ?? '', ['flat','per_person','per_sheet'], true) ? Input::post('pricing_mode') : 'flat', 'price_cents' => max(0, $price), 'names_per_sheet' => max(1, absint(Input::post('names_per_sheet') ?? 10)), 'is_active' => isset($_POST['is_active']) ? 1 : 0, 'sort_order' => (int) (Input::post('sort_order') ?? 0), 'updated_at' => current_time('mysql')];
            $data = self::preserve_translations($data);
            if ($id) $wpdb->update(Database::table('services'), $data, ['id' => $id]); else { $data['created_at'] = current_time('mysql'); $wpdb->insert(Database::table('services'), $data); }
            self::redirect('kloster-prayer-services', 'saved');
        }
        if ($action === 'save_form') {
            $id = absint(Input::post('id') ?? 0);
            $current_intention = $id ? (string) $wpdb->get_var($wpdb->prepare('SELECT default_intention FROM %i WHERE id=%d', Database::table('forms'), $id)) : 'health';
            $default_intention = isset($_POST['default_intention']) ? ((Input::post('default_intention') === 'rest') ? 'rest' : 'health') : $current_intention;
            $layouts=['standard','compact','wide','two_columns','cards','minimal']; $layout=in_array(Input::post('layout')??'',$layouts,true)?Input::post('layout'):'standard';
            $data = ['slug' => sanitize_title((string) (Input::post('slug') ?? '')), 'title_ru' => sanitize_text_field((string) Input::post('title_ru') ?? ''), 'title_de' => sanitize_text_field((string) Input::post('title_de') ?? ''), 'title_uk' => sanitize_text_field((string) Input::post('title_uk') ?? ''), 'title_pl' => sanitize_text_field((string) Input::post('title_pl') ?? ''), 'intro_ru' => sanitize_textarea_field((string) Input::textarea('intro_ru') ?? ''), 'intro_de' => sanitize_textarea_field((string) Input::textarea('intro_de') ?? ''), 'intro_uk' => sanitize_textarea_field((string) Input::textarea('intro_uk') ?? ''), 'intro_pl' => sanitize_textarea_field((string) Input::textarea('intro_pl') ?? ''), 'layout' => $layout, 'default_intention' => $default_intention, 'is_active' => isset($_POST['is_active']) ? 1 : 0, 'updated_at' => current_time('mysql')];
            $data = self::preserve_translations($data);
            if ($id) $wpdb->update(Database::table('forms'), $data, ['id' => $id]); else { $data['created_at'] = current_time('mysql'); $wpdb->insert(Database::table('forms'), $data); $id = (int) $wpdb->insert_id; }
            $wpdb->delete(Database::table('form_services'), ['form_id' => $id]);
            foreach (array_map('absint', (array) (Input::post_list('services') ?? [])) as $sort => $service_id) $wpdb->insert(Database::table('form_services'), ['form_id' => $id, 'service_id' => $service_id, 'sort_order' => $sort]);
            self::redirect('kloster-prayer-forms', 'saved');
        }
        if (in_array($action, ['mark_printed','mark_unprinted','delete_order','archive_order','restore_order'], true)) {
            $ids = array_filter(array_map('absint', (array) (Input::post_list('order_ids') ?? [])));
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
        if (!empty(Input::get('klp_notice'))) echo ('<div class="notice notice-success is-dismissible"><p>'.esc_html__('Saved.', 'kloster-prayer').'</p></div>');
    }

    public static function orders(): void
    {
        require_once DIR.'/includes/class-orders-table.php';
        global $wpdb; self::header((__('Prayer requests', 'kloster-prayer')));
        $view=absint(Input::get('view')??0);
        if($view){
            $detail=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'),$view));
            if($detail){$detail_items=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d', Database::table('order_items'),$view));$address=json_decode((string)$detail->billing_address,true)?:[]; ?>
            <div class="klp-card"><h2><?php echo esc_html__('Payment and request #', 'kloster-prayer'); ?><?php echo  (int)$detail->id?></h2><div class="klp-stats"><div><small><?php echo esc_html__('Donor', 'kloster-prayer'); ?></small><strong><?php echo esc_html($detail->billing_name?:$detail->donor_name?:'—')?></strong></div><div><small>E-mail</small><strong><?php echo esc_html($detail->billing_email?:$detail->donor_email)?></strong></div><div><small><?php echo esc_html__('Phone', 'kloster-prayer'); ?></small><strong><?php echo esc_html($detail->billing_phone?:'—')?></strong></div><div><small><?php echo esc_html__('Amount', 'kloster-prayer'); ?></small><strong><?php echo esc_html(number_format_i18n($detail->amount_cents/100,2))?> €</strong></div></div><table class="widefat striped"><tbody><tr><th><?php echo esc_html__('Services', 'kloster-prayer'); ?></th><td><?php echo esc_html(implode(', ',array_column($detail_items,'service_title')))?></td></tr><tr><th><?php echo esc_html__('Type', 'kloster-prayer'); ?></th><td><?php echo $detail->intention==='none'?esc_html(I18n::donation_text('title')):($detail->intention==='rest'?(esc_html__('For the departed', 'kloster-prayer')):(esc_html__('For the living', 'kloster-prayer')))?></td></tr><tr><th><?php echo esc_html__('Billing address', 'kloster-prayer'); ?></th><td><?php echo esc_html(implode(', ',array_filter([$address['line1']??'',$address['line2']??'',$address['postal_code']??'',$address['city']??'',$address['state']??'',$address['country']??''])))?:'—'?></td></tr><tr><th>Stripe / <?php echo esc_html__('PayPal', 'kloster-prayer'); ?> ID</th><td><code><?php echo esc_html($detail->payment_id?:'—')?></code></td></tr><tr><th><?php echo esc_html__('Paid', 'kloster-prayer'); ?></th><td><?php echo esc_html($detail->paid_at?:'—')?></td></tr><tr><th><?php echo esc_html__('Names', 'kloster-prayer'); ?></th><td><?php echo nl2br(esc_html($detail->names))?></td></tr><tr><th><?php echo esc_html__('Request comment','kloster-prayer')?></th><td><?php echo wp_kses_post(Orders_Table::comment_html((string)($detail->note??'')))?:esc_html__('No comment provided','kloster-prayer')?></td></tr></tbody></table><p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=kloster-prayer'))?>"><?php echo esc_html__('← Back to requests', 'kloster-prayer'); ?></a></p></div><?php }
        }
        if ($view) {
            if(isset($detail)&&$detail) Order_Workflow::detail($detail);
            if((Input::get('customer_sync') !== null))echo '<div class="notice notice-info"><p>'.(Input::get('customer_sync')==='ok'?(esc_html__('Customer details refreshed from Stripe.', 'kloster-prayer')):(esc_html__('Stripe did not return payer details.', 'kloster-prayer'))).'</p></div>';
            if(isset($detail)&&$detail&&$detail->payment_provider==='stripe'){
                echo '<form method="post">';wp_nonce_field('klp_admin_action', '_klp_nonce');
                echo '<input type="hidden" name="klp_action" value="refresh_customer"><input type="hidden" name="order_id" value="'.(int)$view.('"><button class="button">'.esc_html__('Retrieve payer details from Stripe', 'kloster-prayer').'</button></form>');
            }
            if(isset($detail)&&$detail&&$detail->payment_status==='paid'){
                echo '<form method="post" class="klp-detail-actions">';wp_nonce_field('klp_admin_action', '_klp_nonce');
                echo '<input type="hidden" name="klp_action" value="resend_confirmation"><input type="hidden" name="order_id" value="'.(int)$view.('"><button class="button">'.esc_html__('Resend confirmation', 'kloster-prayer').'</button></form>');
                if (($detail->intention ?? '') !== 'none') echo '<a class="button" href="'.esc_url(Pdf::url(['order_ids'=>[$view]])).'">'.esc_html__('Request PDF', 'kloster-prayer').'</a>';
            }
            echo '</div>'; return;
        }
        require_once DIR.'/includes/class-orders-table.php';
        $table=new Orders_Table();$table->prepare_items();
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=kloster-prayer')).'">'.esc_html__('Current requests','kloster-prayer').'</a> | <a href="'.esc_url(admin_url('admin.php?page=kloster-prayer&archive=yes')).'">'.esc_html__('Archive','kloster-prayer').'</a></p>';
        echo ('<p>'.esc_html__('Blank PDF forms:', 'kloster-prayer').' ');
        foreach (['ru'=>'RU', 'de'=>'DE'] as $language=>$label) {
            foreach (['health'=>(esc_html__('For the living', 'kloster-prayer')), 'rest'=>(esc_html__('For the departed', 'kloster-prayer'))] as $intention=>$title) {
                echo '<a class="button" href="'.esc_url(Pdf::url(['blank'=>'1','language'=>$language,'intention'=>$intention])).'">'.esc_html($label.' · '.$title).'</a> ';
            }
        }
        echo '</p>';
        echo '<form method="get"><input type="hidden" name="page" value="kloster-prayer">';
        if ((Input::get('archive')??'') === 'yes') echo '<input type="hidden" name="archive" value="yes">';
        $table->search_box((__('Search requests', 'kloster-prayer')),'klp-orders');echo '</form>';
        echo '<form method="post">';wp_nonce_field('klp_admin_action', '_klp_nonce');
        echo '<input type="hidden" name="page" value="kloster-prayer"><input type="hidden" name="klp_action" value="orders_bulk">';
        foreach(['provider','month','service_id','s'] as $key) if((Input::get($key)!==null))echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((Input::get($key)??'')).'">';
        $table->display();echo '</form></div>';
    }

    public static function services(): void { self::services_native(); }
    public static function forms(): void { self::forms_native(); }

    private static function services_native(): void
    {
        global $wpdb; $id=absint(Input::get('edit')??0); $editing=$id||(Input::get('new') !== null);
        if (!$editing) { self::catalog('services'); return; }
        $r=$id?$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('services'),$id)):null; self::header($r?(__('Edit service', 'kloster-prayer')):(__('Add service', 'kloster-prayer'))); ?>
        <form method="post" class="klp-native-editor"><?php wp_nonce_field('klp_admin_action', '_klp_nonce');?><input type="hidden" name="klp_action" value="save_service"><input type="hidden" name="id" value="<?php echo  (int)($r->id??0) ?>"><table class="form-table" role="presentation"><tbody>
        <?php foreach(self::editor_languages() as $code=>$label):?><tr><th colspan="2"><h2><?php echo esc_html($label)?></h2></th></tr><tr><th><label for="title_<?php echo esc_attr($code)?>"><?php echo esc_html__('Title', 'kloster-prayer'); ?></label></th><td><input class="regular-text" id="title_<?php echo esc_attr($code)?>" name="title_<?php echo esc_attr($code)?>" value="<?php echo esc_attr($r->{'title_'.$code}??'')?>"></td></tr><tr><th><label for="description_<?php echo esc_attr($code)?>"><?php echo esc_html__('Description', 'kloster-prayer'); ?></label></th><td><textarea class="large-text" rows="2" id="description_<?php echo esc_attr($code)?>" name="description_<?php echo esc_attr($code)?>"><?php echo esc_textarea($r->{'description_'.$code}??'')?></textarea></td></tr><?php endforeach;?>
        <tr><th><label for="slug"><?php echo esc_html__('Slug', 'kloster-prayer'); ?></label></th><td><input class="regular-text code" id="slug" name="slug" required value="<?php echo esc_attr($r->slug??'')?>"></td></tr><tr><th><?php echo esc_html__('Background image', 'kloster-prayer'); ?></th><td><input type="hidden" name="image_id" id="klp-image-id" value="<?php echo  (int)($r->image_id??0) ?>"><?php Service_Images::preview($r); ?><button type="button" class="button" id="klp-image-select"><?php echo esc_html__('Choose from Media Library', 'kloster-prayer'); ?></button> <button type="button" class="button-link-delete" id="klp-image-clear"><?php echo esc_html__('Reset to default', 'kloster-prayer'); ?></button><p class="description"><?php echo esc_html__('The bundled thematic background is used when no image is selected.', 'kloster-prayer'); ?></p></td></tr>
        <tr><th><?php echo esc_html__('Intention', 'kloster-prayer'); ?></th><td><select name="intention_mode"><?php foreach(['both'=>(__('Both', 'kloster-prayer')),'health'=>(__('Living only', 'kloster-prayer')),'rest'=>(__('Departed only', 'kloster-prayer')),'none'=>(__('No type', 'kloster-prayer'))] as $v=>$l):?><option value="<?php echo esc_attr($v)?>" <?php echo selected($r->intention_mode??'both',$v,false)?>><?php echo esc_html($l)?></option><?php endforeach;?></select></td></tr><tr><th><?php echo esc_html__('Pricing', 'kloster-prayer'); ?></th><td><select name="pricing_mode"><?php foreach(['flat'=>(__('Fixed amount', 'kloster-prayer')),'per_person'=>(__('Per name', 'kloster-prayer')),'per_sheet'=>(__('Per request', 'kloster-prayer'))] as $v=>$l):?><option value="<?php echo esc_attr($v)?>" <?php echo selected($r->pricing_mode??'flat',$v,false)?>><?php echo esc_html($l)?></option><?php endforeach;?></select></td></tr><tr><th><?php echo esc_html__('Donation, EUR', 'kloster-prayer'); ?></th><td><input name="price" type="number" min="0" step="0.01" value="<?php echo esc_attr(isset($r->price_cents)?number_format($r->price_cents/100,2,'.',''):'')?>"></td></tr><tr><th><?php echo esc_html__('Names per slip', 'kloster-prayer'); ?></th><td><input name="names_per_sheet" type="number" min="1" value="<?php echo  (int)($r->names_per_sheet??10) ?>"></td></tr><tr><th><?php echo esc_html__('Sort order', 'kloster-prayer'); ?></th><td><input name="sort_order" type="number" value="<?php echo  (int)($r->sort_order??10) ?>"></td></tr><tr><th><?php echo esc_html__('Status', 'kloster-prayer'); ?></th><td><label><input type="checkbox" name="is_active" value="1" <?php echo checked($r->is_active??1,1,false)?>> <?php echo esc_html__('Active', 'kloster-prayer'); ?></label></td></tr></tbody></table><?php submit_button($r?(__('Save changes', 'kloster-prayer')):(__('Add service', 'kloster-prayer')));?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=kloster-prayer-services'))?>"><?php echo esc_html__('Cancel', 'kloster-prayer'); ?></a></form></div><?php
    }

    private static function forms_native(): void
    {
        global $wpdb; $id=absint(Input::get('edit')??0); $editing=$id||(Input::get('new') !== null);
        if (!$editing) { self::catalog('forms'); return; }
        $r=$id?$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('forms'),$id)):null;$services=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY sort_order',Database::table('services')));$selected=$r?array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT service_id FROM %i WHERE form_id=%d ORDER BY sort_order', Database::table('form_services'),$id))):[];self::header($r?(__('Edit form', 'kloster-prayer')):(__('Add form', 'kloster-prayer')));?><form method="post" class="klp-native-editor"><?php wp_nonce_field('klp_admin_action', '_klp_nonce');?><input type="hidden" name="klp_action" value="save_form"><input type="hidden" name="id" value="<?php echo  (int)($r->id??0) ?>"><table class="form-table" role="presentation"><tbody><?php foreach(self::editor_languages() as $code=>$label):?><tr><th colspan="2"><h2><?php echo esc_html($label)?></h2></th></tr><tr><th><?php echo esc_html__('Title', 'kloster-prayer'); ?></th><td><input class="regular-text" name="title_<?php echo esc_attr($code)?>" value="<?php echo esc_attr($r->{'title_'.$code}??'')?>"></td></tr><tr><th><?php echo esc_html__('Introduction', 'kloster-prayer'); ?></th><td><textarea class="large-text" rows="2" name="intro_<?php echo esc_attr($code)?>"><?php echo esc_textarea($r->{'intro_'.$code}??'')?></textarea></td></tr><?php endforeach;?><tr><th><?php echo esc_html__('Slug', 'kloster-prayer'); ?></th><td><input class="regular-text code" required name="slug" value="<?php echo esc_attr($r->slug??'')?>"></td></tr><tr><th><?php echo esc_html__('Services in this form', 'kloster-prayer'); ?></th><td><fieldset class="klp-service-checklist"><?php foreach($services as $s):?><label><input type="checkbox" name="services[]" value="<?php echo (int)$s->id?>" <?php echo checked(in_array((int)$s->id,$selected,true),true,false)?>> <?php echo esc_html($s->title_ru)?> <span>— <?php echo esc_html($s->title_de)?></span></label><?php endforeach;?></fieldset></td></tr><tr><th><?php echo esc_html__('Layout', 'kloster-prayer'); ?></th><td><select name="layout"><?php foreach(['standard'=>(__('Classic', 'kloster-prayer')),'compact'=>(__('Compact', 'kloster-prayer')),'wide'=>(__('Wide: 3 cards', 'kloster-prayer')),'two_columns'=>(__('Two columns', 'kloster-prayer')),'cards'=>(__('Image cards', 'kloster-prayer')),'minimal'=>(__('Minimal', 'kloster-prayer'))] as $v=>$l):?><option value="<?php echo esc_attr($v)?>" <?php echo selected($r->layout??'standard',$v,false)?>><?php echo esc_html($l)?></option><?php endforeach;?></select><p class="description"><?php echo esc_html__('The form always fills 100% of the shortcode block; the layout changes its internal arrangement.', 'kloster-prayer'); ?></p></td></tr><tr><th><?php echo esc_html__('Default type', 'kloster-prayer'); ?></th><td><select name="default_intention"><option value="health" <?php echo selected($r->default_intention??'health','health',false)?>><?php echo esc_html__('For the living', 'kloster-prayer'); ?></option><option value="rest" <?php echo selected($r->default_intention??'health','rest',false)?>><?php echo esc_html__('For the departed', 'kloster-prayer'); ?></option></select></td></tr><tr><th><?php echo esc_html__('Status', 'kloster-prayer'); ?></th><td><label><input type="checkbox" name="is_active" value="1" <?php echo checked($r->is_active??1,1,false)?>> <?php echo esc_html__('Active', 'kloster-prayer'); ?></label></td></tr></tbody></table><?php submit_button($r?(__('Save changes', 'kloster-prayer')):(__('Add form', 'kloster-prayer')));?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=kloster-prayer-forms'))?>"><?php echo esc_html__('Cancel', 'kloster-prayer'); ?></a></form></div><?php
    }

    public static function reports(): void { Admin_Screens::reports(); }
    public static function settings(): void { Admin_Screens::settings(); }

    public static function export_csv(): never
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('klp_csv');
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY created_at DESC',Database::table('orders')),ARRAY_A);
        nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="kloster-orders-'.wp_date('Ymd-His').'.csv"');
        $out=fopen('php://output','wb'); echo "\xEF\xBB\xBF"; fputcsv($out,['ID',(__('Date', 'kloster-prayer')),(__('Status', 'kloster-prayer')),(__('Payment', 'kloster-prayer')),(__('Amount EUR', 'kloster-prayer')),(__('Type', 'kloster-prayer')),(__('Names', 'kloster-prayer')),(__('Note', 'kloster-prayer')),(__('Donor name', 'kloster-prayer')),'E-mail',(__('Printed', 'kloster-prayer')),(__('Payer name', 'kloster-prayer')),(__('Payer email', 'kloster-prayer')),(__('Phone', 'kloster-prayer')),(__('Billing address', 'kloster-prayer'))],';','"','');
        foreach($rows as $row)fputcsv($out,[$row['id'],$row['created_at'],$row['payment_status'],$row['payment_provider'],number_format(((int)$row['amount_cents'])/100,2,',',''),$row['intention'],$row['names'],$row['note'],$row['donor_name'],$row['donor_email'],$row['printed_at'],$row['billing_name']??'',$row['billing_email']??'',$row['billing_phone']??'',implode(', ',(array)(json_decode((string)($row['billing_address']??''),true)?:[]))],';','"','');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output, not a filesystem file.
        fclose($out); exit;
    }

    public static function diagnostics(): void
    {
        global $wpdb; self::header((__('Diagnostics', 'kloster-prayer')));
        $enabled=(array)get_option('klp_payment_methods',['stripe']);
        $checks=[
            (__('PHP 8.4 or later', 'kloster-prayer'))=>version_compare(PHP_VERSION,'8.4','>='),
            (__('WordPress 7.1 or later', 'kloster-prayer'))=>version_compare((string)get_bloginfo('version'),'7.1','>='),
            'Stripe Secret Key'=>trim((string)get_option('klp_stripe_secret'))!=='',
            'Stripe Webhook Secret'=>trim((string)get_option('klp_stripe_webhook_secret'))!=='',
            'PayPal Client ID'=>trim((string)get_option('klp_paypal_client_id'))!=='',
            'PayPal Secret'=>trim((string)get_option('klp_paypal_secret'))!=='',
            'PayPal Webhook ID'=>trim((string)get_option('klp_paypal_webhook_id'))!=='',
            (__('Email retry scheduler', 'kloster-prayer'))=>wp_next_scheduled('klp_retry_confirmations')!==false,
            (__('Database tables', 'kloster-prayer'))=>$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',Database::table('orders')))===Database::table('orders'),
            'Dompdf'=>is_file(DIR.'/includes/lib/dompdf/autoload.inc.php'),
        ];
        $events=$wpdb->get_results($wpdb->prepare('SELECT provider,event_type,order_id,processed,error_message,created_at FROM %i ORDER BY id DESC LIMIT 20',Database::table('payment_events'))); ?>
        <div class="klp-card"><table class="widefat striped"><tbody><?php foreach($checks as $name=>$ok): $inactive=(str_starts_with($name,'Stripe')&&!in_array('stripe',$enabled,true))||(str_starts_with($name,'PayPal')&&!in_array('paypal',$enabled,true)); ?><tr><th><?php echo esc_html($name)?></th><td><strong style="color:<?php echo $inactive?'#646970':($ok?'#15803d':'#b91c1c')?>"><?php echo esc_html($inactive?__('Disabled — no configuration required', 'kloster-prayer'):($ok?__('Ready', 'kloster-prayer'):__('Check configuration', 'kloster-prayer')))?></strong></td></tr><?php endforeach;?></tbody></table></div>
        <div class="klp-card"><h2><?php echo esc_html__('Recent payment events', 'kloster-prayer'); ?></h2><table class="widefat striped"><thead><tr><th><?php echo esc_html__('Date', 'kloster-prayer'); ?></th><th><?php echo esc_html__('Provider', 'kloster-prayer'); ?></th><th><?php echo esc_html__('Event', 'kloster-prayer'); ?></th><th><?php echo esc_html__('Request', 'kloster-prayer'); ?></th><th><?php echo esc_html__('Result', 'kloster-prayer'); ?></th></tr></thead><tbody><?php foreach($events as $event):?><tr><td><?php echo esc_html($event->created_at)?></td><td><?php echo esc_html($event->provider)?></td><td><?php echo esc_html($event->event_type)?></td><td><?php echo  (int)$event->order_id?></td><td><?php echo esc_html($event->processed?__('Processed', 'kloster-prayer'):($event->error_message?:__('Error', 'kloster-prayer')))?></td></tr><?php endforeach;if(!$events):?><tr><td colspan="5"><?php echo esc_html__('No events yet.', 'kloster-prayer'); ?></td></tr><?php endif;?></tbody></table></div></div><?php
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
        $supported=['ru'=>__('Russian', 'kloster-prayer'),'de'=>__('German', 'kloster-prayer'),'uk'=>__('Ukrainian', 'kloster-prayer'),'pl'=>__('Polish', 'kloster-prayer')];
        $locales=[];
        if(function_exists('pll_languages_list')) $locales=pll_languages_list(['fields'=>'locale','hide_empty'=>0]);
        if(!$locales) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing WPML integration hook, not a plugin-defined hook.
            $wpml=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
            if(is_array($wpml)) foreach($wpml as $code=>$language) $locales[]=$language['default_locale']??$code;
        }
        if(!$locales) $locales=[get_locale()];
        $result=[];
        foreach($locales as $locale){$code=substr(strtolower((string)$locale),0,2);if(isset($supported[$code]))$result[$code]=$supported[$code];}
        return $result ?: ['de'=>__('German', 'kloster-prayer')];
    }

    private static function preserve_translations(array $data): array
    {
        if (empty(Input::post('id'))) return $data;
        foreach(['ru','de','uk','pl'] as $code)foreach(['title','intro','description'] as $field){
            $key=$field.'_'.$code;
            if(Input::post($key)===null) unset($data[$key]);
        }
        return $data;
    }

    private static function catalog(string $kind): void
    {
        require_once DIR.'/includes/class-catalog-table.php';
        self::header($kind==='forms'?(__('Forms', 'kloster-prayer')):(__('Services', 'kloster-prayer')));
        echo '<a class="page-title-action" href="'.esc_url(admin_url('admin.php?page=kloster-prayer-'.$kind.'&new=1')).('">'.esc_html__('Add new', 'kloster-prayer').'</a><hr class="wp-header-end">');
        $table=new Catalog_Table($kind); $table->prepare_items(); $table->views();
        echo '<form method="get"><input type="hidden" name="page" value="kloster-prayer-'.esc_attr($kind).'">';
        $table->search_box((__('Search', 'kloster-prayer')),'klp-catalog');echo '</form>';
        echo '<form method="post">';wp_nonce_field('klp_admin_action', '_klp_nonce');
        echo '<input type="hidden" name="klp_action" value="catalog_bulk"><input type="hidden" name="catalog" value="'.esc_attr($kind).'">';
        $table->display();echo '</form></div>';
    }

    private static function field(string $name,string $label,mixed $value,string $type='text'): string { $display=$type==='password'?'':(string)$value; $placeholder=$type==='password'&&$value!==''?(' placeholder="'.esc_attr__('Saved — leave blank to keep unchanged', 'kloster-prayer').'"'):''; return '<label>'.esc_html($label).'<input class="regular-text" type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($display).'"'.$placeholder.'></label>'; }
    private static function textarea(string $name,string $label,mixed $value): string { return '<label>'.esc_html($label).'<textarea name="'.esc_attr($name).'" rows="3">'.esc_textarea((string)$value).'</textarea></label>'; }
}
