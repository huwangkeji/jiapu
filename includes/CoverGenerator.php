<?php
/**
 * 家谱封面生成器
 * 使用 PHP GD 库生成多种风格的封面图片
 * 完全本地化，不依赖外部 API
 */

class CoverGenerator
{
    private $width  = 400;
    private $height = 560;
    private $image;

    /** 预设字体（使用GD内置字体，无需外部文件） */
    private function fontPath($size)
    {
        // 尝试加载本地字体，失败时使用GD内置字体
        $fonts = [
            __DIR__ . '/../assets/fonts/SimSun.ttf',
            __DIR__ . '/../assets/fonts/simsun.ttc',
            __DIR__ . '/../assets/fonts/captcha.ttf',
        ];
        foreach ($fonts as $f) {
            if (file_exists($f)) return $f;
        }
        return null;
    }

    /**
     * 生成封面
     * @param string $surname 姓氏
     * @param string $title 谱名
     * @param string $origin 发源地（可选）
     * @param string $style 样式: ancient/modern/scroll/ink/ming
     * @return string|false 封面图片路径
     */
    public function generate($surname, $title, $origin = '', $style = 'ancient')
    {
        $this->image = imagecreatetruecolor($this->width, $this->height);
        imagesavealpha($this->image, true);
        imagealphablending($this->image, true);

        switch ($style) {
            case 'ancient':     $this->styleAncient($surname, $title, $origin); break;
            case 'modern':      $this->styleModern($surname, $title, $origin); break;
            case 'scroll':      $this->styleScroll($surname, $title, $origin); break;
            case 'ink':         $this->styleInk($surname, $title, $origin); break;
            case 'ming':        $this->styleMing($surname, $title, $origin); break;
            case 'familytree':  $this->styleFamilyTree($surname, $title, $origin); break;
            case 'seal':        $this->styleSeal($surname, $title, $origin); break;
            case 'bamboo':      $this->styleBamboo($surname, $title, $origin); break;
            case 'goldleaf':    $this->styleGoldLeaf($surname, $title, $origin); break;
            case 'silk':        $this->styleSilk($surname, $title, $origin); break;
            case 'vertical':    $this->styleVertical($surname, $title, $origin); break;
            case 'redgold':     $this->styleRedGold($surname, $title, $origin); break;
            case 'landscape':   $this->styleLandscape($surname, $title, $origin); break;
            case 'ribbon':      $this->styleRibbon($surname, $title, $origin); break;
            default:            $this->styleAncient($surname, $title, $origin);
        }

        // 保存文件
        $dir = __DIR__ . '/../uploads/covers/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'cover_' . md5($surname . $title . $style) . '_' . time() . '.png';
        $path = $dir . $filename;

        imagepng($this->image, $path, 8);
        imagedestroy($this->image);

        return 'uploads/covers/' . $filename;
    }

    /* ============ 样式1: 古书典籍 ============ */
    private function styleAncient($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 背景 - 古籍纸色
        $bg = imagecreatetruecolor($w, $h);
        $this->fillGradientRect($bg, 0, 0, $w, $h,
            [248, 238, 215], [218, 200, 165]);
        imagecopy($this->image, $bg, 0, 0, 0, 0, $w, $h);
        imagedestroy($bg);

        // 竖向细边线框
        $borderColor = imagecolorallocate($this->image, 110, 60, 20);
        imagerectangle($this->image, 28, 28, $w - 28, $h - 28, $borderColor);
        imagerectangle($this->image, 34, 34, $w - 34, $h - 34, $borderColor);

        // 顶部装饰双横线
        $goldColor = imagecolorallocate($this->image, 170, 130, 70);
        $lineTop = 90;
        imagesetthickness($this->image, 2);
        imageline($this->image, 60, $lineTop, $w - 60, $lineTop, $goldColor);
        imageline($this->image, 60, $lineTop + 6, $w - 60, $lineTop + 6, $goldColor);
        imagesetthickness($this->image, 1);

        // 底部装饰双横线
        $lineBottom = $h - 90;
        imagesetthickness($this->image, 2);
        imageline($this->image, 60, $lineBottom, $w - 60, $lineBottom, $goldColor);
        imageline($this->image, 60, $lineBottom + 6, $w - 60, $lineBottom + 6, $goldColor);
        imagesetthickness($this->image, 1);

        $font = $this->fontPath(72);
        $textColor = imagecolorallocate($this->image, 70, 30, 5);
        $accentColor = imagecolorallocate($this->image, 95, 55, 25);

        // 自适应字号：姓名字数多则字号小
        $slen = mb_strlen($surname);
        $surnameFont = ($slen > 2) ? 40 : (($slen > 1) ? 48 : 56);
        $surnameY = 175;

        $this->drawTextCentered($font, $surname . '氏', $surnameFont, $surnameY, $textColor);

        // 垂直间距固定
        $this->drawTextCentered($font, '族  谱', 30, $surnameY + 65, $accentColor);

        // 谱名
        if ($title && $title !== '族谱') {
            $tl = mb_strlen($title);
            $titleFont = ($tl > 6) ? 18 : (($tl > 4) ? 22 : 26);
            $this->drawTextCentered($font, $title, $titleFont, $surnameY + 130, $textColor);
        }

        // 发源地
        if ($origin) {
            $originShort = mb_strlen($origin) > 10 ? mb_substr($origin, 0, 10) . '…' : $origin;
            $this->drawTextCentered($font, '源出  ' . $originShort, 14, $lineBottom - 35, $goldColor);
        }

        // 底部传承语
        $this->drawTextCentered($font, '承  ·  传  ·  家  ·  风', 13, $lineBottom - 8, $accentColor);
    }

