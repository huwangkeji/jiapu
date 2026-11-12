<?php
/**
 * 前台 - 文章查看页面
 */
require_once __DIR__ . '/includes/header_pre.php';

$db = Database::getInstance();
$slug = get_param('slug');

if (empty($slug)) {
    // 文章列表页
    $page = max(1, (int)get_param('p', 1));
    $perPage = 12;
    $offset = ($page - 1) * $perPage;
    $total = $db->fetch("SELECT COUNT(*) AS cnt FROM articles WHERE is_published = 1")['cnt'];
    $articles = $db->fetchAll(
        "SELECT a.*, u.username AS author_name FROM articles a
         LEFT JOIN users u ON a.author_id = u.id
         WHERE a.is_published = 1 ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT $perPage OFFSET $offset"
    );
    $totalPages = ceil($total / $perPage);
    $pageTitle = '文章列表';
} else {
    // 单篇文章
    $article = $db->fetch("SELECT a.*, u.username AS author_name FROM articles a LEFT JOIN users u ON a.author_id = u.id WHERE a.slug = ? AND a.is_published = 1", [$slug]);
    if (!$article) {
        http_response_code(404);
        $pageTitle = '文章未找到';
    } else {
        // 增加浏览量
        $db->execute("UPDATE articles SET view_count = view_count + 1 WHERE id = ?", [$article['id']]);
        $pageTitle = $article['title'];
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <?php if (empty($slug)): // 文章列表页 ?>
        <div class="page-header">
            <h1>文章列表</h1>
            <span style="color:#999;">共 <?php echo $total; ?> 篇文章</span>
        </div>

        <?php if (empty($articles)): ?>
            <div class="card" style="text-align:center;padding:60px 20px;">
                <p style="font-size:48px;margin:0;">📝</p>
                <p style="color:#999;margin-top:12px;">暂无文章</p>
            </div>
        <?php else: ?>
            <div class="article-grid">
                <?php foreach ($articles as $a): ?>
                <div class="article-card">
                    <?php if ($a['is_pinned']): ?>
                        <span class="article-pin">📌 置顶</span>
                    <?php endif; ?>
                    <a href="<?php echo SITE_URL; ?>/article.php?slug=<?php echo urlencode($a['slug']); ?>" class="article-card-title"><?php echo e($a['title']); ?></a>
                    <?php if ($a['category']): ?>
                        <span class="badge"><?php echo e($a['category']); ?></span>
                    <?php endif; ?>
                    <p class="article-card-excerpt">
                        <?php echo $a['excerpt'] ? e($a['excerpt']) : mb_substr(strip_tags($a['content'] ?? ''), 0, 120) . '...'; ?>
                    </p>
                    <div class="article-card-meta">
                        <span>👁 <?php echo (int)$a['view_count']; ?> 次阅读</span>
                        <span><?php echo substr($a['created_at'], 0, 10); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <div style="text-align:center;padding:20px;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?p=<?php echo $i; ?>"
                       class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>"
                       style="margin:0 2px;"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif (empty($article)): // 404 ?>
        <div class="card" style="text-align:center;padding:80px 20px;">
            <p style="font-size:72px;margin:0;">📄</p>
            <h2>文章未找到</h2>
            <p style="color:#999;">该文章可能已被删除或未发布</p>
            <a href="<?php echo SITE_URL; ?>/article.php" class="btn btn-primary">返回文章列表</a>
        </div>

    <?php else: // 单篇文章详情 ?>
        <div class="article-detail">
            <div class="article-detail-header">
                <?php if ($article['category']): ?>
                    <span class="badge" style="margin-bottom:8px;"><?php echo e($article['category']); ?></span>
                <?php endif; ?>
                <h1><?php echo e($article['title']); ?></h1>
                <div class="article-detail-meta">
                    <span>📅 发布于 <?php echo date('Y-m-d H:i', strtotime($article['created_at'])); ?></span>
                    <?php if ($article['author_name']): ?>
                    <span>✍️ 作者：<?php echo e($article['author_name']); ?></span>
                    <?php endif; ?>
                    <span>👁 <?php echo (int)$article['view_count']; ?> 次阅读</span>
                    <?php if ($article['category']): ?>
                    <span>📂 <?php echo e($article['category']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="article-detail-content">
                <?php echo safe_html($article['content']); ?>
            </div>
            <div class="article-detail-footer">
                <a href="<?php echo SITE_URL; ?>/article.php" class="btn btn-outline">← 返回文章列表</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
