<?php
declare(strict_types=1);

namespace {
    final class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '') {}
        public function get_error_message(): string { return $this->message; }
    }

    function __($message, $domain = '') { return $message; }
    function absint($value): int { return abs((int) $value); }
    function get_option($name, $default = false) { return $name === 'gkod_stripe_secret' ? 'sk_test_empty_description' : $default; }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
    function sanitize_key($value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
    function current_time($format): string { return '2026-10-24 12:00:00'; }
    function wp_json_encode($value): string|false { return json_encode($value); }
    function wp_remote_request($url, $args) {
        $GLOBALS['stripe_catalog_request'] = [$url, $args];
        return ['body' => json_encode(['id' => 'prod_fixture', 'default_price' => 'price_fixture']), 'response' => ['code' => 200]];
    }
    function wp_remote_retrieve_body($response): string { return $response['body']; }
    function wp_remote_retrieve_response_code($response): int { return $response['response']['code']; }
}

namespace GeorgKlosterOrthodoxDonate {
    final class Database { public static function table(string $name): string { return 'wp_gkod_' . $name; } }
    final class Service_Images { public static function url(object $service): string { return ''; } }
    final class I18n { public static function in_language(callable $callback, string $language): string { return $callback(); } }

    final class FixtureDB
    {
        public string $last_error = '';

        public function prepare(string $query, ...$args): string { return $query; }
        public function get_results(string $query): array
        {
            return [(object) [
                'id' => 7, 'slug' => 'test-service', 'title_de' => 'Testdienst', 'title_ru' => 'Тестовая треба',
                'description_de' => $GLOBALS['fixture_service_description'] ?? '', 'description_ru' => '', 'is_active' => 1, 'pricing_mode' => 'flat', 'price_cents' => 1000,
            ]];
        }
        public function get_row(string $query): ?object { return null; }
        public function replace(string $table, array $row): int { return 1; }
    }

    $wpdb = new FixtureDB();
    require dirname(__DIR__) . '/georg-kloster-orthodox-donations/includes/class-stripe-catalog.php';
    $result = Stripe_Catalog::sync_selected([7]);
    $body = $GLOBALS['stripe_catalog_request'][1]['body'] ?? [];
    if (($result['created'] ?? 0) !== 1 || isset($body['description'])) {
        throw new \RuntimeException('An empty description must be omitted from Stripe product creation.');
    }
    $GLOBALS['fixture_service_description'] = 'Beschreibung der Gebetsanliegen';
    Stripe_Catalog::sync_selected([7]);
    $body = $GLOBALS['stripe_catalog_request'][1]['body'] ?? [];
    if (($body['description'] ?? '') !== $GLOBALS['fixture_service_description']) {
        throw new \RuntimeException('A non-empty service description must be sent to Stripe.');
    }
    echo "PASS empty descriptions are omitted and non-empty descriptions are sent\n";
}
