<?php
/**
 * 找回密码 - 第二步：输入验证码 + 设置新密码
 */
require_once __DIR__ . '/includes/header_pre.php';

if (Auth::isLoggedIn()) {
    redirect(SITE_URL . '/');
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求，请重试';
    } else {
        $email    = trim(post_param('email'));
        $code     = trim(post_param('code'));
        $password = post_param('password');
        $password2 = post_param('password2');

        if (empty($email) || empty($code) || empty($password)) {
            $error = '请填写完整信息';
        } elseif ($password !== $password2) {
            $error = '两次输入的密码不一致';
        } elseif (mb_strlen($password) < 6) {
            $error = '密码长度不能少于6位';
        } else {
            $db = Database::getInstance();

            // 验证验证码（库中存的是哈希；同时兼容历史上遗留的明文记录）
            $codeHash = hash('sha256', $code . '|' . SECURITY_SALT . '|' . $email);
            $reset = $db->fetch(
                "SELECT * FROM password_resets
                 WHERE email = ? AND (code = ? OR code = ?) AND expires_at > NOW() AND used = 0",
                [$email, $codeHash, $code]
            );

            if (!$reset) {
                $error = '验证码错误或已过期，请重新获取';
            } else {
                $user = $db->fetch("SELECT id, username FROM users WHERE email = ? AND status = 1", [$email]);
                if (!$user) {
                    $error = '用户不存在';
                } else {
                    // 更新密码
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
                    $db->execute("UPDATE users SET password = ? WHERE id = ?", [$hash, $user['id']]);

                    // 标记验证码已使用
                    $db->execute("UPDATE password_resets SET used = 1 WHERE id = ?", [$reset['id']]);

                    Logger::info('密码重置', "用户 {$user['username']} 通过邮箱验证重置密码", $user['id']);

                    $_SESSION['save_credentials_reminder'] = true;
                    $success = true;
                }
            }
        }
    }
}

$pageTitle = '重置密码';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h2>重置密码</h2>
            <p>请输入验证码和新密码</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" style="text-align:left;">
                <p><strong>✅ 密码修改成功！</strong></p>
                <div style="background:#fef9e7;border:1px solid #f39c12;border-radius:8px;padding:16px;margin:12px 0;">
                    <p style="margin:0 0 8px;font-weight:bold;color:#856404;">⚠️ 重要提醒：请立即保存您的登录凭据</p>
                    <ol style="margin:4px 0;padding-left:20px;color:#856404;font-size:14px;">
                        <li>用手机或截图工具保存本页面</li>
                        <li>记录您的用户名和新密码</li>
                        <li>截图保存网站地址：<strong><?php echo SITE_URL; ?></strong></li>
                    </ol>
                </div>
                <p style="margin-top:16px;">
                    <a href="<?php echo SITE_URL; ?>/login.php" class="btn btn-primary" style="display:inline-block;">去登录</a>
                </p>
            </div>
        <?php else: ?>
            <form method="post" class="auth-form">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="email">注册邮箱</label>
                    <input type="email" id="email" name="email" required
                           placeholder="请输入注册时使用的邮箱" autofocus
                           value="<?php echo e(post_param('email')); ?>">
                </div>
                <div class="form-group">
                    <label for="code">验证码</label>
                    <input type="text" id="code" name="code" required
                           placeholder="请输入6位验证码" maxlength="6"
                           value="<?php echo e(post_param('code')); ?>"
                           style="letter-spacing:4px;font-size:18px;text-align:center;">
                </div>
                <div class="form-group">
                    <label for="password">新密码</label>
                    <input type="password" id="password" name="password" required
                           placeholder="至少6位，建议包含字母和数字">
                </div>
                <div class="form-group">
                    <label for="password2">确认新密码</label>
                    <input type="password" id="password2" name="password2" required
                           placeholder="请再次输入新密码">
                </div>
                <button type="submit" class="btn btn-primary btn-block">重置密码</button>
            </form>
        <?php endif; ?>

        <div style="text-align:center;margin-top:20px;">
            <a href="<?php echo SITE_URL; ?>/login.php" style="font-size:13px;">← 返回登录</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
