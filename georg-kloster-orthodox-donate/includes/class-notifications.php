<?php
declare(strict_types=1);

namespace KlosterPrayer;

/** Separate outbox for the site's designated prayer-request recipient. */
final class Notifications
{
    public static function validate_recipient(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && (strlen($value) > 190 || !is_email($value) || preg_match('/[\r\n]/', $value))) {
            throw new \InvalidArgumentException('Invalid notification email');
        }
        return $value;
    }

    public static function recipient(): string
    {
        try { return self::validate_recipient((string) get_option('gkod_notification_email', '')); }
        catch (\InvalidArgumentException $e) { return ''; }
    }

    /** Called only on a new transition to paid: never backfill historical orders. */
    public static function enqueue(int $order_id): void
    {
        if ($order_id <= 0 || self::recipient() === '') return;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO %i (order_id,created_at) VALUES (%d,%s)', Database::table('notifications'),
            $order_id, gmdate('Y-m-d H:i:s')
        ));
    }

    public static function retry(): void
    {
        if (self::recipient() === '') return;
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT order_id FROM %i WHERE sent_at IS NULL AND (locked_at IS NULL OR locked_at < %s) AND (last_attempt_at IS NULL OR last_attempt_at < %s) ORDER BY COALESCE(last_attempt_at,created_at),order_id LIMIT 25', Database::table('notifications'),
            gmdate('Y-m-d H:i:s', time() - 900), gmdate('Y-m-d H:i:s', time() - 3600)
        ));
        foreach ($ids as $id) self::send((int) $id);
    }

    public static function send(int $order_id): bool
    {
        $recipient = self::recipient();
        if ($recipient === '') return false;
        global $wpdb;
        $table = Database::table('notifications');
        $now = gmdate('Y-m-d H:i:s');
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET locked_at=%s,last_attempt_at=%s,attempts=attempts+1 WHERE order_id=%d AND sent_at IS NULL AND (locked_at IS NULL OR locked_at < %s)", $table,
            $now, $now, $order_id, gmdate('Y-m-d H:i:s', time() - 900)
        ));
        if (!$claimed) return false;
        $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('orders'), $order_id));
        if (!$order || $order->payment_status !== 'paid') {
            $wpdb->update($table, ['locked_at' => null], ['order_id' => $order_id]);
            return false;
        }
        $titles = $wpdb->get_col($wpdb->prepare('SELECT service_title FROM %i WHERE order_id=%d', Database::table('order_items'), $order_id));
        /* translators: %d: prayer order ID. */
        $subject = sprintf(__('Prayer request #%d paid', 'georg-kloster-orthodox-donate'), $order_id);
        if (($order->intention ?? '') === 'none') $subject = I18n::donation_text('subject') . ' #' . $order_id;
        $fields = [
            __('Order date', 'georg-kloster-orthodox-donate') => $order->created_at,
            __('Services', 'georg-kloster-orthodox-donate') => implode(', ', $titles),
            __('Request type', 'georg-kloster-orthodox-donate') => $order->intention === 'rest' ? __('For the departed', 'georg-kloster-orthodox-donate') : __('For the living', 'georg-kloster-orthodox-donate'),
            __('Donor', 'georg-kloster-orthodox-donate') => $order->donor_name ?: '—',
            __('Donor email', 'georg-kloster-orthodox-donate') => $order->donor_email,
            __('Amount', 'georg-kloster-orthodox-donate') => number_format_i18n($order->amount_cents / 100, 2) . ' ' . $order->currency,
            __('Payment method', 'georg-kloster-orthodox-donate') => ucfirst($order->payment_provider),
        ];
        if (($order->intention ?? '') === 'none') {
            $fields = [
                I18n::donation_text('title') => '#' . $order_id,
                I18n::text('donor_name') => $order->donor_name ?: '—',
                I18n::text('email') => $order->donor_email,
                I18n::text('total') => number_format_i18n($order->amount_cents / 100, 2) . ' ' . $order->currency,
                I18n::text('provider') => ucfirst($order->payment_provider),
            ];
        }
        $rows = '';
        foreach ($fields as $label => $value) $rows .= '<tr><th align="left" style="padding:8px;border-bottom:1px solid #ddd">' . esc_html($label) . '</th><td style="padding:8px;border-bottom:1px solid #ddd">' . esc_html((string) $value) . '</td></tr>';
        $url = add_query_arg(['page' => 'georg-kloster-orthodox-donate', 'view' => $order_id], admin_url('admin.php'));
        $body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#222"><div style="max-width:640px;margin:auto;padding:24px;border-top:4px solid #8c2633"><p>' . esc_html(get_bloginfo('name')) . '</p><h1 style="font-size:24px">' . esc_html($subject) . '</h1><table style="width:100%;border-collapse:collapse">' . $rows . '</table><h2 style="font-size:18px">' . esc_html__('Names', 'georg-kloster-orthodox-donate') . '</h2><p>' . nl2br(esc_html(implode("\n", Payments::split_names((string) $order->names)))) . '</p>';
        if (($order->intention ?? '') === 'none') $body = substr($body, 0, strpos($body, '<h2'));
        if ($order->note !== '') $body .= '<h2 style="font-size:18px">' . esc_html__('Note', 'georg-kloster-orthodox-donate') . '</h2><p>' . nl2br(esc_html($order->note)) . '</p>';
        $body .= '<p><a href="' . esc_url($url) . '">' . esc_html__('Open request in WordPress', 'georg-kloster-orthodox-donate') . '</a></p></div></body></html>';
        try { $sent = wp_mail($recipient, $subject, $body, ['Content-Type: text/html; charset=UTF-8']); }
        catch (\Throwable $e) { $sent = false; }
        $wpdb->update($table, ['sent_at' => $sent ? $now : null, 'locked_at' => null], ['order_id' => $order_id]);
        Order_Workflow::log($order_id, $sent ? 'notification_sent' : 'notification_failed', $sent
            ? __('The recipient notification was handed to the mail system.', 'georg-kloster-orthodox-donate')
            : __('Recipient notification failed. It will be retried.', 'georg-kloster-orthodox-donate'));
        return $sent;
    }
}
