<?php
/**
 * 退出登录（安全版）
 *
 * 必须通过以下任一方式调用，防止 CSRF 强制登出：
 *   1) POST + csrf_token 字段
 *   2) GET  + token 参数（一次性退出令牌，见 Auth::getLogoutToken）
 */
require_once __DIR__ . '/includes/header_pre.php';

$allowed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 方式一：POST + CSRF Token
    $allowed = csrf_verify($_POST['csrf_token'] ?? '');
} else {
    // 方式二：GET + 一次性退出令牌
    $allowed = Auth::verifyLogoutToken(get_param('token'));
}

if (!$allowed) {
    // 未携带有效凭据：展示确认页，避免被 <img src> 之类的方式静默登出
    $pageTitle = '退出登录';
    $confirmAction = SITE_URL . '/logout.php';
    if (!Auth::isLoggedIn()) {
        redirect(SITE_URL . '/login.php');
    }
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="auth-page">
        <div class="auth-card">
            <div class="auth-header">
                <h2>退出登录</h2>
                <p>请确认退出，防止他人冒用您的身份操作</p>
            </div>
            <form method="post" action="<?php echo e($confirmAction); ?>" class="auth-form">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-primary btn-block">确认退出登录</button>
            </form>
            <div style="text-align:center;margin-top:16px;">
                <a href="<?php echo SITE_URL; ?>/" style="font-size:13px;">← 返回首页</a>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// UCenter 互通：先取同步退出脚本（必须在会话销毁前获取）
$synLogoutScript = '';
if (class_exists('UCenterBridge') && UCenterBridge::opt('uc_sync_login', '1') === '1' && UCenter::enabled()) {
    try {
        $synLogoutScript = UCenter::userSynLogout();
    } catch (\Exception $e) {
        error_log('UCenter 同步退出异常: ' . $e->getMessage());
    }
}

$auth = new Auth();
$auth->logout();

// 退出后给一句提示（重新开启一个干净会话承载 flash）
unset($_COOKIE[session_name()]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['flash_message'] = '您已安全退出登录';
$_SESSION['flash_type'] = 'success';

// 有同步退出脚本时，先渲染脚本让其他应用退出，再跳转
if ($synLogoutScript !== '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>正在退出…</title></head><body>'
       . $synLogoutScript
       . '<script>setTimeout(function(){location.href="' . SITE_URL . '/login.php";},800);</script>'
       . '<noscript><meta http-equiv="refresh" content="1;url=' . SITE_URL . '/login.php"></noscript>'
       . '</body></html>';
    exit;
}

redirect(SITE_URL . '/login.php');
