<?php
function gen($r, $g, $b) {
    $im = imagecreate(1, 1);
    imagecolorallocate($im, $r, $g, $b);
    ob_start();
    imagepng($im);
    return base64_encode(ob_get_clean());
}
echo "PNG_ONE: " . gen(0, 0, 0) . "\n";
echo "PNG_TWO: " . gen(255, 255, 255) . "\n";
echo "PNG_THREE: " . gen(255, 0, 0) . "\n";