    private function styleModern($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        $bg = imagecreatetruecolor($w, $h);
        $this->fillGradientRect($bg, 0, 0, $w, $h,
            [35, 20, 12], [70, 42, 22]);
        imagecopy($this->image, $bg, 0, 0, 0, 0, $w, $h);
        imagedestroy($bg);

        // 装饰圆环
        $ringColor = imagecolorallocatealpha($this->image, 190, 150, 90, 85);
        imagefilledellipse($this->image, $w / 2, 175, 150, 150, $ringColor);
        $innerColor = imagecolorallocate($this->image, 50, 28, 14);
        imagefilledellipse($this->image, $w / 2, 175, 122, 122, $innerColor);

        $whiteColor = imagecolorallocate($this->image, 252, 242, 225);
        $goldColor = imagecolorallocate($this->image, 210, 170, 110);
        $font = $this->fontPath(72);

        $slen = mb_strlen($surname);
        $surnameFont = ($slen > 2) ? 44 : 56;
        $this->drawTextCentered($font, $surname, $surnameFont, 175, $whiteColor);

        if ($title && $title !== '族谱') {
            $this->drawTextCentered($font, $title, 20, 310, $goldColor);
        }

        $lineColor = imagecolorallocatealpha($this->image, 180, 140, 80, 70);
        imagesetthickness($this->image, 1);
        imageline($this->image, 80, 360, $w - 80, 360, $lineColor);

        if ($origin) {
            $this->drawTextCentered($font, $origin, 14, 415, $goldColor);
        }
        $this->drawTextCentered($font, date('Y') . '年 修', 12, 475, $goldColor);
    }

    /* ============ 样式3: 卷轴风格 ============ */
    private function styleScroll($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        $bgColor = imagecolorallocate($this->image, 252, 244, 222);
        imagefill($this->image, 0, 0, $bgColor);

        // 上下卷轴杆
        $rodColor = imagecolorallocate($this->image, 90, 50, 15);
        imagefilledrectangle($this->image, 12, 18, $w - 12, 38, $rodColor);
        imagefilledrectangle($this->image, 12, $h - 38, $w - 12, $h - 18, $rodColor);

        // 轴头
        $headColor = imagecolorallocate($this->image, 130, 80, 30);
        imagefilledellipse($this->image, 18, 28, 26, 26, $headColor);
        imagefilledellipse($this->image, $w - 18, 28, 26, 26, $headColor);
        imagefilledellipse($this->image, 18, $h - 28, 26, 26, $headColor);
        imagefilledellipse($this->image, $w - 18, $h - 28, 26, 26, $headColor);

        $textColor = imagecolorallocate($this->image, 60, 25, 0);
        $goldColor = imagecolorallocate($this->image, 150, 100, 40);
        $font = $this->fontPath(72);

        $slen = mb_strlen($surname);
        $surnameFont = ($slen > 2) ? 38 : 46;
        $this->drawTextCentered($font, $surname . '氏族谱', $surnameFont, 150, $textColor);

        imagesetthickness($this->image, 2);
        $lineY = 200;
        imageline($this->image, 70, $lineY, $w - 70, $lineY, $goldColor);
        imagesetthickness($this->image, 1);

        // 谱名单独一行，避免和姓氏重叠
        if ($title && $title !== '族谱') {
            $this->drawTextCentered($font, '《' . $title . '》', 22, 280, $textColor);
        }

        if ($origin) {
            $this->drawTextCentered($font, '源出  ' . $origin, 14, 430, $goldColor);
        }
        $this->drawTextCentered($font, '岁次' . date('Y'), 13, 485, $goldColor);
    }

    /* ============ 样式4: 水墨风格 ============ */
    private function styleInk($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        $whiteColor = imagecolorallocate($this->image, 252, 250, 242);
        imagefill($this->image, 0, 0, $whiteColor);

        // 泼墨效果
        for ($i = 0; $i < 40; $i++) {
            $x = rand(15, $w - 15);
            $y = rand(15, $h - 15);
            $r = rand(4, 22);
            $alpha = rand(95, 120);
            $dotColor = imagecolorallocatealpha($this->image, 15, 15, 15, $alpha);
            imagefilledellipse($this->image, $x, $y, $r, $r * 0.55, $dotColor);
        }

        // 梅枝线条
        $inkColor = imagecolorallocatealpha($this->image, 25, 25, 25, 45);
        imagesetthickness($this->image, 2);
        for ($j = 0; $j < 2; $j++) {
            $x1 = rand(8, 40);
            $y1 = rand(15, 65);
            $x2 = rand(70, 160);
            $y2 = rand(35, 135);
            imageline($this->image, $x1, $y1, $x2, $y2, $inkColor);
        }
        imagesetthickness($this->image, 1);

        $textColor = imagecolorallocate($this->image, 18, 18, 18);
        $redColor = imagecolorallocate($this->image, 170, 35, 30);
        $font = $this->fontPath(72);

        $slen = mb_strlen($surname);
        $surnameFont = ($slen > 2) ? 44 : 54;
        $this->drawTextCentered($font, $surname . '氏', $surnameFont, 190, $textColor);
        $this->drawTextCentered($font, '家  谱', 28, 260, $textColor);

        // 分割留白
        if ($title && $title !== '族谱') {
            $this->drawTextCentered($font, '《' . $title . '》', 18, 360, $textColor);
        }

        // 印章效果
        $stampColor = imagecolorallocate($this->image, 175, 35, 25);
        imagefilledrectangle($this->image, $w - 75, $h - 95, $w - 25, $h - 45, $stampColor);
        $stampTextColor = imagecolorallocate($this->image, 248, 238, 218);
        $this->drawTextCentered($font, '谱', 18, $h - 68, $stampTextColor);
    }

