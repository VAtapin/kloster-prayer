<?php
declare(strict_types=1);
// Clone the disposable review database; load the selected source or extracted ZIP.
$site=realpath($argv[1]??'');$source=realpath($argv[2]??dirname(__DIR__));
if(!$site||!str_contains(str_replace('\\','/',$site),'/tmp/wporg-review/wordpress'))throw new RuntimeException('Local review WordPress required');
if(!$source||!is_file($source.'/georg-kloster-orthodox-donations/georg-kloster-orthodox-donations.php'))throw new RuntimeException('Plugin source required');
$file='wporg-check-'.bin2hex(random_bytes(6)).'.sqlite';
$db=new PDO('sqlite:'.$site.'/wp-content/database/.ht.sqlite');
$db->exec('VACUUM INTO '.$db->quote($site.'/wp-content/database/'.$file));unset($db);
$db=new PDO('sqlite:'.$site.'/wp-content/database/'.$file);
$options=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%_options' LIMIT 1")->fetchColumn();
if(!is_string($options)||!preg_match('/^[a-zA-Z0-9_]+$/',$options))throw new RuntimeException('Options fixture missing');
$statement=$db->prepare('UPDATE "'.$options.'" SET option_value=? WHERE option_name=\'active_plugins\'');
$statement->execute([serialize(['georg-kloster-orthodox-donations/georg-kloster-orthodox-donations.php'])]);unset($db);
define('DB_FILE',$file);define('WP_PLUGIN_DIR',$source);
require $site.'/wp-load.php';
set_exception_handler(static function(Throwable $error):void{fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});
if(!class_exists(GeorgKlosterOrthodoxDonate\Database::class))require $source.'/georg-kloster-orthodox-donations/georg-kloster-orthodox-donations.php';
require_once GeorgKlosterOrthodoxDonate\DIR.'/includes/class-orders-table.php';
GeorgKlosterOrthodoxDonate\Database::activate();
