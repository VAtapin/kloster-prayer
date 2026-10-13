<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

final class I18n
{
    public static function init(): void
    {
        // Directory builds use WordPress language packs and need no registration.
        // Manual ZIPs also provide local catalogs. Register their fallback path
        // without loading a translation: WordPress keeps its normal JIT loader
        // and gives wp-content/languages/plugins language packs precedence.
        if (glob(DIR . '/languages/*.mo')) {
            global $wp_textdomain_registry;
            $wp_textdomain_registry->set_custom_path('georg-kloster-orthodox-donations', DIR . '/languages');
        }
    }

    public static function language(): string
    {
        if (function_exists('is_admin') && !is_admin()) return self::content_language();
        $code = substr(strtolower(determine_locale()), 0, 2);
        return in_array($code, ['ru', 'de', 'uk', 'pl'], true) ? $code : 'en';
    }

    /** Content follows the installed language plugin; UI follows WordPress gettext. */
    public static function content_language(): string
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official WPML API hook, owned by WPML.
        $language = apply_filters('wpml_current_language', null);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official WPML API hook, owned by WPML.
        if ($language === 'all') $language = apply_filters('wpml_default_language', null);
        if (!$language && function_exists('pll_current_language')) $language = pll_current_language('slug');
        $code = substr(strtolower(is_string($language) && $language !== '' ? $language : determine_locale()), 0, 2);
        return in_array($code, ['en','ru','de','uk','pl'], true) ? $code : 'en';
    }

    /** Standalone donation strings, following the saved request language. */
    public static function donation_text(string $key, ?string $language = null): string
    {
        return self::in_language(static fn(): string => match ($key) {
            'title' => __('Donation for the church', 'georg-kloster-orthodox-donations'),
            'submit' => __('Donate', 'georg-kloster-orthodox-donations'),
            'secure' => __('Secure payment. Your information is used only to process your donation.', 'georg-kloster-orthodox-donations'),
            'thanks' => __('Thank you. Your donation is confirmed.', 'georg-kloster-orthodox-donations'),
            'cancelled' => __('Payment was not completed. Your donation has not been received.', 'georg-kloster-orthodox-donations'),
            'subject' => __('Thank you for your donation', 'georg-kloster-orthodox-donations'),
            'amount_help' => __('Amount in EUR: 0.50 to 999,999.99. Up to two decimal places.', 'georg-kloster-orthodox-donations'),
            'invalid_amount' => __('Enter a valid amount between 0.50 and 999,999.99 EUR.', 'georg-kloster-orthodox-donations'),
            default => $key,
        }, $language ?? self::language());
    }

    public static function text(string $key, ?string $language = null): string
    {
        return self::in_language(static fn(): string => self::messages()[$key] ?? $key, $language ?? self::language());
    }

    /** Render a request in its saved language without changing the admin locale.
     * WordPress gettext remains the source API; bundled MO supports request
     * languages even when that WordPress core language pack is not installed.
     */
    public static function in_language(callable $render, string $language): string
    {
        if ($language === self::language()) return (string) $render();
        $locales = ['ru' => 'ru_RU', 'de' => 'de_DE', 'uk' => 'uk', 'pl' => 'pl_PL'];
        static $catalogs = [];
        if (!array_key_exists($language, $catalogs)) {
            $catalogs[$language] = null;
            if (isset($locales[$language])) {
                if (!class_exists('MO')) require_once ABSPATH . 'wp-includes/pomo/mo.php';
                $mo = new \MO();
                $paths=[];
                if (defined('WP_LANG_DIR')) $paths[]=WP_LANG_DIR.'/plugins/georg-kloster-orthodox-donations-'.$locales[$language].'.mo';
                $paths[]=__DIR__.'/../languages/georg-kloster-orthodox-donations-'.$locales[$language].'.mo';
                foreach ($paths as $path) if (is_readable($path) && $mo->import_from_file($path)) { $catalogs[$language]=$mo; break; }
            }
        }
        $catalog = $catalogs[$language];
        $filter = static function (string $translation, string $source, string $domain) use ($catalog): string {
            return $domain === 'georg-kloster-orthodox-donations' ? ($catalog ? $catalog->translate($source) : $source) : $translation;
        };
        add_filter('gettext', $filter, 20, 3);
        try { return (string) $render(); }
        finally { remove_filter('gettext', $filter, 20); }
    }

    private static function messages(): array
    {
        return [
            'health' => __('For the living', 'georg-kloster-orthodox-donations'),
            'rest' => __('For the departed', 'georg-kloster-orthodox-donations'),
            'choose' => __('Choose prayer services', 'georg-kloster-orthodox-donations'),
            'names' => __('Names, one person per line', 'georg-kloster-orthodox-donations'),
            'names_placeholder' => __("Name\nName\nName", 'georg-kloster-orthodox-donations'),
            'names_help' => __('Please use baptismal names. Prayer slips are automatically divided into groups.', 'georg-kloster-orthodox-donations'),
            'note' => __('Comment (optional)', 'georg-kloster-orthodox-donations'),
            'donor_name' => __('Your name (optional)', 'georg-kloster-orthodox-donations'),
            'email' => __('Email address', 'georg-kloster-orthodox-donations'),
            'email_help' => __('Confirmation is sent only after payment is received.', 'georg-kloster-orthodox-donations'),
            'provider' => __('Payment method', 'georg-kloster-orthodox-donations'),
            'stripe' => __('Card / Stripe', 'georg-kloster-orthodox-donations'),
            'paypal' => __('PayPal', 'georg-kloster-orthodox-donations'),
            'total' => __('Donation amount', 'georg-kloster-orthodox-donations'),
            'submit' => __('Submit prayer request and donate', 'georg-kloster-orthodox-donations'),
            'secure' => __('Secure payment. Your information is used only to process your prayer request.', 'georg-kloster-orthodox-donations'),
            'required' => __('Please complete all required fields.', 'georg-kloster-orthodox-donations'),
            'error' => __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations'),
            'thanks' => __('Thank you. Your payment is confirmed and your prayer request has been received.', 'georg-kloster-orthodox-donations'),
            'processing' => __('Thank you. Payment is still being confirmed. We will email you once the funds have arrived.', 'georg-kloster-orthodox-donations'),
            'cancelled' => __('Payment was not completed. Your prayer request is temporarily saved.', 'georg-kloster-orthodox-donations'),
            'success_title' => __('Payment successful', 'georg-kloster-orthodox-donations'),
            'processing_title' => __('Checking payment', 'georg-kloster-orthodox-donations'),
            'cancelled_title' => __('Payment not completed', 'georg-kloster-orthodox-donations'),
            'close' => __('Understood', 'georg-kloster-orthodox-donations'),
            'per_person' => __('per name', 'georg-kloster-orthodox-donations'),
            'per_sheet' => __('per 10 names', 'georg-kloster-orthodox-donations'),
            'mail_subject' => __('Your prayer request has been received', 'georg-kloster-orthodox-donations'),
            'greeting' => __('Hello', 'georg-kloster-orthodox-donations'),
            'service' => __('Prayer service', 'georg-kloster-orthodox-donations'),
            'request_type' => __('Request type', 'georg-kloster-orthodox-donations'),
            'names_label' => __('Names', 'georg-kloster-orthodox-donations'),
            'footer' => __('With gratitude and prayer,', 'georg-kloster-orthodox-donations'),
            'order_date' => __('Order date:', 'georg-kloster-orthodox-donations'),
            'prayer_type' => __('Prayer type', 'georg-kloster-orthodox-donations'),
            'payment_prefix' => __('Prayer request:', 'georg-kloster-orthodox-donations'),
            'payment_status' => __('Payment status:', 'georg-kloster-orthodox-donations'),
            'flat' => __('Fixed amount', 'georg-kloster-orthodox-donations'),
        ];
    }
}
