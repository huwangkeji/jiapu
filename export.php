<?php
/**
 * 家谱导出 — 编目成书
 * 支持 Excel（世系表）和 Word（家谱全书）格式下载
 * 引擎完全本地化，零外部依赖
 */
require_once __DIR__ . '/includes/header_pre.php';

$db = Database::getInstance();
$userId = Auth::getUserId();

// 获取用户的所有家谱
$families = [];
if (Auth::isLoggedIn()) {
    $families = $db->fetchAll(
        "SELECT id, surname, title, origin, created_at,
                (SELECT COUNT(*) FROM members WHERE family_id = f.id AND status = 1) as member_count
         FROM families f
         WHERE user_id = ? AND status = 1
         ORDER BY updated_at DESC",
        [$userId]
    );
}

$pageTitle = '编目成书';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:900px;margin:20px auto;">

    <h2 style="margin-bottom:8px;">📚 编目成书</h2>
    <p style="color:#888;margin-bottom:24px;">将家谱数据自动排版，导出为电子表格或 Word 文档格式。引擎完全本地化，无需联网。</p>

    <?php if (empty($families)): ?>
        <div class="empty-state" style="text-align:center;padding:60px 20px;">
            <div style="font-size:48px;margin-bottom:16px;">📚</div>
            <p style="font-size:16px;color:#666;">您还没有创建家谱</p>
            <p style="color:#999;margin-bottom:20px;">创建家谱并添加成员后，即可导出成书</p>
            <a href="<?php echo SITE_URL; ?>/family_create.php" class="btn btn-primary">创建家谱</a>
        </div>
    <?php else: ?>
        <div class="export-cards">
            <?php foreach ($families as $family): ?>
                <?php
                $canExport = $family['member_count'] > 0;
                ?>
                <div class="export-card">
                    <div class="export-card-info">
                        <h3><?php echo e($family['surname']); ?>氏族谱</h3>
                        <p class="export-card-title"><?php echo e($family['title']); ?></p>
                        <?php if ($family['origin']): ?>
                            <p class="export-card-origin">源出：<?php echo e($family['origin']); ?></p>
                        <?php endif; ?>
                        <p class="export-card-count">
                            <span class="badge"><?php echo $family['member_count']; ?> 人</span>
                            <span class="text-muted">创建于 <?php echo date('Y-m-d', strtotime($family['created_at'])); ?></span>
                        </p>
                    </div>

                    <?php if ($canExport): ?>
                    <div class="export-card-actions">
                        <a href="<?php echo SITE_URL; ?>/export_download.php?family_id=<?php echo $family['id']; ?>&format=xls"
                           class="btn btn-export btn-excel" target="_blank">
                            📊 导出世系表<br><small>Excel 电子表格</small>
                        </a>
                        <a href="<?php echo SITE_URL; ?>/export_download.php?family_id=<?php echo $family['id']; ?>&format=doc"
                           class="btn btn-export btn-word" target="_blank">
                            📖 导出全书<br><small>Word 文档</small>
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="export-card-actions">
                        <span class="btn btn-export btn-disabled" title="请先添加成员">
                            📊 暂无成员<br><small>请先添加成员</small>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 格式说明 -->
        <div class="export-info" style="margin-top:40px;">
            <h3>导出格式说明</h3>
            <div class="info-grid">
                <div class="info-item">
                    <h4>📊 Excel 世系表</h4>
                    <ul>
                        <li>按世次分组的成员信息表</li>
                        <li>包含：序号、世次、姓名、性别、生卒年、父名、生平</li>
                        <li>格式：.xls（Excel / WPS 均可打开）</li>
                        <li>带有表头样式和世代分组</li>
                    </ul>
                </div>
                <div class="info-item">
                    <h4>📖 Word 家谱全书</h4>
                    <ul>
                        <li>古典排版，仿书籍格式</li>
                        <li>包含：封面 → 目录 → 谱序 → 世系总览 → 人物传 → 后记</li>
                        <li>格式：.doc（Word / WPS 均可打开）</li>
                        <li>含有人物传记排版</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="export-notice" style="margin-top:24px;padding:16px;background:#fffef5;border:1px solid #f0e6cc;border-radius:8px;color:#8b7355;font-size:13px;">
            <strong>💡 提示：</strong>
            导出引擎完全运行在服务器本地，无需联网。导出内容基于您当前的家谱数据。如需更完整的家谱全书，建议先在成员管理中完善每个人的生平简介。
        </div>
    <?php endif; ?>
</div>

<style>
/* ===== 导出卡片 ===== */
.export-cards { display: flex; flex-direction: column; gap: 16px; }
.export-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    background: #fff;
    border: 1px solid #e8dcc8;
    border-radius: 10px;
    transition: border-color 0.2s;
}
.export-card:hover { border-color: #c8a87c; }
.export-card-info h3 { font-size: 18px; color: #5c450c; margin: 0 0 4px; }
.export-card-title { font-size: 14px; color: #8b6914; margin: 0 0 4px; }
.export-card-origin { font-size: 13px; color: #999; margin: 0 0 8px; }
.export-card-count { font-size: 13px; color: #888; margin: 0; display: flex; align-items: center; gap: 8px; }
.badge { background: #f0e6cc; color: #8b6914; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; }
.text-muted { color: #aaa; }
.export-card-actions { display: flex; gap: 10px; flex-shrink: 0; margin-left: 20px; }

/* ===== 导出按钮 ===== */
.btn-export {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 14px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    text-align: center;
    min-width: 120px;
    line-height: 1.5;
    transition: all 0.2s;
    border: 1.5px solid;
}
.btn-export small { font-size: 11px; font-weight: normal; opacity: 0.75; }
.btn-excel {
    background: #e8f5e9;
    color: #2e7d32;
    border-color: #81c784;
}
.btn-excel:hover { background: #c8e6c9; border-color: #4caf50; }
.btn-word {
    background: #e3f2fd;
    color: #1565c0;
    border-color: #64b5f6;
}
.btn-word:hover { background: #bbdefb; border-color: #42a5f5; }
.btn-disabled {
    background: #f5f5f5;
    color: #ccc;
    border-color: #e0e0e0;
    cursor: not-allowed;
}

/* ===== 格式说明 ===== */
.export-info h3 { font-size: 16px; color: #5c450c; margin-bottom: 16px; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.info-item { background: #fefdf8; border: 1px solid #e8dcc8; border-radius: 8px; padding: 16px 20px; }
.info-item h4 { font-size: 14px; color: #8b6914; margin: 0 0 8px; }
.info-item ul { margin: 0; padding: 0 0 0 18px; font-size: 13px; color: #888; }
.info-item li { margin-bottom: 4px; }

@media (max-width: 768px) {
    .export-card { flex-direction: column; align-items: flex-start; }
    .export-card-actions { margin-left: 0; margin-top: 14px; width: 100%; }
    .btn-export { flex: 1; }
    .info-grid { grid-template-columns: 1fr; }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
