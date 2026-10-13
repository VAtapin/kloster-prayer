<?php
declare(strict_types=1);
require __DIR__.'/bootstrap-wordpress.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once $site.'/wp-content/plugins/plugin-check/vendor/autoload.php';
$readme=file_get_contents(GeorgKlosterOrthodoxDonate\DIR.'/readme.txt');
function release_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$parser=new WordPress\Plugin_Check\Lib\Readme\Parser($readme);
$metadata=get_plugin_data(GeorgKlosterOrthodoxDonate\FILE,false,false);
release_assert($parser->name===$metadata['Name'],'Readme and plugin names agree');
release_assert($parser->stable_tag===$metadata['Version']&&$metadata['Version']===GeorgKlosterOrthodoxDonate\VERSION,'All release versions agree');
release_assert($metadata['TextDomain']===basename(GeorgKlosterOrthodoxDonate\DIR),'Text domain matches package folder');
release_assert($metadata['TextDomain']==='georg-kloster-orthodox-donations','Text domain matches assigned WordPress.org slug');
release_assert(basename(GeorgKlosterOrthodoxDonate\FILE)==='georg-kloster-orthodox-donations.php','Main file matches assigned slug');
release_assert($metadata['Name']==='Georg Kloster – Orthodox Donations','Plugin name matches owner-approved name');
release_assert($parser->requires===$metadata['RequiresWP']&&$parser->requires_php===$metadata['RequiresPHP'],'Requirements agree');
release_assert(strlen($readme)<10000&&strlen($parser->short_description)<=150,'Directory readme length limits');
release_assert(!preg_match('/[А-Яа-яЁёІіЇїЄє]/u',$readme),'Default directory readme uses English');
preg_match_all('/^= (\d+\.\d+\.\d+) =\r?$/m',$readme,$versions);
release_assert(count($versions[1])===count(array_unique($versions[1]))&&$versions[1][0]===$metadata['Version'],'Unique current changelog versions');
foreach($versions[1]as$i=>$version)if($i)release_assert(version_compare($versions[1][$i-1],$version,'>'),'Changelog newest first');
release_assert(!str_contains($readme,'1.2.')&&!str_contains($readme,'remain compatible'),'No unrelated legacy release claims');
foreach(array_keys(GeorgKlosterOrthodoxDonate\Shortcode_Help::entries())as$tag)release_assert(str_contains($readme,'['.$tag),'All registered shortcodes documented');
// The directory parser resolves contributors against its local user database;
// this disposable site has no WordPress.org accounts. Do not invent one to pass.
$warnings=$parser->warnings;
if(isset($warnings['contributor_ignored'])){
    release_assert($warnings['contributor_ignored']===['atapin'],'Unexpected contributor syntax');
    unset($warnings['contributor_ignored']);
}
release_assert($warnings===[],'Readme parser warnings: '.json_encode($warnings));
echo "PASS release readiness: WordPress.org parser, English readme, sizes, metadata/version/domain, unique changelog and all shortcodes\n";
