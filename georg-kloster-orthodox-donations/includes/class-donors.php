<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

/** Donor identity, reusable private cabinet links and optional WordPress accounts. */
final class Donors
{
    private const COOKIE = 'gkod_donor_access';

    public static function init(): void
    {
        add_action('init', [self::class, 'ensure_cabinet_page'], 20);
        add_action('template_redirect', [self::class, 'prevent_cache'], 0);
        add_action('template_redirect', [self::class, 'accept_access_link'], 1);
        add_action('admin_post_nopriv_gkod_donor_register', [self::class, 'register_account']);
        add_action('admin_post_gkod_donor_register', [self::class, 'register_account']);
    }

    public static function normalize_email(string $email): string
    {
        return strtolower(trim(sanitize_email($email)));
    }

    public static function find(string $email): ?object
    {
        global $wpdb;
        $email = self::normalize_email($email);
        if (!is_email($email)) return null;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Donor access and account mapping require a fresh custom identity record.
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE email=%s', Database::table('donors'), $email));
    }

    public static function ensure(string $email): ?object
    {
        global $wpdb;
        $email = self::normalize_email($email);
        if (!is_email($email) || strlen($email) > 190) return null;
        $table = Database::table('donors');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A unique email key makes first-donation creation race-safe.
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO %i (email,created_at,updated_at) VALUES (%s,%s,%s)', $table, $email, current_time('mysql'), current_time('mysql')));
        return self::find($email);
    }

    /** Return the existing reusable link secret, creating one if this donor has none. */
    public static function access_token(string $email): string
    {
        global $wpdb;
        $donor = self::ensure($email);
        if (!$donor || (int) $donor->access_revoked === 1) return '';
        // Derive a stable bearer token from the donor identity and WordPress secret.
        // This stores only a one-way hash and avoids optional PHP crypto extensions.
        $token = hash_hmac('sha256', (int) $donor->id . ':' . (string) $donor->email, wp_salt('auth'));
        $token_hash = hash('sha256', $token);
        if (!hash_equals((string) ($donor->access_token_hash ?? ''), $token_hash)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Store only a hash of the deterministic donor bearer token.
            if ($wpdb->update(Database::table('donors'), ['access_token_hash' => $token_hash, 'access_revoked' => 0, 'updated_at' => current_time('mysql')], ['id' => (int) $donor->id]) === false) return '';
        }
        return $token;
    }

    public static function cabinet_url(string $email, string $language = ''): string
    {
        $token = self::access_token($email);
        $page_id = absint(get_option('gkod_donor_cabinet_page_id', 0));
        $base = $page_id ? get_permalink($page_id) : home_url('/');
        if ($token === '') return (string) $base;
        $args = ['gkod_donor' => $token];
        if (in_array($language, ['en','de','ru','uk','pl'], true)) $args['gkod_lang'] = $language;
        return add_query_arg($args, $base);
    }

    public static function cabinet_page_url(): string
    {
        $page_id = absint(get_option('gkod_donor_cabinet_page_id', 0));
        return (string) ($page_id ? get_permalink($page_id) : home_url('/'));
    }

    public static function donate_again_url(): string
    {
        static $url = null;
        if ($url !== null) return $url;
        $url = home_url('/');
        $pages = get_pages(['post_status' => 'publish', 'number' => 500]);
        foreach ($pages as $page) {
            if (has_shortcode((string) $page->post_content, 'gkod_donation')) {
                $url = (string) get_permalink($page->ID);
                break;
            }
        }
        if ($url === home_url('/')) {
            foreach ($pages as $page) {
                if (has_shortcode((string) $page->post_content, 'gkod_form')) {
                    $url = (string) get_permalink($page->ID);
                    break;
                }
            }
        }
        return $url;
    }

