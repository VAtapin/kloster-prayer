<?php
declare(strict_types=1);
$site=realpath($argv[1]??'');
if(!$site||!str_contains(str_replace('\\','/',$site),'/tmp/wporg-review/wordpress'))throw new RuntimeException('Local review site required');
require $site.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/template.php';
use KlosterPrayer\I18n;
use KlosterPrayer\Admin_Screens;
use KlosterPrayer\Public_Form;
function ensure_i18n($ok,$message){if(!$ok)throw new RuntimeException($message);}
wp_set_current_user(1);
$before=determine_locale();
ensure_i18n(I18n::language()==='en','US English defaults to English, not German');
ensure_i18n(__('Settings','georg-kloster-orthodox-donate')==='Settings','English admin source');
$legacy=json_decode(file_get_contents(__DIR__.'/fixtures/public-translations.json'),true);
$donation=json_decode(file_get_contents(__DIR__.'/fixtures/donation-translations.json'),true);
foreach(['ru'=>'ru_RU','de'=>'de_DE','uk'=>'uk','pl'=>'pl_PL'] as $lang=>$testLocale){
    $mo=new MO();ensure_i18n($mo->import_from_file(__DIR__.'/../georg-kloster-orthodox-donate/languages/georg-kloster-orthodox-donate-'.$testLocale.'.mo'),'MO loads');
    foreach($legacy as $key=>$values){$actual=I18n::text($key,$lang);ensure_i18n($actual===$values[$lang],'Original form translation: '.$lang.'/'.$key.' ('.I18n::language().'): '.$actual);}
    $html=I18n::in_language(static function():string{ob_start();Admin_Screens::settings();return ob_get_clean();},$lang);
    ensure_i18n(str_contains($html,esc_html($mo->translate('Payment settings'))),'Admin settings translation: '.$lang);
    ensure_i18n(str_contains($html,esc_html($mo->translate('PayPal setup — step-by-step instructions'))),'PayPal help translation: '.$lang);
    $form=Public_Form::shortcode(['lang'=>$lang]);
    ensure_i18n(str_contains($form,esc_html($legacy['choose'][$lang])),'Prayer form translation: '.$lang);
    $html=Public_Form::donation_shortcode(['lang'=>$lang]);
    ensure_i18n(str_contains($html,esc_html($donation['title'][$lang]))&&str_contains($html,esc_html($donation['submit'][$lang])),'Donation translation: '.$lang);
    ensure_i18n(determine_locale()===$before,'Current locale is unchanged');
}
$html=Public_Form::donation_shortcode(['lang'=>'en']);
ensure_i18n(str_contains($html,'Donation for the church')&&!str_contains($html,'Spende für die Kirche'),'English donation title');
ensure_i18n(__('Settings','georg-kloster-orthodox-donate')==='Settings','Gettext filters restored');
// Check that normal WordPress gettext loads the plugin catalog for each locale,
// independently of core language-pack installation and the explicit-language helper.
foreach(['ru_RU','de_DE','uk','pl_PL'] as $testLocale){
    $select=static fn()=>$testLocale;
    add_filter('locale',$select);add_filter('determine_locale',$select);
    unload_textdomain('georg-kloster-orthodox-donate',true);
    ensure_i18n(load_textdomain('georg-kloster-orthodox-donate',__DIR__.'/../georg-kloster-orthodox-donate/languages/georg-kloster-orthodox-donate-'.$testLocale.'.mo',$testLocale),'WordPress catalog load: '.$testLocale);
    $mo=new MO();$mo->import_from_file(__DIR__.'/../georg-kloster-orthodox-donate/languages/georg-kloster-orthodox-donate-'.$testLocale.'.mo');
    ensure_i18n(__('Settings','georg-kloster-orthodox-donate')===$mo->translate('Settings'),'Native gettext: '.$testLocale);
    remove_filter('locale',$select);remove_filter('determine_locale',$select);
}
echo "PASS real WordPress English default, four native gettext catalogs, prayer/donation/admin/PayPal help languages and locale isolation\n";
