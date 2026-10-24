<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Generates and sends an individual thank-you letter only after an admin action. */
final class Thank_You
{
    public static function send_order(int $order_id, string $template_id = ''): bool|\WP_Error
    {
        return self::send_order_to($order_id, '', true, $template_id);
    }

    public static function send_order_to(int $order_id, string $recipient_override, bool $log, string $template_id = ''): bool|\WP_Error
    {
        $pdf = self::generate_order($order_id, $template_id);
        if (is_wp_error($pdf)) return $pdf;
        return self::email_pdf($pdf, $order_id, $recipient_override, $log);
    }

    public static function send_test_copy(int $order_id, string $recipient, string $template_id = ''): bool|\WP_Error
    {
        $pdf = $order_id > 0 ? self::generate_order($order_id, $template_id) : self::generate_sample($template_id);
        if (is_wp_error($pdf)) return $pdf;
        return self::email_pdf($pdf, $order_id, $recipient, false);
    }

    private static function email_pdf(string $pdf, int $order_id, string $recipient_override, bool $log): bool|\WP_Error
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The mail action must check the current order payment state and recipient.
        $order = $order_id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'), $order_id)) : null;
        if ($order_id > 0 && (!$order || $order->payment_status !== 'paid' || !is_email((string) $order->donor_email))) return new \WP_Error('donation_unavailable', __('A thank-you letter is available only for paid donations with an email address.', 'georg-kloster-orthodox-donations'));
        $language = $order && in_array($order->language, ['en', 'de', 'ru', 'uk', 'pl'], true) ? $order->language : I18n::content_language();
        $temporary = wp_tempnam('gkod-thank-you.pdf');
        $path = $temporary ? preg_replace('/\.tmp$/i', '', $temporary) . '.pdf' : '';
        $handle = $path !== '' ? fopen($path, 'xb') : false;
        $written = $handle && fwrite($handle, $pdf) === strlen($pdf);
        if (is_resource($handle)) fclose($handle);
        if ($temporary && file_exists($temporary)) wp_delete_file($temporary);
        if (!$written) {
            if ($path && file_exists($path)) wp_delete_file($path);
            return new \WP_Error('temporary_file', __('Could not prepare the thank-you PDF attachment.', 'georg-kloster-orthodox-donations'));
        }
        $subject = I18n::in_language(static fn() => __('Thank you for supporting our monastery', 'georg-kloster-orthodox-donations'), $language);
        $donor_name = $order ? sanitize_text_field((string) ($order->billing_name ?: $order->donor_name)) : '';
        $greeting = $donor_name !== ''
            ? sprintf(I18n::in_language(static fn() => __('Hello, %s!', 'georg-kloster-orthodox-donations'), $language), $donor_name)
            : I18n::in_language(static fn() => __('Hello!', 'georg-kloster-orthodox-donations'), $language);
        $message = $greeting . "\n\n" . I18n::in_language(static fn() => __("Thank you for your donation and for supporting our monastery. Your kindness helps sustain our community and its prayerful work.\n\nPlease find your personal letter of gratitude attached as a PDF.\n\nWith gratitude,\nThe monastery brotherhood", 'georg-kloster-orthodox-donations'), $language);
        $recipient_to = $recipient_override !== '' ? $recipient_override : (string) ($order->donor_email ?? '');
        if (!is_email($recipient_to)) { wp_delete_file($path); return new \WP_Error('email', __('The recipient email address is invalid.', 'georg-kloster-orthodox-donations')); }
        try {
            $sent = wp_mail($recipient_to, $subject, $message, ['Content-Type: text/plain; charset=UTF-8'], [$path]);
        } finally {
            wp_delete_file($path);
        }
        if (!$sent) return new \WP_Error('mail', __('The thank-you email could not be sent. Please try again.', 'georg-kloster-orthodox-donations'));
        if ($log) Order_Workflow::log($order_id, 'thank_you_sent', __('A thank-you letter was emailed to the donor.', 'georg-kloster-orthodox-donations'));
        return true;
    }

    public static function generate_order(int $order_id, string $template_id = ''): string|\WP_Error
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Generate preview only for an existing paid order.
        $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'), $order_id));
        if (!$order || $order->payment_status !== 'paid' || !is_email((string) $order->donor_email)) return new \WP_Error('donation_unavailable', __('A thank-you letter is available only for paid donations with an email address.', 'georg-kloster-orthodox-donations'));
        return self::render($order, $template_id);
    }

    public static function generate_sample(string $template_id = ''): string|\WP_Error
    {
        $order = (object) ['id' => 0, 'language' => I18n::content_language(), 'billing_name' => __('Alexey Donor', 'georg-kloster-orthodox-donations'), 'donor_name' => '', 'amount_cents' => 4000, 'currency' => 'EUR', 'paid_at' => current_time('mysql'), 'created_at' => current_time('mysql'), 'campaign_id' => 0, 'intention' => 'none', 'donor_email' => 'sample@example.invalid'];
        return self::render($order, $template_id);
    }

    private static function render(object $order, string $template_id = ''): string|\WP_Error
    {
        if (!class_exists(Dompdf::class)) require_once DIR . '/includes/lib/dompdf/autoload.inc.php';
        $language = in_array($order->language, ['en','de','ru','uk','pl'], true) ? $order->language : 'en';
        $recipient = trim((string) ($order->billing_name ?: $order->donor_name));
        if ($recipient === '') $recipient = __('Dear donor', 'georg-kloster-orthodox-donations');
        try {
            $html = self::letter_html($order, $recipient, $language, $template_id);
        } catch (\Throwable $error) {
            return new \WP_Error('background', $error->getMessage());
        }
        try {
            $options = new Options(); $options->set('isRemoteEnabled', false); $options->set('defaultFont', 'DejaVu Sans');
            $upload = wp_upload_dir();
            $options->setChroot(array_filter([DIR, $upload['basedir'] ?? '']));
            $dompdf = new Dompdf($options);
            $config = Thank_You_Template_Admin::config($order, $template_id);
            $dompdf->setPaper('A4', $config['orientation'] === 'landscape' ? 'landscape' : 'portrait');
            $dompdf->loadHtml($html, 'UTF-8'); $dompdf->render();
            $pdf = $dompdf->output();
            return str_starts_with($pdf, '%PDF-') ? $pdf : new \WP_Error('pdf', __('Could not generate the thank-you PDF.', 'georg-kloster-orthodox-donations'));
        } catch (\Throwable) {
            return new \WP_Error('pdf', __('Could not generate the thank-you PDF.', 'georg-kloster-orthodox-donations'));
        }
    }

    public static function background_preview_url(int $attachment_id): string
    {
        $path = get_attached_file($attachment_id);
        if (!$path || !is_file($path) || !class_exists('Imagick')) return '';
        try {
            $image = new \Imagick(); $image->setResolution(120, 120); $image->readImage($path . '[0]'); $image->setImageFormat('png');
            $upload = wp_upload_dir(); $dir = trailingslashit($upload['basedir']) . 'gkod-private-preview';
            if (!wp_mkdir_p($dir)) return '';
            $file = $dir . '/thank-you-' . $attachment_id . '-' . substr(md5((string) filemtime($path)), 0, 10) . '.png';
            if (!$image->writeImage($file)) return '';
            return trailingslashit($upload['baseurl']) . 'gkod-private-preview/' . basename($file);
        } catch (\Throwable) { return ''; }
    }

    private static function letter_html(object $order, string $recipient, string $language, string $template_id = ''): string
    {
        $config = Thank_You_Template_Admin::config($order, $template_id);
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $date_format = get_option('date_format', 'j F Y');
        $date_value = (string) ($order->paid_at ?: $order->created_at);
        $date = mysql2date($date_format, $date_value) ?: wp_date($date_format);
        $amount = number_format_i18n((int) $order->amount_cents / 100, 2) . ' ' . $order->currency;
        $campaign = $order->campaign_id ? Campaigns::get((int) $order->campaign_id) : null;
        $purpose = $campaign ? Campaigns::title($campaign, $language) : '';
        if ($purpose === '' && $order->intention !== 'none') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the existing order item names for the individual letter.
            $purpose = implode(', ', $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare('SELECT service_title FROM %i WHERE order_id=%d ORDER BY id', Database::table('order_items'), (int) $order->id)));
        }
        if ($purpose === '') $purpose = I18n::donation_text('title', $language);
        $values = ['name' => $recipient, 'body' => (string) $config['body_text'], 'quote' => (string) $config['quote_text'], 'extra' => (string) $config['extra_text'], 'donation' => $purpose, 'date' => $date, 'amount' => $amount, 'signer' => (string) $config['signer_text'], 'address' => (string) $config['address_text']];
        $template = $config['attachment_id'] ? get_attached_file((int) $config['attachment_id']) : '';
        $mime = $template ? (string) get_post_mime_type((int) $config['attachment_id']) : '';
        $background = '';
        if ($template && $mime === 'application/pdf') {
            if (!class_exists('Imagick')) throw new \RuntimeException(__('This server needs ImageMagick with PDF support to use a PDF letter background. Use an image background or enable PDF conversion.', 'georg-kloster-orthodox-donations'));
            $background = self::pdf_background_image($template);
        } elseif ($template && in_array($mime, ['image/jpeg', 'image/png'], true)) $background = $template;
        $parts = [];
        foreach ($config['fields'] as $key => $field) {
            $value = (string) ($values[$key] ?? '');
            if (empty($field['enabled']) || trim($value) === '') continue;
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $formatted = implode('<br>', array_map('esc_html', explode("\n", $value)));
            $parts[] = '<div style="position:absolute;left:' . esc_attr((string) $field['x']) . '%;top:' . esc_attr((string) $field['y']) . '%;width:' . esc_attr((string) $field['width']) . '%;transform:translate(-50%,-50%);font-size:' . esc_attr((string) $field['size']) . 'pt;color:' . esc_attr((string) $field['color']) . ';text-align:' . esc_attr((string) $field['align']) . ';white-space:normal">' . $formatted . '</div>';
        }
        $bg = $background !== '' ? 'background-image:url("file://' . esc_attr(str_replace('\\', '/', $background)) . '");background-size:100% 100%;' : '';
        $height = $config['orientation'] === 'landscape' ? '210mm' : '297mm'; $width = $config['orientation'] === 'landscape' ? '297mm' : '210mm';
        return '<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A4 ' . esc_attr((string) $config['orientation']) . ';margin:0}html,body{margin:0;padding:0}body{font-family:"DejaVu Sans",sans-serif;color:#302b25}.letter{position:relative;width:' . $width . ';height:' . $height . ';overflow:hidden;' . $bg . 'background-repeat:no-repeat}.field{position:absolute}</style></head><body><div class="letter">' . implode('', $parts) . '</div></body></html>';
    }

    private static function pdf_background_image(string $pdf_path): string
    {
        $info = pathinfo($pdf_path); $target = trailingslashit(dirname($pdf_path)) . $info['filename'] . '-gkod-letter.png';
        if (is_file($target) && filemtime($target) >= filemtime($pdf_path)) return $target;
        $image = new \Imagick(); $image->setResolution(150, 150); $image->readImage($pdf_path . '[0]'); $image->setImageFormat('png');
        if (!$image->writeImage($target)) throw new \RuntimeException(__('Could not convert the PDF letter background to an image.', 'georg-kloster-orthodox-donations'));
        return $target;
    }
}
