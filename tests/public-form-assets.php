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
    '.gkod-donation-form .gkod-intention input[value="health"]:checked + span {',
    '.gkod-donation-form .gkod-intention input[value="rest"]:checked + span {',
    '.gkod-donation-form--two_columns .gkod-grid,',
    '.gkod-donation-form__header {',
] as $rule) {
    if (!str_contains($css, $rule)) throw new RuntimeException('Configured form style rule missing: ' . $rule);
}
preg_match_all('/([^{}]*\.gkod-submit[^{}]*)\{([^{}]*)\}/', $css, $submit_rules, PREG_SET_ORDER);
foreach ($submit_rules as [, $selector, $declarations]) {
    if (preg_match('/(?:background|color|padding|border|border-radius|box-shadow|font|min-height)\s*:/', $declarations)) {
        throw new RuntimeException('Submit buttons must use WordPress theme styling: ' . trim($selector));
    }
}
$form_markup = file_get_contents($root . '/includes/class-public-form.php');
if (!str_contains($form_markup, 'class="wp-element-button gkod-submit"')) throw new RuntimeException('Submit button must use the WordPress theme button class');
if (!str_contains($form_markup, 'data-ajax-url="<?php echo esc_url(admin_url(\'admin-ajax.php\')) ?>"')) throw new RuntimeException('The form must carry its own AJAX endpoint for templates without localized footer scripts');
$script_markup = file_get_contents($root . '/assets/js/public.js');
if (!str_contains($script_markup, 'root.dataset.ajaxUrl || config.ajaxUrl') || !str_contains($script_markup, 'var config = window.georgKlosterOrthodoxDonate || {};')) throw new RuntimeException('Form submission must tolerate missing WordPress-localized script data');
if (str_contains($css, '.gkod-form')) throw new RuntimeException('The donation form selectors must not collide with the theme’s generic .gkod-form rule');
if (preg_match('/^\.gkod-donation-form\s*\{[^}]*\bbackground(?:-color)?\s*:/ms', $css)) throw new RuntimeException('The form root must inherit the page background without a background declaration');
echo "PASS isolated form selectors, theme-provided page background and buttons, independent intention colors and content-hashed cache keys\n";
