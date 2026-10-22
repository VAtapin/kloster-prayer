<?php
declare(strict_types=1);
namespace GeorgKlosterOrthodoxDonate;

/** Normalize request values; authorization/nonces stay at the action entry points. */
final class Input
{
    public static function get(string $key): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only normalization; view filters do not change state.
        return self::scalar($_GET, $key);
    }

    public static function post(string $key): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Admin/AJAX callers verify their nonce before invoking actions.
        return self::scalar($_POST, $key);
    }

    public static function textarea(string $key): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Admin/AJAX callers verify their nonce before invoking actions.
        return self::scalar($_POST, $key, true);
    }

    public static function post_html(string $key): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called after the campaign action nonce is verified.
        if (!isset($_POST[$key])) return null;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rich text is unslashed and passed through wp_kses_post before persistence.
        $value = $_POST[$key];
        return is_scalar($value) ? wp_kses_post(wp_unslash((string) $value)) : '';
    }

    public static function post_list(string $key): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- List normalization only; action authorization remains mandatory.
        $values = map_deep(wp_unslash($_POST[$key] ?? []), 'sanitize_text_field');
        $values = is_array($values) ? $values : [$values];
        return array_values(array_map(static fn($value): string => is_scalar($value) ? (string)$value : '', $values));
    }

    public static function get_all(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters are normalized here, not executed.
        return self::fields($_GET);
    }

    public static function post_all(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called by an already-authorized action to normalize its filters.
        return self::fields($_POST);
    }

    public static function request(string $key): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only used for a read-only search string.
        return self::scalar($_REQUEST, $key);
    }

    public static function client_ip(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked for scalar shape, unslashed and validated as an IP immediately below.
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';
        $value = is_scalar($raw) ? sanitize_text_field(wp_unslash((string)$raw)) : '';
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    private static function scalar(array $values, string $key, bool $textarea = false): ?string
    {
        if (!isset($values[$key])) return null;
        if (!is_scalar($values[$key])) return '';
        $value = wp_unslash((string)$values[$key]);
        return $textarea ? sanitize_textarea_field($value) : sanitize_text_field($value);
    }

    private static function fields(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) if (is_string($key) && is_scalar($value)) $result[$key] = sanitize_text_field(wp_unslash((string)$value));
        return $result;
    }
}
