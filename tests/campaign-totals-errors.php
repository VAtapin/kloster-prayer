<?php
declare(strict_types=1);

namespace {
    final class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '') {}
    }

    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function __(string $text, string $domain = ''): string { return $text; }
}

namespace GeorgKlosterOrthodoxDonate {
    final class Database
    {
        public static function table(string $name): string { return 'wp_gkod_' . $name; }
    }
}

namespace {
    final class CampaignTotalsFixtureDatabase
    {
        public string $last_error = '';
        public array $responses = [];

        public function prepare(string $query, mixed ...$arguments): string { return $query; }

        public function get_var(string $query): mixed
        {
            $response = array_shift($this->responses) ?? [];
            $this->last_error = (string) ($response['error'] ?? '');
            return $response['value'] ?? null;
        }
    }

    require dirname(__DIR__) . '/georg-kloster-orthodox-donations/includes/class-campaigns.php';

    $wpdb = new CampaignTotalsFixtureDatabase();
    $wpdb->responses = [['value' => '1250'], ['value' => '250']];
    $totals = \GeorgKlosterOrthodoxDonate\Campaigns::totals(7);
    if ($totals !== ['received' => 1250, 'spent' => 250, 'balance' => 1000]) {
        throw new \RuntimeException('Campaign income, expenses and balance must reconcile.');
    }

    $wpdb->responses = [['error' => 'orders table unavailable']];
    if (!is_wp_error(\GeorgKlosterOrthodoxDonate\Campaigns::totals(7))) {
        throw new \RuntimeException('An income query failure must not be reported as zero donations.');
    }

    $wpdb->responses = [['value' => '1250'], ['error' => 'expenses table unavailable']];
    if (!is_wp_error(\GeorgKlosterOrthodoxDonate\Campaigns::totals(7))) {
        throw new \RuntimeException('An expense query failure must not be reported as a zero balance.');
    }

    echo "PASS campaign totals fail visibly when income or expense queries fail\n";
}
