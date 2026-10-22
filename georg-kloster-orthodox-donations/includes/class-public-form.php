<?php
declare(strict_types=1);

namespace GeorgKlosterOrthodoxDonate;

final class Public_Form
{
    public static function init(): void
    {
        add_shortcode('gkod_form', [self::class, 'shortcode']);
        add_shortcode('gkod_prayer_form', [self::class, 'shortcode']);
        add_shortcode('gkod_donation', [self::class, 'donation_shortcode']);
        add_shortcode('gkod_service', [self::class, 'service_shortcode']);
        add_shortcode('gkod_payment_result', [self::class, 'result_shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        add_action('wp_ajax_gkod_create_payment', [Payments::class, 'create_payment']);
        add_action('wp_ajax_nopriv_gkod_create_payment', [Payments::class, 'create_payment']);
    }

    public static function register_assets(): void
    {
        wp_register_style('georg-kloster-orthodox-donations', plugins_url('assets/css/public.css', FILE), [], VERSION . '.' . filemtime(DIR . '/assets/css/public.css'));
        wp_register_script('georg-kloster-orthodox-donations', plugins_url('assets/js/public.js', FILE), [], VERSION . '.' . filemtime(DIR . '/assets/js/public.js'), true);
        // Shortcodes are rendered after wp_head in many themes. Enqueue the small
        // stylesheet here so it is always printed in the document head.
        wp_enqueue_style('georg-kloster-orthodox-donations');
    }

    public static function shortcode(array|string $atts = []): string
    {
        global $wpdb;
        $atts = shortcode_atts(['id' => 0, 'slug' => '', 'layout' => '', 'lang' => '', 'header' => 'yes'], $atts, 'gkod_form');
        $forms = Database::table('forms');
        if ((int) $atts['id'] > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site-owned form/service configuration in custom tables; query has no post/meta API equivalent.
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id=%d AND is_active=1", $forms, (int) $atts['id']));
        } elseif ($atts['slug'] !== '') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site-owned form/service configuration in custom tables; query has no post/meta API equivalent.
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE slug=%s AND is_active=1", $forms, sanitize_title($atts['slug'])));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site-owned form/service configuration in custom tables; query has no post/meta API equivalent.
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE slug='gebetsanliegen' AND is_active=1 ORDER BY id LIMIT 1", $forms));
        }
        if (!$form) {
            return current_user_can('manage_options') ? '<p>' . esc_html__('Kloster: form not found.', 'georg-kloster-orthodox-donations') . '</p>' : '';
        }
        return self::result_shortcode() . self::render($form, $atts, null);
    }

    public static function service_shortcode(array|string $atts = []): string
    {
        global $wpdb;
        $atts = shortcode_atts(['id' => 0, 'slug' => '', 'layout' => 'compact', 'lang' => ''], $atts, 'gkod_service');
        $services = Database::table('services');
        $service = (int) $atts['id'] > 0
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site-owned form/service configuration in custom tables; query has no post/meta API equivalent.
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id=%d AND is_active=1", $services, (int) $atts['id']))
            : $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE slug=%s AND is_active=1", $services, sanitize_title($atts['slug'])));
        if (!$service) {
            return '';
        }
        $form = (object) ['id' => 0, 'slug' => 'service-' . $service->slug, 'title_ru' => $service->title_ru, 'title_de' => $service->title_de, 'title_uk' => $service->title_uk, 'title_pl' => $service->title_pl, 'intro_ru' => '', 'intro_de' => '', 'intro_uk' => '', 'intro_pl' => '', 'layout' => 'compact', 'default_intention' => $service->intention_mode === 'rest' ? 'rest' : 'health'];
        return self::result_shortcode() . self::render($form, $atts, [$service]);
    }

    public static function donation_shortcode(array|string $atts = []): string
    {
        $atts = shortcode_atts(['layout' => 'compact', 'lang' => '', 'header' => 'yes', 'campaign_id' => 0, 'support' => 'no'], $atts, 'gkod_donation');
        $campaign = absint($atts['campaign_id']) ? Campaigns::get(absint($atts['campaign_id'])) : null;
        if (absint($atts['campaign_id']) && (!$campaign || !Campaigns::accepting($campaign))) return I18n::in_language(static fn(): string => '<p>' . esc_html__('This campaign is closed.', 'georg-kloster-orthodox-donations') . '</p>', in_array($atts['lang'], ['en','ru','de','uk','pl'], true) ? $atts['lang'] : I18n::language());
        $atts['request_type'] = 'donation';
        $form = (object) ['id' => 0, 'layout' => 'compact'];
        foreach (['en', 'de', 'ru', 'uk', 'pl'] as $lang) {
            $form->{'title_' . $lang} = $campaign ? Campaigns::title($campaign, $lang) : ($atts['support'] === 'yes' ? I18n::in_language(static fn() => __('Regular support', 'georg-kloster-orthodox-donations'), $lang) : I18n::donation_text('title', $lang));
            $form->{'intro_' . $lang} = '';
        }
        return self::result_shortcode() . self::render($form, $atts, []);
    }

    private static function render(object $form, array $atts, ?array $fixed_services): string
    {
        global $wpdb;
        $lang = in_array($atts['lang'], ['en', 'de', 'ru', 'uk', 'pl'], true) ? $atts['lang'] : I18n::language();
        $donation = ($atts['request_type'] ?? '') === 'donation';
        $campaign = $donation && !empty($atts['campaign_id']) ? Campaigns::get(absint($atts['campaign_id'])) : null;
        if ($fixed_services === null) {
            $services_table = Database::table('services');
            $links = Database::table('form_services');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site-owned form/service configuration in custom tables; query has no post/meta API equivalent.
            $services = $wpdb->get_results($wpdb->prepare("SELECT s.* FROM %i s INNER JOIN %i fs ON fs.service_id=s.id WHERE fs.form_id=%d AND s.is_active=1 ORDER BY fs.sort_order,s.sort_order", $services_table, $links, (int) $form->id));
        } else {
            $services = $fixed_services;
        }
        if (!$services && !$donation) {
            return '';
        }

        wp_enqueue_script('georg-kloster-orthodox-donations');
        wp_localize_script('georg-kloster-orthodox-donations', 'georgKlosterOrthodoxDonate', [
            'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('gkod_payment'),
            'error' => I18n::text('error', $lang), 'required' => I18n::text('required', $lang),
        ]);

        $layouts = ['standard','compact','wide','two_columns','cards','minimal'];
        $layout = in_array($atts['layout'], $layouts, true) ? $atts['layout'] : (in_array($form->layout, $layouts, true) ? $form->layout : 'standard');
        // Configured service/form content is data, not a gettext source string.
        $content_language = $donation ? $lang : ($lang === 'en' ? 'de' : $lang);
        $title_key = 'title_' . $content_language;
        $intro_key = 'intro_' . $content_language;
        $enabled = get_option('gkod_payment_methods', ['stripe', 'paypal']);
        if (!is_array($enabled)) {
            $enabled = ['stripe'];
        }
        $amount_id = wp_unique_id('gkod-donation-');
        ob_start();
        ?>
        <section class="gkod-form gkod-form--<?php echo  esc_attr($layout) ?>" data-request-type="<?php echo $donation ? 'donation' : 'prayer' ?>" data-currency="EUR" data-lang="<?php echo  esc_attr($lang) ?>">
            <?php if (($atts['header'] ?? 'yes') !== 'no') : ?><header class="gkod-form__header">
                <h2><?php echo  esc_html($form->{$title_key}) ?></h2>
                <?php if (!empty($form->{$intro_key})) : ?><p><?php echo  esc_html($form->{$intro_key}) ?></p><?php endif; ?>
            </header><?php endif; ?>
            <form class="gkod-form__body" novalidate>
                <input type="hidden" name="action" value="gkod_create_payment">
                <input type="hidden" name="nonce" value="<?php echo  esc_attr(wp_create_nonce('gkod_payment')) ?>">
                <input type="hidden" name="form_id" value="<?php echo  (int) $form->id ?>">
                <input type="hidden" name="language" value="<?php echo  esc_attr($lang) ?>">
                <input class="gkod-honeypot" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
                <input type="hidden" name="request_type" value="<?php echo $donation ? 'donation' : 'prayer' ?>">
                <?php if ($donation && !empty($atts['campaign_id'])) : ?><input type="hidden" name="campaign_id" value="<?php echo absint($atts['campaign_id']) ?>"><?php endif; ?>
                <?php if (!$donation) : ?>
                <?php $modes = array_unique(array_column($services, 'intention_mode')); $show_both = in_array('both', $modes, true) || (in_array('health', $modes, true) && in_array('rest', $modes, true)); ?>
                <fieldset class="gkod-intention<?php echo  $show_both ? '' : ' gkod-intention--single' ?>">
                    <legend class="screen-reader-text"><?php echo esc_html(I18n::text('prayer_type', $lang)) ?></legend>
                    <?php if ($show_both || in_array('health', $modes, true)) : ?><label><input type="radio" name="intention" value="health" <?php echo  checked($form->default_intention, 'health', false) ?>><span>☦ <?php echo  esc_html(I18n::text('health', $lang)) ?></span></label><?php endif; ?>
                    <?php if ($show_both || in_array('rest', $modes, true)) : ?><label><input type="radio" name="intention" value="rest" <?php echo  checked($form->default_intention, 'rest', false) ?>><span>☦ <?php echo  esc_html(I18n::text('rest', $lang)) ?></span></label><?php endif; ?>
                </fieldset>
                <fieldset class="gkod-services">
                    <legend><?php echo  esc_html(I18n::text('choose', $lang)) ?></legend>
                    <?php foreach ($services as $service) : $service_title = $service->{$title_key} ?: $service->title_de; $description_key = 'description_' . $content_language; $image=$layout==='cards'?Service_Images::url($service):''; ?>
                        <label class="gkod-service" style="--gkod-service-bg:url('<?php echo  esc_url($image) ?>')" data-mode="<?php echo  esc_attr($service->pricing_mode) ?>" data-price="<?php echo  (int) $service->price_cents ?>" data-limit="<?php echo  (int) $service->names_per_sheet ?>" data-intention="<?php echo  esc_attr($service->intention_mode) ?>">
                            <input type="checkbox" name="services[]" value="<?php echo  (int) $service->id ?>">
                            <span class="gkod-service__check" aria-hidden="true"></span>
                            <span class="gkod-service__name"><?php echo  esc_html($service_title) ?><small><?php echo  esc_html($service->{$description_key} ?: I18n::text($service->pricing_mode, $lang)) ?></small></span>
                            <strong><?php echo  esc_html(number_format_i18n($service->price_cents / 100, 2)) ?> €</strong>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="gkod-field">
                    <label for="gkod-names-<?php echo  (int) $form->id ?>"><?php echo  esc_html(I18n::text('names', $lang)) ?> <span>*</span></label>
                    <textarea id="gkod-names-<?php echo  (int) $form->id ?>" name="names" rows="<?php echo  $layout === 'compact' ? 4 : 6 ?>" required placeholder="<?php echo  esc_attr(I18n::text('names_placeholder', $lang)) ?>"></textarea>
                    <small><?php echo  esc_html(I18n::text('names_help', $lang)) ?></small>
                </div>
                <?php else : ?>
                <div class="gkod-field">
                    <label for="<?php echo esc_attr($amount_id.'-input') ?>"><?php echo esc_html(I18n::text('total', $lang)) ?> (EUR) <span>*</span></label>
                    <?php if ($campaign) : ?><span class="gkod-amount-options" role="radiogroup" aria-label="<?php echo esc_attr(I18n::text('total', $lang)) ?>">
                            <?php foreach ([1,2,3] as $index) : $preset=(int)($campaign->{'preset_'.$index.'_cents'}??[1=>1000,2=>2000,3=>10000][$index]); ?>
                                <label><input type="radio" name="donation_preset" value="<?php echo esc_attr(number_format($preset/100,2,'.','')) ?>" <?php checked($index===1) ?>><span><?php echo esc_html(number_format_i18n($preset/100,2).' €') ?></span></label>
                            <?php endforeach; ?>
                            <label class="gkod-amount-custom"><input type="radio" name="donation_preset" value="custom"><span><?php echo esc_html(I18n::text('custom_amount', $lang)) ?></span></label>
                    </span><?php endif; ?>
                    <input id="<?php echo esc_attr($amount_id.'-input') ?>" type="text" name="donation_amount" inputmode="decimal" required maxlength="9" pattern="[0-9]{1,6}([.,][0-9]{1,2})?" min="<?php echo esc_attr(number_format(max(100,(int)($campaign->minimum_cents??1000))/100,2,'.','')) ?>" value="<?php echo $campaign ? esc_attr(number_format(($campaign->preset_1_cents??1000)/100,2,'.','')) : '' ?>" aria-describedby="<?php echo esc_attr($amount_id) ?>">
                    <small id="<?php echo esc_attr($amount_id) ?>"><?php echo esc_html($campaign ? sprintf(I18n::text('minimum_amount_help', $lang), number_format_i18n(max(100,(int)($campaign->minimum_cents??1000))/100,2)) : I18n::donation_text('amount_help', $lang)) ?></small>
                </div>
                <?php endif; ?>
                <div class="gkod-field"><label><?php echo  esc_html(I18n::text('note', $lang)) ?></label><textarea name="note" rows="<?php echo  $layout === 'compact' ? 1 : 2 ?>"></textarea></div>
                <?php if ($donation && ($atts['support'] ?? 'no') === 'yes') : ?>
                <div class="gkod-field gkod-frequency"><label><?php echo esc_html(I18n::in_language(static fn() => __('Frequency', 'georg-kloster-orthodox-donations'), $lang)) ?>
                    <select name="frequency"><option value="month"><?php echo esc_html(I18n::in_language(static fn() => __('Monthly', 'georg-kloster-orthodox-donations'), $lang)) ?></option><option value="year"><?php echo esc_html(I18n::in_language(static fn() => __('Yearly', 'georg-kloster-orthodox-donations'), $lang)) ?></option></select></label>
                </div>
                <div class="gkod-field gkod-consent"><label><input type="checkbox" name="recurring_consent" value="1" required><span><?php echo esc_html(I18n::in_language(static fn() => __('Repeat this amount at the selected interval until I cancel. I can cancel in my donation history.', 'georg-kloster-orthodox-donations'), $lang)) ?></span></label></div>
                <?php endif; ?>
                <div class="gkod-grid">
                    <div class="gkod-field"><label><?php echo  esc_html(I18n::text('donor_name', $lang)) ?></label><input type="text" name="donor_name" autocomplete="name"></div>
                    <div class="gkod-field"><label><?php echo  esc_html(I18n::text('email', $lang)) ?> <span>*</span></label><input type="email" name="donor_email" required autocomplete="email"><small><?php echo  esc_html(I18n::text('email_help', $lang)) ?></small></div>
                </div>
                <?php if (count($enabled) > 1) : ?>
                    <fieldset class="gkod-payment"><legend><?php echo  esc_html(I18n::text('provider', $lang)) ?></legend>
                    <?php foreach ($enabled as $index => $provider) : if (!in_array($provider, ['stripe', 'paypal'], true)) continue; ?>
                        <label><input type="radio" name="provider" value="<?php echo  esc_attr($provider) ?>" <?php echo  checked($index, 0, false) ?>><span><?php echo  esc_html(I18n::text($provider, $lang)) ?></span></label>
                    <?php endforeach; ?></fieldset>
                <?php else : ?><input type="hidden" name="provider" value="<?php echo  esc_attr($enabled[0] ?? 'stripe') ?>"><?php endif; ?>
                <div class="gkod-summary"><span><?php echo  esc_html(I18n::text('total', $lang)) ?></span><strong><output>0,00 €</output></strong></div>
                <div class="gkod-error" role="alert" hidden></div>
                <button class="gkod-submit" type="submit"><span><?php echo esc_html(($atts['support'] ?? 'no') === 'yes' ? I18n::in_language(static fn() => __('Start regular support', 'georg-kloster-orthodox-donations'), $lang) : ($donation ? I18n::donation_text('submit', $lang) : I18n::text('submit', $lang))) ?></span><b aria-hidden="true">→</b></button>
                <p class="gkod-secure">♙ <?php echo  esc_html(($donation ? I18n::donation_text('secure', $lang) : I18n::text('secure', $lang))) ?></p>
            </form>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function result_shortcode(): string
    {
        global $wpdb;
        $status = sanitize_key(Input::get('gkod_payment') ?? '');
        if (!in_array($status, ['success', 'cancelled'], true)) {
            return '';
        }
        $key = 'cancelled';
        $state = 'cancelled';
        $lang = I18n::language();
        $public_id = sanitize_text_field((string) (Input::get('gkod_order') ?? ''));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Site-owned form/service configuration in custom tables; query has no post/meta API equivalent.
        $order = $public_id !== '' ? $wpdb->get_row($wpdb->prepare('SELECT payment_status,language,intention FROM %i WHERE public_id=%s', Database::table('orders'), $public_id)) : null;
        if ($status === 'success' || ($order && $order->payment_status === 'paid')) {
            $paid = $order && $order->payment_status === 'paid';
            if ($order && in_array($order->language, ['en','de','ru','uk','pl'], true)) $lang = $order->language;
            $key = $paid ? 'thanks' : 'processing';
            $state = $paid ? 'success' : 'processing';
        }
        if ($order && in_array($order->language, ['en','de','ru','uk','pl'], true)) $lang = $order->language;
        $message = ($order && ($order->intention ?? '') === 'none' && in_array($key, ['thanks', 'cancelled'], true))
            ? I18n::donation_text($key, $lang) : I18n::text($key, $lang);
        $title = I18n::text($state . '_title', $lang);
        $close = I18n::text('close', $lang);
        $icon = $state === 'success' ? '✓' : ($state === 'processing' ? '…' : '×');
        return '<div class="gkod-result-modal" data-gkod-result-modal role="dialog" aria-modal="true" aria-labelledby="gkod-result-title">'
            . '<div class="gkod-result-modal__backdrop" data-gkod-result-close></div><div class="gkod-result-modal__card gkod-result-modal__card--' . esc_attr($state) . '" tabindex="-1">'
            . '<button type="button" class="gkod-result-modal__x" data-gkod-result-close aria-label="' . esc_attr($close) . '">×</button>'
            . '<span class="gkod-result-modal__icon" aria-hidden="true">' . esc_html($icon) . '</span><h2 id="gkod-result-title">' . esc_html($title) . '</h2>'
            . '<p>' . esc_html($message) . '</p><button type="button" class="gkod-result-modal__button" data-gkod-result-close>' . esc_html($close) . '</button></div></div>';
    }
}
