<?php
declare(strict_types=1);
require __DIR__.'/bootstrap-wordpress.php';
if(!WP_DEBUG)throw new RuntimeException('WP_DEBUG must be enabled');
foreach(['ru_RU','de_DE','uk','pl_PL']as$locale){
    $select=static fn()=>$locale;add_filter('locale',$select);add_filter('determine_locale',$select);
    unload_textdomain('georg-kloster-orthodox-donations',true);
    $mo=new MO();$mo->import_from_file(GeorgKlosterOrthodoxDonate\DIR.'/languages/georg-kloster-orthodox-donations-'.$locale.'.mo');
    if(__('Donations','georg-kloster-orthodox-donations')!==$mo->translate('Donations'))throw new RuntimeException('Bundled JIT translation failed: '.$locale);
    remove_filter('locale',$select);remove_filter('determine_locale',$select);
}
echo "PASS automatic WordPress JIT translations in four locales; no explicit translation loader\n";
