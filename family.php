<?php
/**
 * 家谱馆 - 公开家谱列表
 */
require_once __DIR__ . '/includes/header_pre.php';

$db = Database::getInstance();
$page = max(1, (int)get_param('page', 1));
$surname = get_param('surname');
$keyword = get_param('keyword', get_param('q')); // 兼容首页搜索的 q 参数

$where = "WHERE f.status = 1 AND f.is_public = 1";
$params = [];

if ($surname) {
    $where .= " AND f.surname = ?";
    $params[] = $surname;
}
if ($keyword) {
    $where .= " AND (f.title LIKE ? OR f.surname LIKE ? OR f.description LIKE ?)";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

$sql = "SELECT f.*, u.nickname as creator_name,
        (SELECT COUNT(*) FROM members m WHERE m.family_id = f.id AND m.status = 1) as member_count
        FROM families f
        LEFT JOIN users u ON f.user_id = u.id
        {$where}
        ORDER BY f.created_at DESC";

$result = $db->paginate($sql, $params, $page);

// 获取姓氏统计
$surnames = $db->fetchAll(
    "SELECT surname, COUNT(*) as cnt FROM families WHERE status = 1 AND is_public = 1 GROUP BY surname ORDER BY cnt DESC LIMIT 30"
);

$pageTitle = '家谱馆';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>家谱馆</h1>
        <p class="subtitle">寻根溯源，传承家风</p>
    </div>

    <!-- 搜索栏 -->
    <div class="search-bar">
        <form method="get" class="search-form">
            <input type="text" name="keyword" placeholder="搜索姓氏、谱名..."
                   value="<?php echo e($keyword); ?>" class="search-input">
            <button type="submit" class="btn btn-primary">搜 索</button>
            <?php if ($surname): ?>
                <a href="<?php echo SITE_URL; ?>/family.php" class="btn btn-outline">清除筛选</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- 姓氏标签 -->
    <div class="surname-tags">
        <span class="tag-label">热门姓氏：</span>
        <?php foreach ($surnames as $s): ?>
            <a href="<?php echo SITE_URL; ?>/family.php?surname=<?php echo urlencode($s['surname']); ?>"
               class="tag<?php echo $surname === $s['surname'] ? ' active' : ''; ?>">
                <?php echo e($s['surname']); ?> <small>(<?php echo $s['cnt']; ?>)</small>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- 家谱列表 -->
    <?php if (empty($result['data'])): ?>
        <div class="empty-state">
            <div class="empty-icon">📜</div>
            <p>暂无公开的家谱</p>
            <?php if (Auth::isLoggedIn()): ?>
                <a href="<?php echo SITE_URL; ?>/family_create.php" class="btn btn-primary">创建第一个家谱</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="family-grid">
            <?php foreach ($result['data'] as $family): ?>
                <div class="family-card">
                    <div class="family-cover" style="background: linear-gradient(135deg, #8B4513, #D2691E);">
                        <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $family['id']; ?>" class="family-surname"><?php echo e($family['surname']); ?></a>
                    </div>
                    <div class="family-info">
                        <h3><a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $family['id']; ?>"><?php echo e($family['title']); ?></a></h3>
                        <p class="family-meta">
                            <span>👥 <?php echo $family['member_count']; ?>人</span>
                            <span>📍 <?php echo e($family['origin'] ?: '未标注'); ?></span>
                        </p>
                        <p class="family-desc"><?php echo e(str_cut($family['description'] ?: '暂无简介', 80)); ?></p>
                        <p class="family-creator">创建者: <?php echo e($family['creator_name']); ?> · <?php echo friendly_time($family['created_at']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php echo pagination_html($result['page'], $result['totalPages'], SITE_URL . '/family.php?keyword=' . urlencode($keyword) . '&surname=' . urlencode($surname)); ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
