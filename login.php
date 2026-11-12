<?php
/**
 * 用户登录
 */
require_once __DIR__ . '/includes/header_pre.php';

if (Auth::isLoggedIn()) {
    redirect(SITE_URL . '/');
}

$pageTitle = '用户登录';
$error = ''; // 初始化错误变量

// 安全检查：IP 是否被封禁
if (Security::isIpBanned()) {
    $error = '您的IP已被临时封禁，请30分钟后再试';
}

$captchaEnabled = get_site_setting('captcha_enabled', '0') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求，请重试';
    } elseif ($captchaEnabled) {
        $captchaInput = strtoupper(trim(post_param('captcha')));
        $captchaSession = strtoupper($_SESSION['captcha_code'] ?? '');
        unset($_SESSION['captcha_code']); // 验证码一次性使用
        if (empty($captchaInput) || $captchaInput !== $captchaSession) {
            $error = '验证码错误，请重新输入';
        }
    }

    if (!$error) {
        $auth = new Auth();
        $result = $auth->login(
            post_param('username'),
            post_param('password'),
            post_param('remember') === '1'
        );
        if ($result['success']) {
            $redirect = $_SESSION['redirect_after_login'] ?? SITE_URL . '/';
            unset($_SESSION['redirect_after_login']);
            redirect($redirect);
        } else {
            $error = $result['message'];
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h2>用户登录</h2>
            <p>欢迎回来，请输入您的账号信息</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="post" class="auth-form">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="username">用户名 / 邮箱</label>
                <input type="text" id="username" name="username" required
                       placeholder="请输入用户名或邮箱" autofocus
                       value="<?php echo e(post_param('username')); ?>">
            </div>
            <div class="form-group">
                <label for="password">密码</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required
                           placeholder="请输入密码">
                    <button type="button" class="toggle-password" onclick="togglePwd('password', this)">👁</button>
                </div>
            </div>
            <?php if ($captchaEnabled): ?>
            <div class="form-group">
                <label for="captcha">验证码</label>
                <div class="captcha-row">
                    <input type="text" id="captcha" name="captcha" required
                           placeholder="请输入验证码" maxlength="4" autocomplete="off"
                           style="flex:1;text-align:center;letter-spacing:4px;font-size:16px;">
                    <img src="<?php echo SITE_URL; ?>/captcha.php" alt="验证码"
                         onclick="this.src='<?php echo SITE_URL; ?>/captcha.php?'+Date.now()"
                         title="点击刷新验证码" style="cursor:pointer;height:42px;border-radius:6px;">
                </div>
            </div>
            <?php endif; ?>
            <div class="form-group form-row">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember" value="1"> 记住我
                </label>
                <a href="<?php echo SITE_URL; ?>/register.php" class="link">还没有账号？立即注册</a>
            </div>
            <div class="form-group" style="text-align:right;margin-top:-8px;">
                <a href="<?php echo SITE_URL; ?>/forgot_password.php" style="font-size:13px;color:#999;">忘记密码？</a>
            </div>
            <button type="submit" class="btn btn-primary btn-block">登 录</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
