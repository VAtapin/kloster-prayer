<?php
declare(strict_types=1);
$site=realpath($argv[1]??'');
if(!$site||!str_contains(str_replace('\\','/',$site),'/tmp/wporg-review/wordpress'))throw new RuntimeException('Disposable review site required');
$catalogs=dirname(__DIR__).'/georg-kloster-orthodox-donations/languages';
$languages=['ru'=>'ru_RU','de'=>'de_DE','uk'=>'uk','pl'=>'pl_PL'];
$directory=$site.'/wp-content/languages/plugins';if(!is_dir($directory))mkdir($directory,0777,true);
$backups=[];
try{
    foreach($languages as$locale){
        $path=$directory.'/georg-kloster-orthodox-donations-'.$locale.'.mo';
        $backups[$path]=is_file($path)?file_get_contents($path):null;
        copy($catalogs.'/georg-kloster-orthodox-donations-'.$locale.'.mo',$path);
    }
    // Install packs before startup, exactly as on the next real WordPress request.
    require __DIR__.'/bootstrap-wordpress.php';
    foreach($languages as$lang=>$locale){
        $select=static fn()=>$locale;add_filter('locale',$select);add_filter('determine_locale',$select);
        unload_textdomain('georg-kloster-orthodox-donations',true);
        $mo=new MO();$mo->import_from_file($directory.'/georg-kloster-orthodox-donations-'.$locale.'.mo');
        if(__('Donations','georg-kloster-orthodox-donations')!==$mo->translate('Donations'))throw new RuntimeException('Native language pack translation failed');
        remove_filter('locale',$select);remove_filter('determine_locale',$select);
        $actual=GeorgKlosterOrthodoxDonate\I18n::in_language(static fn()=>__('Donations','georg-kloster-orthodox-donations'),$lang);
        if($actual!==$mo->translate('Donations'))throw new RuntimeException('Explicit email/PDF locale does not use language packs');
    }
    if(glob(GeorgKlosterOrthodoxDonate\DIR.'/languages/*.mo'))throw new RuntimeException('Use the WordPress.org ZIP without bundled MO for this check');
    echo "PASS language packs: native WordPress and explicit request/email/PDF translations in four locales without bundled MO\n";
}finally{
    foreach($backups as$path=>$contents){if($contents===null)unlink($path);else file_put_contents($path,$contents);}
}