    /* ============ 样式5: 明清代风格 ============ */
    private function styleMing($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        $blueBg = imagecreatetruecolor($w, $h);
        $this->fillGradientRect($blueBg, 0, 0, $w, $h,
            [22, 36, 65], [42, 60, 100]);
        imagecopy($this->image, $blueBg, 0, 0, 0, 0, $w, $h);
        imagedestroy($blueBg);

        $cardMargin = 30;
        $cardColor = imagecolorallocate($this->image, 250, 244, 228);
        imagefilledrectangle($this->image, $cardMargin, $cardMargin + 28,
            $w - $cardMargin, $h - $cardMargin - 28, $cardColor);

        $borderColor = imagecolorallocate($this->image, 170, 140, 90);
        imagerectangle($this->image, $cardMargin, $cardMargin + 28,
            $w - $cardMargin, $h - $cardMargin - 28, $borderColor);

        $darkGold = imagecolorallocate($this->image, 150, 110, 40);
        imagefilledrectangle($this->image, $cardMargin + 8, $cardMargin + 56,
            $w - $cardMargin - 8, $cardMargin + 59, $darkGold);

        $font = $this->fontPath(72);
        $textColor = imagecolorallocate($this->image, 35, 18, 4);

        $slen = mb_strlen($surname);
        $surnameFont = ($slen > 2) ? 40 : 48;
        $this->drawTextCentered($font, $surname . '氏', $surnameFont, 160, $textColor);
        $this->drawTextCentered($font, '族', 30, 215, $textColor);

        imagesetthickness($this->image, 1);

        if ($title && $title !== '族谱') {
            $this->drawTextCentered($font, $title, 20, 310, $textColor);
        }
        if ($origin) {
            $this->drawTextCentered($font, $origin, 14, 430, $darkGold);
        }

        imagefilledrectangle($this->image, $cardMargin + 8, $h - $cardMargin - 68,
            $w - $cardMargin - 8, $h - $cardMargin - 65, $darkGold);
        $this->drawTextCentered($font, '大清' . rand(34, 36) . '年续修', 11, $h - $cardMargin - 45, $darkGold);
    }

    /* ============ 样式6: 家族之树 ============ */
    private function styleFamilyTree($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 天空渐变背景
        $bg = imagecreatetruecolor($w, $h);
        $this->fillGradientRect($bg, 0, 0, $w, (int)($h * 0.55),
            [135, 195, 170], [210, 235, 220]);
        imagecopy($this->image, $bg, 0, 0, 0, 0, $w, $h);
        imagedestroy($bg);

        // 大地渐变
        $ground = imagecreatetruecolor($w, (int)($h * 0.45));
        $this->fillGradientRect($ground, 0, 0, $w, (int)($h * 0.45),
            [90, 140, 95], [60, 95, 65]);
        imagecopy($this->image, $ground, 0, (int)($h * 0.55), 0, 0, $w, (int)($h * 0.45));
        imagedestroy($ground);

        // 树干
        $trunkColor = imagecolorallocate($this->image, 85, 55, 35);
        imagefilledrectangle($this->image, 185, (int)($h * 0.4), 215, (int)($h * 0.75), $trunkColor);

        // 树冠（圆形簇）
        $leafColors = [
            imagecolorallocate($this->image, 60, 130, 75),
            imagecolorallocate($this->image, 45, 115, 60),
            imagecolorallocate($this->image, 80, 150, 90),
            imagecolorallocate($this->image, 50, 125, 70),
            imagecolorallocate($this->image, 70, 140, 80),
        ];
        $canopyCenters = [
            [200, 150, 80], [140, 170, 65], [260, 170, 70],
            [170, 120, 60], [230, 125, 65], [200, 105, 55],
            [120, 140, 50], [280, 140, 50],
        ];
        foreach ($canopyCenters as $c) {
            $leafColor = $leafColors[array_rand($leafColors)];
            imagefilledellipse($this->image, $c[0], $c[1], $c[2], $c[2] * 0.85, $leafColor);
        }

        // 零星花朵
        $flowerColor = imagecolorallocate($this->image, 245, 190, 200);
        for ($i = 0; $i < 15; $i++) {
            $fx = rand(100, 300);
            $fy = rand(80, 210);
            imagefilledellipse($this->image, $fx, $fy, 6, 6, $flowerColor);
        }

        // 文字
        $whiteColor = imagecolorallocate($this->image, 255, 255, 250);
        $darkColor = imagecolorallocate($this->image, 30, 50, 30);
        $goldColor = imagecolorallocate($this->image, 200, 170, 100);
        $font = $this->fontPath(72);

        $this->drawTextCentered($font, $surname . '氏', 48, 320, $whiteColor);
        $this->drawTextCentered($font, '族 谱', 28, 365, $whiteColor);

        if ($title) {
            $this->drawTextCentered($font, $title, 18, 430, $whiteColor);
        }
        if ($origin) {
            $this->drawTextCentered($font, '根在 ' . $origin, 13, 490, $goldColor);
        }

        // 根部文字
        $this->drawTextCentered($font, '枝繁叶茂 源远流长', 12, 525, imagecolorallocate($this->image, 160, 190, 150));
    }

