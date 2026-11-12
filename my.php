<?php
/**
 * 我的家谱列表
 */
require_once __DIR__ . '/includes/header_pre.php';
Auth::requireLogin();

$db = Database::getInstance();
$userId = Auth::getUserId();
$page = max(1, (int)get_param('page', 1));

$sql = "SELECT f.*,
        (SELECT COUNT(*) FROM members m WHERE m.family_id = f.id AND m.status = 1) as member_count
        FROM families f
        WHERE f.user_id = ? AND f.status = 1
        ORDER BY f.updated_at DESC";

$result = $db->paginate($sql, [$userId], $page);

$pageTitle = '我的家谱';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>我的家谱</h1>
        <a href="<?php echo SITE_URL; ?>/family_create.php" class="btn btn-primary">创建新家谱</a>
    </div>

    <?php if (empty($result['data'])): ?>
        <div class="empty-state">
            <div class="empty-icon">📖</div>
            <p>您还没有创建家谱</p>
            <a href="<?php echo SITE_URL; ?>/family_create.php" class="btn btn-primary">立即创建</a>
        </div>
    <?php else: ?>
        <div class="family-list">
            <?php foreach ($result['data'] as $family): ?>
                <div class="family-list-item">
                    <div class="family-list-surname"><?php echo mb_substr($family['surname'], 0, 1); ?></div>
                    <div class="family-list-info">
                        <h3>
                            <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $family['id']; ?>"><?php echo e($family['title']); ?></a>
                            <?php if ($family['is_public']): ?>
                                <span class="badge badge-success">公开</span>
                            <?php else: ?>
                                <span class="badge badge-default">私密</span>
                            <?php endif; ?>
                        </h3>
                        <p class="family-meta">
                            <span>👥 <?php echo $family['member_count']; ?>人</span>
                            <span>📍 <?php echo e($family['origin'] ?: '未标注'); ?></span>
                            <span>🕐 更新于 <?php echo friendly_time($family['updated_at']); ?></span>
                        </p>
                        <p class="family-desc"><?php echo e(str_cut($family['description'] ?: '暂无简介', 100)); ?></p>
                    </div>
                    <div class="family-list-actions">
                        <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $family['id']; ?>" class="btn btn-sm btn-outline">查看</a>
                        <a href="<?php echo SITE_URL; ?>/family_edit.php?id=<?php echo $family['id']; ?>" class="btn btn-sm btn-outline">编辑</a>
                        <a href="<?php echo SITE_URL; ?>/member_add.php?family_id=<?php echo $family['id']; ?>" class="btn btn-sm btn-primary">添加成员</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php
        // BUGFIX: 之前缺少 "?"，分页链接会拼成 /my.php&page=2 导致 404
        echo pagination_html($result['page'], $result['totalPages'], SITE_URL . '/my.php?');
        ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
