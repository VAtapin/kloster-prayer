<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

/** Administrator reference for the public shortcode handlers. */
final class Shortcode_Help
{
    public static function entries(): array
    {
        return [
            'gkod_form' => [__('Prayer form. Without id or slug, uses the active gebetsanliegen form. An id takes precedence over a slug.', 'georg-kloster-orthodox-donations'), ['id','slug','layout','lang','header','accent_color'], ['id="1"','slug="gebetsanliegen" layout="compact" header="no"','accent_color="#ad2618"']],
            'gkod_prayer_form' => [__('Alias of gkod_form with the same parameters and behavior.', 'georg-kloster-orthodox-donations'), ['id','slug','layout','lang','header','accent_color'], ['slug="gebetsanliegen"','id="1" layout="two_columns"']],
            'gkod_service' => [__('Form for one active service. Specify its id or slug; an id takes precedence. Find these values in Services.', 'georg-kloster-orthodox-donations'), ['id','slug','layout','lang','accent_color'], ['slug="altar-candle"','id="1" layout="compact"']],
            'gkod_donation' => [__('One-time donation without prayer names or services. The donor enters an amount from 0.50 to 999,999.99 EUR.', 'georg-kloster-orthodox-donations'), ['campaign_id','layout','lang','header','support','accent_color'], ['', 'campaign_id="1" header="no"','accent_color="#ad2618"']],
            'gkod_support' => [__('Anyone can start monthly or yearly support without an account. An email address and explicit consent are required; manage or cancel support in the donor cabinet. Configure recurring webhooks and WP-Cron first.', 'georg-kloster-orthodox-donations'), ['campaign_id','layout','lang','header','accent_color'], ['', 'campaign_id="1" layout="compact"','accent_color="#ad2618"']],
            'gkod_campaigns' => [__('Published campaigns with progress, totals and donation forms. Use layout="compact" for a small campaign card and url="/donate/" to link it to the full campaign page. Without id, shows up to 100 published campaigns. Drafts are hidden; closed or expired campaigns do not accept new payments.', 'georg-kloster-orthodox-donations'), ['id','lang','layout','url'], ['', 'id="1"', 'id="1" layout="compact" url="/donate/"']],
            'gkod_campaign_report' => [__('Public report for one published campaign: goal, confirmed receipts after refunds, separately recorded expenses and balance. Does not disclose donor data or include a payment form.', 'georg-kloster-orthodox-donations'), ['id','lang'], ['id="1"']],
            'gkod_donation_history' => [__('Private donation history and recurring-support cancellation for a donor reached through the personal link in the payment email or a linked WordPress account. Exclude this page from external full-page caches.', 'georg-kloster-orthodox-donations'), ['lang'], ['']],
            'gkod_payment_result' => [__('Payment result for the returned order, using its saved language. Has no shortcode parameters. Forms already include this block; use it separately on a custom return page. A return URL alone does not confirm payment.', 'georg-kloster-orthodox-donations'), [], ['']],
        ];
    }

    private static function parameters(): array
    {
        return [
            'id' => __('Numeric form, service or campaign ID. Find it in the corresponding admin section. Required for a campaign report; optional for the campaign list.', 'georg-kloster-orthodox-donations'),
            'slug' => __('Saved form or service slug. Used only when id is omitted or zero.', 'georg-kloster-orthodox-donations'),
            'layout' => __('Layout for forms: standard, compact, wide, two_columns, cards or minimal. For campaign lists, use compact for a small linked card; full shows the description, reports, and donation form.', 'georg-kloster-orthodox-donations'),
            'lang' => __('Language override: en, ru, de, uk or pl. Usually omit it: the site follows the current WPML/Polylang language, or WordPress without a language plugin. Existing prayer form/service content uses German as the English fallback.', 'georg-kloster-orthodox-donations'),
            'header' => __('yes (default) shows the form heading; no hides it. Not supported by gkod_service.', 'georg-kloster-orthodox-donations'),
            'campaign_id' => __('Optional campaign ID. Omit it or use 0 for general church support. A specified campaign must be open and unexpired.', 'georg-kloster-orthodox-donations'),
            'url' => __('Destination page for a compact campaign card, normally the page containing the full campaign and donation form.', 'georg-kloster-orthodox-donations'),
            'support' => __('Compatibility parameter for gkod_donation: no (default) means one-time payment; yes displays recurring choices. Prefer gkod_support, which presents recurring choices without requiring login.', 'georg-kloster-orthodox-donations'),
            'accent_color' => __('Hex color override for this shortcode. It takes precedence over a saved prayer-form color. Campaign forms use the color set in the campaign editor; set this value for a standalone one-time or regular donation form.', 'georg-kloster-orthodox-donations'),
        ];
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes all text and attributes.
        echo self::render();
    }

    private static function render(): string
    {
        $parameters = self::parameters();
        ob_start(); ?>
        <div class="wrap gkod-admin gkod-shortcode-help" data-copied="<?php echo esc_attr__('Copied.', 'georg-kloster-orthodox-donations'); ?>" data-copy-failed="<?php echo esc_attr__('The example is selected. Copy it manually.', 'georg-kloster-orthodox-donations'); ?>">
        <h1><?php echo esc_html__('Help → Shortcodes', 'georg-kloster-orthodox-donations'); ?></h1>
        <p><?php echo esc_html__('Add a Shortcode block to a WordPress page and paste an example. Replace example IDs and slugs with your saved values. Publish the page to display the form or report.', 'georg-kloster-orthodox-donations'); ?></p>
        <p role="status" aria-live="polite" class="gkod-copy-status"></p>
        <?php foreach (self::entries() as $tag=>[$description,$keys,$examples]): ?>
            <section class="postbox gkod-card"><h2><code><?php echo esc_html('['.$tag.']'); ?></code></h2><p><?php echo esc_html($description); ?></p>
            <h3><?php echo esc_html__('Parameters', 'georg-kloster-orthodox-donations'); ?></h3>
            <?php if (!$keys): ?><p><?php echo esc_html__('No parameters.', 'georg-kloster-orthodox-donations'); ?></p>
            <?php else: ?><table class="widefat striped"><tbody>
            <?php foreach ($keys as $key): ?><tr><th scope="row"><code><?php echo esc_html($key); ?></code></th><td><?php echo esc_html($parameters[$key]); ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
            <h3><?php echo esc_html__('Examples to copy', 'georg-kloster-orthodox-donations'); ?></h3>
            <?php foreach ($examples as $index=>$attributes): $value='['.$tag.($attributes!==''?' '.$attributes:'').']'; $id='gkod-example-'.$tag.'-'.$index; ?>
            <div class="gkod-shortcode-example"><label class="screen-reader-text" for="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Shortcode example', 'georg-kloster-orthodox-donations'); ?></label><input type="text" class="code" id="<?php echo esc_attr($id); ?>" readonly value="<?php echo esc_attr($value); ?>"><button type="button" class="button gkod-copy-shortcode" data-target="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Copy', 'georg-kloster-orthodox-donations'); ?></button></div>
            <?php endforeach; ?></section>
        <?php endforeach; ?></div>
        <?php return (string) ob_get_clean();
    }
}