    /* ============ 样式7: 红印篆刻 ============ */
    private function styleSeal($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 纯白背景
        $whiteColor = imagecolorallocate($this->image, 252, 250, 245);
        imagefill($this->image, 0, 0, $whiteColor);

        // 外边框
        $redColor = imagecolorallocate($this->image, 175, 38, 35);
        imagesetthickness($this->image, 4);
        imagerectangle($this->image, 30, 50, $w - 30, $h - 50, $redColor);
        imagesetthickness($this->image, 2);
        imagerectangle($this->image, 40, 60, $w - 40, $h - 60, $redColor);
        imagesetthickness($this->image, 1);

        // 印章圆框
        $sealColor = imagecolorallocate($this->image, 185, 42, 38);
        imagesetthickness($this->image, 6);
        imageellipse($this->image, $w / 2, 240, 220, 220, $sealColor);
        imagesetthickness($this->image, 3);
        imageellipse($this->image, $w / 2, 240, 200, 200, $sealColor);
        imagesetthickness($this->image, 1);

        // 姓氏在印中
        $font = $this->fontPath(72);
        $this->drawTextCentered($font, $surname, 52, 255, $sealColor);

        // 下方文字
        if ($title) {
            $this->drawTextCentered($font, $title, 22, 390, $redColor);
        }

        // 分割
        $grayColor = imagecolorallocate($this->image, 180, 170, 160);
        imageline($this->image, 80, 430, $w - 80, 430, $grayColor);

        if ($origin) {
            $this->drawTextCentered($font, $origin, 14, 470, $grayColor);
        }

        // 底部篆刻落款
        $this->drawTextCentered($font, $surname . '氏族谱 × 岁次' . date('Y'), 12, 510, $grayColor);
    }

    /* ============ 样式8: 竹简复古 ============ */
    private function styleBamboo($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 竹简底色
        $bambooBg = imagecolorallocate($this->image, 210, 195, 155);
        imagefill($this->image, 0, 0, $bambooBg);

        // 竖向竹片纹理
        $strips = 12;
        $stripWidth = (int)($w / $strips);
        for ($s = 0; $s < $strips; $s++) {
            $sx = $s * $stripWidth;
            // 竹片颜色
            $r = rand(185, 215);
            $g = rand(170, 200);
            $b = rand(130, 165);
            $stripColor = imagecolorallocate($this->image, $r, $g, $b);
            imagefilledrectangle($this->image, $sx + 5, 10, $sx + $stripWidth - 5, $h - 10, $stripColor);

            // 竹节
            $knotColor = imagecolorallocate($this->image, $r - 20, $g - 20, $b - 15);
            for ($ky = 60; $ky < $h - 10; $ky += 120) {
                imageline($this->image, $sx + 5, $ky, $sx + $stripWidth - 5, $ky, $knotColor);
            }
        }

        // 编绳（横向）
        $ropeColor = imagecolorallocate($this->image, 140, 115, 65);
        imagesetthickness($this->image, 3);
        for ($ry = 55; $ry < $h; $ry += 120) {
            imageline($this->image, 0, $ry, $w, $ry, $ropeColor);
            imageline($this->image, 0, $ry + 4, $w, $ry + 4, $ropeColor);
        }
        imagesetthickness($this->image, 1);

        // 文字（竖向写在中央竹片区）
        $textColor = imagecolorallocate($this->image, 35, 20, 5);
        $font = $this->fontPath(72);

        // 中央留白区域
        $whiteBg = imagecolorallocatealpha($this->image, 220, 215, 200, 30);
        imagefilledrectangle($this->image, $w * 0.18, 80, $w * 0.82, $h - 80, $whiteBg);

        // 竖排效果：姓氏单独大字
        $chars = preg_split('//u', $surname . '氏族谱', -1, PREG_SPLIT_NO_EMPTY);
        $startY = 180;
        $lineHeight = 48;
        foreach ($chars as $i => $char) {
            $this->drawTextCentered($font, $char, 34, $startY + $i * $lineHeight, $textColor);
        }

        if ($title) {
            $this->drawTextCentered($font, $title, 16, $startY + (count($chars) + 1) * $lineHeight, $textColor);
        }
    }

