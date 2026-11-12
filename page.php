<?php
/**
 * 自定义页面路由
 * 访问方式: /page.php?slug=about
 */
require_once __DIR__ . '/includes/header_pre.php';

$db = Database::getInstance();
$slug = get_param('slug', '');

if (empty($slug)) {
    redirect(SITE_URL . '/');
}

$page = $db->fetch(
    "SELECT * FROM custom_pages WHERE slug = ? AND is_published = 1",
    [$slug]
);

if (!$page) {
    $_SESSION['flash_message'] = '页面不存在';
    $_SESSION['flash_type'] = 'error';
    redirect(SITE_URL . '/');
}

$pageTitle = $page['title'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:800px;margin:40px auto;padding:0 20px;">
    <div class="page-card" style="background:#fff;padding:40px;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.08);">
        <h1 style="text-align:center;color:#8B4513;margin-bottom:24px;"><?php echo e($page['title']); ?></h1>
        <div class="page-content">
            <?php echo safe_html($page['content']); ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
