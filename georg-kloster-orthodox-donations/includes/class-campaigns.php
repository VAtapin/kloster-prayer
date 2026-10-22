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
        if (!current_user_can('manage_options')) return new \WP_Error('forbidden', __('Forbidden', 'georg-kloster-orthodox-donations'));
        global $wpdb;
        $id = absint($input['campaign_id'] ?? 0);
        $existing = $id ? self::get($id) : null;
        if ($id && !$existing) return new \WP_Error('missing', __('Campaign not found.', 'georg-kloster-orthodox-donations'));
        $goal = Payments::donation_cents((string) ($input['goal'] ?? ''));
        $minimum = Payments::donation_cents((string) ($input['minimum'] ?? number_format(max(1000, $existing->minimum_cents ?? 1000) / 100, 2, '.', '')));
        $image_id = absint($input['image_id'] ?? ($existing->image_id ?? 0));
        if (!current_user_can('upload_files')) $image_id = (int) ($existing->image_id ?? 0);
        elseif ($image_id && !wp_attachment_is_image($image_id)) $image_id = 0;
        $status = (string) ($input['status'] ?? 'draft');
        $deadline = (string) ($input['deadline'] ?? '');
        $date = $deadline !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $deadline) : null;
        $presets = [];
        foreach ([1, 2, 3] as $index) $presets[$index] = Payments::donation_cents((string) ($input['preset_' . $index] ?? number_format(($existing->{'preset_' . $index . '_cents'} ?? [1 => 1000, 2 => 2000, 3 => 10000][$index]) / 100, 2, '.', '')));
        if (!$goal || !$minimum || $minimum < 100 || $minimum > 99999999 || in_array(null, $presets, true) || min($presets) < $minimum
            || !in_array($status, ['draft', 'open', 'closed'], true) || ($deadline !== '' && (!$date || $date->format('Y-m-d') !== $deadline))) {
            return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donations'));
        }
        $data = ['goal_cents' => $goal, 'image_id' => $image_id, 'minimum_cents' => $minimum, 'preset_1_cents' => $presets[1], 'preset_2_cents' => $presets[2], 'preset_3_cents' => $presets[3], 'status' => $status, 'deadline' => $deadline ?: null, 'updated_at' => current_time('mysql')];
        foreach (['en', 'de', 'ru', 'uk', 'pl'] as $lang) {
            foreach (['title','description'] as $field) {
                $key=$field.'_'.$lang;
                if (!array_key_exists($key,$input) && $existing) continue;
                if (isset($input[$key]) && !is_scalar($input[$key])) return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donations'));
                $data[$key]=$field==='title' ? sanitize_text_field((string)($input[$key]??'')) : wp_kses_post((string)($input[$key]??''));
            }
            if (isset($data['title_'.$lang]) && mb_strlen($data['title_'.$lang]) > 250) return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donations'));
        }
        $titles=[];
        foreach (['en','ru','de','uk','pl'] as $lang) $titles[]=$data['title_'.$lang]??($existing->{'title_'.$lang}??'');
        if (!array_filter($titles,static fn($title)=>$title!=='')) return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donations'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        if ($id) $ok = $wpdb->update(Database::table('campaigns'), $data, ['id' => $id]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        else { $data['created_at'] = current_time('mysql'); $ok = $wpdb->insert(Database::table('campaigns'), $data); $id = (int) $wpdb->insert_id; }
        return $ok === false ? new \WP_Error('database', __('The request could not be processed. Please try again.', 'georg-kloster-orthodox-donations')) : $id;
    }

    public static function expense(array $input): bool|\WP_Error
    {
        if (!current_user_can('manage_options')) return new \WP_Error('forbidden', __('Forbidden', 'georg-kloster-orthodox-donations'));
        global $wpdb;
        $id = absint($input['campaign_id'] ?? 0);
        $amount = Payments::donation_cents((string) ($input['expense_amount'] ?? ''), 1);
        $description = sanitize_text_field((string) ($input['expense_description'] ?? ''));
        $date = (string) ($input['expense_date'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!self::get($id) || !$amount || $description === '' || strlen($description) > 250 || !$parsed || $parsed->format('Y-m-d') !== $date) {
            return new \WP_Error('invalid', __('Please complete all required fields.', 'georg-kloster-orthodox-donations'));
        }
        $expense_id = absint($input['expense_id'] ?? 0);
        if ($expense_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the selected expense belongs to this campaign before editing.
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id=%d AND campaign_id=%d', Database::table('campaign_expenses'), $expense_id, $id));
            if (!$exists) return new \WP_Error('missing', __('The expense could not be found.', 'georg-kloster-orthodox-donations'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Update one validated record in the custom expenses ledger.
            return $wpdb->update(Database::table('campaign_expenses'), ['amount_cents'=>$amount,'description'=>$description,'incurred_on'=>$date], ['id'=>$expense_id,'campaign_id'=>$id]) !== false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom expense ledger has no post/meta equivalent.
        return $wpdb->insert(Database::table('campaign_expenses'), ['campaign_id' => $id, 'amount_cents' => $amount, 'description' => $description, 'incurred_on' => $date, 'user_id' => get_current_user_id(), 'created_at' => current_time('mysql')]) !== false;
    }

    public static function delete_expense(int $campaign_id, int $expense_id): bool
    {
        if (!current_user_can('manage_options') || $campaign_id < 1 || $expense_id < 1) return false;
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Delete only the explicitly selected expense for this campaign.
        return $wpdb->delete(Database::table('campaign_expenses'), ['id'=>$expense_id,'campaign_id'=>$campaign_id], ['%d','%d']) === 1;
    }

    public static function expense_list(int $campaign_id): string
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Public campaign transparency requires current recorded expenses.
        $expenses = $wpdb->get_results($wpdb->prepare('SELECT description,amount_cents,incurred_on FROM %i WHERE campaign_id=%d ORDER BY incurred_on DESC,id DESC', Database::table('campaign_expenses'), $campaign_id));
        if (!$expenses) return '';
        $html = '<div class="gkod-campaign-expenses"><h3>'.esc_html__('Recorded expenses','georg-kloster-orthodox-donations').'</h3><ul>';
        foreach ($expenses as $expense) $html .= '<li><span>'.esc_html($expense->description).'</span><time datetime="'.esc_attr($expense->incurred_on).'">'.esc_html($expense->incurred_on).'</time><strong>'.esc_html(number_format_i18n($expense->amount_cents/100,2).' EUR').'</strong></li>';
        return $html.'</ul></div>';
    }

    public static function summary(object $campaign): string
    {
        $totals = self::totals((int) $campaign->id);
        $rows = [__('Goal', 'georg-kloster-orthodox-donations') => (int) $campaign->goal_cents, __('Donations received', 'georg-kloster-orthodox-donations') => $totals['received'], __('Expenses', 'georg-kloster-orthodox-donations') => $totals['spent'], __('Balance', 'georg-kloster-orthodox-donations') => $totals['balance']];
        $html = '<dl class="gkod-campaign-summary">';
        foreach ($rows as $label => $cents) $html .= '<dt>' . esc_html($label) . '</dt><dd>' . esc_html(number_format_i18n($cents / 100, 2) . ' EUR') . '</dd>';
        $ratio = min(100, max(0, (int) floor($totals['received'] * 100 / max(1, (int) $campaign->goal_cents))));
        return $html . '</dl><progress max="100" value="' . $ratio . '" aria-label="' . esc_attr__('Campaign progress', 'georg-kloster-orthodox-donations') . '">' . $ratio . '%</progress>';
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
        $atts = shortcode_atts(['id' => 0, 'lang' => '', 'layout' => 'full', 'url' => ''], $atts, 'gkod_campaigns');
        $lang = in_array($atts['lang'], ['en','ru','de','uk','pl'], true) ? $atts['lang'] : I18n::language();
        $compact = $atts['layout'] === 'compact';
        $target_url = $atts['url'] !== '' ? esc_url_raw($atts['url']) : add_query_arg([], get_permalink());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom campaign/expense ledger; read current confirmed income and refunds, without stale financial cache.
        $rows = $atts['id'] ? array_filter([self::get(absint($atts['id']))]) : $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE status IN ('open','closed') ORDER BY id DESC LIMIT 100", Database::table('campaigns')));
        return I18n::in_language(static function () use ($rows, $lang, $compact, $target_url): string {
            $html = '<div class="gkod-campaigns">';
            foreach ($rows as $campaign) {
                if (!in_array($campaign->status, ['open', 'closed'], true)) continue;
                $description = $campaign->{'description_' . $lang} ?: $campaign->description_en;
                $image = !empty($campaign->image_id) ? wp_get_attachment_image((int) $campaign->image_id, 'large', false, ['class' => 'gkod-campaign__image']) : '';
                if ($compact) {
                    $totals=self::totals((int)$campaign->id);
                    $thumb=!empty($campaign->image_id)?wp_get_attachment_image((int)$campaign->image_id,'thumbnail',false,['class'=>'gkod-campaign-card__image']):'';
                    $url=add_query_arg('campaign_id',(int)$campaign->id,$target_url).'#gkod-campaign-'.(int)$campaign->id;
                    $html.='<article class="gkod-campaign-card">'.$thumb.'<div class="gkod-campaign-card__content"><h2>'.esc_html(self::title($campaign,$lang)).'</h2><p>'.esc_html(number_format_i18n($totals['received']/100,2).' / '.number_format_i18n($campaign->goal_cents/100,2).' EUR').'</p><progress max="100" value="'.min(100,(int)floor($totals['received']*100/max(1,(int)$campaign->goal_cents))).'"></progress><a class="gkod-campaign-card__link" href="'.esc_url($url).'">'.esc_html__('Donate','georg-kloster-orthodox-donations').' <span aria-hidden="true">→</span></a></div></article>';
                    continue;
                }
                $html .= '<section class="gkod-campaign" id="gkod-campaign-' . (int)$campaign->id . '"><h2>' . esc_html(self::title($campaign, $lang)) . '</h2>' . $image . '<div class="gkod-campaign__description">' . wpautop(wp_kses_post($description)) . '</div><div class="gkod-campaign__goal">' . self::summary($campaign) . '</div>';
                if ($campaign->deadline) $html .= '<p>' . esc_html__('Deadline', 'georg-kloster-orthodox-donations') . ': ' . esc_html($campaign->deadline) . '</p>';
                $html .= self::accepting($campaign) ? Public_Form::donation_shortcode(['campaign_id' => (int) $campaign->id, 'lang' => $lang, 'header' => 'no']) : '<p>' . esc_html__('This campaign is closed.', 'georg-kloster-orthodox-donations') . '</p>';
                $html .= self::expense_list((int)$campaign->id);
                $html .= '</section>';
            }
            return $html . '</div>';
        }, $lang);
    }
}
