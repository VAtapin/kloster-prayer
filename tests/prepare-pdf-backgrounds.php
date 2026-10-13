<?php
// Lossless geometry and full source resolution; opaque palette avoids Dompdf's RGBA memory overhead.
foreach (['ZDRAVIE', 'UPOKOI'] as $name) {
    $base = dirname(__DIR__).'/georg-kloster-orthodox-donations/assets/img/'.$name;
    $source = imagecreatefrompng($base.'.png');
    $image = imagecreatetruecolor(imagesx($source), imagesy($source));
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagecopy($image, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
    imagetruecolortopalette($image, false, 256);
    imagepng($image, $base.'-print.png', 9);
    imagedestroy($source);
    imagedestroy($image);
}
