<?php
/**
 * 封面预览图生成器
 * 接收 style + surname 参数，返回 160×224 缩略预览图
 * 使用缓存避免重复生成
 */
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

$style = isset($_GET['style']) ? preg_replace('/[^a-z]/', '', $_GET['style']) : 'ancient';
$surname = isset($_GET['surname']) ? $_GET['surname'] : '李';
$title   = isset($_GET['title']) ? $_GET['title'] : '';
if (mb_strlen($surname) > 3) $surname = mb_substr($surname, 0, 3);

// 检查缓存（title也参与缓存key）
$cacheDir = __DIR__ . '/uploads/covers/previews/';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

$cacheKey = $style . '_' . md5($surname . '|' . $title) . '.png';
$cachePath = $cacheDir . $cacheKey;

if (file_exists($cachePath)) {
    readfile($cachePath);
    exit;
}

// 生成预览
require_once __DIR__ . '/includes/CoverGenerator.php';

try {
    $gen = new CoverGenerator();
    // 使用反射修改尺寸为缩略图
    $ref = new \ReflectionClass($gen);
    $propW = $ref->getProperty('width');
    $propH = $ref->getProperty('height');
    $propW->setAccessible(true);
    $propH->setAccessible(true);
    $propW->setValue($gen, 160);
    $propH->setValue($gen, 224);

    $displayTitle = $title ?: '族谱';
    $path = $gen->generate($surname, $displayTitle, '', $style);

    // 复制到缓存
    if (file_exists(__DIR__ . '/' . $path)) {
        copy(__DIR__ . '/' . $path, $cachePath);
        readfile($cachePath);

        // 清理临时文件
        @unlink(__DIR__ . '/' . $path);
    } else {
        // 降级：输出占位图
        outputPlaceholder($style, $surname);
    }
} catch (\Exception $e) {
    outputPlaceholder($style, $surname);
}

/**
 * 输出占位预览图
 */
function outputPlaceholder($style, $surname)
{
    $img = imagecreatetruecolor(160, 224);
    $bg = imagecolorallocate($img, 240, 235, 220);
    imagefill($img, 0, 0, $bg);

    $border = imagecolorallocate($img, 180, 150, 100);
    imagerectangle($img, 0, 0, 159, 223, $border);

    $textColor = imagecolorallocate($img, 120, 80, 30);
    $x = 80 - imagefontwidth(5) * mb_strlen($surname . '氏') / 2;
    imagestring($img, 5, (int)$x, 100, $surname . '氏', $textColor);

    imagepng($img);
    imagedestroy($img);
}
