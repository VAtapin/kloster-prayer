<?php
declare(strict_types=1);
require __DIR__.'/bootstrap-wordpress.php';
$catalogs=dirname(__DIR__).'/georg-kloster-orthodox-donate/languages';
$languages=['ru'=>'ru_RU','de'=>'de_DE','uk'=>'uk','pl'=>'pl_PL'];
$directory=WP_LANG_DIR.'/plugins';if(!is_dir($directory))wp_mkdir_p($directory);
$backups=[];
try{
    foreach($languages as$locale){
        $path=$directory.'/georg-kloster-orthodox-donate-'.$locale.'.mo';
        $backups[$path]=is_file($path)?file_get_contents($path):null;
        copy($catalogs.'/georg-kloster-orthodox-donate-'.$locale.'.mo',$path);
    }
    foreach($languages as$lang=>$locale){
        $select=static fn()=>$locale;add_filter('locale',$select);add_filter('determine_locale',$select);
        unload_textdomain('georg-kloster-orthodox-donate',true);
        if(!load_textdomain('georg-kloster-orthodox-donate',$directory.'/georg-kloster-orthodox-donate-'.$locale.'.mo',$locale))throw new RuntimeException('Language pack loader failed');
        $mo=new MO();$mo->import_from_file($directory.'/georg-kloster-orthodox-donate-'.$locale.'.mo');
        if(__('Donations','georg-kloster-orthodox-donate')!==$mo->translate('Donations'))throw new RuntimeException('Native language pack translation failed');
        remove_filter('locale',$select);remove_filter('determine_locale',$select);
        $actual=GeorgKlosterOrthodoxDonate\I18n::in_language(static fn()=>__('Donations','georg-kloster-orthodox-donate'),$lang);
        if($actual!==$mo->translate('Donations'))throw new RuntimeException('Explicit email/PDF locale does not use language packs');
    }
    if(glob(GeorgKlosterOrthodoxDonate\DIR.'/languages/*.mo'))throw new RuntimeException('Use the WordPress.org ZIP without bundled MO for this check');
    echo "PASS language packs: native WordPress and explicit request/email/PDF translations in four locales without bundled MO\n";
}finally{
    foreach($backups as$path=>$contents){if($contents===null)unlink($path);else file_put_contents($path,$contents);}
}
