<?php
declare(strict_types=1);
require __DIR__.'/donations.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
// Core menu checks relative files: run from WordPress, not the repository's plugin folder.
chdir(ABSPATH);
if(!defined('WP_ADMIN'))define('WP_ADMIN',true);
wp_set_current_user(1);
remove_all_actions('admin_menu');
// Match plugin initialization: feature submenus are queued before Admin::init().
GeorgKlosterOrthodoxDonate\Donation_Pages::init();GeorgKlosterOrthodoxDonate\Support_Pages::init();GeorgKlosterOrthodoxDonate\Admin::init();
$self='admin.php';$parent_file='georg-kloster-orthodox-donate';$submenu_file='';$plugin_page='';$typenow='';$menu=[];$submenu=[];
ob_start();require_once ABSPATH.'wp-admin/menu-header.php';ob_end_clean();
foreach(['en','ru','de','uk','pl'] as $language){
    $menu=[];$submenu=[];$admin_page_hooks=[];$_registered_pages=[];$_parent_pages=[];
    GeorgKlosterOrthodoxDonate\I18n::in_language(static function(){do_action('admin_menu');return '';},$language);
    $labels=['en'=>'Donations','ru'=>'Пожертвования','de'=>'Spenden','uk'=>'Пожертви','pl'=>'Darowizny'];
    $parent=array_values(array_filter($menu,static fn($item)=>$item[2]==='georg-kloster-orthodox-donate'));
    donation_assert(count($parent)===1&&$parent[0][0]===$labels[$language],'Donation menu name uses the current WordPress translation');
    foreach(['georg-kloster-orthodox-donate-campaigns','georg-kloster-orthodox-donate-support','georg-kloster-orthodox-donate-help'] as $slug){
        $hook=get_plugin_page_hook($slug,'georg-kloster-orthodox-donate');
        donation_assert((bool)$hook,'WordPress resolves the submenu callback: '.$language.'/'.$slug);
        donation_assert(isset($_registered_pages[$hook]),'Page is registered under its actual parent hook');
        ob_start();do_action($hook);$html=ob_get_clean();
        donation_assert(str_contains($html,'class="wrap'),'Registered page callback actually renders');
    }
    ob_start();_wp_menu_output($menu,$submenu);$navigation=ob_get_clean();
    file_put_contents(dirname(__DIR__).'/tmp/admin-navigation.html',$navigation);
    foreach(['georg-kloster-orthodox-donate-campaigns','georg-kloster-orthodox-donate-support'] as $slug){
        donation_assert(str_contains($navigation,"href='admin.php?page=".$slug."'"),'Core menu renders the correct admin URL');
        donation_assert(!str_contains($navigation,"href='".$slug."'"),'No bare wp-admin path');
    }
}
echo "PASS admin navigation: actual initialization order, parent hooks and working callbacks in five languages\n";
