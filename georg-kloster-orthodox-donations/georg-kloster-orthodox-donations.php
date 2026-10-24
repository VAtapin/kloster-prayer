<?php
/**
 * Plugin Name: Georg Kloster – Orthodox Donations
 * Plugin URI: https://github.com/VAtapin/kloster-prayer/tree/main/georg-kloster-orthodox-donations
 * Description: Orthodox prayer requests and donations with Stripe, PayPal, printable prayer slips and reports.
 * Version: 1.0.6
 * Author: Vladimir Atapin
 * Author URI: https://atapin.de/
 * Text Domain: georg-kloster-orthodox-donations
 * Domain Path: /languages
 * Requires at least: 7.1
 * Requires PHP: 8.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

namespace GeorgKlosterOrthodoxDonate;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION = '1.0.6';
const FILE = __FILE__;
const DIR = __DIR__;

require_once DIR . '/includes/class-database.php';
require_once DIR . '/includes/class-order-workflow.php';
require_once DIR . '/includes/class-i18n.php';
require_once DIR . '/includes/class-public-form.php';
require_once DIR . '/includes/class-service-images.php';
require_once DIR . '/includes/class-payments.php';
require_once DIR . '/includes/class-stripe-catalog.php';
require_once DIR . '/includes/class-campaigns.php';
require_once DIR . '/includes/class-donation-pages.php';
require_once DIR . '/includes/class-recurring-gateways.php';
require_once DIR . '/includes/class-payment-refunds.php';
require_once DIR . '/includes/class-subscriptions.php';
require_once DIR . '/includes/class-support-pages.php';
require_once DIR . '/includes/class-notifications.php';
require_once DIR . '/includes/class-admin.php';
require_once DIR . '/includes/class-shortcode-help.php';
require_once DIR . '/includes/class-admin-screens.php';
require_once DIR . '/includes/class-pdf.php';

register_activation_hook(FILE, [Database::class, 'activate']);
register_deactivation_hook(FILE, static function (): void {
    wp_clear_scheduled_hook('gkod_retry_confirmations');
    wp_clear_scheduled_hook('gkod_cleanup_pending');
    wp_unschedule_hook('gkod_prepare_recurring_plan');
    wp_unschedule_hook('gkod_sync_recurring_refunds');
});
add_action('plugins_loaded', static function (): void {
    Database::maybe_upgrade();
    Public_Form::init();
    Payments::init();
    Campaigns::init();
    Donation_Pages::init();
    Subscriptions::init();
    Support_Pages::init();
    Admin::init();
    Pdf::init();
});

add_action('init', [I18n::class, 'init']);

add_filter('plugin_action_links_' . plugin_basename(FILE), static function (array $links): array {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=georg-kloster-orthodox-donations-settings')) . '">' . esc_html__('Settings', 'georg-kloster-orthodox-donations') . '</a>');
    return $links;
});