    /* ============ 样式9: 金箔典藏 ============ */
    private function styleGoldLeaf($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 深黑背景
        $bgColor = imagecolorallocate($this->image, 18, 15, 12);
        imagefill($this->image, 0, 0, $bgColor);

        // 金色光晕
        $glowColors = [];
        for ($gi = 0; $gi < 8; $gi++) {
            $alpha = 100 + $gi * 5;
            $glowColors[] = imagecolorallocatealpha($this->image, 200, 160, 40, $alpha);
        }
        foreach ($glowColors as $gi => $gc) {
            $radius = 120 - $gi * 2;
            imagefilledellipse($this->image, $w / 2, 200, $radius * 2, $radius * 2, $gc);
        }

        // 装饰金色边框
        $goldBorder = imagecolorallocate($this->image, 195, 155, 45);
        imagesetthickness($this->image, 3);
        imagerectangle($this->image, 25, 25, $w - 25, $h - 25, $goldBorder);
        imagesetthickness($this->image, 1);
        imagerectangle($this->image, 32, 32, $w - 32, $h - 32, $goldBorder);

        // 顶部装饰花纹（简易菱形）
        imagesetthickness($this->image, 2);
        $diamondY = 70;
        for ($dx = 0; $dx < 5; $dx++) {
            $cx = 70 + $dx * 65;
            imageline($this->image, $cx, $diamondY - 10, $cx + 10, $diamondY, $goldBorder);
            imageline($this->image, $cx + 10, $diamondY, $cx + 20, $diamondY - 10, $goldBorder);
            imageline($this->image, $cx + 20, $diamondY - 10, $cx + 10, $diamondY - 20, $goldBorder);
            imageline($this->image, $cx + 10, $diamondY - 20, $cx, $diamondY - 10, $goldBorder);
        }
        imagesetthickness($this->image, 1);

        // 文字
        $goldColor = imagecolorallocate($this->image, 210, 170, 60);
        $lightGold = imagecolorallocate($this->image, 235, 205, 110);
        $font = $this->fontPath(72);

        $this->drawTextCentered($font, $surname . '氏', 50, 190, $goldColor);
        $this->drawTextCentered($font, '族 谱', 28, 240, $lightGold);

        // 中央横线
        imagesetthickness($this->image, 2);
        imageline($this->image, 80, 280, $w - 80, 280, $goldColor);
        imageline($this->image, 80, 282, $w - 80, 282, $goldColor);
        imagesetthickness($this->image, 1);

        if ($title) {
            $this->drawTextCentered($font, $title, 20, 340, $lightGold);
        }
        if ($origin) {
            $this->drawTextCentered($font, $origin, 14, 440, $goldColor);
        }

        // 底部分割
        imagesetthickness($this->image, 2);
        imageline($this->image, 80, $h - 80, $w - 80, $h - 80, $goldColor);
        imagesetthickness($this->image, 1);
        $this->drawTextCentered($font, '典 藏 珍 本', 14, $h - 50, $goldColor);
    }

    /* ============ 样式10: 锦绣丝帛 ============ */
    private function styleSilk($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 丝帛渐变背景
        $bg = imagecreatetruecolor($w, $h);
        $this->fillGradientRect($bg, 0, 0, $w, $h,
            [250, 235, 215], [240, 218, 190]);
        imagecopy($this->image, $bg, 0, 0, 0, 0, $w, $h);
        imagedestroy($bg);

        // 丝绸暗纹（隐约的水波纹）
        $patternColor = imagecolorallocatealpha($this->image, 220, 200, 170, 100);
        for ($py = 0; $py < $h; $py += 14) {
            $sineOffset = (int)(sin($py * 0.05) * 8);
            imageline($this->image, 20, $py, $w - 20 + $sineOffset, $py, $patternColor);
        }

        // 云纹装饰
        $cloudColor = imagecolorallocatealpha($this->image, 210, 180, 130, 60);
        $cloudCenters = [[120, 80], [280, 70], [80, 490], [320, 500]];
        foreach ($cloudCenters as $cc) {
            for ($cr = 0; $cr < 4; $cr++) {
                $radius = 15 + $cr * 12;
                imagearc($this->image, $cc[0], $cc[1], $radius, $radius * 0.6, 0, 180, $cloudColor);
            }
        }

        // 祥云边框
        $borderColor = imagecolorallocate($this->image, 175, 130, 75);
        imagesetthickness($this->image, 2);
        imagerectangle($this->image, 35, 35, $w - 35, $h - 35, $borderColor);
        imagesetthickness($this->image, 1);
        imagerectangle($this->image, 42, 42, $w - 42, $h - 42, $borderColor);

        // 文字区域
        $textColor = imagecolorallocate($this->image, 80, 45, 20);
        $accentColor = imagecolorallocate($this->image, 160, 100, 50);
        $font = $this->fontPath(72);

        $this->drawTextCentered($font, $surname . '氏', 48, 170, $textColor);
        $this->drawTextCentered($font, '家 谱', 30, 230, $textColor);

        // 锦绣分割线
        imagesetthickness($this->image, 1);
        imageline($this->image, 80, 270, $w / 2 - 20, 270, $accentColor);
        imageline($this->image, $w / 2 + 20, 270, $w - 80, 270, $accentColor);
        // 中央菱形
        $diamondLines = [
            [$w / 2, 264, $w / 2 + 6, 270],
            [$w / 2 + 6, 270, $w / 2, 276],
            [$w / 2, 276, $w / 2 - 6, 270],
            [$w / 2 - 6, 270, $w / 2, 264],
        ];
        foreach ($diamondLines as $dl) {
            imageline($this->image, $dl[0], $dl[1], $dl[2], $dl[3], $accentColor);
        }

        if ($title) {
            $this->drawTextCentered($font, $title, 20, 350, $textColor);
        }
        if ($origin) {
            $this->drawTextCentered($font, '源出 ' . $origin, 14, 450, $accentColor);
        }

        $this->drawTextCentered($font, '锦 绣 传 家', 14, 510, $accentColor);
    }

