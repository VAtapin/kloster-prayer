<?php
/**
 * Plugin Name: Orthodox Prayer Notes and Donations
 * Plugin URI: https://atapin.de/
 * Description: Orthodox prayer requests and donations with Stripe, PayPal, printable prayer slips and reports.
 * Version: 1.2.12
 * Author: atapin.de
 * Author URI: https://atapin.de/
 * Text Domain: kloster-prayer
 * Domain Path: /languages
 * Requires at least: 7.1
 * Requires PHP: 8.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

namespace KlosterPrayer;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION = '1.2.12';
const FILE = __FILE__;
const DIR = __DIR__;

require_once DIR . '/includes/class-database.php';
require_once DIR . '/includes/class-order-workflow.php';
require_once DIR . '/includes/class-i18n.php';
require_once DIR . '/includes/class-public-form.php';
require_once DIR . '/includes/class-service-images.php';
require_once DIR . '/includes/class-payments.php';
require_once DIR . '/includes/class-notifications.php';
require_once DIR . '/includes/class-admin.php';
require_once DIR . '/includes/class-admin-screens.php';
require_once DIR . '/includes/class-pdf.php';

register_activation_hook(FILE, [Database::class, 'activate']);
register_deactivation_hook(FILE, static function (): void {
    wp_clear_scheduled_hook('klp_retry_confirmations');
    wp_clear_scheduled_hook('klp_cleanup_pending');
});
add_action('plugins_loaded', static function (): void {
    Database::maybe_upgrade();
    Public_Form::init();
    Payments::init();
    Admin::init();
    Pdf::init();
});

add_action('init', [I18n::class, 'init']);

add_filter('plugin_action_links_' . plugin_basename(FILE), static function (array $links): array {
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=kloster-prayer-settings')) . '">' . esc_html__('Настройки', 'kloster-prayer') . '</a>');
    return $links;
});
