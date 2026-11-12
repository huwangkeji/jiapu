<?php
/**
 * 找回密码 - 第一步：输入邮箱
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
        $email = trim(post_param('email'));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '请输入有效的邮箱地址';
        } elseif (!Security::throttle('forgot_password', 5, 600)) {
            // BUGFIX: 此前无频率限制，可被用来轰炸任意邮箱
            $error = '请求过于频繁，请 10 分钟后再试';
        } else {
            $db = Database::getInstance();
            $user = $db->fetch("SELECT id, username FROM users WHERE email = ? AND status = 1", [$email]);

            if ($user) {
                // 生成6位数字验证码
                $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expires = date('Y-m-d H:i:s', time() + 600); // 10分钟有效

                // 安全加固：库中只存验证码的哈希，避免拖库后直接重置他人账号
                $codeHash = hash('sha256', $code . '|' . SECURITY_SALT . '|' . $email);

                // 清除旧的验证码
                $db->execute("DELETE FROM password_resets WHERE email = ?", [$email]);

                // 保存新验证码
                $db->execute(
                    "INSERT INTO password_resets (email, code, expires_at, created_at) VALUES (?, ?, ?, NOW())",
                    [$email, $codeHash, $expires]
                );

                // 发送邮件
                $siteName = get_site_setting('site_name', '族谱传承');
                $codeDisplay = '';
                $mailSent = false;

                require_once __DIR__ . '/includes/Mailer.php';

                try {
                    set_time_limit(30); // 邮件发送最多等30秒
                    $mailer = new Mailer();

                    if ($mailer->isConfigured()) {
                        $subject = str_replace(
                            ['{site_name}', '{username}'],
                            [$siteName, $user['username']],
                            get_site_setting('email_reset_subject', $siteName . '——密码重置验证码')
                        );
                        $body = str_replace(
                            ['{username}', '{code}', '{expire}', '{site_name}', '{site_url}'],
                            [$user['username'], $code, '10', $siteName, SITE_URL],
                            get_site_setting('email_reset_body', "<p>验证码：{$code}</p>")
                        );
                        $result = $mailer->send($email, $subject, $body);
                        $mailSent = $result['success'];
                        if (!$mailSent) {
                            Logger::warning('找回密码-邮件失败', "SMTP错误: " . $result['message'], $user['id']);
                        }
                    } else {
                        // SMTP未配置时使用PHP mail()
                        $fromName = get_site_setting('smtp_from_name', $siteName);
                        $fromEmail = get_site_setting('smtp_from_email', '');
                        if (empty($fromEmail)) {
                            $fromEmail = get_site_setting('smtp_user', 'noreply@familytree.com');
                        }
                        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
                        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
                        $mailSent = @mail($email, "{$siteName} - 找回密码验证码",
                            "验证码：{$code}\n有效期10分钟", $headers);
                    }
                } catch (\Exception $e) {
                    // 邮件异常不影响流程，生产环境提示联系管理员
                    Logger::error('找回密码-邮件异常', $e->getMessage(), $user['id']);
                    $mailSent = false;
                }

                if (!$mailSent) {
                    // 生产环境：不暴露验证码，提示联系管理员
                    // 开发模式 (APP_DEBUG=true)：显示验证码方便调试
                    $isDebug = defined('APP_DEBUG') && APP_DEBUG;
                    if ($isDebug) {
                        $codeDisplay = '<p style="color:#e67e22;font-size:13px;">（开发模式：邮件发送失败，验证码直接显示如下）</p>';
                        $codeDisplay .= '<div style="background:#fef9e7;border:1px dashed #e67e22;padding:12px;text-align:center;margin-top:8px;"><strong style="font-size:20px;color:#e67e22;">' . $code . '</strong></div>';
                    } else {
                        $codeDisplay = '<p style="color:#c62828;font-size:13px;">邮件发送失败，请联系管理员手动处理。</p>';
                    }
                }

                Logger::info('找回密码', "用户 {$user['username']} 申请找回密码，邮件发送" . ($mailSent ? '成功' : '失败'), $user['id']);

                $success = $mailSent
                    ? "验证码已发送至 {$email}，请查看邮箱。"
                    : "{$codeDisplay}";
            } else {
                // 不透露邮箱是否注册
                $success = '如果该邮箱已注册，验证码将发送到您的邮箱。';
            }
        }
    }
}

$pageTitle = '找回密码';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h2>找回密码</h2>
            <p>请输入注册时使用的邮箱，我们将发送验证码</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" style="text-align:left;">
                <?php echo $success; ?>
                <p style="margin-top:12px;">
                    <a href="<?php echo SITE_URL; ?>/reset_password.php" class="btn btn-primary" style="display:inline-block;">下一步：输入验证码</a>
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
                <button type="submit" class="btn btn-primary btn-block">发送验证码</button>
            </form>
        <?php endif; ?>

        <div style="text-align:center;margin-top:20px;">
            <a href="<?php echo SITE_URL; ?>/login.php" style="font-size:13px;">← 返回登录</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