    /* ============ 样式11: 竖排古典（仿线装书） ============ */
    private function styleVertical($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 古籍纸底
        $bg = imagecreatetruecolor($w, $h);
        $this->fillGradientRect($bg, 0, 0, $w, $h,
            [252, 244, 230], [235, 220, 195]);
        imagecopy($this->image, $bg, 0, 0, 0, 0, $w, $h);
        imagedestroy($bg);

        // 左右双栏线
        $lineColor = imagecolorallocate($this->image, 160, 120, 75);
        imagesetthickness($this->image, 1);
        // 左栏线
        imageline($this->image, 60, 30, 60, $h - 30, $lineColor);
        // 右栏线
        imageline($this->image, $w - 60, 30, $w - 60, $h - 30, $lineColor);
        // 中线（书脊）
        imageline($this->image, $w / 2, 30, $w / 2, $h - 30, $lineColor);

        // 上下横线
        imagesetthickness($this->image, 2);
        imageline($this->image, 40, 35, $w - 40, 35, $lineColor);
        imageline($this->image, 40, $h - 35, $w - 40, $h - 35, $lineColor);
        imagesetthickness($this->image, 1);

        $font = $this->fontPath(72);
        $textColor = imagecolorallocate($this->image, 55, 25, 5);
        $goldColor = imagecolorallocate($this->image, 140, 100, 45);

        // 竖排文字 - 从右到左
        $surnameText = $surname . '氏';
        $mainText = $surnameText . '族谱';
        $chars = preg_split('//u', $mainText, -1, PREG_SPLIT_NO_EMPTY);
        $startX = $w / 2 + 30;
        $startY = 100;
        $lineSpacing = 38;

        foreach ($chars as $i => $char) {
            $x = $startX;
            $y = $startY + $i * $lineSpacing;
            imagettftext($this->image, 28, 0, $x, $y, $textColor, $font, $char);
        }

        // 谱名在左半边顶部
        if ($title && $title !== '族谱') {
            $tl = mb_strlen($title);
            $titleFont = ($tl > 4) ? 16 : 20;
            $this->drawTextCentered($font, $title, $titleFont, 320, $textColor);
        }

        // 左下角印章
        $stampColor = imagecolorallocate($this->image, 180, 40, 35);
        imagefilledrectangle($this->image, 55, $h - 90, 105, $h - 50, $stampColor);
        imagettftext($this->image, 14, 0, 68, $h - 62, imagecolorallocate($this->image, 250, 235, 215), $font, '谱');

        // 右栏注释
        if ($origin) {
            $this->drawTextCentered($font, '源出' . $origin, 11, 490, $goldColor);
        }
    }

    /* ============ 样式12: 红底金字（传统喜庆） ============ */
    private function styleRedGold($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 朱红底色
        $bg = imagecreatetruecolor($w, $h);
        $this->fillGradientRect($bg, 0, 0, $w, $h,
            [175, 32, 28], [145, 22, 18]);
        imagecopy($this->image, $bg, 0, 0, 0, 0, $w, $h);
        imagedestroy($bg);

        // 金色边框
        $goldColor = imagecolorallocate($this->image, 230, 190, 50);
        imagesetthickness($this->image, 4);
        imagerectangle($this->image, 25, 25, $w - 25, $h - 25, $goldColor);
        imagesetthickness($this->image, 2);
        imagerectangle($this->image, 33, 33, $w - 33, $h - 33, $goldColor);
        imagesetthickness($this->image, 1);

        // 金色回纹（四角装饰）
        $corner = 50;
        imageline($this->image, 50, $corner, 120, $corner, $goldColor);
        imageline($this->image, 50, $corner, 50, $corner + 30, $goldColor);
        imageline($this->image, $w - 50, $corner, $w - 120, $corner, $goldColor);
        imageline($this->image, $w - 50, $corner, $w - 50, $corner + 30, $goldColor);
        imageline($this->image, 50, $h - $corner, 120, $h - $corner, $goldColor);
        imageline($this->image, 50, $h - $corner, 50, $h - $corner - 30, $goldColor);
        imageline($this->image, $w - 50, $h - $corner, $w - 120, $h - $corner, $goldColor);
        imageline($this->image, $w - 50, $h - $corner, $w - 50, $h - $corner - 30, $goldColor);

        // 圆形金框
        imagesetthickness($this->image, 3);
        imageellipse($this->image, $w / 2, 210, 200, 200, $goldColor);
        imagesetthickness($this->image, 1);

        $font = $this->fontPath(72);
        $slen = mb_strlen($surname);
        $surnameFont = ($slen > 2) ? 42 : 52;
        $this->drawTextCentered($font, $surname . '氏', $surnameFont, 200, $goldColor);
        $this->drawTextCentered($font, '族  谱', 26, 260, $goldColor);

        if ($title && $title !== '族谱') {
            $this->drawTextCentered($font, $title, 18, 350, $goldColor);
        }
        if ($origin) {
            $this->drawTextCentered($font, $origin, 13, 460, $goldColor);
        }

        $this->drawTextCentered($font, '承传有序  ·  光前裕后', 12, 510, $goldColor);
    }

