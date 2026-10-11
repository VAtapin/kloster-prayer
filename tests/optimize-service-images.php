<?php
declare(strict_types=1);
$dir=dirname(__DIR__).'/georg-kloster-orthodox-donate/assets/img/services/';
foreach(glob($dir.'*.png') as $file){
    $source=imagecreatefrompng($file);$width=imagesx($source);$height=imagesy($source);
    $w=min(768,$width);$h=(int)round($height*$w/$width);$target=imagecreatetruecolor($w,$h);
    imagecopyresampled($target,$source,0,0,0,0,$w,$h,$width,$height);
    $output=substr($file,0,-4).'.webp';
    if(!imagewebp($target,$output,76)||!getimagesize($output)||filesize($output)>100000)throw new RuntimeException('Image validation failed');
    printf("%s: %d -> %d bytes (%dx%d)\n",basename($file),filesize($file),filesize($output),$w,$h);
}
