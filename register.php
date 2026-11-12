<?php
/**
 * 用户注册
 */
require_once __DIR__ . '/includes/header_pre.php';

// 检查是否允许注册
if (get_site_setting('allow_register', '1') !== '1') {
    $_SESSION['flash_message'] = '当前系统暂停注册';
    $_SESSION['flash_type'] = 'error';
    redirect(SITE_URL . '/login.php');
}

if (Auth::isLoggedIn()) {
    redirect(SITE_URL . '/');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求，请重试';
    } elseif (!isset($_POST['agree'])) {
        $error = '请先阅读并同意用户协议和隐私政策';
    } else {
        $auth = new Auth();
        $result = $auth->register(
            post_param('username'),
            post_param('email'),
            post_param('password'),
            post_param('nickname')
        );
        if ($result['success']) {
            // 注册成功后自动登录
            $auth->login(post_param('username'), post_param('password'));

            // 发送欢迎邮件（异步：不影响主流程）
            try {
                set_time_limit(15); // 邮件发送最多等15秒
                require_once __DIR__ . '/includes/Mailer.php';
                $mailer = new Mailer();
                if ($mailer->isConfigured()) {
                    $username = post_param('username');
                    $emailVal = post_param('email');
                    $subject = str_replace('{site_name}', SITE_NAME,
                        get_site_setting('email_welcome_subject', '欢迎加入' . SITE_NAME));
                    $body = str_replace(
                        ['{username}', '{email}', '{site_name}', '{site_url}'],
                        [$username, $emailVal, SITE_NAME, SITE_URL],
                        get_site_setting('email_welcome_body', '<p>欢迎加入！</p>')
                    );
                    $mailer->send($emailVal, $subject, $body);
                }
            } catch (\Exception $e) {
                // 邮件失败不影响注册
            }

            $_SESSION['flash_message'] = '注册成功！欢迎加入家谱管理系统';
            $_SESSION['flash_type'] = 'success';
            $_SESSION['save_credentials_reminder'] = true;
            redirect(SITE_URL . '/');
        } else {
            $error = $result['message'];
        }
    }
}

$pageTitle = '用户注册';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h2>用户注册</h2>
            <p>创建账号，开始记录您的家族历史</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form method="post" class="auth-form">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="username">用户名 <span class="required">*</span></label>
                <input type="text" id="username" name="username" required
                       placeholder="3-50个字符" minlength="3" maxlength="50"
                       value="<?php echo e(post_param('username')); ?>">
            </div>
            <div class="form-group">
                <label for="nickname">昵称</label>
                <input type="text" id="nickname" name="nickname"
                       placeholder="显示名称，不填则使用用户名"
                       value="<?php echo e(post_param('nickname')); ?>">
            </div>
            <div class="form-group">
                <label for="email">邮箱 <span class="required">*</span></label>
                <input type="email" id="email" name="email" required
                       placeholder="用于找回密码"
                       value="<?php echo e(post_param('email')); ?>">
            </div>
            <div class="form-group">
                <label for="password">密码 <span class="required">*</span></label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required
                           placeholder="至少6位" minlength="6">
                    <button type="button" class="toggle-password" onclick="togglePwd('password', this)">👁</button>
                </div>
            </div>
            <div class="form-group">
                <label for="password_confirm">确认密码 <span class="required">*</span></label>
                <div class="password-wrapper">
                    <input type="password" id="password_confirm" name="password_confirm" required
                           placeholder="请再次输入密码" minlength="6">
                    <button type="button" class="toggle-password" onclick="togglePwd('password_confirm', this)">👁</button>
                </div>
            </div>
            <div class="form-group">
                <label class="checkbox-label" style="display:flex;align-items:flex-start;gap:6px;font-size:13px;color:#666;">
                    <input type="checkbox" name="agree" value="1" required style="margin-top:2px;flex-shrink:0;">
                    <span>我已阅读并同意
                        <a href="<?php echo SITE_URL; ?>/agreement.php" target="_blank">《用户协议》</a>和
                        <a href="<?php echo SITE_URL; ?>/privacy.php" target="_blank">《隐私政策》</a>
                    </span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary btn-block">注 册</button>
            <p class="auth-footer-text">已有账号？<a href="<?php echo SITE_URL; ?>/login.php">立即登录</a></p>
        </form>
    </div>
</div>

<script>
document.querySelector('.auth-form').addEventListener('submit', function(e) {
    var pwd = document.getElementById('password').value;
    var cpwd = document.getElementById('password_confirm').value;
    if (pwd !== cpwd) {
        e.preventDefault();
        alert('两次输入的密码不一致');
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