    /* ============ 样式13: 山水画意 ============ */
    private function styleLandscape($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 天空渐变
        $bg = imagecreatetruecolor($w, $h);
        $this->fillGradientRect($bg, 0, 0, $w, (int)($h * 0.35),
            [190, 215, 200], [220, 235, 218]);
        imagecopy($this->image, $bg, 0, 0, 0, 0, $w, $h);
        imagedestroy($bg);

        // 远山层叠
        $mountColors = [
            imagecolorallocatealpha($this->image, 140, 175, 155, 20),
            imagecolorallocatealpha($this->image, 110, 150, 130, 30),
            imagecolorallocatealpha($this->image, 85, 125, 105, 40),
        ];
        $mountXs = [[20, 120, 180, 260, 350], [50, 170, 220, 310, 370], [80, 140, 240, 300, 340]];
        $mountHeights = [[30, 70, 50, 65, 40], [55, 90, 70, 85, 60], [70, 110, 85, 100, 75]];
        foreach ($mountColors as $mi => $mc) {
            $xs = $mountXs[$mi];
            $hs = $mountHeights[$mi];
            $baseY = (int)($h * 0.33) + $mi * 12;
            imagefilledpolygon($this->image, [
                $xs[0], $baseY,
                ($xs[0]+$xs[1])/2, $baseY - $hs[0],
                $xs[1], $baseY,
                ($xs[1]+$xs[2])/2, $baseY - $hs[1],
                $xs[2], $baseY,
                ($xs[2]+$xs[3])/2, $baseY - $hs[2],
                $xs[3], $baseY,
                ($xs[3]+$xs[4])/2, $baseY - $hs[3],
                $xs[4], $baseY
            ], 10, $mc);
        }

        // 水面
        $waterColor = imagecolorallocatealpha($this->image, 175, 200, 188, 40);
        imagefilledrectangle($this->image, 0, (int)($h * 0.45), $w, (int)($h * 0.50), $waterColor);

        // 底部大地
        $groundColor = imagecolorallocate($this->image, 225, 232, 220);
        imagefilledrectangle($this->image, 0, (int)($h * 0.50), $w, $h, $groundColor);

        // 文字区域（白底透光）
        $textBg = imagecolorallocatealpha($this->image, 252, 250, 242, 15);
        imagefilledrectangle($this->image, 50, 130, $w - 50, 420, $textBg);

        $font = $this->fontPath(72);
        $textColor = imagecolorallocate($this->image, 35, 25, 10);
        $accentColor = imagecolorallocate($this->image, 80, 105, 65);

        $slen = mb_strlen($surname);
        $surnameFont = ($slen > 2) ? 38 : 46;
        $this->drawTextCentered($font, $surname . '氏', $surnameFont, 210, $textColor);
        $this->drawTextCentered($font, '家  谱', 26, 265, $textColor);

        imagesetthickness($this->image, 1);
        imageline($this->image, 100, 310, $w - 100, 310, $accentColor);

        if ($title && $title !== '族谱') {
            $this->drawTextCentered($font, $title, 18, 360, $textColor);
        }
        if ($origin) {
            $this->drawTextCentered($font, '源出  ' . $origin, 13, 450, $accentColor);
        }

        // 底部印章
        $stampColor = imagecolorallocate($this->image, 160, 45, 35);
        imagefilledrectangle($this->image, $w - 75, $h - 85, $w - 25, $h - 45, $stampColor);
        $this->drawTextCentered($font, '谱', 16, $h - 60, imagecolorallocate($this->image, 250, 235, 215));
    }

