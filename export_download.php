<?php
/**
 * 家谱导出 — 下载接口
 * 根据 format 参数调用对应的导出器
 * GET: /export_download.php?family_id=X&format=xls|doc
 */
require_once __DIR__ . '/includes/header_pre.php';

// ── 参数验证 ──
$familyId = (int)get_param('family_id', 0);
$format   = get_param('format', '');

if ($familyId <= 0 || !in_array($format, ['xls', 'doc'])) {
    http_response_code(400);
    die('参数错误');
}

// ── 权限验证 ──
$db = Database::getInstance();
$family = $db->fetch(
    "SELECT * FROM families WHERE id = ? AND status = 1",
    [$familyId]
);

if (!$family) {
    http_response_code(404);
    die('家谱不存在');
}

if (!Auth::isLoggedIn() || Auth::getUserId() != $family['user_id']) {
    if (!$family['is_public']) {
        http_response_code(403);
        die('无权导出此家谱');
    }
}

// ── 加载引擎 ──
require_once __DIR__ . '/includes/ExportEngine.php';

try {
    $engine = new ExportEngine($familyId);

    if ($format === 'xls') {
        require_once __DIR__ . '/includes/ExcelExporter.php';
        $exporter = new ExcelExporter($engine);
        $exporter->download();
    } elseif ($format === 'doc') {
        require_once __DIR__ . '/includes/WordExporter.php';
        $exporter = new WordExporter($engine);
        $exporter->download();
    }
} catch (Exception $e) {
    http_response_code(500);
    die('导出失败: ' . $e->getMessage());
}
