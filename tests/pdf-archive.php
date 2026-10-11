<?php
declare(strict_types=1);
define('ABSPATH',dirname(__DIR__).'/tmp/admin-check-core/wordpress/');
define('KlosterPrayer\\DIR', dirname(__DIR__).'/kloster-prayer');
require KlosterPrayer\DIR.'/includes/class-database.php';
require KlosterPrayer\DIR.'/includes/class-payments.php';
require KlosterPrayer\DIR.'/includes/class-pdf.php';
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function sanitize_text_field($v){return strip_tags((string)$v);}
function wp_unslash($v){return stripslashes((string)$v);}
function absint($v){return abs((int)$v);}
function wp_create_nonce($v){return 'fixture';}
function admin_url($v){return 'https://example.org/wp-admin/'.$v;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
class ArchivePdfDB {
 public $prefix='wp_';public $rows;public $sql='';
 function __construct(){
  $base=['service_title'=>'Молебен','service_id'=>2,'service_slug'=>'moleben','created_at'=>'2026-10-10 12:00:00','printed_at'=>null,'intention'=>'health','language'=>'ru','names'=>'Мария, Иоанн','note'=>''];
  $this->rows=[(object)($base+['id'=>5,'archived_at'=>null]),(object)($base+['id'=>6,'archived_at'=>'2026-10-10 14:00:00'])];
 }
 function prepare($sql,...$args){foreach($args as $arg)$sql=preg_replace_callback('/%[ids]/',fn($m)=>$m[0]==='%i'?'`'.$arg.'`':(is_int($arg)?(string)$arg:"'".$arg."'"),$sql,1);return $sql;}
 function get_results($sql){$this->sql=$sql;return array_values(array_filter($this->rows,function($row)use($sql){
  if(str_contains($sql,'o.archived_at IS NOT NULL')&&$row->archived_at===null)return false;
  if(str_contains($sql,'o.archived_at IS NULL')&&$row->archived_at!==null)return false;
  if(preg_match('/o.id IN \(([^)]+)\)/',$sql,$m)&&!in_array($row->id,array_map('intval',explode(',',$m[1])),true))return false;
  return true;
 }));}
 function get_var($sql){return 10;}
 function update(...$args){throw new RuntimeException('Reading PDF cards must not change records');}
}
$wpdb=new ArchivePdfDB();$read=new ReflectionMethod(KlosterPrayer\Pdf::class,'cards');
$_GET=['order_ids'=>'6'];$archived=$read->invoke(null);
check(count($archived)===1&&$archived[0]['order_id']===6,'Archived individual PDF');
check($archived[0]['names']===['Мария','Иоанн'],'Archived names normalized');
check(!str_contains($wpdb->sql,'archived_at IS'),'Explicit ID is independent of archive view');
$_GET=['order_ids'=>'5,6'];check(count($read->invoke(null))===2,'Mixed selected PDF');
$_GET=[];$active=$read->invoke(null);check(count($active)===1&&$active[0]['order_id']===5,'Default list excludes archive');
$_GET=['archive'=>'yes'];$archiveList=$read->invoke(null);check(count($archiveList)===1&&$archiveList[0]['order_id']===6,'Archive list PDF');
$_GET=['order_ids'=>'6','payment_status'=>'paid','intention'=>'health','printed'=>'yes'];$read->invoke(null);
check(str_contains($wpdb->sql,"o.payment_status='paid'")&&str_contains($wpdb->sql,"o.intention='health'")&&str_contains($wpdb->sql,'o.printed_at IS NOT NULL'),'Other PDF filters retained');
$url=KlosterPrayer\Pdf::url(['archive'=>'yes','order_ids'=>[6]]);check(str_contains($url,'archive=yes')&&str_contains($url,'order_ids=6'),'Archive URL retained');
check($wpdb->rows[1]->archived_at==='2026-10-10 14:00:00','Order remains archived');
if(in_array('--render',$argv,true)){
 require KlosterPrayer\DIR.'/includes/lib/dompdf/autoload.inc.php';
 $options=new Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('defaultFont','DejaVu Sans');$options->setChroot(KlosterPrayer\DIR);
 $pdf=new Dompdf\Dompdf($options);$pdf->setPaper('A4','landscape');$pdf->loadHtml((new ReflectionMethod(KlosterPrayer\Pdf::class,'modern_html'))->invoke(null,$archived),'UTF-8');$pdf->render();
 $bytes=$pdf->output();check(str_starts_with($bytes,'%PDF-'),'Actual PDF');check($pdf->getCanvas()->get_page_count()===1,'Archived slip rendered');
 file_put_contents(dirname(__DIR__).'/tmp/pdfs/archived-order-6.pdf',$bytes);
}
echo "PASS archived single/selected/list PDF, active default, filters, names and unchanged archive state\n";
