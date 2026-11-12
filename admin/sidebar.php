<?php
/**
 * 后台侧边栏
 * 被 admin/ 目录下的页面 include
 */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<aside class="admin-sidebar">
    <div class="sidebar-header">
        <span class="sidebar-logo">🏛️</span>
        <span class="sidebar-title">后台管理</span>
    </div>
    <nav class="sidebar-nav">
        <a href="<?php echo SITE_URL; ?>/admin/index.php" class="sidebar-link <?php echo $currentPage === 'index' ? 'active' : ''; ?>">
            <span class="sidebar-icon">📊</span> 仪表盘
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/settings.php" class="sidebar-link <?php echo $currentPage === 'settings' ? 'active' : ''; ?>">
            <span class="sidebar-icon">⚙️</span> 网站设置
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/homepage.php" class="sidebar-link <?php echo $currentPage === 'homepage' ? 'active' : ''; ?>">
            <span class="sidebar-icon">🏠</span> 首页独立设置
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/nav_items.php" class="sidebar-link <?php echo $currentPage === 'nav_items' ? 'active' : ''; ?>">
            <span class="sidebar-icon">🧭</span> 导航栏设置
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/articles.php" class="sidebar-link <?php echo $currentPage === 'articles' ? 'active' : ''; ?>">
            <span class="sidebar-icon">📝</span> 文章管理
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/custom_pages.php" class="sidebar-link <?php echo $currentPage === 'custom_pages' ? 'active' : ''; ?>">
            <span class="sidebar-icon">📄</span> 自定义页面
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/links.php" class="sidebar-link <?php echo $currentPage === 'links' ? 'active' : ''; ?>">
            <span class="sidebar-icon">🔗</span> 友情链接
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/users.php" class="sidebar-link <?php echo $currentPage === 'users' ? 'active' : ''; ?>">
            <span class="sidebar-icon">👥</span> 会员管理
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/families.php" class="sidebar-link <?php echo $currentPage === 'families' ? 'active' : ''; ?>">
            <span class="sidebar-icon">📖</span> 家谱管理
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/logs.php" class="sidebar-link <?php echo $currentPage === 'logs' ? 'active' : ''; ?>">
            <span class="sidebar-icon">📋</span> 系统日志
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/email.php" class="sidebar-link <?php echo $currentPage === 'email' ? 'active' : ''; ?>">
            <span class="sidebar-icon">📧</span> 邮件群发
        </a>
        <a href="<?php echo SITE_URL; ?>/admin/ucenter.php" class="sidebar-link <?php echo $currentPage === 'ucenter' ? 'active' : ''; ?>">
            <span class="sidebar-icon">🔄</span> UCenter 互通
        </a>
        <div class="sidebar-divider"></div>
        <a href="<?php echo SITE_URL; ?>/" class="sidebar-link">
            <span class="sidebar-icon">🏠</span> 返回前台
        </a>
        <a href="<?php echo SITE_URL; ?>/logout.php?token=<?php echo urlencode(Auth::getLogoutToken()); ?>" class="sidebar-link">
            <span class="sidebar-icon">🚪</span> 退出登录
        </a>
    </nav>
</aside>
