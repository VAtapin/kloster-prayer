<?php
declare(strict_types=1);
define('ABSPATH',dirname(__DIR__).'/tmp/admin-check-core/wordpress/');
define('KlosterPrayer\\DIR', dirname(__DIR__).'/kloster-prayer');
require KlosterPrayer\DIR.'/includes/lib/dompdf/autoload.inc.php';
require KlosterPrayer\DIR.'/includes/class-pdf.php';
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$base=['intention'=>'health','language'=>'ru','title'=>'Сорокоуст','order_date'=>'10.10.2026','names'=>['Мария'],'note'=>"Просим помолиться о выздоровлении.\nВторая строка комментария."];
$htmlMethod=new ReflectionMethod(KlosterPrayer\Pdf::class,'modern_html');
$html=$htmlMethod->invoke(null,[$base]);
preg_match('/class="note" style="top:([0-9.]+)mm;[^\"]*">(.*?)<\/div>/s',$html,$match);
$firstName=573*282/3540-5.3;
check(abs((float)$match[1]-($firstName+7))<0.001,'Comment immediately follows one name');
check(str_contains($html,'Вторая строка комментария.'),'Preserve paragraph breaks');
check(!str_contains($html,'top:181mm'),'No fixed footer comment');
$dense=array_merge($base,['names'=>array_map(fn($n)=>'Имя '.$n,range(1,14))]);
$denseHtml=$htmlMethod->invoke(null,[$dense]);
check(str_contains($denseHtml,'Имя 14'),'No lost names when reserving comment space');
$long=array_merge($base,['language'=>'de','note'=>str_repeat('W',400).' '.str_repeat('Длинный комментарий. ',100)]);
$longHtml=$htmlMethod->invoke(null,[$long]);
check(substr_count($longHtml,'class="note"')>1,'Long comment continues onto another slip');
foreach([$denseHtml,$longHtml] as $document){
 preg_match_all('/class="note" style="top:([0-9.]+)mm;[^\"]*">(.*?)<\/div>/s',$document,$notes,PREG_SET_ORDER);
 foreach($notes as $note)check((float)$note[1]+4<=180.001,'Comment clear of date and frame');
}
$escaped=$htmlMethod->invoke(null,[array_merge($base,['note'=>'<script>test</script>'])]);
check(!str_contains($escaped,'<script>'),'Escape comment markup');
$blank=$htmlMethod->invoke(null,[array_merge($base,['names'=>[],'note'=>'','order_date'=>''])]);
check(!str_contains($blank,'class="note"'),'Empty form has no comment');
if(in_array('--render',$argv,true)){
 $options=new Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('defaultFont','DejaVu Sans');$options->setChroot(KlosterPrayer\DIR);
 $pdf=new Dompdf\Dompdf($options);$pdf->setPaper('A4','landscape');$pdf->loadHtml($htmlMethod->invoke(null,[$base,$dense,$long]),'UTF-8');$pdf->render();
 file_put_contents(dirname(__DIR__).'/tmp/pdfs/comment-placement.pdf',$pdf->output());
 echo 'Rendered pages: '.$pdf->getCanvas()->get_page_count()."\n";
}
echo "PASS comment after names, paragraphs, dense names, long comments/words, footer clearance, escaping and blank forms\n";
