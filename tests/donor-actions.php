<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');
    class WP_List_Table { public function __construct(array $args = []) {} public function column_default($item, $column_name) {} }
    function esc_html__(string $text, string $domain = ''): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr__(string $text, string $domain = ''): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
    function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
    function wp_nonce_field(string $action, string $name, bool $referer = true, bool $display = true): string
    {
        return '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
    }
}

namespace GeorgKlosterOrthodoxDonate {
    final class Donor_Admin
    {
        public static function template_selector(): string { return '<select name="template_id"><option value="3">Благодарность</option></select>'; }
    }

    require __DIR__ . '/../georg-kloster-orthodox-donations/includes/class-donors-table.php';
    $table = new Donor_Orders_Table('donor@example.test', 9);
    $html = $table->column_default((object) ['id' => 42, 'payment_status' => 'paid'], 'letter');
    if (substr_count($html, '<select') !== 1) throw new \RuntimeException('Each donation row must render only one template selector.');
    if (substr_count($html, '<button') !== 2) throw new \RuntimeException('Each donation row must render only preview and send actions.');
    if (!str_contains($html, '>Благодарность</option>') || str_contains($html, 'Русский /') || str_contains($html, 'Any case')) throw new \RuntimeException('The template selector must show only the template name.');
    foreach (['value="gkod_preview_thank_you"', 'value="gkod_send_thank_you"', 'name="gkod_nonce_gkod_preview_thank_you"', 'name="gkod_nonce_gkod_send_thank_you"', 'button-link gkod-donor-letter-icon', 'dashicons-visibility', 'dashicons-email-alt', 'formtarget="_blank"'] as $expected) {
        if (!str_contains($html, $expected)) throw new \RuntimeException('Missing donor letter control: ' . $expected);
    }
    if (str_contains($html, 'gkod_test_thank_you')) throw new \RuntimeException('The test-copy action does not belong in the donor payment row.');
    echo "PASS donor letter row has one selector and compact preview/send icon actions\n";
}
