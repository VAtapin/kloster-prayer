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
    '.gkod-intention input[value="health"]:checked+span{background:var(--gkod-health-accent);border-color:var(--gkod-health-accent)',
    '.gkod-intention input[value="rest"]:checked+span{background:var(--gkod-rest-accent);border-color:var(--gkod-rest-accent)',
    '.gkod-form{--gkod-accent:var(--wp--preset--color--primary,#8c2732);--gkod-health-accent:#ad5754;--gkod-rest-accent:#595959;position:relative;background:transparent;border:1px solid #dfe6eb',
    '.gkod-form__header,.gkod-form--cards .gkod-form__header{padding:1.5rem clamp(1rem,3vw,1.75rem);color:inherit;background:transparent;border:0}',
    '.gkod-form__body,.gkod-form--cards .gkod-form__body{padding:clamp(1rem,3vw,1.75rem);background:transparent}',
    'section.gkod-form>header.gkod-form__header{background:transparent}',
    'section.gkod-form>form.gkod-form__body{background:transparent}',
] as $rule) {
    if (!str_contains($css, $rule)) throw new RuntimeException('Configured intention color/transparent surface rule missing: ' . $rule);
}
preg_match_all('/([^{}]*\.gkod-submit[^{}]*)\{([^{}]*)\}/', $css, $submit_rules, PREG_SET_ORDER);
foreach ($submit_rules as [, $selector, $declarations]) {
    if (preg_match('/(?:background|color|padding|border|border-radius|box-shadow|font|min-height)\s*:/', $declarations)) {
        throw new RuntimeException('Submit buttons must use WordPress theme styling: ' . trim($selector));
    }
}
$form_markup = file_get_contents($root . '/includes/class-public-form.php');
if (!str_contains($form_markup, 'class="wp-element-button gkod-submit"')) throw new RuntimeException('Submit button must use the WordPress theme button class');
echo "PASS independent intention colors, transparent bordered form, theme-styled submit buttons and content-hashed cache keys\n";
