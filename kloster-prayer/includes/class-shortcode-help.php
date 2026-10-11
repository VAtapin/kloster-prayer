<?php
declare(strict_types=1);
namespace KlosterPrayer;

/** Administrator reference for the public shortcode handlers. */
final class Shortcode_Help
{
    public static function entries(): array
    {
        return [
            'kloster_form' => [__('Prayer form. Without id or slug, uses the active gebetsanliegen form. An id takes precedence over a slug.', 'kloster-prayer'), ['id','slug','layout','lang','header'], ['id="1"','slug="gebetsanliegen" layout="compact" header="no"']],
            'kloster_prayer_form' => [__('Alias of kloster_form with the same parameters and behavior.', 'kloster-prayer'), ['id','slug','layout','lang','header'], ['slug="gebetsanliegen"','id="1" layout="two_columns"']],
            'kloster_service' => [__('Form for one active service. Specify its id or slug; an id takes precedence. Find these values in Services.', 'kloster-prayer'), ['id','slug','layout','lang'], ['slug="altar-candle"','id="1" layout="compact"']],
            'kloster_donation' => [__('One-time donation without prayer names or services. The donor enters an amount from 0.50 to 999,999.99 EUR.', 'kloster-prayer'), ['campaign_id','layout','lang','header','support'], ['', 'campaign_id="1" header="no"']],
            'kloster_support' => [__('Monthly or yearly support. Requires login and explicit consent. The owner can cancel in donation history; configure recurring webhooks and WP-Cron first.', 'kloster-prayer'), ['campaign_id','layout','lang','header'], ['', 'campaign_id="1" layout="compact"']],
            'kloster_campaigns' => [__('Published campaigns with progress, totals and donation forms. Without id, shows up to 100 published campaigns. Drafts are hidden; closed or expired campaigns do not accept new payments.', 'kloster-prayer'), ['id','lang'], ['', 'id="1"']],
            'kloster_campaign_report' => [__('Public report for one published campaign: goal, confirmed receipts after refunds, separately recorded expenses and balance. Does not disclose donor data or include a payment form.', 'kloster-prayer'), ['id','lang'], ['id="1"']],
            'kloster_donation_history' => [__('Private payment history and regular-support cancellation for the signed-in account. Guest and historical payments are not assigned by email. Exclude this page from external full-page caches.', 'kloster-prayer'), ['lang'], ['']],
            'kloster_payment_result' => [__('Payment result for the returned order, using its saved language. Has no shortcode parameters. Forms already include this block; use it separately on a custom return page. A return URL alone does not confirm payment.', 'kloster-prayer'), [], ['']],
        ];
    }

    private static function parameters(): array
    {
        return [
            'id' => __('Numeric form, service or campaign ID. Find it in the corresponding admin section. Required for a campaign report; optional for the campaign list.', 'kloster-prayer'),
            'slug' => __('Saved form or service slug. Used only when id is omitted or zero.', 'kloster-prayer'),
            'layout' => __('Layout: standard, compact, wide, two_columns, cards or minimal. Prayer forms default to their saved layout; other forms default to compact.', 'kloster-prayer'),
            'lang' => __('Language: en, ru, de, uk or pl. Omit it to use the current WordPress language. Existing prayer form/service content uses German as the English fallback.', 'kloster-prayer'),
            'header' => __('yes (default) shows the form heading; no hides it. Not supported by kloster_service.', 'kloster-prayer'),
            'campaign_id' => __('Optional campaign ID. Omit it or use 0 for general church support. A specified campaign must be open and unexpired.', 'kloster-prayer'),
            'support' => __('Compatibility parameter for kloster_donation: no (default) means one-time payment; yes displays recurring choices. Prefer kloster_support, which also checks login before showing the form.', 'kloster-prayer'),
        ];
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'kloster-prayer'), '', ['response' => 403]);
        $language = Input::get('help_lang') ?? I18n::language();
        if (!in_array($language, ['en','ru','de','uk','pl'], true)) $language = I18n::language();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes all text and attributes.
        echo I18n::in_language(static fn(): string => self::render($language), $language);
    }

    private static function render(string $language): string
    {
        $parameters = self::parameters();
        ob_start(); ?>
        <div class="wrap klp-admin klp-shortcode-help" data-copied="<?php echo esc_attr__('Copied.', 'kloster-prayer'); ?>" data-copy-failed="<?php echo esc_attr__('The example is selected. Copy it manually.', 'kloster-prayer'); ?>">
        <h1><?php echo esc_html__('Help → Shortcodes', 'kloster-prayer'); ?></h1>
        <nav aria-label="<?php echo esc_attr__('Language', 'kloster-prayer'); ?>">
        <?php foreach (['en'=>__('English', 'kloster-prayer'),'ru'=>__('Russian', 'kloster-prayer'),'de'=>__('German', 'kloster-prayer'),'uk'=>__('Ukrainian', 'kloster-prayer'),'pl'=>__('Polish', 'kloster-prayer')] as $code=>$label): ?>
            <a class="button" href="<?php echo esc_url(add_query_arg(['page'=>'kloster-prayer-help','help_lang'=>$code], admin_url('admin.php'))); ?>" <?php if ($code === $language) echo 'aria-current="page"'; ?>><?php echo esc_html($label); ?></a>
        <?php endforeach; ?></nav>
        <p><?php echo esc_html__('Add a Shortcode block to a WordPress page and paste an example. Replace example IDs and slugs with your saved values. Publish the page to display the form or report.', 'kloster-prayer'); ?></p>
        <p><?php echo esc_html__('Choose a language above to translate this guide and the lang parameter in its examples. Keep shortcode names and parameter values unchanged.', 'kloster-prayer'); ?></p>
        <p role="status" aria-live="polite" class="klp-copy-status"></p>
        <?php foreach (self::entries() as $tag=>[$description,$keys,$examples]): ?>
            <section class="klp-card"><h2><code><?php echo esc_html('['.$tag.']'); ?></code></h2><p><?php echo esc_html($description); ?></p>
            <h3><?php echo esc_html__('Parameters', 'kloster-prayer'); ?></h3>
            <?php if (!$keys): ?><p><?php echo esc_html__('No parameters.', 'kloster-prayer'); ?></p>
            <?php else: ?><table class="widefat striped"><tbody>
            <?php foreach ($keys as $key): ?><tr><th scope="row"><code><?php echo esc_html($key); ?></code></th><td><?php echo esc_html($parameters[$key]); ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
            <h3><?php echo esc_html__('Examples to copy', 'kloster-prayer'); ?></h3>
            <?php foreach ($examples as $index=>$attributes): $value='['.$tag.($attributes!==''?' '.$attributes:'').(in_array('lang',$keys,true)?' lang="'.$language.'"':'').']'; $id='klp-example-'.$tag.'-'.$index; ?>
            <div class="klp-shortcode-example"><label class="screen-reader-text" for="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Shortcode example', 'kloster-prayer'); ?></label><input class="code" id="<?php echo esc_attr($id); ?>" readonly value="<?php echo esc_attr($value); ?>"><button type="button" class="button klp-copy-shortcode" data-target="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Copy', 'kloster-prayer'); ?></button></div>
            <?php endforeach; ?></section>
        <?php endforeach; ?></div>
        <?php return (string) ob_get_clean();
    }
}
