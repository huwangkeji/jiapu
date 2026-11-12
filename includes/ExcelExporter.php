<?php
/**
 * ExcelExporter — 家谱世系表导出（Excel格式）
 * 使用 HTML 表格转 .xls（Excel原生支持），纯PHP，零外部依赖
 * PHP 7.4+
 */

class ExcelExporter
{
    private $engine;

    public function __construct(ExportEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * 输出 Excel 下载
     */
    public function download($filename = null)
    {
        $data      = $this->engine->getExportData();
        $family    = $data['family'];
        $stats     = $data['stats'];
        $generations = $data['generations'];
        $genRange  = $data['gen_range'];

        if (!$filename) {
            $filename = $family['surname'] . '氏族谱_世系表_' . date('Ymd');
        }

        $html = $this->buildHtml($data);

        // 输出 Excel 头
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Cache-Control: max-age=0');
        header('Expires: 0');
        header('Pragma: public');

        // UTF-8 BOM for Excel
        echo "\xEF\xBB\xBF";
        echo $html;
        exit;
    }

    /**
     * 构建 Excel HTML
     */
    private function buildHtml($data)
    {
        $family      = $data['family'];
        $title       = $data['title'];
        $stats       = $data['stats'];
        $generations = $data['generations'];
        $genRange    = $data['gen_range'];
        $members     = $data['members'];

        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
        $html .= '<head><meta charset="UTF-8">';
        $html .= '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets>';
        $html .= '<x:ExcelWorksheet><x:Name>世系表</x:Name><x:WorksheetOptions>';
        $html .= '<x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet>';
        $html .= '</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
        $html .= '<style>';
        $html .= 'table { border-collapse: collapse; }';
        $html .= '.title-row td { font-size:18px; font-weight:bold; text-align:center; padding:12px; background:#8b6914; color:#fff; }';
        $html .= '.info-row td { font-size:12px; padding:6px 8px; border:1px solid #ddd; background:#fef5e0; }';
        $html .= '.header-row td { font-size:13px; font-weight:bold; text-align:center; padding:8px 6px; border:1px solid #999; background:#daa520; color:#fff; }';
        $html .= '.data-row td { font-size:12px; padding:6px 8px; border:1px solid #ccc; text-align:center; }';
        $html .= '.data-row td.left { text-align:left; }';
        $html .= '.gen-header td { font-size:13px; font-weight:bold; padding:7px 10px; background:#f0e6cc; border:1px solid #b8860b; color:#6b4e0a; }';
        $html .= '.even td { background:#fffdf5; }';
        $html .= '.footer-row td { font-size:11px; text-align:center; padding:8px; border-top:2px solid #8b6914; color:#999; }';
        $html .= 'td { mso-number-format:"\@"; }'; // 所有单元格文本格式
        $html .= '</style>';
        $html .= '</head><body>';

        // === 表格 ===
        $html .= '<table>';

        // 标题行
        $html .= '<tr class="title-row"><td colspan="8">' . $this->esc($title) . '</td></tr>';

        // 基本信息
        $origin = $family['origin'] ?: '未填写';
        $html .= '<tr class="info-row"><td colspan="4">发源地：' . $this->esc($origin) . '</td><td colspan="4">创建时间：' . $family['created_at'] . '</td></tr>';
        $html .= '<tr class="info-row"><td colspan="2">总人数：' . $stats['total'] . '</td><td colspan="2">男：' . $stats['male'] . '</td><td colspan="2">女：' . $stats['female'] . '</td><td colspan="2">世次：第' . $genRange['min'] . '−' . $genRange['max'] . '世</td></tr>';
        $html .= '<tr class="info-row"><td colspan="8" style="height:4px;"></td></tr>';

        // 表头
        $html .= '<tr class="header-row">';
        $html .= '<td width="50">序号</td>';
        $html .= '<td width="70">世次</td>';
        $html .= '<td width="90">姓名</td>';
        $html .= '<td width="50">性别</td>';
        $html .= '<td width="90">出生日期</td>';
        $html .= '<td width="90">逝世日期</td>';
        $html .= '<td width="100">父亲</td>';
        $html .= '<td width="200">生平简介</td>';
        $html .= '</tr>';

        // 按世代输出
        $seq = 0;
        $genKeys = array_keys($generations);

        foreach ($genKeys as $genIdx => $gen) {
            // 世代标题行
            if ($genIdx > 0) {
                $html .= '<tr class="info-row"><td colspan="8" style="height:2px;"></td></tr>';
            }
            $html .= '<tr class="gen-header"><td colspan="8">第 ' . $gen . ' 世 （' . count($generations[$gen]) . ' 人）</td></tr>';

            $genMembers = $generations[$gen];
            foreach ($genMembers as $idx => $m) {
                $seq++;
                $rowClass = ($idx % 2 === 0) ? 'data-row' : 'data-row even';
                $genderText = $m['gender'] === 'male' ? '男' : ($m['gender'] === 'female' ? '女' : '未知');

                $bio = $m['biography'] ?? '';
                if (mb_strlen($bio) > 60) {
                    $bio = mb_substr($bio, 0, 60) . '…';
                }

                $html .= '<tr class="' . $rowClass . '">';
                $html .= '<td>' . $seq . '</td>';
                $html .= '<td>第' . $m['generation'] . '世</td>';
                $html .= '<td>' . $this->esc($m['name']) . '</td>';
                $html .= '<td>' . $genderText . '</td>';
                $html .= '<td>' . $this->esc($m['birth_date'] ?: '-') . '</td>';
                $html .= '<td>' . $this->esc($m['death_date'] ?: '-') . '</td>';
                $html .= '<td>' . $this->esc($m['father_name'] ?: '-') . '</td>';
                $html .= '<td class="left">' . $this->esc($bio) . '</td>';
                $html .= '</tr>';
            }
        }

        // 页脚
        $html .= '<tr class="info-row"><td colspan="8" style="height:8px;"></td></tr>';
        $html .= '<tr class="footer-row"><td colspan="8">' . $this->esc(SITE_NAME) . ' — 家族传承，源远流长 — 导出时间：' . date('Y-m-d H:i') . '</td></tr>';

        $html .= '</table>';
        $html .= '</body></html>';

        return $html;
    }

    private function esc($str)
    {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}
