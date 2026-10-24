<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

/** Admin editor and preview/test actions for the thank-you letter template. */
final class Thank_You_Template_Admin
{
    private const SELECTED_OPTION = 'gkod_thank_you_selected_template';
    private const DEFAULT_ID = 'default';
    private static bool $default_template_checked = false;

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_gkod_save_thank_you_template', [self::class, 'save']);
        add_action('admin_post_gkod_create_thank_you_template', [self::class, 'create']);
        add_action('admin_post_gkod_delete_thank_you_template', [self::class, 'delete']);
        add_action('admin_post_gkod_preview_thank_you', [self::class, 'preview']);
        add_action('admin_post_gkod_test_thank_you', [self::class, 'test_send']);
    }

    public static function menu(): void
    {
        add_submenu_page('georg-kloster-orthodox-donations', esc_html__('Thank-you letter', 'georg-kloster-orthodox-donations'), esc_html__('Thank-you letter', 'georg-kloster-orthodox-donations'), 'manage_options', 'georg-kloster-orthodox-donations-thank-you', [self::class, 'page']);
    }

    public static function assets(string $hook): void
    {
        if (!str_ends_with($hook, '_page_georg-kloster-orthodox-donations-thank-you')) return;
        wp_enqueue_media();
        wp_enqueue_script('gkod-thank-you-template', plugins_url('assets/js/thank-you-template.js', FILE), ['jquery', 'media-views'], VERSION . '.' . filemtime(DIR . '/assets/js/thank-you-template.js'), true);
        wp_localize_script('gkod-thank-you-template', 'gkodThankYouTemplate', [
            'title' => __('Choose a PDF or image background', 'georg-kloster-orthodox-donations'),
            'button' => __('Use this background', 'georg-kloster-orthodox-donations'),
            'pdfSelected' => __('PDF selected. Save the template to load its first page preview.', 'georg-kloster-orthodox-donations'),
            'chooseBackground' => __('Choose a background to begin positioning fields.', 'georg-kloster-orthodox-donations'),
            'bodySample' => __('Thank you for supporting the monastery.', 'georg-kloster-orthodox-donations'),
            'signerSample' => __('Abbot', 'georg-kloster-orthodox-donations'),
        ]);
    }

    public static function defaults(): array
    {
        $fields = [
            'name' => [__('Donor name', 'georg-kloster-orthodox-donations'), 50, 25, 80, 20, '#302b25', 'center'],
            'body' => [__('Thank-you text', 'georg-kloster-orthodox-donations'), 50, 38, 80, 12, '#302b25', 'center'],
            'quote' => [__('Bible quote / additional text', 'georg-kloster-orthodox-donations'), 50, 47, 80, 11, '#302b25', 'center'],
            'extra' => [__('Additional text block', 'georg-kloster-orthodox-donations'), 50, 51, 80, 10, '#302b25', 'center'],
            'donation' => [__('Donation purpose', 'georg-kloster-orthodox-donations'), 50, 52, 80, 11, '#302b25', 'center'],
            'date' => [__('Donation date', 'georg-kloster-orthodox-donations'), 50, 58, 80, 10, '#302b25', 'center'],
            'amount' => [__('Donation amount', 'georg-kloster-orthodox-donations'), 50, 64, 80, 10, '#302b25', 'center'],
            'signer' => [__('Abbot / signer', 'georg-kloster-orthodox-donations'), 50, 78, 80, 12, '#6f302c', 'center'],
            'address' => [__('Monastery address', 'georg-kloster-orthodox-donations'), 50, 92, 90, 9, '#595959', 'center'],
        ];
        $out = [];
        foreach ($fields as $key => [$label, $x, $y, $width, $size, $color, $align]) $out[$key] = compact('label', 'x', 'y', 'width', 'size', 'color', 'align') + ['enabled' => !in_array($key, ['quote', 'extra'], true)];
        return ['template_name' => __('Default template', 'georg-kloster-orthodox-donations'), 'language' => 'any', 'occasion' => 'any', 'attachment_id' => 0, 'orientation' => 'portrait', 'body_text' => __('Thank you for your generous support of the monastery. Your gift helps sustain our prayer and service.', 'georg-kloster-orthodox-donations'), 'quote_text' => '', 'extra_text' => '', 'signer_text' => '', 'address_text' => '', 'test_email' => '', 'fields' => $out];
    }

    /** Return templates stored in the dedicated plugin table. */
    public static function templates(): array
    {
        global $wpdb;
        self::ensure_default_template();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Templates are stored in the plugin's dedicated template table.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,config FROM %i ORDER BY id ASC', Database::table('thank_you_templates')));
        if ($wpdb->last_error !== '') return [self::DEFAULT_ID => self::defaults()];
        $templates = [];
        foreach ((array) $rows as $row) {
            $template = json_decode((string) $row->config, true);
            if (!is_array($template)) continue;
            $templates[(string) $row->id] = self::normalize_template($template);
        }
        return $templates ?: [self::DEFAULT_ID => self::defaults()];
    }

    private static function ensure_default_template(): void
    {
        if (self::$default_template_checked) return;
        self::$default_template_checked = true;
        global $wpdb;
        $table = Database::table('thank_you_templates');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ensure one initial template exists in the dedicated plugin table.
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        if ($wpdb->last_error !== '' || $count > 0) return;
        $template = self::defaults();
        $now = current_time('mysql');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Create the initial default template only when this table is empty.
        $wpdb->insert($table, [
            'template_name' => $template['template_name'],
            'language' => $template['language'],
            'occasion' => $template['occasion'],
            'config' => wp_json_encode($template),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function normalize_template(array $saved): array
    {
        unset($saved['active']); // Older builds stored this flag for automatic matching; templates are now chosen explicitly.
        $base = self::defaults();
        $fields = is_array($saved['fields'] ?? null) ? $saved['fields'] : [];
        foreach ($base['fields'] as $key => $defaults) $base['fields'][$key] = array_merge($defaults, is_array($fields[$key] ?? null) ? $fields[$key] : []);
        return array_merge($base, $saved, ['fields' => $base['fields']]);
    }

    public static function selected_id(): string
    {
        $id = (string) get_option(self::SELECTED_OPTION, '');
        $templates = self::templates();
        return isset($templates[$id]) ? $id : (string) array_key_first($templates);
    }

    /** Return only languages enabled by the site's multilingual plugin, or its single WordPress locale. */
    public static function language_options(): array
    {
        $options = ['any' => __('Any language', 'georg-kloster-orthodox-donations')];
        // WPML exposes configured languages through its public active-languages filter.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official WPML API hook, owned by WPML.
        $wpml = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        if (is_array($wpml)) foreach ($wpml as $key => $language) {
            if (!is_array($language)) continue;
            $code = sanitize_key((string) ($language['code'] ?? $key));
            if ($code !== '' && $code !== 'any') $options[$code] = (string) ($language['native_name'] ?? $language['translated_name'] ?? $code);
        }
        // Polylang's raw language list includes only languages enabled in its settings.
        if (count($options) === 1 && function_exists('pll_the_languages')) {
            $polylang = pll_the_languages(['raw' => 1]);
            if (is_array($polylang)) foreach ($polylang as $key => $language) {
                if (!is_array($language)) continue;
                $code = sanitize_key((string) ($language['slug'] ?? $key));
                if ($code !== '' && $code !== 'any') $options[$code] = (string) ($language['name'] ?? $language['locale'] ?? $code);
            }
        }
        if (count($options) === 1) {
            $locale = sanitize_key((string) get_locale());
            if ($locale !== '') $options[$locale] = $locale;
        }
        return $options;
    }

    public static function occasion_options(): array
    {
        return ['any' => __('Any case', 'georg-kloster-orthodox-donations'), 'donation' => __('One-time donation', 'georg-kloster-orthodox-donations'), 'prayer' => __('Prayer request', 'georg-kloster-orthodox-donations'), 'regular' => __('Regular support', 'georg-kloster-orthodox-donations')];
    }

    public static function config(?object $order = null, string $template_id = ''): array
    {
        $templates = self::templates();
        if ($template_id !== '' && isset($templates[$template_id])) return $templates[$template_id];
        $selected_id = self::selected_id();
        return $templates[$selected_id] ?? reset($templates);
    }

    private static function persist_template(string $id, array $template): bool
    {
        global $wpdb;
        $table = Database::table('thank_you_templates');
        $data = [
            'template_name' => sanitize_text_field((string) ($template['template_name'] ?? '')),
            'language' => sanitize_key((string) ($template['language'] ?? 'any')),
            'occasion' => sanitize_key((string) ($template['occasion'] ?? 'any')),
            'config' => wp_json_encode($template),
            'updated_at' => current_time('mysql'),
        ];
        if ($id === self::DEFAULT_ID) {
            $data['created_at'] = current_time('mysql');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert the first persisted template into the dedicated template table.
            $saved = $wpdb->insert($table, $data);
            if ($saved === false) return false;
            update_option(self::SELECTED_OPTION, (string) $wpdb->insert_id, false);
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Update only the selected template row.
        $saved = $wpdb->update($table, $data, ['id' => absint($id)]);
        if ($saved === false) return false;
        update_option(self::SELECTED_OPTION, $id, false);
        return true;
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        $templates = self::templates();
        $requested_template_id = Input::get('template_id');
        if ($requested_template_id === null || $requested_template_id === '') {
            self::list_page();
            return;
        }
        $template_id = sanitize_key((string) $requested_template_id);
        if (!isset($templates[$template_id])) $template_id = self::selected_id();
        $cfg = self::config(null, $template_id);
        $attachment_id = absint($cfg['attachment_id']);
        $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        $mime = $attachment_id ? (string) get_post_mime_type($attachment_id) : '';
        $image_url = $attachment_id && str_starts_with($mime, 'image/') ? wp_get_attachment_image_url($attachment_id, 'full') : '';
        $preview_url = $attachment_id && $mime === 'application/pdf' ? Thank_You::background_preview_url($attachment_id) : $image_url;
        echo '<div class="wrap gkod-admin"><h1>' . esc_html__('Thank-you letter template', 'georg-kloster-orthodox-donations') . '</h1>';
        if (Input::get('saved') === '1') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Template saved.', 'georg-kloster-orthodox-donations') . '</p></div>';
        if (Input::get('gkod_notice') === 'thank_you_test_sent') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Test copy sent to the configured test address.', 'georg-kloster-orthodox-donations') . '</p></div>';
        elseif (Input::get('gkod_notice') === 'thank_you_failed') echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Thank-you letter could not be sent.', 'georg-kloster-orthodox-donations') . '</p></div>';
        if (Input::get('error') === 'background') echo '<div class="notice notice-error"><p>' . esc_html__('The background file is not a supported PDF, JPEG or PNG image.', 'georg-kloster-orthodox-donations') . '</p></div>';
        if ($mime === 'application/pdf' && !$preview_url) echo '<div class="notice notice-warning"><p>' . esc_html__('PDF backgrounds need the server ImageMagick extension with PDF/Ghostscript support for preview and final letter generation. JPEG and PNG backgrounds do not need this.', 'georg-kloster-orthodox-donations') . '</p></div>';
        echo '<p>' . esc_html__('Choose a PDF or image as the page artwork. Drag the sample labels to place each text field; positions are saved as percentages so they follow the page when its preview is resized. Choose the template manually for each donor letter; language and case are labels to help you identify it.', 'georg-kloster-orthodox-donations') . '</p>';
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-thank-you')) . '">' . esc_html__('Back to templates', 'georg-kloster-orthodox-donations') . '</a></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="gkod_save_thank_you_template">';
        wp_nonce_field('gkod_save_thank_you_template');
        echo '<input type="hidden" name="template_id" value="' . esc_attr($template_id) . '"><table class="form-table"><tbody><tr><th><label for="gkod-thank-you-template-name">' . esc_html__('Template name', 'georg-kloster-orthodox-donations') . '</label></th><td><input class="regular-text" id="gkod-thank-you-template-name" name="template_name" value="' . esc_attr((string) $cfg['template_name']) . '"></td></tr><tr><th><label for="gkod-thank-you-language">' . esc_html__('Language', 'georg-kloster-orthodox-donations') . '</label></th><td><select id="gkod-thank-you-language" name="template_language">';
        foreach (self::language_options() as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected($cfg['language'], $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></td></tr><tr><th><label for="gkod-thank-you-occasion">' . esc_html__('Donation case', 'georg-kloster-orthodox-donations') . '</label></th><td><select id="gkod-thank-you-occasion" name="template_occasion">';
        foreach (self::occasion_options() as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected($cfg['occasion'], $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></td></tr></tbody></table>';
        echo '<input type="hidden" id="gkod-thank-you-attachment" name="attachment_id" value="' . $attachment_id . '"><p><button type="button" class="button" id="gkod-thank-you-choose">' . esc_html__('Choose PDF or image', 'georg-kloster-orthodox-donations') . '</button> <button type="button" class="button" id="gkod-thank-you-clear">' . esc_html__('Remove background', 'georg-kloster-orthodox-donations') . '</button> <a id="gkod-thank-you-file" href="' . esc_url($url ?: '#') . '" target="_blank" rel="noopener">' . esc_html($url ? basename((string) wp_parse_url($url, PHP_URL_PATH)) : __('No background selected', 'georg-kloster-orthodox-donations')) . '</a></p>';
        echo '<p><label for="gkod-thank-you-orientation"><strong>' . esc_html__('Page orientation', 'georg-kloster-orthodox-donations') . '</strong></label> <select id="gkod-thank-you-orientation" name="orientation"><option value="portrait" ' . selected($cfg['orientation'], 'portrait', false) . '>' . esc_html__('Portrait', 'georg-kloster-orthodox-donations') . '</option><option value="landscape" ' . selected($cfg['orientation'], 'landscape', false) . '>' . esc_html__('Landscape', 'georg-kloster-orthodox-donations') . '</option></select></p>';
        echo '<table class="form-table"><tbody><tr><th><label for="gkod-thank-you-body">' . esc_html__('Thank-you text', 'georg-kloster-orthodox-donations') . '</label></th><td><textarea class="large-text" rows="3" id="gkod-thank-you-body" name="body_text">' . esc_textarea((string) $cfg['body_text']) . '</textarea></td></tr><tr><th><label for="gkod-thank-you-quote">' . esc_html__('Bible quote / additional text', 'georg-kloster-orthodox-donations') . '</label></th><td><textarea class="large-text" rows="2" id="gkod-thank-you-quote" name="quote_text">' . esc_textarea((string) $cfg['quote_text']) . '</textarea></td></tr><tr><th><label for="gkod-thank-you-extra">' . esc_html__('Additional text block', 'georg-kloster-orthodox-donations') . '</label></th><td><textarea class="large-text" rows="2" id="gkod-thank-you-extra" name="extra_text">' . esc_textarea((string) $cfg['extra_text']) . '</textarea></td></tr><tr><th><label for="gkod-thank-you-signer">' . esc_html__('Abbot / signer', 'georg-kloster-orthodox-donations') . '</label></th><td><input class="regular-text" id="gkod-thank-you-signer" name="signer_text" value="' . esc_attr((string) $cfg['signer_text']) . '"></td></tr><tr><th><label for="gkod-thank-you-address">' . esc_html__('Monastery address', 'georg-kloster-orthodox-donations') . '</label></th><td><textarea class="large-text" rows="2" id="gkod-thank-you-address" name="address_text">' . esc_textarea((string) $cfg['address_text']) . '</textarea></td></tr><tr><th><label for="gkod-thank-you-test-email">' . esc_html__('Test email address', 'georg-kloster-orthodox-donations') . '</label></th><td><input class="regular-text" type="email" id="gkod-thank-you-test-email" name="test_email" value="' . esc_attr((string) $cfg['test_email']) . '"><p class="description">' . esc_html__('A paid donation can be sent as a test copy to this address from its donor record. Test copies do not mark the donation letter as sent.', 'georg-kloster-orthodox-donations') . '</p></td></tr></tbody></table>';
        echo '<h2>' . esc_html__('Place the fields', 'georg-kloster-orthodox-donations') . '</h2><p>' . esc_html__('Drag each field on the page. Select a field below the preview to adjust its width, font size, color and alignment.', 'georg-kloster-orthodox-donations') . '</p>';
        echo '<div class="gkod-thank-you-editor"><div id="gkod-thank-you-canvas" class="gkod-thank-you-canvas" data-preview="' . esc_url($preview_url ?: '') . '" data-is-pdf="' . ($mime === 'application/pdf' ? '1' : '0') . '" data-orientation="' . esc_attr((string) $cfg['orientation']) . '">';
        if ($preview_url) echo '<img id="gkod-thank-you-preview-bg" src="' . esc_url($preview_url) . '" alt="">';
        else echo '<div class="gkod-thank-you-placeholder">' . esc_html__('Choose a background to begin positioning fields.', 'georg-kloster-orthodox-donations') . '</div>';
        foreach ($cfg['fields'] as $key => $field) {
            $preview_text = str_replace(["\r\n", "\r", "\n"], ['<br>', '<br>', '<br>'], esc_html(self::sample($key, $cfg)));
            printf('<div class="gkod-thank-you-field%9$s" data-field="%1$s" style="left:%2$s%%;top:%3$s%%;width:%4$s%%;font-size:%5$spt;color:%6$s;text-align:%7$s">%8$s</div>', esc_attr($key), esc_attr((string) $field['x']), esc_attr((string) $field['y']), esc_attr((string) $field['width']), esc_attr((string) $field['size']), esc_attr((string) $field['color']), esc_attr((string) $field['align']), $preview_text, empty($field['enabled']) ? ' is-disabled' : '');
            foreach (['x', 'y', 'width', 'size', 'color', 'align', 'enabled'] as $property) echo '<input type="hidden" data-field-value="' . esc_attr($key) . '" data-property="' . esc_attr($property) . '" name="fields[' . esc_attr($key) . '][' . esc_attr($property) . ']" value="' . esc_attr((string) $field[$property]) . '">';
        }
        echo '</div><div class="gkod-thank-you-controls"><label>' . esc_html__('Selected field', 'georg-kloster-orthodox-donations') . ' <select id="gkod-thank-you-field-select">';
        foreach ($cfg['fields'] as $key => $field) echo '<option value="' . esc_attr($key) . '">' . esc_html((string) $field['label']) . '</option>';
        echo '</select></label><p><label><input id="gkod-thank-you-enabled" type="checkbox"> ' . esc_html__('Show this field', 'georg-kloster-orthodox-donations') . '</label></p><p><label>' . esc_html__('Width (%)', 'georg-kloster-orthodox-donations') . ' <input id="gkod-thank-you-width" type="number" min="5" max="100" step="1"></label></p><p><label>' . esc_html__('Font size (pt)', 'georg-kloster-orthodox-donations') . ' <input id="gkod-thank-you-size" type="number" min="6" max="48" step="1"></label></p><p><label>' . esc_html__('Text color', 'georg-kloster-orthodox-donations') . ' <input id="gkod-thank-you-color" type="color"></label></p><p><label>' . esc_html__('Alignment', 'georg-kloster-orthodox-donations') . ' <select id="gkod-thank-you-align"><option value="left">' . esc_html__('Left', 'georg-kloster-orthodox-donations') . '</option><option value="center">' . esc_html__('Center', 'georg-kloster-orthodox-donations') . '</option><option value="right">' . esc_html__('Right', 'georg-kloster-orthodox-donations') . '</option></select></label></p></div></div>';
        submit_button(__('Save template', 'georg-kloster-orthodox-donations'));
        echo '</form><hr><h2>' . esc_html__('Test the letter', 'georg-kloster-orthodox-donations') . '</h2><p>' . esc_html__('Preview a sample PDF or email a sample copy to the test address. Neither action sends to a donor or marks a donation as thanked.', 'georg-kloster-orthodox-donations') . '</p>';
        echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url(wp_nonce_url(add_query_arg(['action' => 'gkod_preview_thank_you', 'order_id' => 0, 'template_id' => $template_id], admin_url('admin-post.php')), 'gkod_preview_thank_you_0')) . '">' . esc_html__('Preview sample PDF', 'georg-kloster-orthodox-donations') . '</a> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"><input type="hidden" name="action" value="gkod_test_thank_you"><input type="hidden" name="order_id" value="0"><input type="hidden" name="template_id" value="' . esc_attr($template_id) . '">' . wp_nonce_field('gkod_test_thank_you_0', '_wpnonce', true, false) . '<button class="button" type="submit">' . esc_html__('Send sample to test address', 'georg-kloster-orthodox-donations') . '</button></form></div>';
    }

    private static function list_page(): void
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        require_once DIR . '/includes/class-thank-you-templates-table.php';
        echo '<div class="wrap gkod-admin"><h1 class="wp-heading-inline">' . esc_html__('Thank-you letter templates', 'georg-kloster-orthodox-donations') . '</h1>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"><input type="hidden" name="action" value="gkod_create_thank_you_template">' . wp_nonce_field('gkod_create_thank_you_template', '_wpnonce', true, false) . '<button class="page-title-action" type="submit">' . esc_html__('Add new', 'georg-kloster-orthodox-donations') . '</button></form><hr class="wp-header-end">';
        if (Input::get('saved') === '1') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Template saved.', 'georg-kloster-orthodox-donations') . '</p></div>';
        if (Input::get('gkod_notice') === 'thank_you_test_sent') echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Test copy sent to the configured test address.', 'georg-kloster-orthodox-donations') . '</p></div>';
        elseif (Input::get('gkod_notice') === 'thank_you_failed') echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Thank-you letter could not be sent.', 'georg-kloster-orthodox-donations') . '</p></div>';
        echo '<p>' . esc_html__('Choose a template manually for each donor letter; language and case are labels to help identify it.', 'georg-kloster-orthodox-donations') . '</p>';
        $table = new Thank_You_Templates_Table();
        $table->prepare_items();
        echo '<form method="get"><input type="hidden" name="page" value="georg-kloster-orthodox-donations-thank-you">';
        $table->search_box(__('Search templates', 'georg-kloster-orthodox-donations'), 'gkod-thank-you-templates');
        $table->display();
        echo '</form></div>';
    }

    private static function sample(string $key, array $cfg): string
    {
        return match ($key) {
            'name' => __('Alexey Donor', 'georg-kloster-orthodox-donations'),
            'body' => (string) $cfg['body_text'],
            'quote' => (string) $cfg['quote_text'],
            'extra' => (string) $cfg['extra_text'],
            'donation' => __('Donation: Prayer request', 'georg-kloster-orthodox-donations'),
            'date' => wp_date(get_option('date_format', 'j F Y')),
            'amount' => '40,00 EUR',
            'signer' => (string) $cfg['signer_text'] ?: __('Abbot', 'georg-kloster-orthodox-donations'),
            'address' => (string) $cfg['address_text'],
            default => '',
        };
    }

    public static function save(): never
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        check_admin_referer('gkod_save_thank_you_template');
        $id = absint(Input::post('attachment_id') ?? 0);
        $mime = $id ? (string) get_post_mime_type($id) : '';
        if ($id && !in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            wp_safe_redirect(add_query_arg(['page' => 'georg-kloster-orthodox-donations-thank-you', 'error' => 'background'], admin_url('admin.php'))); exit;
        }
        $templates = self::templates();
        $template_id = sanitize_key((string) (Input::post('template_id') ?? ''));
        if (!isset($templates[$template_id])) wp_die(esc_html__('Template not found.', 'georg-kloster-orthodox-donations'), '', ['response' => 404]);
        $old = self::config(null, $template_id);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified nonce above; nested values are unslashed and sanitized below.
        $fields = wp_unslash($_POST['fields'] ?? []);
        $fields = is_array($fields) ? $fields : [];
        $clean = [];
        foreach ($old['fields'] as $key => $defaults) {
            $raw = is_array($fields[$key] ?? null) ? $fields[$key] : [];
            $number = static function (string $property) use ($raw, $defaults): float {
                $value = $raw[$property] ?? $defaults[$property];
                return is_scalar($value) && is_numeric($value) ? (float) $value : (float) $defaults[$property];
            };
            $color = is_scalar($raw['color'] ?? null) ? sanitize_hex_color((string) $raw['color']) : null;
            $align = is_scalar($raw['align'] ?? null) ? (string) $raw['align'] : '';
            $clean[$key] = [
                'label' => $defaults['label'],
                'x' => max(0, min(100, $number('x'))),
                'y' => max(0, min(100, $number('y'))),
                'width' => max(5, min(100, $number('width'))),
                'size' => max(6, min(48, $number('size'))),
                'color' => $color ?: $defaults['color'],
                'align' => in_array($align, ['left', 'center', 'right'], true) ? $align : $defaults['align'],
                'enabled' => in_array((string) ($raw['enabled'] ?? ''), ['1', 'true', 'on'], true),
            ];
        }
        $language = (string) (Input::post('template_language') ?? 'any');
        $occasion = (string) (Input::post('template_occasion') ?? 'any');
        $language = array_key_exists($language, self::language_options()) ? $language : 'any';
        $occasion = in_array($occasion, ['any', 'donation', 'prayer', 'regular'], true) ? $occasion : 'any';
        $templates[$template_id] = array_merge($old, [
            'template_name' => sanitize_text_field((string) (Input::post('template_name') ?? '')) ?: $old['template_name'],
            'language' => $language,
            'occasion' => $occasion,
            'attachment_id' => $id,
            'orientation' => in_array(Input::post('orientation') ?? '', ['portrait', 'landscape'], true) ? Input::post('orientation') : 'portrait',
            'body_text' => Input::textarea('body_text') ?? '',
            'quote_text' => Input::textarea('quote_text') ?? '',
            'extra_text' => Input::textarea('extra_text') ?? '',
            'signer_text' => sanitize_text_field((string) (Input::post('signer_text') ?? '')),
            'address_text' => Input::textarea('address_text') ?? '',
            'test_email' => sanitize_email((string) (Input::post('test_email') ?? '')),
            'fields' => $clean,
        ]);
        if (!self::persist_template($template_id, $templates[$template_id])) {
            wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        }
        wp_safe_redirect(add_query_arg(['page' => 'georg-kloster-orthodox-donations-thank-you', 'template_id' => $template_id, 'saved' => '1'], admin_url('admin.php'))); exit;
    }

    public static function create(): never
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        check_admin_referer('gkod_create_thank_you_template');
        global $wpdb;
        $templates = self::templates();
        $template = self::defaults();
        $template['template_name'] = sprintf(__('New template %d', 'georg-kloster-orthodox-donations'), count($templates) + 1);
        $now = current_time('mysql');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Create a new template row in its dedicated plugin table.
        $saved = $wpdb->insert(Database::table('thank_you_templates'), [
            'template_name' => $template['template_name'],
            'language' => $template['language'],
            'occasion' => $template['occasion'],
            'config' => wp_json_encode($template),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($saved === false || (int) $wpdb->insert_id < 1) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        $id = (string) $wpdb->insert_id;
        update_option(self::SELECTED_OPTION, $id, false);
        wp_safe_redirect(add_query_arg(['page' => 'georg-kloster-orthodox-donations-thank-you', 'template_id' => $id], admin_url('admin.php'))); exit;
    }

    public static function delete(): never
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        global $wpdb;
        $id = sanitize_key((string) (Input::request('template_id') ?? ''));
        check_admin_referer('gkod_delete_thank_you_template_' . $id);
        $templates = self::templates();
        if (count($templates) < 2 || !isset($templates[$id])) wp_die(esc_html__('The last template cannot be deleted.', 'georg-kloster-orthodox-donations'), '', ['response' => 400]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Delete only the explicitly selected template row.
        if ($wpdb->delete(Database::table('thank_you_templates'), ['id' => absint($id)], ['%d']) !== 1) wp_die(esc_html__('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'), '', ['response' => 500]);
        unset($templates[$id]);
        $selected = (string) array_key_first($templates);
        update_option(self::SELECTED_OPTION, $selected, false);
        wp_safe_redirect(add_query_arg(['page' => 'georg-kloster-orthodox-donations-thank-you'], admin_url('admin.php'))); exit;
    }

    public static function preview(): never
    {
        self::authorize_order_action('gkod_preview_thank_you');
        $order_id = absint(Input::get('order_id') ?? Input::post('order_id') ?? 0);
        $template_id = sanitize_key((string) (Input::get('template_id') ?? Input::post('template_id') ?? ''));
        $pdf = $order_id > 0 ? Thank_You::generate_order($order_id, $template_id) : Thank_You::generate_sample($template_id);
        if (is_wp_error($pdf)) wp_die(esc_html($pdf->get_error_message()), '', ['response' => 400]);
        nocache_headers(); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="thank-you-preview-' . $order_id . '.pdf"'); echo $pdf; exit;
    }

    public static function test_send(): never
    {
        self::authorize_order_action('gkod_test_thank_you');
        $order_id = absint(Input::post('order_id') ?? 0);
        $template_id = sanitize_key((string) (Input::post('template_id') ?? ''));
        $config = self::config(null, $template_id);
        if (!is_email((string) $config['test_email'])) wp_die(esc_html__('Set a valid test email address in the thank-you letter settings first.', 'georg-kloster-orthodox-donations'), '', ['response' => 400]);
        $donor = absint(Input::post('donor_id') ?? 0);
        if ($donor && !Donors::order_belongs_to($donor, $order_id)) wp_die(esc_html__('The donation does not belong to this donor.', 'georg-kloster-orthodox-donations'), '', ['response' => 400]);
        $sent = Thank_You::send_test_copy($order_id, (string) $config['test_email'], $template_id);
        $notice = $sent === true ? 'thank_you_test_sent' : 'thank_you_failed';
        $page = $donor ? 'georg-kloster-orthodox-donations-donors' : 'georg-kloster-orthodox-donations-thank-you';
        wp_safe_redirect(add_query_arg(['page' => $page, 'donor' => $donor ?: null, 'template_id' => $template_id ?: null, 'gkod_notice' => $notice], admin_url('admin.php'))); exit;
    }

    private static function authorize_order_action(string $action): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        $order_id = absint(Input::get('order_id') ?? Input::post('order_id') ?? 0);
        $nonce_name = 'gkod_nonce_' . $action;
        if (isset($_REQUEST[$nonce_name])) check_admin_referer($action . '_' . $order_id, $nonce_name);
        else check_admin_referer($action . '_' . $order_id);
    }
}
