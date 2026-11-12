<?php
/**
 * 公共头部模板
 */
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/functions.php';

$db = Database::getInstance();

$siteName = get_site_setting('site_name', SITE_NAME);
$siteSubtitle = get_site_setting('site_subtitle', '寻根溯源 传承家风');
$logoText = get_site_setting('logo_text', '族');
$primaryColor = get_site_setting('primary_color', '#8B4513');
$isLoggedIn = Auth::isLoggedIn();
// BUGFIX: 昵称为空时头像与用户名会显示成空白，回退到用户名
$userNickname = Auth::getNickname() ?: Auth::getUsername();
$isAdmin = Auth::isAdmin();

// 获取时间问候语
function getGreeting() {
    $hour = (int)date('H');
    if ($hour >= 5 && $hour < 11) {
        return '上午好';
    } elseif ($hour >= 11 && $hour < 14) {
        return '中午好';
    } elseif ($hour >= 14 && $hour < 18) {
        return '下午好';
    } else {
        return '晚上好';
    }
}
$greeting = getGreeting();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="keywords" content="<?php echo e(get_site_setting('site_keywords', '家谱,族谱,修谱')); ?>">
    <meta name="description" content="<?php echo e(get_site_setting('site_description', '家谱管理平台')); ?>">
    <title><?php echo e($pageTitle ?? $siteName); ?> - <?php echo e($siteName); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo SITE_URL; ?>/favicon.ico">
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css">
    <?php if (isset($extraCss)): ?>
        <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/<?php echo $extraCss; ?>">
    <?php endif; ?>
    <style>
        :root { --primary-color: <?php echo e($primaryColor); ?>; }
    </style>
</head>
<body>
    <!-- 顶部导航 -->
    <header class="site-header">
        <div class="container">
            <div class="header-inner">
                <a href="<?php echo SITE_URL; ?>/" class="logo">
                    <span class="logo-icon"><?php echo e($logoText); ?></span>
                    <span class="logo-text"><?php echo e($siteName); ?></span>
                </a>
                <nav class="main-nav">
                    <?php
                    // 动态加载导航栏
                    try {
                        $navItems = $db->fetchAll("SELECT * FROM nav_items WHERE is_active = 1 AND position = 'main' ORDER BY sort_order ASC, id ASC");
                    } catch (\Exception $e) {
                        $navItems = [];
                    }
                    $currentPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
                    if (empty($navItems)) {
                        // 降级显示默认导航
                    ?>
                        <a href="<?php echo SITE_URL; ?>/" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? ' active' : ''; ?>">首页</a>
                        <a href="<?php echo SITE_URL; ?>/family.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'family.php' ? ' active' : ''; ?>">家谱馆</a>
                    <?php } else {
                        foreach ($navItems as $nav) {
                            $isActive = ($_SERVER['REQUEST_URI'] === $nav['url']) || (strpos($_SERVER['REQUEST_URI'], $nav['url']) === 0 && $nav['url'] !== '/');
                            echo '<a href="' . SITE_URL . $nav['url'] . '" class="nav-link' . ($isActive ? ' active' : '') . '" target="' . e($nav['target']) . '">' . e($nav['label']) . '</a>';
                        }
                    }
                    // 登录后固定链接（与站点导航之间加视觉分隔）
                    if ($isLoggedIn): ?>
                        <span class="nav-divider" aria-hidden="true"></span>
                        <a href="<?php echo SITE_URL; ?>/my.php" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'my.php' ? ' active' : ''; ?>">我的家谱</a>
                        <?php if ($isAdmin && basename(dirname($_SERVER['PHP_SELF'])) !== 'admin'): ?>
                            <a href="<?php echo SITE_URL; ?>/admin/" class="nav-link">后台管理</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </nav>
                <div class="header-right">
                    <?php if ($isLoggedIn): ?>
                        <span class="header-greeting"><?php echo $greeting; ?>，</span>
                        <div class="user-menu-wrap">
                            <button type="button" class="user-menu" id="userMenuTrigger" aria-haspopup="true" aria-expanded="false">
                                <span class="user-avatar"><?php echo mb_substr($userNickname, 0, 1); ?></span>
                                <span class="user-name"><?php echo e($userNickname); ?></span>
                                <span class="user-caret" aria-hidden="true">▾</span>
                            </button>
                            <div class="dropdown" id="userDropdown">
                                <a href="<?php echo SITE_URL; ?>/profile.php">⚙️ 个人设置</a>
                                <a href="<?php echo SITE_URL; ?>/my.php">📖 我的家谱</a>
                                <?php if ($isAdmin): ?>
                                    <a href="<?php echo SITE_URL; ?>/admin/">🏛️ 后台管理</a>
                                <?php endif; ?>
                                <span class="dropdown-divider"></span>
                                <a href="<?php echo SITE_URL; ?>/logout.php?token=<?php echo urlencode(Auth::getLogoutToken()); ?>">🚪 退出登录</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-outline">登录</a>
                        <a href="<?php echo SITE_URL; ?>/register.php" class="btn btn-primary">注册</a>
                    <?php endif; ?>
                </div>
                <button class="mobile-menu-btn" onclick="document.querySelector('.main-nav').classList.toggle('show')">☰</button>
            </div>
        </div>
    </header>

    <!-- 消息提示 -->
    <?php $flash = Flash::get(); if ($flash): ?>
        <div class="flash-message flash-<?php echo e($flash['type']); ?>">
            <span><?php echo e($flash['message']); ?></span>
            <button onclick="this.parentElement.remove()" class="flash-close">&times;</button>
        </div>
    <?php endif; ?>

    <!-- 注册/找回密码后的保存凭据提醒 -->
    <?php if (isset($_SESSION['save_credentials_reminder'])): ?>
        <div class="flash-message flash-warning" style="text-align:left;padding:16px 20px;">
            <strong style="font-size:15px;">⚠️ 重要提醒：请立即保存您的登录凭据</strong>
            <ol style="margin:6px 0 0 18px;line-height:1.8;font-size:13px;">
                <li>使用手机拍下或截图保存本页面</li>
                <li>记录您的 <strong>用户名</strong> 和 <strong>密码</strong></li>
                <li>截图保存网站地址：<strong><?php echo SITE_URL; ?></strong></li>
            </ol>
            <button class="flash-close" onclick="this.parentElement.remove()" style="top:8px;right:14px;">&times;</button>
        </div>
        <?php unset($_SESSION['save_credentials_reminder']); ?>
    <?php endif; ?>

    <main class="main-content">
