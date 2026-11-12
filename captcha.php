<?php
/**
 * 验证码生成器
 * 生成 4位数字验证码图片，存入 session
 */
session_start();
header('Content-Type: image/png');

$width  = 100;
$height = 36;

$image = imagecreatetruecolor($width, $height);

// 背景色 (浅灰)
$bg = imagecolorallocate($image, 245, 245, 245);
imagefill($image, 0, 0, $bg);

// 生成验证码
$chars = 'ABCDEFGHIJKLMNPQRSTUVWXYZ23456789';
$code  = '';
for ($i = 0; $i < 4; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['captcha_code'] = $code;

// 字体大小与颜色
$fontSize = 18;
$fontFile = __DIR__ . '/assets/fonts/captcha.ttf';

// 干扰线
for ($i = 0; $i < 3; $i++) {
    $lineColor = imagecolorallocate($image, rand(180, 220), rand(180, 220), rand(180, 220));
    imageline($image, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $lineColor);
}

// 干扰点
for ($i = 0; $i < 80; $i++) {
    $dotColor = imagecolorallocate($image, rand(180, 220), rand(180, 220), rand(180, 220));
    imagesetpixel($image, rand(0, $width), rand(0, $height), $dotColor);
}

if ($fontFile && file_exists($fontFile)) {
    // 使用 TTF 字体
    $charWidth = $width / 4;
    for ($i = 0; $i < 4; $i++) {
        $char     = $code[$i];
        $angle    = rand(-15, 15);
        $textColor = imagecolorallocate($image, rand(50, 120), rand(50, 120), rand(50, 120));
        $x = 8 + $charWidth * $i + rand(-3, 3);
        $y = 26 + rand(-3, 3);
        imagettftext($image, $fontSize, $angle, $x, $y, $textColor, $fontFile, $char);
    }
} else {
    // 无 TTF 字体时使用内置字体（效果较差但保证可用）
    for ($i = 0; $i < 4; $i++) {
        $char     = $code[$i];
        $textColor = imagecolorallocate($image, rand(50, 120), rand(50, 120), rand(50, 120));
        $x = 15 + 20 * $i;
        $y = rand(5, 12);
        imagestring($image, 5, $x, $y, $char, $textColor);
    }
}

imagepng($image);
imagedestroy($image);