    public static function ensure_cabinet_page(): void
    {
        if (!get_option('gkod_donor_cabinet_page_id')) {
            $pages = get_pages(['post_status' => 'publish', 'number' => 500]);
            foreach ($pages as $page) {
                if (has_shortcode((string) $page->post_content, 'gkod_donation_history')) {
                    update_option('gkod_donor_cabinet_page_id', (int) $page->ID, false);
                    return;
                }
            }
            $page_id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => __('Donor account', 'georg-kloster-orthodox-donations'),
                'post_name' => 'donor-account',
                'post_content' => '[gkod_donation_history]',
            ], true);
            if (!is_wp_error($page_id) && $page_id) update_option('gkod_donor_cabinet_page_id', (int) $page_id, false);
        }
    }

    /** Validate a permanent emailed link, exchange it for a short-lived HttpOnly session, then remove it from the address bar. */
    public static function accept_access_link(): void
    {
        $token = Input::get('gkod_donor');
        if ($token === null || !preg_match('/^[a-f0-9]{64}$/D', $token)) return;
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bearer token lookup must use the current hash and revocation state.
        $donor = $wpdb->get_row($wpdb->prepare('SELECT id,access_token_hash FROM %i WHERE access_token_hash=%s AND access_revoked=0', Database::table('donors'), hash('sha256', $token)));
        if (!$donor || !hash_equals((string) $donor->access_token_hash, hash('sha256', $token))) {
            wp_die(esc_html__('This donor link is no longer available. Please use the latest link from your donation email.', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        }
        Donation_Pages::private_page();
        self::set_session((int) $donor->id);
        wp_safe_redirect(remove_query_arg('gkod_donor'));
        exit;
    }

    public static function prevent_cache(): void
    {
        if (isset($_COOKIE[self::COOKIE]) || Input::get('gkod_donor') !== null) Donation_Pages::private_page();
    }

    public static function current(): ?object
    {
        global $wpdb;
        $cookie_donor = self::cookie_donor();
        if ($cookie_donor && is_user_logged_in()) {
            $user = wp_get_current_user();
            if (self::normalize_email((string) $user->user_email) === self::normalize_email((string) $cookie_donor->email)) {
                self::link_account((string) $cookie_donor->email, get_current_user_id());
                return self::find((string) $cookie_donor->email);
            }
        }
        if ($cookie_donor) return $cookie_donor;
        if (!is_user_logged_in()) return null;
        $user_id = get_current_user_id();
        // Keep a linked donor accessible if the WordPress profile email changes later.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolve an account to its existing donor identity.
        $linked = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE wp_user_id=%d LIMIT 1', Database::table('donors'), $user_id));
        if ($linked) return $linked;
        $user = wp_get_current_user();
        $email = self::normalize_email((string) $user->user_email);
        if (!is_email($email)) return null;
        $donor = self::find($email);
        if (!$donor) {
            // Older payments may predate the donor directory. Only establish a donor record when this account has matching orders.
            $has_orders = (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE LOWER(donor_email)=LOWER(%s) LIMIT 1', Database::table('orders'), $email));
            if (!$has_orders) return null;
            $donor = self::ensure($email);
        }
        if (!$donor) return null;
        if ((int) $donor->wp_user_id === 0) self::link_account($email, $user_id);
        $fresh = self::find($email);
        return $fresh && (int) $fresh->wp_user_id === $user_id ? $fresh : null;
    }

    public static function current_email(): string
    {
        $donor = self::current();
        return $donor ? (string) $donor->email : '';
    }

    public static function has_access_to_email(string $email): bool
    {
        $donor = self::current();
        return $donor !== null && hash_equals(self::normalize_email($email), self::normalize_email((string) $donor->email));
    }

    public static function link_account(string $email, int $user_id): bool
    {
        global $wpdb;
        $email = self::normalize_email($email);
        $donor = self::ensure($email);
        if (!$donor || !$user_id) return false;
        if ((int) $donor->wp_user_id > 0 && (int) $donor->wp_user_id !== $user_id) return false;
        $table = Database::table('donors');
        // An account can claim only the same email that was used for the donation. Revoking the bearer link is part of that binding.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The donor's email identity and account binding are updated together in this custom ledger.
        $ok = $wpdb->update($table, ['wp_user_id' => $user_id, 'access_token_hash' => null, 'access_revoked' => 1, 'updated_at' => current_time('mysql')], ['id' => (int) $donor->id, 'wp_user_id' => 0]);
        if ($ok === false || (int) (self::find($email)->wp_user_id ?? 0) !== $user_id) return false;
        // Keep the existing order rows as the source of addresses and payer details; only backfill the account ownership field.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Linking the same email's historic orders to its newly created account.
        $wpdb->query($wpdb->prepare('UPDATE %i SET donor_user_id=%d WHERE LOWER(donor_email)=LOWER(%s)', Database::table('orders'), $user_id, $email));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Match each recurring agreement through its initial order; no address data is duplicated.
        $wpdb->query($wpdb->prepare('UPDATE %i SET donor_user_id=%d WHERE initial_order_id IN (SELECT id FROM %i WHERE LOWER(donor_email)=LOWER(%s))', Database::table('subscriptions'), $user_id, Database::table('orders'), $email));
        return true;
    }

    public static function by_id(int $id): ?object
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Staff actions resolve a donor by its internal identity.
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', Database::table('donors'), $id));
    }

    public static function order_belongs_to(int $donor_id, int $order_id): bool
    {
        global $wpdb;
        $donor = self::by_id($donor_id);
        if (!$donor || !$order_id) return false;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Protect the admin letter action from mismatched donor/order identifiers.
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id=%d AND LOWER(donor_email)=LOWER(%s)', Database::table('orders'), $order_id, (string) $donor->email));
    }

    public static function register_account(): void
    {
        $donor = self::current();
        if (!$donor || (int) $donor->access_revoked === 1 || (int) $donor->wp_user_id > 0) wp_die(esc_html__('Open your donor account link before registering.', 'georg-kloster-orthodox-donations'), '', ['response' => 403]);
        check_admin_referer('gkod_donor_register');
        $login = sanitize_user((string) (Input::post('user_login') ?? ''), true);
        $password_raw = $_POST['user_password'] ?? '';
        $password = is_string($password_raw) ? (string) wp_unslash($password_raw) : '';
        if (!preg_match('/^[a-zA-Z0-9_.@-]{3,60}$/D', $login) || username_exists($login)) self::registration_error('username');
        if (strlen($password) < 12 || strlen($password) > 4096) self::registration_error('password');
        if (email_exists((string) $donor->email)) {
            wp_safe_redirect(add_query_arg('gkod_account_error', 'existing', self::cabinet_page_url()));
            exit;
        }
        $user_id = wp_insert_user(['user_login' => $login, 'user_pass' => $password, 'user_email' => (string) $donor->email, 'display_name' => $login, 'role' => 'subscriber']);
        if (is_wp_error($user_id)) self::registration_error('failed');
        if (!self::link_account((string) $donor->email, (int) $user_id)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $user_id);
            self::registration_error('failed');
        }
        self::clear_session();
        wp_set_current_user((int) $user_id);
        wp_set_auth_cookie((int) $user_id, true, is_ssl());
        wp_safe_redirect(self::cabinet_page_url());
        exit;
    }

    private static function registration_error(string $code): never
    {
        wp_safe_redirect(add_query_arg('gkod_account_error', sanitize_key($code), self::cabinet_page_url()));
        exit;
    }

    private static function set_session(int $donor_id): void
    {
        $expires = time() + (30 * DAY_IN_SECONDS);
        $payload = $donor_id . '.' . $expires;
        $value = $payload . '.' . hash_hmac('sha256', $payload, wp_salt('auth'));
        setcookie(self::COOKIE, $value, ['expires' => $expires, 'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/', 'domain' => defined('COOKIE_DOMAIN') && is_string(COOKIE_DOMAIN) ? COOKIE_DOMAIN : '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[self::COOKIE] = $value;
    }

    private static function cookie_donor(): ?object
    {
        global $wpdb;
        $raw = is_string($_COOKIE[self::COOKIE] ?? null) ? wp_unslash($_COOKIE[self::COOKIE]) : '';
        if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/D', $raw, $parts) || (int) $parts[2] < time()) return null;
        $payload = $parts[1] . '.' . $parts[2];
        if (!hash_equals(hash_hmac('sha256', $payload, wp_salt('auth')), $parts[3])) return null;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recheck the donor record and link revocation on each private request.
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d AND access_revoked=0', Database::table('donors'), (int) $parts[1]));
    }

    private static function clear_session(): void
    {
        setcookie(self::COOKIE, '', ['expires' => time() - HOUR_IN_SECONDS, 'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/', 'domain' => defined('COOKIE_DOMAIN') && is_string(COOKIE_DOMAIN) ? COOKIE_DOMAIN : '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[self::COOKIE]);
    }

}
