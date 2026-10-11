<?php
declare(strict_types=1);
namespace KlosterPrayer;

final class I18n
{
    public static function init(): void
    {
        load_plugin_textdomain('kloster-prayer', false, dirname(plugin_basename(FILE)) . '/languages');
    }

    public static function language(): string
    {
        $code = substr(strtolower(determine_locale()), 0, 2);
        return in_array($code, ['ru', 'de', 'uk', 'pl'], true) ? $code : 'en';
    }

    /** Standalone donation strings, following the saved request language. */
    public static function donation_text(string $key, ?string $language = null): string
    {
        return self::in_language(static fn(): string => match ($key) {
            'title' => __('Donation for the church', 'kloster-prayer'),
            'submit' => __('Donate', 'kloster-prayer'),
            'secure' => __('Secure payment. Your information is used only to process your donation.', 'kloster-prayer'),
            'thanks' => __('Thank you. Your donation is confirmed.', 'kloster-prayer'),
            'cancelled' => __('Payment was not completed. Your donation has not been received.', 'kloster-prayer'),
            'subject' => __('Thank you for your donation', 'kloster-prayer'),
            'amount_help' => __('Amount in EUR: 0.50 to 999,999.99. Up to two decimal places.', 'kloster-prayer'),
            'invalid_amount' => __('Enter a valid amount between 0.50 and 999,999.99 EUR.', 'kloster-prayer'),
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
                if ($mo->import_from_file(__DIR__ . '/../languages/kloster-prayer-' . $locales[$language] . '.mo')) $catalogs[$language] = $mo;
            }
        }
        $catalog = $catalogs[$language];
        $filter = static function (string $translation, string $source, string $domain) use ($catalog): string {
            return $domain === 'kloster-prayer' ? ($catalog ? $catalog->translate($source) : $source) : $translation;
        };
        add_filter('gettext', $filter, 20, 3);
        try { return (string) $render(); }
        finally { remove_filter('gettext', $filter, 20); }
    }

    private static function messages(): array
    {
        return [
            'health' => __('For the living', 'kloster-prayer'),
            'rest' => __('For the departed', 'kloster-prayer'),
            'choose' => __('Choose prayer services', 'kloster-prayer'),
            'names' => __('Names, one person per line', 'kloster-prayer'),
            'names_placeholder' => __("Name\nName\nName", 'kloster-prayer'),
            'names_help' => __('Please use baptismal names. Prayer slips are automatically divided into groups.', 'kloster-prayer'),
            'note' => __('Comment (optional)', 'kloster-prayer'),
            'donor_name' => __('Your name (optional)', 'kloster-prayer'),
            'email' => __('Email address', 'kloster-prayer'),
            'email_help' => __('Confirmation is sent only after payment is received.', 'kloster-prayer'),
            'provider' => __('Payment method', 'kloster-prayer'),
            'stripe' => __('Card / Stripe', 'kloster-prayer'),
            'paypal' => __('PayPal', 'kloster-prayer'),
            'total' => __('Donation amount', 'kloster-prayer'),
            'submit' => __('Submit prayer request and donate', 'kloster-prayer'),
            'secure' => __('Secure payment. Your information is used only to process your prayer request.', 'kloster-prayer'),
            'required' => __('Please complete all required fields.', 'kloster-prayer'),
            'error' => __('The request could not be processed. Please try again.', 'kloster-prayer'),
            'thanks' => __('Thank you. Your payment is confirmed and your prayer request has been received.', 'kloster-prayer'),
            'processing' => __('Thank you. Payment is still being confirmed. We will email you once the funds have arrived.', 'kloster-prayer'),
            'cancelled' => __('Payment was not completed. Your prayer request is temporarily saved.', 'kloster-prayer'),
            'success_title' => __('Payment successful', 'kloster-prayer'),
            'processing_title' => __('Checking payment', 'kloster-prayer'),
            'cancelled_title' => __('Payment not completed', 'kloster-prayer'),
            'close' => __('Understood', 'kloster-prayer'),
            'per_person' => __('per name', 'kloster-prayer'),
            'per_sheet' => __('per 10 names', 'kloster-prayer'),
            'mail_subject' => __('Your prayer request has been received', 'kloster-prayer'),
            'greeting' => __('Hello', 'kloster-prayer'),
            'service' => __('Prayer service', 'kloster-prayer'),
            'request_type' => __('Request type', 'kloster-prayer'),
            'names_label' => __('Names', 'kloster-prayer'),
            'footer' => __('With gratitude and prayer,', 'kloster-prayer'),
            'order_date' => __('Order date:', 'kloster-prayer'),
            'prayer_type' => __('Prayer type', 'kloster-prayer'),
            'payment_prefix' => __('Prayer request:', 'kloster-prayer'),
            'payment_status' => __('Payment status:', 'kloster-prayer'),
            'flat' => __('Fixed amount', 'kloster-prayer'),
        ];
    }
}
