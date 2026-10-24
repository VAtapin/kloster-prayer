<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Generates and sends an individual thank-you letter only after an admin action. */
final class Thank_You
{
    public static function send_order(int $order_id): bool|\WP_Error
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The mail action must check the current order payment state and recipient.
        $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'), $order_id));
        if (!$order || $order->payment_status !== 'paid' || !is_email((string) $order->donor_email)) return new \WP_Error('donation_unavailable', __('A thank-you letter is available only for paid donations with an email address.', 'georg-kloster-orthodox-donations'));
        if (!class_exists(Dompdf::class)) require_once DIR . '/includes/lib/dompdf/autoload.inc.php';
        $language = in_array($order->language, ['en','de','ru','uk','pl'], true) ? $order->language : 'en';
        $recipient = trim((string) ($order->billing_name ?: $order->donor_name));
        if ($recipient === '') $recipient = __('Dear donor', 'georg-kloster-orthodox-donations');
        // The visual PDF can later be replaced with the monastery's supplied artwork; all variable donation data is already isolated here.
        $html = self::letter_html($order, $recipient, $language);
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot(DIR);
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $pdf = $dompdf->output();
        if (!str_starts_with($pdf, '%PDF-')) return new \WP_Error('pdf', __('Could not generate the thank-you PDF.', 'georg-kloster-orthodox-donations'));
        $path = wp_tempnam('gkod-thank-you.pdf');
        if (!$path || file_put_contents($path, $pdf) !== strlen($pdf)) {
            if ($path && file_exists($path)) wp_delete_file($path);
            return new \WP_Error('temporary_file', __('Could not prepare the thank-you PDF attachment.', 'georg-kloster-orthodox-donations'));
        }
        $subject = I18n::in_language(static fn() => __('Thank you for your donation', 'georg-kloster-orthodox-donations'), $language);
        $message = I18n::in_language(static fn() => __('We sincerely thank you for supporting the monastery. Your personal thank-you letter is attached as a PDF.', 'georg-kloster-orthodox-donations'), $language);
        try {
            $sent = wp_mail((string) $order->donor_email, $subject, $message, ['Content-Type: text/plain; charset=UTF-8'], [$path]);
        } finally {
            wp_delete_file($path);
        }
        if (!$sent) return new \WP_Error('mail', __('The thank-you email could not be sent. Please try again.', 'georg-kloster-orthodox-donations'));
        Order_Workflow::log($order_id, 'thank_you_sent', __('A thank-you letter was emailed to the donor.', 'georg-kloster-orthodox-donations'));
        return true;
    }

    private static function letter_html(object $order, string $recipient, string $language): string
    {
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $date = wp_date(get_option('date_format', 'j F Y'), strtotime((string) ($order->paid_at ?: $order->created_at)));
        $amount = number_format_i18n((int) $order->amount_cents / 100, 2) . ' ' . $order->currency;
        $campaign = $order->campaign_id ? Campaigns::get((int) $order->campaign_id) : null;
        $purpose = $campaign ? Campaigns::title($campaign, $language) : '';
        if ($purpose === '' && $order->intention !== 'none') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the existing order item names for the individual letter.
            $purpose = implode(', ', $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare('SELECT service_title FROM %i WHERE order_id=%d ORDER BY id', Database::table('order_items'), (int) $order->id)));
        }
        if ($purpose === '') $purpose = I18n::donation_text('title', $language);
        $labels = [
            'title' => I18n::in_language(static fn() => __('With gratitude for your donation', 'georg-kloster-orthodox-donations'), $language),
            'greeting' => I18n::in_language(static fn() => __('Dear %s,', 'georg-kloster-orthodox-donations'), $language),
            'body' => I18n::in_language(static fn() => __('Thank you for your generous support of the monastery. Your gift helps sustain our prayer and service.', 'georg-kloster-orthodox-donations'), $language),
            'donation' => I18n::in_language(static fn() => __('Donation', 'georg-kloster-orthodox-donations'), $language),
            'date' => I18n::in_language(static fn() => __('Date', 'georg-kloster-orthodox-donations'), $language),
            'amount' => I18n::in_language(static fn() => __('Amount', 'georg-kloster-orthodox-donations'), $language),
            'closing' => I18n::in_language(static fn() => __('With gratitude and prayer,', 'georg-kloster-orthodox-donations'), $language),
        ];
        $greeting = sprintf($labels['greeting'], $recipient);
        return '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:25mm 24mm}body{font-family:"DejaVu Sans",sans-serif;color:#302b25;font-size:12pt;line-height:1.7}.ornament{color:#9b3e38;text-align:center;font-size:18pt;letter-spacing:4px;margin:0 0 12mm}h1{font-family:serif;font-weight:400;text-align:center;color:#6f302c;font-size:24pt;margin:0 0 14mm}p{margin:0 0 8mm}.details{margin:12mm 0;padding:8mm 10mm;border-top:1px solid #c8a27a;border-bottom:1px solid #c8a27a}.details div{margin:2mm 0}.closing{margin-top:18mm}.site{font-family:serif;font-size:15pt;color:#6f302c}</style></head><body><p class="ornament">✥ &nbsp; ❧ &nbsp; ✥</p><h1>' . esc_html($labels['title']) . '</h1><p>' . esc_html($greeting) . '</p><p>' . esc_html($labels['body']) . '</p><div class="details"><div><strong>' . esc_html($labels['donation']) . ':</strong> ' . esc_html($purpose) . '</div><div><strong>' . esc_html($labels['date']) . ':</strong> ' . esc_html($date) . '</div><div><strong>' . esc_html($labels['amount']) . ':</strong> ' . esc_html($amount) . '</div></div><p class="closing">' . esc_html($labels['closing']) . '<br><span class="site">' . esc_html($site) . '</span></p></body></html>';
    }
}
