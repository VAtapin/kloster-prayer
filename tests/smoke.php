<?php
declare(strict_types=1);
define('ABSPATH',dirname(__DIR__).'/tmp/admin-check-core/wordpress/');
require __DIR__.'/bootstrap-i18n.php';

function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

define('GeorgKlosterOrthodoxDonate\\DIR', dirname(__DIR__) . '/georg-kloster-orthodox-donations');

require dirname(__DIR__) . '/georg-kloster-orthodox-donations/includes/lib/dompdf/autoload.inc.php';
require dirname(__DIR__) . '/georg-kloster-orthodox-donations/includes/class-payments.php';
require dirname(__DIR__) . '/georg-kloster-orthodox-donations/includes/class-pdf.php';

$payload = '{"id":"evt_test"}';
$timestamp = time();
$secret = 'whsec_test_secret';
$signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
$verify = new ReflectionMethod(\GeorgKlosterOrthodoxDonate\Payments::class, 'verify_stripe_signature');
assert($verify->invoke(null, $payload, "t={$timestamp},v1={$signature}", $secret) === true);
assert($verify->invoke(null, $payload . 'x', "t={$timestamp},v1={$signature}", $secret) === false);
assert($verify->invoke(null, $payload, 't=1,v1=' . $signature, $secret) === false);

$cards = [
    ['intention' => 'health', 'language' => 'ru', 'title' => 'Сорокоуст', 'order_date'=>'08.09.2026', 'names' => ['Болящего Леонида', 'Марии', 'Иоанна'], 'note' => ''],
    ['intention' => 'health', 'language' => 'de', 'title' => 'Akathistos', 'names' => ['Alexander', 'Maria', 'Johannes'], 'note' => 'Für Reisende'],
    ['intention' => 'rest', 'language' => 'de', 'title' => 'Panichida', 'names' => ['Alexej', 'Vera', 'Sergij'], 'note' => ''],
    ['intention' => 'rest', 'language' => 'ru', 'title' => 'Сорокоуст', 'names' => ['Анны', 'Владимира'], 'note' => ''],
];
assert(\GeorgKlosterOrthodoxDonate\Payments::split_names(" Мария, Иоанн\nБолящего Леонида,, Анна-Мария;") === ['Мария','Иоанн','Болящего Леонида','Анна-Мария']);
$cards[0]['names'] = \GeorgKlosterOrthodoxDonate\Payments::split_names('Болящего Леонида, Марии, Иоанна');
$htmlMethod = new ReflectionMethod(\GeorgKlosterOrthodoxDonate\Pdf::class, 'modern_html');
$html = $htmlMethod->invoke(null, $cards);
assert(is_file(\GeorgKlosterOrthodoxDonate\DIR.'/assets/img/ZDRAVIE-print.png'));
assert(is_file(\GeorgKlosterOrthodoxDonate\DIR.'/assets/img/UPOKOI-print.png'));
assert(!str_contains($html, 'data:image/svg'));
assert(str_contains($html, 'data:image/png;base64,'));
assert(substr_count($html, 'class="page"') === 4);
assert(substr_count($html, 'class="card ') === 12);
$mixed = [$cards[0], array_merge($cards[0], ['title'=>'Молебен','order_date'=>'']), array_merge($cards[0], ['title'=>'Акафист','order_date'=>''])];
$mixedHtml = $htmlMethod->invoke(null, $mixed);
assert(substr_count($mixedHtml, 'class="page"') === 1);
assert(str_contains($mixedHtml, 'Молебен') && str_contains($mixedHtml, 'Акафист'));
$overflow = $htmlMethod->invoke(null, [array_merge($cards[0], ['names'=>array_map(fn($n)=>'Имя '.$n, range(1,46))])]);
assert(substr_count($overflow, 'class="page"') === 2);
assert(str_contains($overflow, 'Имя 46'));
assert(substr_count($mixedHtml, 'Дата заказа:') === 1);
$dateMethod = new ReflectionMethod(\GeorgKlosterOrthodoxDonate\Pdf::class, 'order_date');
assert($dateMethod->invoke(null, (object)['service_slug'=>'sorokoust','created_at'=>'2026-09-08 20:00:00']) === '08.09.2026');
assert($dateMethod->invoke(null, (object)['service_slug'=>'moleben','service_title'=>'Молебен','created_at'=>'2026-09-08']) === '');
$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->setChroot(dirname(__DIR__) . '/georg-kloster-orthodox-donations');
$dompdf = new \Dompdf\Dompdf($options);
$dompdf->setPaper('A4', 'landscape');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->render();
assert($dompdf->getCanvas()->get_page_count() === 4);
$output = dirname(__DIR__) . '/tmp/pdfs/new-plugin-smoke.pdf';
file_put_contents($output, $dompdf->output());
$previewCards = array_merge($mixed, [$cards[1], $cards[2], $cards[3]]);
$preview = new \Dompdf\Dompdf($options);
$preview->setPaper('A4', 'landscape');
$preview->loadHtml($htmlMethod->invoke(null, $previewCards), 'UTF-8');
$preview->render();
assert($preview->getCanvas()->get_page_count() === 4);
if (!is_dir(dirname(__DIR__).'/output/pdf')) mkdir(dirname(__DIR__).'/output/pdf', 0777, true);
file_put_contents(dirname(__DIR__).'/output/pdf/prayer-slips-preview.pdf', $preview->output());
echo "Smoke tests passed\n{$output}\n";
