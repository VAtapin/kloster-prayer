<?php
declare(strict_types=1);

namespace Dompdf {
    final class Options { public function set(string $key, mixed $value): void {} public function setChroot(mixed $path): void {} }
    final class Dompdf
    {
        public static string $html = '';
        public function __construct(Options $options) {}
        public function setPaper(string $paper, string $orientation): void {}
        public function loadHtml(string $html, string $encoding): void { self::$html = $html; }
        public function render(): void {}
        public function output(): string { return '%PDF-template-test'; }
    }
}

namespace {
    final class WP_Error { public function __construct(public string $code, public string $message) {} }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function __(string $text, string $domain = ''): string { return $text; }
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $hook === 'wpml_active_languages' ? ($GLOBALS['gkod_wpml_languages'] ?? $value) : $value; }
    function get_locale(): string { return 'de_DE'; }
    function pll_the_languages(array $args = []): array { return $GLOBALS['gkod_pll_languages'] ?? []; }
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\\-]/', '', strtolower($key)); }
    function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['gkod_test_options'][$key] ?? $default; }
    function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['gkod_test_options'][$key] = $value; return true; }
    function wp_json_encode(mixed $value): string|false { return json_encode($value); }
    function absint(mixed $value): int { return abs((int) $value); }
    function wp_upload_dir(): array { return ['basedir' => sys_get_temp_dir(), 'baseurl' => 'https://example.test/uploads']; }
    function wp_specialchars_decode(string $text, int $flags = ENT_COMPAT): string { return html_entity_decode($text, $flags); }
    function get_bloginfo(string $field): string { return 'Test monastery'; }
    function wp_date(string $format, ?int $timestamp = null): string { return '24 October 2026'; }
    function mysql2date(string $format, string $date, bool $translate = true): string|false { return $date !== '' ? '24 October 2026' : false; }
    function current_time(string $type): string { return '2026-10-24 12:00:00'; }
    function number_format_i18n(float $number, int $decimals = 0): string { return number_format($number, $decimals, ',', ' '); }
    function get_attached_file(int $id): string|false { return false; }
    function get_post_mime_type(int $id): string|false { return false; }
    function is_email(string $email): string|false { return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false; }
    function wp_tempnam(string $name = ''): string|false { return tempnam(sys_get_temp_dir(), 'gkod-'); }
    function wp_delete_file(string $file): void { if (is_file($file)) unlink($file); }
    function wp_mail(string $to, string $subject, string $message, array $headers, array $attachments): bool
    {
        $GLOBALS['gkod_test_mail_message'] = $message;
        $path = $attachments[0] ?? '';
        $GLOBALS['gkod_test_mail_attachment_ok'] = str_ends_with($path, '.pdf') && is_file($path) && str_starts_with((string) file_get_contents($path), '%PDF-');
        return true;
    }
    function wp_unslash(string $value): string { return stripslashes($value); }
    function sanitize_textarea_field(string $value): string { return trim(str_replace("\r\n", "\n", $value)); }
    function sanitize_text_field(string $value): string { return trim(str_replace(["\r", "\n", "\t"], ' ', strip_tags($value))); }
}

namespace GeorgKlosterOrthodoxDonate {
    const DIR = __DIR__ . '/../georg-kloster-orthodox-donations';
    final class Database { public static function table(string $name): string { return 'wp_gkod_' . $name; } }
    final class Template_Test_DB
    {
        public array $rows = [];
        public string $last_error = '';
        public int $insert_id = 0;
        private int $next_id = 1;
        public function prepare(string $query, mixed ...$args): string { return $query; }
        public function get_var(string $query): int { return count($this->rows); }
        public function get_results(string $query): array { ksort($this->rows); return array_map(static fn($row) => (object) $row, array_values($this->rows)); }
        public function get_row(string $query): ?object { return $GLOBALS['gkod_test_order'] ?? null; }
        public function insert(string $table, array $data): int|false { $id = $this->next_id++; $this->insert_id = $id; $data['id'] = $id; $this->rows[$id] = $data; return 1; }
        public function update(string $table, array $data, array $where): int|false { $id = (int) ($where['id'] ?? 0); if (!isset($this->rows[$id])) return 0; $this->rows[$id] = array_merge($this->rows[$id], $data); return 1; }
        public function delete(string $table, array $where, array $format = []): int|false { $id = (int) ($where['id'] ?? 0); if (!isset($this->rows[$id])) return 0; unset($this->rows[$id]); return 1; }
    }
    $GLOBALS['wpdb'] = new Template_Test_DB();
    final class I18n
    {
        public static function content_language(): string { return 'en'; }
        public static function donation_text(string $key, string $language): string { return 'Donation'; }
        public static function in_language(callable $callback, string $language): string { return $callback(); }
    }
    require_once __DIR__ . '/../georg-kloster-orthodox-donations/includes/class-thank-you-template-admin.php';
    require_once __DIR__ . '/../georg-kloster-orthodox-donations/includes/class-thank-you.php';
    require_once __DIR__ . '/../georg-kloster-orthodox-donations/includes/class-input.php';

