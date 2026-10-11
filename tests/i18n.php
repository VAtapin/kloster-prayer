<?php
declare(strict_types=1);
// Standalone WordPress gettext/POMO regression check, without a database.
define('ABSPATH', dirname(__DIR__).'/tmp/admin-check-core/wordpress/');
require __DIR__.'/bootstrap-i18n.php';
function ensure($ok,$message){if(!$ok)throw new RuntimeException($message);}
require_once ABSPATH.'wp-includes/pomo/po.php';
$root=dirname(__DIR__).'/georg-kloster-orthodox-donate';
$pot=new PO();ensure($pot->import_from_file($root.'/languages/georg-kloster-orthodox-donate.pot'),'POT loads');
$old=json_decode(file_get_contents(__DIR__.'/fixtures/public-translations.json'),true,512,JSON_THROW_ON_ERROR);
$donation=json_decode(file_get_contents(__DIR__.'/fixtures/donation-translations.json'),true,512,JSON_THROW_ON_ERROR);
foreach(['ru'=>'ru_RU','de'=>'de_DE','uk'=>'uk','pl'=>'pl_PL'] as $language=>$locale){
    $po=new PO();$mo=new MO();
    ensure($po->import_from_file($root.'/languages/georg-kloster-orthodox-donate-'.$locale.'.po'),'PO loads: '.$locale);
    ensure($mo->import_from_file($root.'/languages/georg-kloster-orthodox-donate-'.$locale.'.mo'),'MO loads: '.$locale);
    foreach($pot->entries as $entry){
        ensure(!preg_match('/[А-Яа-яЁёІіЇїЄє]/u',$entry->singular),'English msgid: '.$entry->singular);
        $translated=$po->translate_entry($entry);
        ensure($translated&&($translated->translations[0]??'')!=='','Complete translation: '.$locale.' '.$entry->singular);
        ensure($mo->translate($entry->singular)===$translated->translations[0],'MO matches PO: '.$locale.' '.$entry->singular);
        preg_match_all('/%(?:\d+\$)?[ds]/',$entry->singular,$a);preg_match_all('/%(?:\d+\$)?[ds]/',$translated->translations[0],$b);
        sort($a[0]);sort($b[0]);ensure($a[0]===$b[0],'Format arguments preserved');
    }
    foreach($old as $key=>$values)ensure(GeorgKlosterOrthodoxDonate\I18n::text($key,$language)===$values[$language],'Existing public translation retained: '.$language.'/'.$key);
    foreach($donation as $key=>$values)ensure(GeorgKlosterOrthodoxDonate\I18n::donation_text($key,$language)===$values[$language],'Existing donation translation retained: '.$language.'/'.$key);
    ensure(GeorgKlosterOrthodoxDonate\I18n::text('order_date',$language)===$mo->translate('Order date:'),'PDF date language');
    ensure(GeorgKlosterOrthodoxDonate\I18n::text('mail_subject',$language)===$mo->translate('Your prayer request has been received'),'Email language');
}
ensure(GeorgKlosterOrthodoxDonate\I18n::text('health','en')==='For the living','English source without translation');
ensure(GeorgKlosterOrthodoxDonate\I18n::text('names_placeholder','en')==="Name\nName\nName",'English multiline placeholder');
ensure($GLOBALS['fixture_gettext_filters']===[],'Gettext scope restored');
try{GeorgKlosterOrthodoxDonate\I18n::in_language(static function(){throw new RuntimeException('fixture');},'de');}catch(RuntimeException $e){}
ensure($GLOBALS['fixture_gettext_filters']===[],'Gettext scope restored after exception');
foreach(glob($root.'/includes/*.php') as $file){
    if(basename($file)==='class-database.php')continue; // Locale-specific seed data, not interface source strings.
    foreach(token_get_all(file_get_contents($file)) as $token){
        if(!is_array($token)||!in_array($token[0],[T_CONSTANT_ENCAPSED_STRING,T_INLINE_HTML],true))continue;
        if(basename($file)==='class-pdf.php'&&str_contains($token[1],'сорокоуст|sorokoust'))continue; // Match saved service content.
        ensure(!preg_match('/[А-Яа-яЁёІіЇїЄє]/u',$token[1]),'No non-English UI literal in '.basename($file));
    }
}
echo 'PASS English sources, '.count($pot->entries)." messages, complete PO/MO catalogs, all four original public translations, explicit email/PDF language and gettext cleanup\n";
