<?php
declare(strict_types=1);
// Compile repository PO catalogs with the same POMO implementation as WordPress.
define('ABSPATH',dirname(__DIR__).'/tmp/admin-check-core/wordpress/');
define('WPINC','wp-includes');
require_once ABSPATH.'wp-includes/compat.php';
require_once ABSPATH.'wp-includes/pomo/po.php';
require_once ABSPATH.'wp-includes/pomo/mo.php';
foreach(glob(dirname(__DIR__).'/georg-kloster-orthodox-donations/languages/*.po')as$file){
    $po=new PO();if(!$po->import_from_file($file))throw new RuntimeException('Invalid PO: '.$file);
    $mo=new MO();$mo->headers=$po->headers;
    foreach($po->entries as$entry)$mo->add_entry($entry);
    if(!$mo->export_to_file(substr($file,0,-3).'.mo'))throw new RuntimeException('MO export failed');
    echo 'PASS '.basename($file).' -> MO ('.count($mo->entries)." entries)\n";
}
