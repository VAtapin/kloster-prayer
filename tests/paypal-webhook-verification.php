<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/tmp/admin-check-core/wordpress/');

final class WP_Error
{
    public function __construct(private string $code = '') {}
    public function get_error_code(): string { return $this->code; }
}

final class WP_REST_Request
{
    public function get_header(string $name): string { return 'fixture'; }
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function get_option(string $key, mixed $default = false): mixed
{
    return match ($key) {
        'gkod_paypal_client_id' => 'client-fixture',
        'gkod_paypal_secret' => 'secret-fixture',
        'gkod_paypal_webhook_id' => 'webhook-fixture',
        'gkod_paypal_mode' => 'sandbox',
        default => $default,
    };
}
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function wp_remote_post(string $url, array $args): array|WP_Error
{
    if (str_ends_with($url, '/v1/oauth2/token')) return ['response' => ['code' => 200], 'body' => '{"access_token":"fixture-token"}'];
    return $GLOBALS['paypal_verify_response'];
}
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }

require dirname(__DIR__) . '/georg-kloster-orthodox-donations/includes/class-payments.php';

$verify = new ReflectionMethod(GeorgKlosterOrthodoxDonate\Payments::class, 'verify_paypal_webhook');
$request = new WP_REST_Request();
$event = ['id' => 'WH-fixture'];

$GLOBALS['paypal_verify_response'] = ['response' => ['code' => 200], 'body' => '{"verification_status":"SUCCESS"}'];
if ($verify->invoke(null, $request, $event) !== true) throw new RuntimeException('A successful PayPal verification must be accepted.');

$GLOBALS['paypal_verify_response'] = ['response' => ['code' => 200], 'body' => '{"verification_status":"FAILURE"}'];
if ($verify->invoke(null, $request, $event) !== false) throw new RuntimeException('An invalid signature must remain a client error.');

$GLOBALS['paypal_verify_response'] = new WP_Error('network');
if (!is_wp_error($verify->invoke(null, $request, $event))) throw new RuntimeException('A network failure must be retryable.');

$GLOBALS['paypal_verify_response'] = ['response' => ['code' => 503], 'body' => '{}'];
if (!is_wp_error($verify->invoke(null, $request, $event))) throw new RuntimeException('A PayPal server failure must be retryable.');

echo "PASS PayPal webhook verification distinguishes invalid signatures from temporary verification failures\n";
