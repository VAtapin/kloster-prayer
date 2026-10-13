<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

/** Campaign records and public, anonymous financial summaries. */
final class Campaigns
{
    public static function init(): void
    {
        add_shortcode('gkod_campaigns', [self::class, 'shortcode']);
        add_shortcode('gkod_campaign_report', [self::class, 'report_shortcode']);
    }

    public static function get(int $id): ?object
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('campaigns'), $id));
    }

    public static function accepting(object $campaign): bool
    {
        return $campaign->status === 'open' && (!$campaign->deadline || $campaign->deadline >= wp_date('Y-m-d'));
    }

    public static function title(object $campaign, ?string $lang = null): string
    {
        $lang ??= I18n::language();
        foreach (array_unique([$lang,'en','ru','de','uk','pl']) as $code) if (!empty($campaign->{'title_'.$code})) return (string) $campaign->{'title_'.$code};
        return '';
    }

    public static function totals(int $id): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        $received = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_cents-refunded_cents),0) FROM %i WHERE campaign_id=%d AND payment_status='paid'", Database::table('orders'), $id));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        $spent = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM %i WHERE campaign_id=%d', Database::table('campaign_expenses'), $id));
        return ['received' => $received, 'spent' => $spent, 'balance' => $received - $spent];
    }

    public static function save(array $input): int|\WP_Error
    {
        if (!current_user_can('manage_options')) return new \WP_Error('forbidden', __('Forbidden', 'georg-kloster-orthodox-donate'));
        global $wpdb;
        $id = absint($input['campaign_id'] ?? 0);
        $existing = $id ? self::get($id) : null;
        if ($id && !$existing) return new \WP_Error('missing', __('Campaign not found.', 'georg-kloster-orthodox-donate'));
        $goal = Payments::donation_cents((string) ($input['goal'] ?? ''));
        $status = (string) ($input['status'] ?? 'draft');
        $deadline = (string) ($input['deadline'] ?? '');
        $date = $deadline !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $deadline) : null;
        if (!$goal || !in_array($status, ['draft', 'open', 'closed'], true) || ($deadline !== '' && (!$date || $date->format('Y-m-d') !== $deadline))) {
            return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donate'));
        }
        $data = ['goal_cents' => $goal, 'status' => $status, 'deadline' => $deadline ?: null, 'updated_at' => current_time('mysql')];
        foreach (['en', 'de', 'ru', 'uk', 'pl'] as $lang) {
            foreach (['title','description'] as $field) {
                $key=$field.'_'.$lang;
                if (!array_key_exists($key,$input) && $existing) continue;
                if (isset($input[$key]) && !is_scalar($input[$key])) return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donate'));
                $data[$key]=$field==='title' ? sanitize_text_field((string)($input[$key]??'')) : sanitize_textarea_field((string)($input[$key]??''));
            }
            if (isset($data['title_'.$lang]) && mb_strlen($data['title_'.$lang]) > 250) return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donate'));
        }
        $titles=[];
        foreach (['en','ru','de','uk','pl'] as $lang) $titles[]=$data['title_'.$lang]??($existing->{'title_'.$lang}??'');
        if (!array_filter($titles,static fn($title)=>$title!=='')) return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donate'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        if ($id) $ok = $wpdb->update(Database::table('campaigns'), $data, ['id' => $id]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        else { $data['created_at'] = current_time('mysql'); $ok = $wpdb->insert(Database::table('campaigns'), $data); $id = (int) $wpdb->insert_id; }
        return $ok === false ? new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donate')) : $id;
    }

    public static function expense(array $input): bool|\WP_Error
    {
        if (!current_user_can('manage_options')) return new \WP_Error('forbidden', __('Forbidden', 'georg-kloster-orthodox-donate'));
        global $wpdb;
        $id = absint($input['campaign_id'] ?? 0);
        $amount = Payments::donation_cents((string) ($input['expense_amount'] ?? ''), 1);
        $description = sanitize_text_field((string) ($input['expense_description'] ?? ''));
        $date = (string) ($input['expense_date'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!self::get($id) || !$amount || $description === '' || strlen($description) > 250 || !$parsed || $parsed->format('Y-m-d') !== $date) {
            return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donate'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        return $wpdb->insert(Database::table('campaign_expenses'), ['campaign_id' => $id, 'amount_cents' => $amount, 'description' => $description, 'incurred_on' => $date, 'user_id' => get_current_user_id(), 'created_at' => current_time('mysql')]) !== false;
    }

    public static function summary(object $campaign): string
    {
        $totals = self::totals((int) $campaign->id);
        $rows = [__('Goal', 'georg-kloster-orthodox-donate') => (int) $campaign->goal_cents, __('Donations received', 'georg-kloster-orthodox-donate') => $totals['received'], __('Expenses', 'georg-kloster-orthodox-donate') => $totals['spent'], __('Balance', 'georg-kloster-orthodox-donate') => $totals['balance']];
        $html = '<dl class="gkod-campaign-summary">';
        foreach ($rows as $label => $cents) $html .= '<dt>' . esc_html($label) . '</dt><dd>' . esc_html(number_format_i18n($cents / 100, 2) . ' EUR') . '</dd>';
        $ratio = min(100, max(0, (int) floor($totals['received'] * 100 / max(1, (int) $campaign->goal_cents))));
        return $html . '</dl><progress max="100" value="' . $ratio . '" aria-label="' . esc_attr__('Campaign progress', 'georg-kloster-orthodox-donate') . '">' . $ratio . '%</progress>';
    }

    public static function report_shortcode(array|string $atts = []): string
    {
        $atts = shortcode_atts(['id' => 0, 'lang' => ''], $atts, 'gkod_campaign_report');
        $campaign = self::get(absint($atts['id']));
        if (!$campaign || !in_array($campaign->status, ['open', 'closed'], true)) return '';
        $lang = in_array($atts['lang'], ['en','ru','de','uk','pl'], true) ? $atts['lang'] : I18n::language();
        return I18n::in_language(static fn(): string => '<section class="gkod-campaign"><h2>' . esc_html(self::title($campaign, $lang)) . '</h2>' . self::summary($campaign) . '</section>', $lang);
    }

    public static function shortcode(array|string $atts = []): string
    {
        global $wpdb;
        $atts = shortcode_atts(['id' => 0, 'lang' => ''], $atts, 'gkod_campaigns');
        $lang = in_array($atts['lang'], ['en','ru','de','uk','pl'], true) ? $atts['lang'] : I18n::language();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        $rows = $atts['id'] ? array_filter([self::get(absint($atts['id']))]) : $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE status IN ('open','closed') ORDER BY id DESC LIMIT 100", Database::table('campaigns')));
        return I18n::in_language(static function () use ($rows, $lang): string {
            $html = '<div class="gkod-campaigns">';
            foreach ($rows as $campaign) {
                if (!in_array($campaign->status, ['open', 'closed'], true)) continue;
                $description = $campaign->{'description_' . $lang} ?: $campaign->description_en;
                $html .= '<section class="gkod-campaign"><h2>' . esc_html(self::title($campaign, $lang)) . '</h2><p>' . nl2br(esc_html($description)) . '</p>' . self::summary($campaign);
                if ($campaign->deadline) $html .= '<p>' . esc_html__('Deadline', 'georg-kloster-orthodox-donate') . ': ' . esc_html($campaign->deadline) . '</p>';
                $html .= self::accepting($campaign) ? Public_Form::donation_shortcode(['campaign_id' => (int) $campaign->id, 'lang' => $lang, 'header' => 'no']) : '<p>' . esc_html__('This campaign is closed.', 'georg-kloster-orthodox-donate') . '</p>';
                $html .= '</section>';
            }
            return $html . '</div>';
        }, $lang);
    }
}