    $GLOBALS['gkod_wpml_languages'] = ['de' => ['code' => 'de', 'native_name' => 'Deutsch'], 'ru' => ['code' => 'ru', 'native_name' => 'Русский']];
    $enabled_languages = Thank_You_Template_Admin::language_options();
    if (array_keys($enabled_languages) !== ['any', 'de', 'ru'] || isset($enabled_languages['uk'])) throw new \RuntimeException('Template language choices must come only from the currently enabled site languages.');
    $GLOBALS['gkod_wpml_languages'] = [];
    $GLOBALS['gkod_pll_languages'] = ['de' => ['slug' => 'de', 'name' => 'Deutsch'], 'ru' => ['slug' => 'ru', 'name' => 'Русский']];
    $enabled_languages = Thank_You_Template_Admin::language_options();
    if (array_keys($enabled_languages) !== ['any', 'de', 'ru'] || isset($enabled_languages['uk'])) throw new \RuntimeException('Polylang choices must use only its enabled languages.');
    $GLOBALS['gkod_pll_languages'] = [];
    if (array_keys(Thank_You_Template_Admin::language_options()) !== ['any', 'de_de']) throw new \RuntimeException('A single-language WordPress site must use its configured locale only.');

    $_POST['body_text'] = "First paragraph\nSecond paragraph";
    if (Input::textarea('body_text') !== "First paragraph\nSecond paragraph") throw new \RuntimeException('Textarea input must preserve line breaks.');

