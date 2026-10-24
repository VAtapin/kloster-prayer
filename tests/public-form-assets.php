<?php
declare(strict_types=1);

define('GeorgKlosterOrthodoxDonate\\VERSION', '1.0.6');
define('GeorgKlosterOrthodoxDonate\\FILE', __FILE__);
define('GeorgKlosterOrthodoxDonate\\DIR', dirname(__DIR__) . '/georg-kloster-orthodox-donations');

$registered = [];
function plugins_url(string $path, string $plugin): string { return $path; }
function wp_register_style(string $handle, string $src, array $deps = [], string|false $version = false): void { $GLOBALS['registered']['style'] = $version; }
function wp_register_script(string $handle, string $src, array $deps = [], string|false $version = false, bool $in_footer = false): void { $GLOBALS['registered']['script'] = $version; }
function wp_enqueue_style(string $handle): void {}

require \GeorgKlosterOrthodoxDonate\DIR . '/includes/class-public-form.php';
\GeorgKlosterOrthodoxDonate\Public_Form::register_assets();

$root = \GeorgKlosterOrthodoxDonate\DIR;
foreach (['assets/css/public.css' => 'style', 'assets/js/public.js' => 'script'] as $file => $asset) {
    $expected = \GeorgKlosterOrthodoxDonate\VERSION . '.' . substr(md5_file($root . '/' . $file), 0, 12);
    if (($registered[$asset] ?? null) !== $expected) throw new RuntimeException('Asset cache key must change when file contents change: ' . $file);
}

$css = file_get_contents($root . '/assets/css/public.css');
foreach ([
    '.gkod-submit{background:var(--gkod-accent)',
    '.gkod-intention input:checked+span{background:var(--gkod-accent);border-color:var(--gkod-accent)',
    '.gkod-service:has(input:checked){border-color:var(--gkod-accent)',
    '.gkod-payment input:checked+span{border-color:var(--gkod-accent)',
    '.gkod-form{--gkod-accent:var(--wp--preset--color--primary,#8c2732);position:relative;background:transparent;border:1px solid #dfe6eb',
] as $rule) {
    if (!str_contains($css, $rule)) throw new RuntimeException('Configured accent/surface rule missing: ' . $rule);
}
foreach (['.gkod-intention input[value="health"]:checked+span', '.gkod-intention input[value="rest"]:checked+span'] as $hardcoded_rule) {
    if (str_contains($css, $hardcoded_rule)) throw new RuntimeException('Intention colors must follow the form accent');
}

echo "PASS public form accent selectors, transparent bordered surface and content-hashed cache keys\n";
