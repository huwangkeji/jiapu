<?php
/**
 * WordExporter — 家谱全书导出（Word格式）
 * 使用 HTML 转 .doc（Word原生支持），纯PHP，零外部依赖
 * 排版：封面 → 谱序 → 世系总览 → 人物传 → 后记
 * PHP 7.4+
 */

class WordExporter
{
    private $engine;

    public function __construct(ExportEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * 输出 Word 下载
     */
    public function download($filename = null)
    {
        $data  = $this->engine->getExportData();
        $family = $data['family'];

        if (!$filename) {
            $filename = $family['surname'] . '氏族谱全书_' . date('Ymd');
        }

        $html = $this->buildHtml($data);

        header('Content-Type: application/msword; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.doc"');
        header('Cache-Control: max-age=0');
        header('Expires: 0');
        header('Pragma: public');

        echo "\xEF\xBB\xBF";
        echo $html;
        exit;
    }

    /**
     * 构建 Word HTML
     */
    private function buildHtml($data)
    {
        $family      = $data['family'];
        $title       = $data['title'];
        $stats       = $data['stats'];
        $generations = $data['generations'];
        $genRange    = $data['gen_range'];

        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">';
        $html .= '<head><meta charset="UTF-8">';
        $html .= '<!--[if gte mso 9]><xml><w:WordDocument>';
        $html .= '<w:View>Print</w:View><w:Zoom>100</w:Zoom>';
        $html .= '<w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->';
        $html .= $this->getStyleCss();
        $html .= '</head><body>';

        // ===== 封面 =====
        $html .= $this->buildCoverPage($data);

        // ===== 分页 =====
        $html .= '<br style="page-break-before: always;">';

        // ===== 目录 =====
        $html .= $this->buildToc($data);

        // ===== 分页 =====
        $html .= '<br style="page-break-before: always;">';

        // ===== 谱序 =====
        $html .= $this->buildPreface($data);

        // ===== 分页 =====
        $html .= '<br style="page-break-before: always;">';

        // ===== 世系总览 =====
        $html .= $this->buildGenealogyTable($data);

        // ===== 分页 =====
        $html .= '<br style="page-break-before: always;">';

        // ===== 人物传 =====
        $html .= $this->buildBiographies($data);

        // ===== 后记 =====
        $html .= '<br style="page-break-before: always;">';
        $html .= $this->buildPostscript($data);

        $html .= '</body></html>';

        return $html;
    }

    /**
     * 封面页
     */
    private function buildCoverPage($data)
    {
        $family = $data['family'];
        $title  = $data['title'];
        $origin = $family['origin'] ? '源出：' . $this->esc($family['origin']) : '';

        $html  = '<div class="cover-page">';
        $html .= '<div class="cover-frame">';
        $html .= '<div class="cover-border-top"></div>';
        $html .= '<h1 class="cover-surname">' . $this->esc($family['surname']) . '氏</h1>';
        $html .= '<h1 class="cover-title">族 谱</h1>';
        $html .= '<p class="cover-subtitle">' . $this->esc($family['title']) . '</p>';
        if ($origin) {
            $html .= '<p class="cover-origin">' . $origin . '</p>';
        }
        $html .= '<p class="cover-gen-info">第 ' . $this->esc($data['gen_range']['min']) . ' 世 — 第 ' . $this->esc($data['gen_range']['max']) . ' 世</p>';
        $html .= '<p class="cover-stats">共收录 ' . $data['stats']['total'] . ' 人</p>';
        $html .= '<div class="cover-border-bottom"></div>';
        $html .= '</div>';
        $html .= '<p class="cover-date">' . date('Y年m月') . ' 编印</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 目录
     */
    private function buildToc($data)
    {
        $genRange = $data['gen_range'];
        $stats    = $data['stats'];

        $html  = '<h2 class="chapter-title">目  录</h2>';
        $html .= '<div class="toc">';
        $html .= '<p class="toc-item">一、谱序 …………………………………………………… 3</p>';
        $html .= '<p class="toc-item">二、世系总览 …………………………………………… 4</p>';
        $html .= '<p class="toc-item">三、人物传 ……………………………………………… 5</p>';
        $html .= '<p class="toc-item">四、后记 ………………………………………………… ' . (5 + count($data['members'])) . '</p>';
        $html .= '</div>';
        $html .= '<p class="toc-note">本谱收录第 ' . $genRange['min'] . ' 世至第 ' . $genRange['max'] . ' 世，共 ' . $stats['total'] . ' 人（男 ' . $stats['male'] . ' 人，女 ' . $stats['female'] . ' 人）。</p>';

        return $html;
    }

    /**
     * 谱序
     */
    private function buildPreface($data)
    {
        $family = $data['family'];
        $stats  = $data['stats'];
        $genRange = $data['gen_range'];

        $html  = '<h2 class="chapter-title">谱  序</h2>';
        $html .= '<div class="content-text">';
        $html .= '<p class="first-para">盖闻木有本，水有源。人之有祖，亦犹是焉。' . $this->esc($family['surname']) . '氏一族，源远流长，世代繁衍，枝繁叶茂。今纂修族谱，以明世系、序昭穆、别亲疏、敦族谊，使后世子孙知其所自，识其所归。</p>';

        if ($family['origin']) {
            $html .= '<p>' . $this->esc($family['surname']) . '氏发源于' . $this->esc($family['origin']) . '，历经沧桑，人才辈出。本次修谱，上溯至第 ' . $genRange['min'] . ' 世，下迄今第 ' . $genRange['max'] . ' 世，凡 ' . $stats['total'] . ' 人入谱。</p>';
        }

        if ($family['description']) {
            $html .= '<p>' . nl2br($this->esc($family['description'])) . '</p>';
        }

        $html .= '<p>谱牒之作，非徒记名讳、叙生卒而已。盖欲使族人观谱而知亲疏，因亲疏而生敬爱，由敬爱而敦族谊。斯谱既成，愿我族人珍之、重之、续之，俾' . $this->esc($family['surname']) . '氏一族永昌不替。</p>';
        $html .= '<p class="signature">' . $this->esc($family['creator_name'] ?? '佚名') . ' 谨序</p>';
        $html .= '<p class="signature">' . date('Y年m月d日') . '</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 世系总览表
     */
    private function buildGenealogyTable($data)
    {
        $family      = $data['family'];
        $generations = $data['generations'];

        $html  = '<h2 class="chapter-title">世系总览</h2>';
        $html .= '<p class="section-desc">以下按世次列明各代族人基本信息：</p>';

        $html .= '<table class="genealogy-table">';
        $html .= '<thead><tr>';
        $html .= '<th width="50">世次</th>';
        $html .= '<th width="70">姓名</th>';
        $html .= '<th width="40">性别</th>';
        $html .= '<th width="90">生年</th>';
        $html .= '<th width="90">卒年</th>';
        $html .= '<th width="80">父亲</th>';
        $html .= '<th>生平摘要</th>';
        $html .= '</tr></thead><tbody>';

        $genKeys = array_keys($generations);
        foreach ($genKeys as $gen) {
            $members = $generations[$gen];
            $first = true;
            $count = count($members);

            foreach ($members as $i => $m) {
                $html .= '<tr>';
                if ($first) {
                    $html .= '<td class="gen-cell" rowspan="' . $count . '">第' . $gen . '世</td>';
                    $first = false;
                }
                $genderText = $m['gender'] === 'male' ? '男' : ($m['gender'] === 'female' ? '女' : '未知');
                $bio = $m['biography'] ?? '';
                if (mb_strlen($bio) > 50) {
                    $bio = mb_substr($bio, 0, 50) . '…';
                }

                $html .= '<td>' . $this->esc($m['name']) . '</td>';
                $html .= '<td>' . $genderText . '</td>';
                $html .= '<td>' . $this->esc($m['birth_date'] ?: '-') . '</td>';
                $html .= '<td>' . $this->esc($m['death_date'] ?: '-') . '</td>';
                $html .= '<td>' . $this->esc($m['father_name'] ?: '-') . '</td>';
                $html .= '<td class="bio-cell">' . $this->esc($bio) . '</td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * 人物传
     */
    private function buildBiographies($data)
    {
        $family  = $data['family'];
        $generations = $data['generations'];

        $html  = '<h2 class="chapter-title">人 物 传</h2>';
        $html .= '<p class="section-desc">以下为族中有传记者：</p>';

        $hasBio = false;
        $genKeys = array_keys($generations);

        foreach ($genKeys as $gen) {
            foreach ($generations[$gen] as $m) {
                if (empty($m['biography'])) continue;
                $hasBio = true;

                $genderLabel = $m['gender'] === 'male' ? '公' : ($m['gender'] === 'female' ? '孺人' : '');
                $html .= '<div class="bio-entry">';
                $html .= '<h3 class="bio-name">' . $this->esc($m['name']) . $genderLabel . ' <span class="bio-gen">（第' . $gen . '世）</span></h3>';

                // 基本信息
                $infoParts = [];
                if ($m['birth_date']) $infoParts[] = '生于' . $m['birth_date'];
                if ($m['death_date']) $infoParts[] = '卒于' . $m['death_date'];
                if ($m['father_name']) $infoParts[] = '父：' . $m['father_name'];
                if (!empty($infoParts)) {
                    $html .= '<p class="bio-info">' . implode('　', $infoParts) . '</p>';
                }

                $html .= '<div class="bio-content">';
                $html .= '<p class="first-para">' . nl2br($this->esc($m['biography'])) . '</p>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '<hr class="bio-sep">';
            }
        }

        if (!$hasBio) {
            $html .= '<p class="no-content">暂无人物传记。建议为家族中的重要人物添加生平简介。</p>';
        }

        return $html;
    }

    /**
     * 后记
     */
    private function buildPostscript($data)
    {
        $family = $data['family'];
        $stats  = $data['stats'];

        $html  = '<h2 class="chapter-title">后  记</h2>';
        $html .= '<div class="content-text">';
        $html .= '<p class="first-para">本次' . $this->esc($family['surname']) . '氏族谱修纂工作，承蒙族中诸位长辈、同宗大力支持，历时多日，终于告竣。</p>';
        $html .= '<p>本谱共收录族人 ' . $stats['total'] . ' 名，其中男士 ' . $stats['male'] . ' 人，女士 ' . $stats['female'] . ' 人，涵盖世系 ' . $stats['generations'] . ' 代。</p>';
        $html .= '<p>由于年代久远、资料有限，部分族人信息或有疏漏，敬请族人海涵。望后世子孙能继续补阙拾遗，使谱牒日趋完善。</p>';
        $html .= '<p>家之有谱，犹国之有史。谱牒存，则世系明、昭穆序、亲疏别。愿我' . $this->esc($family['surname']) . '氏族人珍视此谱，世代相传，永续勿替。</p>';
        $html .= '<p class="signature">' . $this->esc(SITE_NAME) . '</p>';
        $html .= '<p class="signature">' . date('Y年m月d日') . '</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * CSS 样式（Word 兼容）
     */
    private function getStyleCss()
    {
        return '<style>
            @page { size: A4; margin: 2cm 2.5cm; }
            @page Section1 { mso-page-orientation: portrait; }
            div.Section1 { page: Section1; }

            body { font-family: SimSun, 宋体, serif; font-size: 14px; line-height: 2; color: #333; }

            /* ===== 封面 ===== */
            .cover-page { text-align: center; padding-top: 80px; page-break-after: always; }
            .cover-frame { border: 4px double #8b6914; padding: 40px 30px; margin: 0 auto 30px; max-width: 400px; }
            .cover-border-top { border-top: 2px solid #8b6914; margin-bottom: 30px; width: 60%; margin-left: auto; margin-right: auto; }
            .cover-border-bottom { border-bottom: 2px solid #8b6914; margin-top: 30px; width: 60%; margin-left: auto; margin-right: auto; }
            .cover-surname { font-size: 36px; font-weight: bold; color: #8b6914; margin: 10px 0; letter-spacing: 8px; }
            .cover-title { font-size: 42px; font-weight: bold; color: #5c450c; margin: 10px 0; letter-spacing: 16px; }
            .cover-subtitle { font-size: 18px; color: #6b5a3e; margin: 15px 0; }
            .cover-origin { font-size: 14px; color: #8b7355; margin: 8px 0; }
            .cover-gen-info { font-size: 14px; color: #6b5a3e; margin: 8px 0; }
            .cover-stats { font-size: 14px; color: #6b5a3e; margin: 4px 0; }
            .cover-date { font-size: 14px; color: #999; margin-top: 20px; }

            /* ===== 章节标题 ===== */
            .chapter-title { text-align: center; font-size: 22px; font-weight: bold; color: #5c450c; margin: 40px 0 30px; padding-bottom: 12px; border-bottom: 2px solid #8b6914; letter-spacing: 8px; }

            /* ===== 目录 ===== */
            .toc { margin: 20px 40px; }
            .toc-item { font-size: 15px; line-height: 2.5; }
            .toc-note { font-size: 13px; color: #888; margin-top: 30px; text-align: center; }

            /* ===== 正文 ===== */
            .content-text { text-indent: 2em; margin: 0 10px; }
            .content-text p { margin: 10px 0; }
            .first-para { text-indent: 2em; }
            .section-desc { font-size: 13px; color: #888; margin-bottom: 16px; text-indent: 0; }
            .signature { text-align: right; margin-right: 50px; color: #555; text-indent: 0; }

            /* ===== 世系表 ===== */
            .genealogy-table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 12px; }
            .genealogy-table th { background: #8b6914; color: #fff; padding: 8px 6px; border: 1px solid #6b4e0a; font-size: 13px; text-align: center; }
            .genealogy-table td { padding: 6px 6px; border: 1px solid #ccc; text-align: center; }
            .genealogy-table .gen-cell { background: #f0e6cc; font-weight: bold; color: #6b4e0a; vertical-align: middle; }
            .genealogy-table .bio-cell { text-align: left; }

            /* ===== 人物传 ===== */
            .bio-entry { margin: 20px 0 10px; }
            .bio-name { font-size: 16px; font-weight: bold; color: #5c450c; margin-bottom: 6px; text-indent: 0; }
            .bio-gen { font-size: 12px; color: #999; font-weight: normal; }
            .bio-info { font-size: 12px; color: #888; margin: 4px 0; text-indent: 0; }
            .bio-content { margin: 8px 0; }
            .bio-content p { margin: 6px 0; text-indent: 2em; }
            .bio-sep { border: none; border-top: 1px dotted #ddd; margin: 15px 0; }
            .no-content { text-align: center; color: #999; margin: 40px 0; font-style: italic; }

            /* ===== 打印设置 ===== */
            @media print {
                body { font-size: 12px; }
            }
        </style>';
    }

    private function esc($str)
    {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}