    /* ============ 样式14: 锦缎织纹 ============ */
    private function styleRibbon($surname, $title, $origin)
    {
        $w = $this->width;
        $h = $this->height;

        // 深蓝底
        $bgColor = imagecolorallocate($this->image, 28, 35, 58);
        imagefill($this->image, 0, 0, $bgColor);

        // 织锦菱形纹样
        $patternColor = imagecolorallocatealpha($this->image, 180, 160, 105, 95);
        for ($py = 30; $py < $h - 30; $py += 36) {
            for ($px = 30; $px < $w - 30; $px += 36) {
                $offsetX = ($py / 36 % 2) ? 18 : 0;
                $cx = $px + $offsetX;
                $s = 10;
                imagefilledpolygon($this->image, [
                    $cx, $py - $s, $cx + $s, $py, $cx, $py + $s, $cx - $s, $py
                ], 4, $patternColor);
            }
        }

        // 中央金色圆框
        $goldColor = imagecolorallocate($this->image, 205, 170, 55);
        imagesetthickness($this->image, 3);
        imageellipse($this->image, $w / 2, 195, 190, 190, $goldColor);
        imagesetthickness($this->image, 2);
        imageellipse($this->image, $w / 2, 195, 178, 178, $goldColor);
        imagesetthickness($this->image, 1);

        // 金色团花纹
        for ($d = 0; $d < 8; $d++) {
            $angle = $d * M_PI / 4;
            $dx = $w / 2 + cos($angle) * 86;
            $dy = 195 + sin($angle) * 86;
            imagefilledellipse($this->image, (int)$dx, (int)$dy, 6, 6, $goldColor);
        }

        $font = $this->fontPath(72);
        $whiteColor = imagecolorallocate($this->image, 245, 238, 210);

        $slen = mb_strlen($surname);
        $surnameFont = ($slen > 2) ? 40 : 48;
        $this->drawTextCentered($font, $surname . '氏', $surnameFont, 185, $goldColor);
        $this->drawTextCentered($font, '族谱', 28, 235, $whiteColor);

        // 如意纹饰线
        imagesetthickness($this->image, 2);
        imageline($this->image, 80, 310, $w - 80, 310, $goldColor);
        imagesetthickness($this->image, 1);

        if ($title && $title !== '族谱') {
            $this->drawTextCentered($font, $title, 18, 370, $whiteColor);
        }
        if ($origin) {
            $this->drawTextCentered($font, $origin, 13, 450, $goldColor);
        }

        $this->drawTextCentered($font, '族 谱 传 承', 14, 510, $goldColor);
    }

    /* ============ 辅助方法 ============ */

    /**
     * 绘制渐变矩形
     */
    private function fillGradientRect($img, $x1, $y1, $x2, $y2, $start, $end)
    {
        $steps = max($y2 - $y1, $x2 - $x1);
        for ($i = 0; $i < $steps; $i++) {
            $ratio = $i / ($steps - 1);
            $r = (int)($start[0] + ($end[0] - $start[0]) * $ratio);
            $g = (int)($start[1] + ($end[1] - $start[1]) * $ratio);
            $b = (int)($start[2] + ($end[2] - $start[2]) * $ratio);
            $color = imagecolorallocate($img, $r, $g, $b);
            if ($y2 > $y1) {
                imageline($img, $x1, $y1 + $i, $x2, $y1 + $i, $color);
            } else {
                imageline($img, $x1 + $i, $y1, $x1 + $i, $y2, $color);
            }
        }
    }

    /**
     * 居中绘制中文文本
     */
    private function drawTextCentered($fontFile, $text, $size, $y, $color)
    {
        if ($fontFile && file_exists($fontFile)) {
            // TTC 字体 bbox 可能不准确，使用保守估算
            $bbox = @imagettfbbox($size, 0, $fontFile, $text);
            if ($bbox && $bbox[2] > $bbox[0] && $bbox[2] - $bbox[0] < $this->width * 2) {
                $textWidth = $bbox[2] - $bbox[0];
                $x = (int)(($this->width - $textWidth) / 2);
                // 确保不超出左边界
                if ($x < 10) $x = 10;
                imagettftext($this->image, $size, 0, $x, $y, $color, $fontFile, $text);
                return;
            }
        }
        // 降级：使用 GD 内置字体
        $textWidth = imagefontwidth(5) * mb_strlen($text);
        $x = (int)(($this->width - $textWidth) / 2);
        if ($x < 10) $x = 10;
        imagestring($this->image, 5, $x, max(0, $y - 8), $text, $color);
    }

    /**
     * 获取所有可用的封面样式
     */
    public static function getStyles()
    {
        return [
            'ancient'    => ['name' => '古书典籍', 'desc' => '仿古籍线装书风格'],
            'modern'     => ['name' => '现代简约', 'desc' => '深色调现代设计'],
            'scroll'     => ['name' => '卷轴古风', 'desc' => '传统卷轴样式'],
            'ink'        => ['name' => '水墨意境', 'desc' => '黑白水墨画风格'],
            'ming'       => ['name' => '明清官谱', 'desc' => '官修谱牒样式'],
            'familytree' => ['name' => '家族之树', 'desc' => '生命树绿色自然风'],
            'seal'       => ['name' => '红印篆刻', 'desc' => '朱红印章篆刻风格'],
            'bamboo'     => ['name' => '竹简复古', 'desc' => '仿古竹简竖排文字'],
            'goldleaf'   => ['name' => '金箔典藏', 'desc' => '黑金奢华典藏版'],
            'silk'       => ['name' => '锦绣丝帛', 'desc' => '丝绸质感柔光暖色'],
            'vertical'   => ['name' => '竖排古典', 'desc' => '仿线装书竖排文字'],
            'redgold'    => ['name' => '红底金字', 'desc' => '朱红底烫金喜庆风'],
            'landscape'  => ['name' => '山水画意', 'desc' => '青绿山水国画背景'],
            'ribbon'     => ['name' => '锦缎织纹', 'desc' => '宫廷织锦纹样装饰'],
        ];
    }
}
