<?php
declare(strict_types=1);

namespace GeorgKlosterOrthodoxDonate;

final class Admin
{
    public static function init(): void
    {
        if (!is_admin()) return;
        // Register the parent before feature submenus derive their page hooks.
        add_action('admin_menu', [self::class, 'menu'], 9);
        add_action('admin_init', [self::class, 'handle_actions']);
        add_action('admin_post_gkod_csv', [self::class, 'export_csv']);
        add_filter('plugin_row_meta', [self::class, 'external_meta'], 10, 2);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if (str_contains($hook, 'georg-kloster-orthodox-donations')) {
                wp_enqueue_style('dashicons');
                wp_enqueue_style('gkod-admin', plugins_url('assets/css/admin.css', FILE), [], VERSION . '.' . substr(md5_file(DIR . '/assets/css/admin.css'), 0, 12));
                if (str_ends_with($hook, '_page_georg-kloster-orthodox-donations-help')) wp_enqueue_script('gkod-admin-shortcodes', plugins_url('assets/js/admin-shortcodes.js', FILE), [], VERSION, true);
                wp_enqueue_media();
                wp_enqueue_script('gkod-admin-images', plugins_url('assets/js/admin-images.js', FILE), ['jquery','media-views'], VERSION . '.' . filemtime(DIR . '/assets/js/admin-images.js'), true);
                wp_localize_script('gkod-admin-images', 'georgKlosterOrthodoxDonateAdmin', [
                    'empty' => __('No image selected.', 'georg-kloster-orthodox-donations'),
                    'open' => __('Open background image', 'georg-kloster-orthodox-donations'),
                    'title' => __('Service background image', 'georg-kloster-orthodox-donations'),
                    'view' => __('View image ↗', 'georg-kloster-orthodox-donations'),
                    'use' => __('Use image', 'georg-kloster-orthodox-donations'),
                    'campaignTitle' => I18n::text('campaign_image'),
                    'campaignUse' => I18n::text('use_image'),
                ]);
            }
        });
    }

    public static function menu(): void
    {
        add_menu_page(esc_html__('Donations', 'georg-kloster-orthodox-donations'), esc_html__('Donations', 'georg-kloster-orthodox-donations'), 'manage_options', 'georg-kloster-orthodox-donations', [self::class, 'orders'], 'dashicons-heart', 26);
        add_submenu_page('georg-kloster-orthodox-donations', (esc_html__('Prayer notes', 'georg-kloster-orthodox-donations')), (esc_html__('Prayer notes', 'georg-kloster-orthodox-donations')), 'manage_options', 'georg-kloster-orthodox-donations', [self::class, 'orders']);
        add_submenu_page('georg-kloster-orthodox-donations', (esc_html__('Services', 'georg-kloster-orthodox-donations')), (esc_html__('Services', 'georg-kloster-orthodox-donations')), 'manage_options', 'georg-kloster-orthodox-donations-services', [self::class, 'services']);
        add_submenu_page('georg-kloster-orthodox-donations', (esc_html__('Forms', 'georg-kloster-orthodox-donations')), (esc_html__('Forms', 'georg-kloster-orthodox-donations')), 'manage_options', 'georg-kloster-orthodox-donations-forms', [self::class, 'forms']);
        add_submenu_page('georg-kloster-orthodox-donations', (esc_html__('Reports', 'georg-kloster-orthodox-donations')), (esc_html__('Reports', 'georg-kloster-orthodox-donations')), 'manage_options', 'georg-kloster-orthodox-donations-reports', [self::class, 'reports']);
        add_submenu_page('georg-kloster-orthodox-donations', (esc_html__('Settings', 'georg-kloster-orthodox-donations')), (esc_html__('Settings', 'georg-kloster-orthodox-donations')), 'manage_options', 'georg-kloster-orthodox-donations-settings', [self::class, 'settings']);
        add_submenu_page('georg-kloster-orthodox-donations', (esc_html__('Diagnostics', 'georg-kloster-orthodox-donations')), (esc_html__('Diagnostics', 'georg-kloster-orthodox-donations')), 'manage_options', 'georg-kloster-orthodox-donations-diagnostics', [self::class, 'diagnostics']);
        add_submenu_page('georg-kloster-orthodox-donations', esc_html__('Help → Shortcodes', 'georg-kloster-orthodox-donations'), esc_html__('Help', 'georg-kloster-orthodox-donations'), 'manage_options', 'georg-kloster-orthodox-donations-help', [Shortcode_Help::class, 'page']);
    }

    public static function handle_actions(): void
    {
        if (!current_user_can('manage_options') || empty(Input::post('gkod_action'))) return;
        check_admin_referer('gkod_admin_action', '_gkod_nonce');
        global $wpdb;
        $action = sanitize_key((string) Input::post('gkod_action'));
        if ($action === 'refresh_customer') {
            $id=absint(Input::post('order_id')??0);$ok=Payments::refresh_customer($id);
            wp_safe_redirect(add_query_arg(['page'=>'georg-kloster-orthodox-donations','view'=>$id,'customer_sync'=>$ok?'ok':'unavailable'],admin_url('admin.php')));exit;
        }
        if ($action === 'catalog_bulk') {
            $kind=in_array(Input::post('catalog')??'', ['forms','services'],true)?Input::post('catalog'):'forms';
            $operation=(Input::post('action')??'-1')!=='-1'?Input::post('action'):(Input::post('action2')??'');
            $ids=array_filter(array_map('absint',(array)(Input::post_list('ids')??[])));
            if ($kind==='services' && $operation==='sync_stripe_catalog') {
                $result=Stripe_Catalog::sync_selected($ids);
                set_transient('gkod_stripe_sync_' . get_current_user_id(), $result, MINUTE_IN_SECONDS);
                self::redirect('georg-kloster-orthodox-donations-services', 'stripe_sync');
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
            if(in_array($operation,['enable','disable'],true))foreach($ids as $item_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update only records selected in this authorized bulk action.
                if ($wpdb->update(Database::table($kind),['is_active'=>$operation==='enable'?1:0,'updated_at'=>current_time('mysql')],['id'=>$item_id]) === false) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
            }
            self::redirect('georg-kloster-orthodox-donations-'.$kind,'updated');
        }
        if ($action === 'orders_bulk') $action=(Input::post('action')??'-1')!=='-1'?sanitize_key(Input::post('action')):sanitize_key(Input::post('action2')??'');
        if ($action === 'generate_pdf') {
            $ids = array_filter(array_map('absint', (array) (Input::post_list('order_ids') ?? [])));
            if (!$ids) {
                require_once DIR.'/includes/class-orders-table.php';
                [$where,$args]=Orders_Table::where(Input::post_all());
                $query='SELECT id FROM %i WHERE '.$where;
                // WHERE is built only from fixed clauses and placeholders in Orders_Table::where().
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- All identifiers and filter values are bound below. Authorized administration of custom request/form/service tables; read current rows after edits.
                $ids=array_map('intval',$wpdb->get_col($wpdb->prepare($query,...array_merge([Database::table('orders')],$args))));
                if (!$ids) wp_die((esc_html__('No requests match the selected filter.', 'georg-kloster-orthodox-donations')));
            }
            $filters = ['order_ids' => $ids];
            foreach (['archive','payment_status','intention','printed'] as $key) if (!empty(Input::post($key))) $filters[$key] = sanitize_key(Input::post($key));
            wp_safe_redirect(Pdf::url($filters)); exit;
        }
        if ($action === 'resend_confirmation') {
            $ok = Payments::resend_confirmation(absint(Input::post('order_id') ?? 0));
            self::redirect('georg-kloster-orthodox-donations', $ok ? 'email_sent' : 'email_failed');
        }
        if ($action === 'save_settings') {
            $recipient = null;
            if (isset($_POST['notification_email'])) {
                try { $recipient = Notifications::validate_recipient((string) Input::post('notification_email')); }
                catch (\InvalidArgumentException $e) { wp_die(esc_html__('Enter one valid notification email or leave the field blank.', 'georg-kloster-orthodox-donations')); }
            }
            $origin = null;
            if (isset($_POST['api_origin'])) {
                try { $origin = Payments::normalize_api_origin((string)Input::post('api_origin')); }
                catch (\InvalidArgumentException $e) { wp_die(esc_html(__('Enter an HTTPS domain without a path, query or credentials, for example https://example.org. Leave blank for automatic selection.', 'georg-kloster-orthodox-donations'))); }
            }
            // Validate every field before writing any setting, so one invalid field cannot partially save the form.
            if ($recipient !== null) update_option('gkod_notification_email', $recipient);
            if ($origin !== null) update_option('gkod_api_origin', $origin);
            if (!empty(Input::post('stripe_secret'))) update_option('gkod_stripe_secret', sanitize_text_field((string) Input::post('stripe_secret')));
            if (!empty(Input::post('stripe_webhook_secret'))) update_option('gkod_stripe_webhook_secret', sanitize_text_field((string) Input::post('stripe_webhook_secret')));
            update_option('gkod_paypal_mode', in_array(Input::post('paypal_mode') ?? '', ['live', 'sandbox'], true) ? Input::post('paypal_mode') : 'live');
            update_option('gkod_paypal_client_id', sanitize_text_field((string) Input::post('paypal_client_id') ?? ''));
            if (!empty(Input::post('paypal_secret'))) update_option('gkod_paypal_secret', sanitize_text_field((string) Input::post('paypal_secret')));
            update_option('gkod_paypal_webhook_id', sanitize_text_field((string) Input::post('paypal_webhook_id') ?? ''));
            $methods = array_values(array_intersect(['stripe', 'paypal'], (array) (Input::post_list('methods') ?? [])));
            update_option('gkod_payment_methods', $methods ?: ['stripe']);
            self::redirect('georg-kloster-orthodox-donations-settings', 'saved');
        }
        if ($action === 'save_service') {
            $id = absint(Input::post('id') ?? 0);
            $price = Payments::donation_cents((string) (Input::post('price') ?? ''), 0);
            if ($price === null) wp_die(esc_html__('Please complete all required fields.', 'georg-kloster-orthodox-donations'));
            $data = ['slug' => sanitize_title((string) (Input::post('slug') ?? '')), 'title_ru' => sanitize_text_field((string) Input::post('title_ru') ?? ''), 'title_de' => sanitize_text_field((string) Input::post('title_de') ?? ''), 'title_uk' => sanitize_text_field((string) Input::post('title_uk') ?? ''), 'title_pl' => sanitize_text_field((string) Input::post('title_pl') ?? ''), 'description_ru' => sanitize_textarea_field((string) Input::textarea('description_ru') ?? ''), 'description_de' => sanitize_textarea_field((string) Input::textarea('description_de') ?? ''), 'description_uk' => sanitize_textarea_field((string) Input::textarea('description_uk') ?? ''), 'description_pl' => sanitize_textarea_field((string) Input::textarea('description_pl') ?? ''), 'image_id' => absint(Input::post('image_id') ?? 0), 'intention_mode' => in_array(Input::post('intention_mode') ?? '', ['both','health','rest','none'], true) ? Input::post('intention_mode') : 'both', 'pricing_mode' => in_array(Input::post('pricing_mode') ?? '', ['flat','per_person','per_sheet'], true) ? Input::post('pricing_mode') : 'flat', 'price_cents' => max(0, $price), 'names_per_sheet' => max(1, absint(Input::post('names_per_sheet') ?? 10)), 'is_active' => isset($_POST['is_active']) ? 1 : 0, 'sort_order' => (int) (Input::post('sort_order') ?? 0), 'updated_at' => current_time('mysql')];
            if ($data['slug'] === '') wp_die(esc_html__('Please complete all required fields.', 'georg-kloster-orthodox-donations'));
            $data = self::preserve_translations($data);
            if ($id && !$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id=%d', Database::table('services'), $id))) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
            if ($id) $saved = $wpdb->update(Database::table('services'), $data, ['id' => $id]);
            else { $data['created_at'] = current_time('mysql'); $saved = $wpdb->insert(Database::table('services'), $data); }
            if ($saved === false || (!$id && (int) $wpdb->insert_id < 1)) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
            self::redirect('georg-kloster-orthodox-donations-services', 'saved');
        }
        if ($action === 'save_form') {
            $id = absint(Input::post('id') ?? 0);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
            $current_form = $id ? $wpdb->get_row($wpdb->prepare('SELECT default_intention,health_color,rest_color FROM %i WHERE id=%d', Database::table('forms'), $id)) : null;
            if ($id && !$current_form) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
            $current_intention = (string) ($current_form->default_intention ?? 'health');
            $default_intention = isset($_POST['default_intention']) ? ((Input::post('default_intention') === 'rest') ? 'rest' : 'health') : $current_intention;
            $health_color = sanitize_hex_color((string) (Input::post('health_color') ?? '')) ?: (sanitize_hex_color((string) ($current_form->health_color ?? '')) ?: '#ad5754');
            $rest_color = sanitize_hex_color((string) (Input::post('rest_color') ?? '')) ?: (sanitize_hex_color((string) ($current_form->rest_color ?? '')) ?: '#595959');
            $layouts=['standard','compact','wide','two_columns','cards','minimal']; $layout=in_array(Input::post('layout')??'',$layouts,true)?Input::post('layout'):'standard';
            $data = ['slug' => sanitize_title((string) (Input::post('slug') ?? '')), 'title_ru' => sanitize_text_field((string) Input::post('title_ru') ?? ''), 'title_de' => sanitize_text_field((string) Input::post('title_de') ?? ''), 'title_uk' => sanitize_text_field((string) Input::post('title_uk') ?? ''), 'title_pl' => sanitize_text_field((string) Input::post('title_pl') ?? ''), 'intro_ru' => sanitize_textarea_field((string) Input::textarea('intro_ru') ?? ''), 'intro_de' => sanitize_textarea_field((string) Input::textarea('intro_de') ?? ''), 'intro_uk' => sanitize_textarea_field((string) Input::textarea('intro_uk') ?? ''), 'intro_pl' => sanitize_textarea_field((string) Input::textarea('intro_pl') ?? ''), 'layout' => $layout, 'default_intention' => $default_intention, 'health_color' => $health_color, 'rest_color' => $rest_color, 'is_active' => isset($_POST['is_active']) ? 1 : 0, 'updated_at' => current_time('mysql')];
            if ($data['slug'] === '') wp_die(esc_html__('Please complete all required fields.', 'georg-kloster-orthodox-donations'));
            $data = self::preserve_translations($data);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
            if ($id) $saved = $wpdb->update(Database::table('forms'), $data, ['id' => $id]);
            else { $data['created_at'] = current_time('mysql'); $saved = $wpdb->insert(Database::table('forms'), $data); $id = (int) $wpdb->insert_id; }
            if ($saved === false || $id < 1) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
            if ($wpdb->delete(Database::table('form_services'), ['form_id' => $id]) === false) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Authorized administration of custom request/form/service tables; read current rows after edits.
            foreach (array_map('absint', (array) (Input::post_list('services') ?? [])) as $sort => $service_id) {
                if ($service_id > 0 && $wpdb->insert(Database::table('form_services'), ['form_id' => $id, 'service_id' => $service_id, 'sort_order' => $sort]) === false) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
            }
            self::redirect('georg-kloster-orthodox-donations-forms', 'saved');
        }
        if (in_array($action, ['mark_printed','mark_unprinted','delete_order','archive_order','restore_order'], true)) {
            $ids = array_filter(array_map('absint', (array) (Input::post_list('order_ids') ?? [])));
            foreach ($ids as $id) {
                Order_Workflow::change($id, $action);
            }
            self::redirect('georg-kloster-orthodox-donations', 'updated');
        }
    }

    private static function redirect(string $page, string $notice): never
    {
        wp_safe_redirect(add_query_arg(['page' => $page, 'gkod_notice' => $notice], admin_url('admin.php'))); exit;
    }

    private static function header(string $title): void
    {
        echo '<div class="wrap gkod-admin"><h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';
        if (Input::get('gkod_notice') === 'stripe_sync') {
            $key = 'gkod_stripe_sync_' . get_current_user_id();
            $result = get_transient($key);
            delete_transient($key);
            if (is_array($result)) {
                echo '<div class="notice '.(!empty($result['failed'])?'notice-warning':'notice-success').' is-dismissible"><p>';
                printf(esc_html__('Stripe synchronization finished: %1$d created, %2$d updated, %3$d failed.', 'georg-kloster-orthodox-donations'), (int) $result['created'], (int) $result['updated'], (int) $result['failed']);
                if (!empty($result['errors'])) {
                    echo '</p><ul>';
                    foreach ($result['errors'] as $error) echo '<li>'.esc_html((string) $error).'</li>';
                    echo '</ul><p>';
                }
                echo '</p></div>';
            }
        } elseif (!empty(Input::get('gkod_notice'))) echo ('<div class="notice notice-success is-dismissible"><p>'.esc_html__('Saved.', 'georg-kloster-orthodox-donations').'</p></div>');
    }

    public static function orders(): void
    {
        require_once DIR.'/includes/class-orders-table.php';
        global $wpdb; self::header((__('Prayer requests', 'georg-kloster-orthodox-donations')));
        $view=absint(Input::get('view')??0);
        if($view){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
            $detail=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'),$view));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
            if($detail){$detail_items=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE order_id=%d', Database::table('order_items'),$view));$address=json_decode((string)$detail->billing_address,true)?:[]; ?>
            <div class="postbox gkod-card"><h2><?php echo esc_html__('Payment and request #', 'georg-kloster-orthodox-donations'); ?><?php echo  (int)$detail->id?></h2><div class="gkod-stats"><div><small><?php echo esc_html__('Donor', 'georg-kloster-orthodox-donations'); ?></small><strong><?php echo esc_html($detail->billing_name?:$detail->donor_name?:'—')?></strong></div><div><small>E-mail</small><strong><?php echo esc_html($detail->billing_email?:$detail->donor_email)?></strong></div><div><small><?php echo esc_html__('Phone', 'georg-kloster-orthodox-donations'); ?></small><strong><?php echo esc_html($detail->billing_phone?:'—')?></strong></div><div><small><?php echo esc_html__('Amount', 'georg-kloster-orthodox-donations'); ?></small><strong><?php echo esc_html(number_format_i18n($detail->amount_cents/100,2))?> €</strong></div></div><table class="widefat striped"><tbody><tr><th><?php echo esc_html__('Services', 'georg-kloster-orthodox-donations'); ?></th><td><?php echo esc_html(implode(', ',array_column($detail_items,'service_title')))?></td></tr><tr><th><?php echo esc_html__('Type', 'georg-kloster-orthodox-donations'); ?></th><td><?php echo $detail->intention==='none'?esc_html(I18n::donation_text('title')):($detail->intention==='rest'?(esc_html__('For the departed', 'georg-kloster-orthodox-donations')):(esc_html__('For the living', 'georg-kloster-orthodox-donations')))?></td></tr><tr><th><?php echo esc_html__('Billing address', 'georg-kloster-orthodox-donations'); ?></th><td><?php echo esc_html(implode(', ',array_filter([$address['line1']??'',$address['line2']??'',$address['postal_code']??'',$address['city']??'',$address['state']??'',$address['country']??''])))?:'—'?></td></tr><tr><th>Stripe / <?php echo esc_html__('PayPal', 'georg-kloster-orthodox-donations'); ?> ID</th><td><code><?php echo esc_html($detail->payment_id?:'—')?></code></td></tr><tr><th><?php echo esc_html__('Paid', 'georg-kloster-orthodox-donations'); ?></th><td><?php echo esc_html($detail->paid_at?:'—')?></td></tr><tr><th><?php echo esc_html__('Names', 'georg-kloster-orthodox-donations'); ?></th><td><?php echo nl2br(esc_html($detail->names))?></td></tr><tr><th><?php echo esc_html__('Request comment','georg-kloster-orthodox-donations')?></th><td><?php echo wp_kses_post(Orders_Table::comment_html((string)($detail->note??'')))?:esc_html__('No comment provided','georg-kloster-orthodox-donations')?></td></tr></tbody></table><p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations'))?>"><?php echo esc_html__('← Back to requests', 'georg-kloster-orthodox-donations'); ?></a></p></div><?php }
        }
        if ($view) {
            if(isset($detail)&&$detail) Order_Workflow::detail($detail);
            if((Input::get('customer_sync') !== null))echo '<div class="notice notice-info"><p>'.(Input::get('customer_sync')==='ok'?(esc_html__('Customer details refreshed from Stripe.', 'georg-kloster-orthodox-donations')):(esc_html__('Stripe did not return payer details.', 'georg-kloster-orthodox-donations'))).'</p></div>';
            if(isset($detail)&&$detail&&$detail->payment_provider==='stripe'&&empty($detail->subscription_id)){
                echo '<form method="post">';wp_nonce_field('gkod_admin_action', '_gkod_nonce');
                echo '<input type="hidden" name="gkod_action" value="refresh_customer"><input type="hidden" name="order_id" value="'.(int)$view.('"><button class="button">'.esc_html__('Retrieve payer details from Stripe', 'georg-kloster-orthodox-donations').'</button></form>');
            }
            if(isset($detail)&&$detail&&$detail->payment_status==='paid'){
                echo '<form method="post" class="gkod-detail-actions">';wp_nonce_field('gkod_admin_action', '_gkod_nonce');
                echo '<input type="hidden" name="gkod_action" value="resend_confirmation"><input type="hidden" name="order_id" value="'.(int)$view.('"><button class="button">'.esc_html__('Resend confirmation', 'georg-kloster-orthodox-donations').'</button></form>');
                if (($detail->intention ?? '') !== 'none') echo '<a class="button" href="'.esc_url(Pdf::url(['order_ids'=>[$view]])).'">'.esc_html__('Request PDF', 'georg-kloster-orthodox-donations').'</a>';
            }
            echo '</div>'; return;
        }
        require_once DIR.'/includes/class-orders-table.php';
        $table=new Orders_Table();$table->prepare_items();
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations')).'">'.esc_html__('Current requests','georg-kloster-orthodox-donations').'</a> | <a href="'.esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations&archive=yes')).'">'.esc_html__('Archive','georg-kloster-orthodox-donations').'</a></p>';
        echo ('<p>'.esc_html__('Blank PDF forms:', 'georg-kloster-orthodox-donations').' ');
        foreach (['ru'=>'RU', 'de'=>'DE'] as $language=>$label) {
            foreach (['health'=>(esc_html__('For the living', 'georg-kloster-orthodox-donations')), 'rest'=>(esc_html__('For the departed', 'georg-kloster-orthodox-donations'))] as $intention=>$title) {
                echo '<a class="button" href="'.esc_url(Pdf::url(['blank'=>'1','language'=>$language,'intention'=>$intention])).'">'.esc_html($label.' · '.$title).'</a> ';
            }
        }
        echo '</p>';
        echo '<form method="get"><input type="hidden" name="page" value="georg-kloster-orthodox-donations">';
        if ((Input::get('archive')??'') === 'yes') echo '<input type="hidden" name="archive" value="yes">';
        $table->search_box((__('Search requests', 'georg-kloster-orthodox-donations')),'gkod-orders');echo '</form>';
        echo '<form method="post">';wp_nonce_field('gkod_admin_action', '_gkod_nonce');
        echo '<input type="hidden" name="page" value="georg-kloster-orthodox-donations"><input type="hidden" name="gkod_action" value="orders_bulk">';
        foreach(['provider','month','service_id','s'] as $key) if((Input::get($key)!==null))echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((Input::get($key)??'')).'">';
        $table->display();echo '</form></div>';
    }

    public static function services(): void { self::services_native(); }
    public static function forms(): void { self::forms_native(); }

    private static function services_native(): void
    {
        global $wpdb;
        $id = absint(Input::get('edit') ?? 0);
        $editing = $id || Input::get('new') !== null;
        if (!$editing) { self::catalog('services'); return; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
        $r = $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('services'), $id)) : null;
        self::header($r ? __('Edit service', 'georg-kloster-orthodox-donations') : __('Add service', 'georg-kloster-orthodox-donations'));
        ?>
        <form method="post">
            <?php wp_nonce_field('gkod_admin_action', '_gkod_nonce'); ?>
            <input type="hidden" name="gkod_action" value="save_service">
            <input type="hidden" name="id" value="<?php echo (int) ($r->id ?? 0); ?>">
            <table class="form-table" role="presentation"><tbody>
            <?php foreach (self::editor_languages() as $code => $label) : ?>
                <tr>
                    <th scope="row"><label for="title_<?php echo esc_attr($code); ?>"><?php echo esc_html__('Title', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><input type="text" class="regular-text" id="title_<?php echo esc_attr($code); ?>" name="title_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr($r->{'title_' . $code} ?? ''); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="description_<?php echo esc_attr($code); ?>"><?php echo esc_html__('Description', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><textarea class="large-text" rows="2" id="description_<?php echo esc_attr($code); ?>" name="description_<?php echo esc_attr($code); ?>"><?php echo esc_textarea($r->{'description_' . $code} ?? ''); ?></textarea></td>
                </tr>
            <?php endforeach; ?>
                <tr>
                    <th scope="row"><label for="slug"><?php echo esc_html__('Slug', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><input type="text" class="regular-text code" id="slug" name="slug" required value="<?php echo esc_attr($r->slug ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Background image', 'georg-kloster-orthodox-donations'); ?></th>
                    <td>
                        <input type="hidden" name="image_id" id="gkod-image-id" value="<?php echo (int) ($r->image_id ?? 0); ?>">
                        <?php Service_Images::preview($r); ?>
                        <button type="button" class="button" id="gkod-image-select"><?php echo esc_html__('Choose from Media Library', 'georg-kloster-orthodox-donations'); ?></button>
                        <button type="button" class="button-link-delete" id="gkod-image-clear"><?php echo esc_html__('Reset to default', 'georg-kloster-orthodox-donations'); ?></button>
                        <p class="description"><?php echo esc_html__('The bundled thematic background is used when no image is selected.', 'georg-kloster-orthodox-donations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="intention_mode"><?php echo esc_html__('Intention', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><select id="intention_mode" name="intention_mode">
                        <?php foreach (['both' => __('Both', 'georg-kloster-orthodox-donations'), 'health' => __('Living only', 'georg-kloster-orthodox-donations'), 'rest' => __('Departed only', 'georg-kloster-orthodox-donations'), 'none' => __('No type', 'georg-kloster-orthodox-donations')] as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php echo selected($r->intention_mode ?? 'both', $value, false); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pricing_mode"><?php echo esc_html__('Pricing', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><select id="pricing_mode" name="pricing_mode">
                        <?php foreach (['flat' => __('Fixed amount', 'georg-kloster-orthodox-donations'), 'per_person' => __('Per name', 'georg-kloster-orthodox-donations'), 'per_sheet' => __('Per request', 'georg-kloster-orthodox-donations')] as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php echo selected($r->pricing_mode ?? 'flat', $value, false); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>
                <tr><th scope="row"><label for="price"><?php echo esc_html__('Donation, EUR', 'georg-kloster-orthodox-donations'); ?></label></th><td><input id="price" name="price" type="number" min="0" max="999999.99" step="0.01" required value="<?php echo esc_attr(isset($r->price_cents) ? number_format($r->price_cents / 100, 2, '.', '') : ''); ?>"></td></tr>
                <tr><th scope="row"><label for="names_per_sheet"><?php echo esc_html__('Names per slip', 'georg-kloster-orthodox-donations'); ?></label></th><td><input id="names_per_sheet" name="names_per_sheet" type="number" min="1" required value="<?php echo (int) ($r->names_per_sheet ?? 10); ?>"></td></tr>
                <tr><th scope="row"><label for="sort_order"><?php echo esc_html__('Sort order', 'georg-kloster-orthodox-donations'); ?></label></th><td><input id="sort_order" name="sort_order" type="number" value="<?php echo (int) ($r->sort_order ?? 10); ?>"></td></tr>
                <tr><th scope="row"><?php echo esc_html__('Status', 'georg-kloster-orthodox-donations'); ?></th><td><label><input type="checkbox" name="is_active" value="1" <?php echo checked($r->is_active ?? 1, 1, false); ?>> <?php echo esc_html__('Active', 'georg-kloster-orthodox-donations'); ?></label></td></tr>
            </tbody></table>
            <?php submit_button($r ? __('Save changes', 'georg-kloster-orthodox-donations') : __('Add service', 'georg-kloster-orthodox-donations')); ?>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-services')); ?>"><?php echo esc_html__('Cancel', 'georg-kloster-orthodox-donations'); ?></a>
        </form></div>
        <?php
    }

    private static function forms_native(): void
    {
        global $wpdb;
        $id = absint(Input::get('edit') ?? 0);
        $editing = $id || Input::get('new') !== null;
        if (!$editing) { self::catalog('forms'); return; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
        $r = $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('forms'), $id)) : null;
        if ($id && !$r) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'));
        $services = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY sort_order', Database::table('services')));
        $selected = $r ? array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT service_id FROM %i WHERE form_id=%d ORDER BY sort_order', Database::table('form_services'), $id))) : [];
        self::header($r ? __('Edit form', 'georg-kloster-orthodox-donations') : __('Add form', 'georg-kloster-orthodox-donations'));
        ?>
        <form method="post">
            <?php wp_nonce_field('gkod_admin_action', '_gkod_nonce'); ?>
            <input type="hidden" name="gkod_action" value="save_form">
            <input type="hidden" name="id" value="<?php echo (int) ($r->id ?? 0); ?>">
            <table class="form-table" role="presentation"><tbody>
            <?php foreach (self::editor_languages() as $code => $label) : ?>
                <tr>
                    <th scope="row"><label for="form_title_<?php echo esc_attr($code); ?>"><?php echo esc_html__('Title', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><input type="text" class="regular-text" id="form_title_<?php echo esc_attr($code); ?>" name="title_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr($r->{'title_' . $code} ?? ''); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="form_intro_<?php echo esc_attr($code); ?>"><?php echo esc_html__('Introduction', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><textarea class="large-text" rows="2" id="form_intro_<?php echo esc_attr($code); ?>" name="intro_<?php echo esc_attr($code); ?>"><?php echo esc_textarea($r->{'intro_' . $code} ?? ''); ?></textarea></td>
                </tr>
            <?php endforeach; ?>
                <tr>
                    <th scope="row"><label for="slug"><?php echo esc_html__('Slug', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><input type="text" class="regular-text code" id="slug" name="slug" required value="<?php echo esc_attr($r->slug ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><span id="form-services-label"><?php echo esc_html__('Services in this form', 'georg-kloster-orthodox-donations'); ?></span></th>
                    <td><fieldset class="gkod-service-checklist" aria-labelledby="form-services-label">
                        <?php foreach ($services as $service) : ?>
                            <label><input type="checkbox" name="services[]" value="<?php echo (int) $service->id; ?>" <?php echo checked(in_array((int) $service->id, $selected, true), true, false); ?>> <?php echo esc_html(($service->{'title_' . array_key_first(self::editor_languages())} ?? '') ?: $service->title_ru ?: $service->title_de); ?></label>
                        <?php endforeach; ?>
                    </fieldset></td>
                </tr>
                <tr>
                    <th scope="row"><label for="layout"><?php echo esc_html__('Layout', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><select id="layout" name="layout">
                        <?php foreach (['standard' => __('Classic', 'georg-kloster-orthodox-donations'), 'compact' => __('Compact', 'georg-kloster-orthodox-donations'), 'wide' => __('Wide: 3 cards', 'georg-kloster-orthodox-donations'), 'two_columns' => __('Two columns', 'georg-kloster-orthodox-donations'), 'cards' => __('Image cards', 'georg-kloster-orthodox-donations'), 'minimal' => __('Minimal', 'georg-kloster-orthodox-donations')] as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php echo selected($r->layout ?? 'standard', $value, false); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select><p class="description"><?php echo esc_html__('The form always fills 100% of the shortcode block; the layout changes its internal arrangement.', 'georg-kloster-orthodox-donations'); ?></p></td>
                </tr>
                <tr><th scope="row"><label for="health_color"><?php echo esc_html__('Color for the living intention', 'georg-kloster-orthodox-donations'); ?></label></th><td><input type="color" id="health_color" name="health_color" value="<?php echo esc_attr(sanitize_hex_color((string) ($r->health_color ?? '')) ?: '#ad5754'); ?>"></td></tr>
                <tr><th scope="row"><label for="rest_color"><?php echo esc_html__('Color for the departed intention', 'georg-kloster-orthodox-donations'); ?></label></th><td><input type="color" id="rest_color" name="rest_color" value="<?php echo esc_attr(sanitize_hex_color((string) ($r->rest_color ?? '')) ?: '#595959'); ?>"><p class="description"><?php echo esc_html__('These colors apply only to the intention selectors. Submit buttons use the active WordPress theme.', 'georg-kloster-orthodox-donations'); ?></p></td></tr>
                <tr>
                    <th scope="row"><label for="default_intention"><?php echo esc_html__('Default type', 'georg-kloster-orthodox-donations'); ?></label></th>
                    <td><select id="default_intention" name="default_intention"><option value="health" <?php echo selected($r->default_intention ?? 'health', 'health', false); ?>><?php echo esc_html__('For the living', 'georg-kloster-orthodox-donations'); ?></option><option value="rest" <?php echo selected($r->default_intention ?? 'health', 'rest', false); ?>><?php echo esc_html__('For the departed', 'georg-kloster-orthodox-donations'); ?></option></select></td>
                </tr>
                <tr><th scope="row"><?php echo esc_html__('Status', 'georg-kloster-orthodox-donations'); ?></th><td><label><input type="checkbox" name="is_active" value="1" <?php echo checked($r->is_active ?? 1, 1, false); ?>> <?php echo esc_html__('Active', 'georg-kloster-orthodox-donations'); ?></label></td></tr>
            </tbody></table>
            <?php submit_button($r ? __('Save changes', 'georg-kloster-orthodox-donations') : __('Add form', 'georg-kloster-orthodox-donations')); ?>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-forms')); ?>"><?php echo esc_html__('Cancel', 'georg-kloster-orthodox-donations'); ?></a>
        </form></div>
        <?php
    }

    public static function reports(): void { Admin_Screens::reports(); }
    public static function settings(): void { Admin_Screens::settings(); }

    public static function export_csv(): never
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        check_admin_referer('gkod_csv');
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY created_at DESC',Database::table('orders')),ARRAY_A);
        nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="kloster-orders-'.wp_date('Ymd-His').'.csv"');
        $out=fopen('php://output','wb'); echo "\xEF\xBB\xBF"; fputcsv($out,array_map([self::class, 'csv_cell'],['ID',(__('Date', 'georg-kloster-orthodox-donations')),(__('Status', 'georg-kloster-orthodox-donations')),(__('Payment', 'georg-kloster-orthodox-donations')),(__('Amount EUR', 'georg-kloster-orthodox-donations')),(__('Type', 'georg-kloster-orthodox-donations')),(__('Names', 'georg-kloster-orthodox-donations')),(__('Note', 'georg-kloster-orthodox-donations')),(__('Donor name', 'georg-kloster-orthodox-donations')),'E-mail',(__('Printed', 'georg-kloster-orthodox-donations')),(__('Payer name', 'georg-kloster-orthodox-donations')),(__('Payer email', 'georg-kloster-orthodox-donations')),(__('Phone', 'georg-kloster-orthodox-donations')),(__('Billing address', 'georg-kloster-orthodox-donations'))]),';','"','');
        foreach($rows as $row)fputcsv($out,array_map([self::class, 'csv_cell'],[$row['id'],$row['created_at'],$row['payment_status'],$row['payment_provider'],number_format(((int)$row['amount_cents'])/100,2,',',''),$row['intention'],$row['names'],$row['note'],$row['donor_name'],$row['donor_email'],$row['printed_at'],$row['billing_name']??'',$row['billing_email']??'',$row['billing_phone']??'',implode(', ',(array)(json_decode((string)($row['billing_address']??''),true)?:[]))]),';','"','');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output, not a filesystem file.
        fclose($out); exit;
    }

    private static function csv_cell(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        // Keep spreadsheet applications from interpreting donor text as formulas.
        return preg_match('/^(?:[\x00-\x20]*[=+\-@]|[\t\r\n])/u', $text) ? "'" . $text : $text;
    }

    public static function diagnostics(): void
    {
        global $wpdb; self::header((__('Diagnostics', 'georg-kloster-orthodox-donations')));
        $enabled=(array)get_option('gkod_payment_methods',['stripe']);
        $checks=[
            (__('PHP 8.4 or later', 'georg-kloster-orthodox-donations'))=>version_compare(PHP_VERSION,'8.4','>='),
            (__('WordPress 7.1 or later', 'georg-kloster-orthodox-donations'))=>version_compare((string)get_bloginfo('version'),'7.1','>='),
            'Stripe Secret Key'=>trim((string)get_option('gkod_stripe_secret'))!=='',
            'Stripe Webhook Secret'=>trim((string)get_option('gkod_stripe_webhook_secret'))!=='',
            'PayPal Client ID'=>trim((string)get_option('gkod_paypal_client_id'))!=='',
            'PayPal Secret'=>trim((string)get_option('gkod_paypal_secret'))!=='',
            'PayPal Webhook ID'=>trim((string)get_option('gkod_paypal_webhook_id'))!=='',
            (__('Email retry scheduler', 'georg-kloster-orthodox-donations'))=>wp_next_scheduled('gkod_retry_confirmations')!==false,
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
            (__('Database tables', 'georg-kloster-orthodox-donations'))=>$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',Database::table('orders')))===Database::table('orders'),
            'Dompdf'=>is_file(DIR.'/includes/lib/dompdf/autoload.inc.php'),
            (__('PDF letter background support', 'georg-kloster-orthodox-donations'))=>class_exists('Imagick')&&in_array('PDF',\Imagick::queryFormats(),true),
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized administration of custom request/form/service tables; read current rows after edits.
        $events=$wpdb->get_results($wpdb->prepare('SELECT provider,event_type,order_id,processed,error_message,created_at FROM %i ORDER BY id DESC LIMIT 20',Database::table('payment_events'))); ?>
        <div class="postbox gkod-card"><table class="widefat striped"><tbody><?php foreach($checks as $name=>$ok): $inactive=(str_starts_with($name,'Stripe')&&!in_array('stripe',$enabled,true))||(str_starts_with($name,'PayPal')&&!in_array('paypal',$enabled,true)); ?><tr><th><?php echo esc_html($name)?></th><td><strong style="color:<?php echo $inactive?'#646970':($ok?'#15803d':'#b91c1c')?>"><?php echo esc_html($inactive?__('Disabled — no configuration required', 'georg-kloster-orthodox-donations'):($ok?__('Ready', 'georg-kloster-orthodox-donations'):__('Check configuration', 'georg-kloster-orthodox-donations')))?></strong></td></tr><?php endforeach;?></tbody></table></div>
        <div class="postbox gkod-card"><h2><?php echo esc_html__('Recent payment events', 'georg-kloster-orthodox-donations'); ?></h2><table class="widefat striped"><thead><tr><th><?php echo esc_html__('Date', 'georg-kloster-orthodox-donations'); ?></th><th><?php echo esc_html__('Provider', 'georg-kloster-orthodox-donations'); ?></th><th><?php echo esc_html__('Event', 'georg-kloster-orthodox-donations'); ?></th><th><?php echo esc_html__('Request', 'georg-kloster-orthodox-donations'); ?></th><th><?php echo esc_html__('Result', 'georg-kloster-orthodox-donations'); ?></th></tr></thead><tbody><?php foreach($events as $event):?><tr><td><?php echo esc_html($event->created_at)?></td><td><?php echo esc_html($event->provider)?></td><td><?php echo esc_html($event->event_type)?></td><td><?php echo  (int)$event->order_id?></td><td><?php echo esc_html($event->processed?__('Processed', 'georg-kloster-orthodox-donations'):($event->error_message?:__('Error', 'georg-kloster-orthodox-donations')))?></td></tr><?php endforeach;if(!$events):?><tr><td colspan="5"><?php echo esc_html__('No events yet.', 'georg-kloster-orthodox-donations'); ?></td></tr><?php endif;?></tbody></table></div></div><?php
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
        $supported=['ru'=>__('Russian', 'georg-kloster-orthodox-donations'),'de'=>__('German', 'georg-kloster-orthodox-donations'),'uk'=>__('Ukrainian', 'georg-kloster-orthodox-donations'),'pl'=>__('Polish', 'georg-kloster-orthodox-donations')];
        $code=I18n::content_language();
        // Existing prayer/service records have no English content columns.
        if (!isset($supported[$code])) $code='de';
        return [$code=>$supported[$code]];
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
        self::header($kind==='forms'?(__('Forms', 'georg-kloster-orthodox-donations')):(__('Services', 'georg-kloster-orthodox-donations')));
        echo '<a class="page-title-action" href="'.esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-'.$kind.'&new=1')).('">'.esc_html__('Add new', 'georg-kloster-orthodox-donations').'</a><hr class="wp-header-end">');
        $table=new Catalog_Table($kind); $table->prepare_items(); $table->views();
        echo '<form method="get"><input type="hidden" name="page" value="georg-kloster-orthodox-donations-'.esc_attr($kind).'">';
        $table->search_box((__('Search', 'georg-kloster-orthodox-donations')),'gkod-catalog');echo '</form>';
        echo '<form method="post">';wp_nonce_field('gkod_admin_action', '_gkod_nonce');
        echo '<input type="hidden" name="gkod_action" value="catalog_bulk"><input type="hidden" name="catalog" value="'.esc_attr($kind).'">';
        $table->display();echo '</form></div>';
    }

    private static function field(string $name,string $label,mixed $value,string $type='text'): string { $display=$type==='password'?'':(string)$value; $placeholder=$type==='password'&&$value!==''?(' placeholder="'.esc_attr__('Saved — leave blank to keep unchanged', 'georg-kloster-orthodox-donations').'"'):''; return '<label>'.esc_html($label).'<input class="regular-text" type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($display).'"'.$placeholder.'></label>'; }
    private static function textarea(string $name,string $label,mixed $value): string { return '<label>'.esc_html($label).'<textarea name="'.esc_attr($name).'" rows="3">'.esc_textarea((string)$value).'</textarea></label>'; }
}