    $fields = Thank_You_Template_Admin::defaults()['fields'];
    if (count($fields) !== 9 || !isset($fields['name'], $fields['body'], $fields['quote'], $fields['extra'], $fields['signer'], $fields['address'])) throw new \RuntimeException('The editable letter fields are incomplete.');
    $pdf = Thank_You::generate_sample();
    if (is_wp_error($pdf) || !str_starts_with($pdf, '%PDF-')) throw new \RuntimeException('Sample generation did not return PDF data.');
    if (!str_contains(\Dompdf\Dompdf::$html, 'Alexey Donor') || !str_contains(\Dompdf\Dompdf::$html, 'Donation') || !str_contains(\Dompdf\Dompdf::$html, 'position:absolute') || !str_contains(\Dompdf\Dompdf::$html, 'translate(-50%,-50%)')) throw new \RuntimeException('Sample PDF markup is missing sample data or correctly positioned fields.');
    $templates = Thank_You_Template_Admin::templates();
    $default_id = (string) array_key_first($templates);
    $saved_template = array_merge($templates[$default_id], ['body_text' => "First paragraph\nSecond paragraph", 'quote_text' => 'Bible quote', 'extra_text' => 'Extra note']);
    $saved_template['fields'] = array_merge($saved_template['fields'], ['name' => array_merge($saved_template['fields']['name'], ['x' => 24, 'y' => 31, 'width' => 60, 'size' => 22, 'color' => '#123456', 'align' => 'left']), 'quote' => array_merge($saved_template['fields']['quote'], ['enabled' => true]), 'extra' => array_merge($saved_template['fields']['extra'], ['enabled' => true]), 'amount' => array_merge($saved_template['fields']['amount'], ['enabled' => false])]);
    $GLOBALS['wpdb']->rows[(int) $default_id]['config'] = json_encode($saved_template);
    $GLOBALS['wpdb']->rows[(int) $default_id]['template_name'] = 'Saved default';
    $config = Thank_You_Template_Admin::config();
    if ((float) $config['fields']['name']['x'] !== 24.0 || $config['fields']['name']['color'] !== '#123456' || count($config['fields']) !== 9 || !$config['fields']['quote']['enabled'] || $config['fields']['amount']['enabled']) throw new \RuntimeException('Saved field placement, visibility or template defaults were not merged.');
    Thank_You::generate_sample();
    $html = \Dompdf\Dompdf::$html;
    if (!str_contains($html, 'First paragraph<br>Second paragraph') || str_contains($html, '<br>\n') || !str_contains($html, '24 October 2026') || !str_contains($html, 'Bible quote') || !str_contains($html, 'Extra note') || str_contains($html, '40,00 EUR')) throw new \RuntimeException('Newlines, local date, optional fields or extra text blocks are not rendered correctly.');
    if (Thank_You::send_test_copy(0, 'test@example.org') !== true || empty($GLOBALS['gkod_test_mail_attachment_ok'])) throw new \RuntimeException('The test email attachment must be a valid .pdf file.');
    $GLOBALS['gkod_test_order'] = (object) ['id' => 101, 'language' => 'en', 'payment_status' => 'paid', 'donor_email' => 'galina@example.org', 'billing_name' => 'Galina Filenkova', 'donor_name' => '', 'amount_cents' => 4000, 'currency' => 'EUR', 'paid_at' => '2026-10-24 12:00:00', 'created_at' => '2026-10-24 12:00:00', 'campaign_id' => 0, 'intention' => 'none'];
    if (Thank_You::send_order_to(101, '', false) !== true || !str_starts_with($GLOBALS['gkod_test_mail_message'] ?? '', "Hello, Galina Filenkova!\n\n")) throw new \RuntimeException('Thank-you email must address the donor by the saved billing name.');
    if (str_contains($GLOBALS['gkod_test_mail_message'], 'Dear friend') || !str_contains($GLOBALS['gkod_test_mail_message'], "\n\nPlease find your personal letter")) throw new \RuntimeException('Thank-you email must not use a generic greeting and must preserve paragraph breaks.');
    $ru_template = array_merge(Thank_You_Template_Admin::defaults(), ['template_name' => 'Russian prayer', 'language' => 'ru', 'occasion' => 'prayer', 'body_text' => 'Russian prayer letter']);
    $de_template = array_merge(Thank_You_Template_Admin::defaults(), ['template_name' => 'German donation', 'language' => 'de', 'occasion' => 'donation', 'body_text' => 'German donation letter']);
    $GLOBALS['wpdb']->insert('wp_gkod_thank_you_templates', ['template_name' => $ru_template['template_name'], 'language' => 'ru', 'occasion' => 'prayer', 'config' => json_encode($ru_template)]);
    $GLOBALS['wpdb']->insert('wp_gkod_thank_you_templates', ['template_name' => $de_template['template_name'], 'language' => 'de', 'occasion' => 'donation', 'config' => json_encode($de_template)]);
    $ru_id = (string) ($GLOBALS['wpdb']->insert_id - 1);
    $de_id = (string) $GLOBALS['wpdb']->insert_id;
    $GLOBALS['gkod_test_options']['gkod_thank_you_selected_template'] = $default_id;
    $ru_prayer = Thank_You_Template_Admin::config((object) ['language' => 'ru', 'intention' => 'health', 'subscription_id' => 0], $ru_id);
    $de_donation = Thank_You_Template_Admin::config((object) ['language' => 'de', 'intention' => 'none', 'subscription_id' => 0], $de_id);
    $manual_fallback = Thank_You_Template_Admin::config((object) ['language' => 'ru', 'intention' => 'none', 'subscription_id' => 7], $default_id);
    if ($ru_prayer['body_text'] !== 'Russian prayer letter' || $de_donation['body_text'] !== 'German donation letter' || $manual_fallback['body_text'] !== "First paragraph\nSecond paragraph") throw new \RuntimeException('Explicit template selection or the configured default failed.');
    Thank_You::generate_sample($ru_id);
    if (!str_contains(\Dompdf\Dompdf::$html, 'Russian prayer letter')) throw new \RuntimeException('Explicit sample preview did not render the selected template.');
    echo "PASS thank-you PDF, dedicated-table template lookup, explicit template selection by database ID, multiline text, optional fields and PDF email attachment\n";
}
