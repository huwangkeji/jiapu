<?php
require_once __DIR__ . '/includes/header_pre.php';

$title = get_site_setting('agreement_title', '用户注册协议');
$content = get_site_setting('agreement_content', '<p>请前往后台设置用户协议内容。</p>');

$pageTitle = $title;
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="max-width:800px;margin:40px auto;padding:0 20px;">
    <div class="page-card" style="background:#fff;padding:40px;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.08);">
        <h1 style="text-align:center;color:#8B4513;margin-bottom:24px;"><?php echo e($title); ?></h1>
        <div class="page-content">
            <?php echo safe_html($content); ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
