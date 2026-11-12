<?php
/**
 * 用户个人设置
 */
require_once __DIR__ . '/includes/header_pre.php';
Auth::requireLogin();

$db = Database::getInstance();
$userId = Auth::getUserId();
$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求';
    } else {
        $action = post_param('action');

        if ($action === 'profile') {
            $nickname = post_param('nickname');
            $email = post_param('email');
            $phone = post_param('phone', '');
            $wechat = post_param('wechat', '');
            $qq = post_param('qq', '');
            $birthday = post_param('birthday');
            $region = post_param('region', '');

            if (empty($nickname) || empty($email)) {
                $error = '昵称和邮箱不能为空';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = '邮箱格式不正确';
            } elseif ($phone && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
                $error = '手机号格式不正确';
            } else {
                // 检查邮箱是否被其他用户使用
                $exists = $db->fetch("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $userId]);
                if ($exists) {
                    $error = '该邮箱已被其他用户使用';
                } else {
                    $avatar = $user['avatar'];

                    // 处理裁剪后的头像（Base64）
                    $avatarCrop = post_param('avatar_crop', '');
                    if ($avatarCrop && strpos($avatarCrop, 'data:image/png;base64,') === 0) {
                        $avatar = saveBase64Avatar($avatarCrop);
                    } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                        // 降级：普通文件上传
                        $upload = upload_file($_FILES['avatar'], 'avatar');
                        if ($upload['success']) {
                            $avatar = $upload['path'];
                        }
                    }

                    $birthdayVal = (!empty($birthday) && $birthday !== '') ? $birthday : null;

                    // 构建更新字段（兼容列可能不存在的情况）
                    try {
                        $db->execute(
                            "UPDATE users SET nickname = ?, email = ?, avatar = ?, phone = ?, wechat = ?, qq = ?, birthday = ?, region = ? WHERE id = ?",
                            [$nickname, $email, $avatar, $phone, $wechat, $qq, $birthdayVal, $region, $userId]
                        );
                    } catch (\Exception $e) {
                        // 降级：如果扩展字段不存在，用基础字段更新
                        try {
                            $db->execute(
                                "UPDATE users SET nickname = ?, email = ?, avatar = ? WHERE id = ?",
                                [$nickname, $email, $avatar, $userId]
                            );
                            $error = '部分扩展字段未能保存（数据库未升级），基础信息已更新。请执行 upgrade_v1.2.sql';
                        } catch (\Exception $e2) {
                            $error = '保存失败：' . $e2->getMessage();
                        }
                    }
                    $_SESSION['nickname'] = $nickname;
                    $user = $db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
                    Logger::info('修改资料', "修改个人资料", $userId);

                    // 资料修改后同步到 UCenter（若已开启互通）
                    if (class_exists('UCenterBridge')) {
                        try {
                            UCenterBridge::syncProfile($userId, [
                                'nickname' => $nickname,
                                'email'    => $email,
                                'phone'    => $phone,
                                'qq'       => $qq,
                                'wechat'   => $wechat,
                            ]);
                        } catch (\Exception $e) {
                            error_log('UCenter 资料同步失败: ' . $e->getMessage());
                        }
                    }

                    $_SESSION['flash_message'] = '资料修改成功';
                    $_SESSION['flash_type'] = 'success';
                    redirect(SITE_URL . '/profile.php');
                }
            }
        } elseif ($action === 'password') {
            // BUGFIX: 之前未校验「确认新密码」，两次输入不一致也能提交成功
            $oldPassword     = post_param('old_password');
            $newPassword     = post_param('new_password');
            $confirmPassword = post_param('confirm_password');

            if ($newPassword !== $confirmPassword) {
                $error = '两次输入的新密码不一致';
            } elseif ($oldPassword !== '' && $oldPassword === $newPassword) {
                $error = '新密码不能与原密码相同';
            } else {
                $auth = new Auth();
                $result = $auth->changePassword($userId, $oldPassword, $newPassword);
                if ($result['success']) {
                    // 修改密码后同步到 UCenter（若已开启互通）
                    if (class_exists('UCenterBridge')) {
                        try {
                            UCenterBridge::syncPassword($userId, $newPassword);
                        } catch (\Exception $e) {
                            error_log('UCenter 密码同步失败: ' . $e->getMessage());
                        }
                    }
                    $_SESSION['flash_message'] = $result['message'];
                    $_SESSION['flash_type'] = 'success';
                    redirect(SITE_URL . '/profile.php');
                } else {
                    $error = $result['message'];
                }
            }
        }
    }
}

$pageTitle = '个人设置';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="profile-page">
        <div class="profile-sidebar">
            <div class="profile-avatar-large">
                <?php if ($user['avatar']): ?>
                    <img src="<?php echo e($user['avatar']); ?>" alt="头像">
                <?php else: ?>
                    <span><?php echo mb_substr($user['nickname'] ?: $user['username'], 0, 1); ?></span>
                <?php endif; ?>
            </div>
            <h3><?php echo e($user['nickname'] ?: $user['username']); ?></h3>
            <p class="text-muted">@<?php echo e($user['username']); ?></p>
            <p class="text-muted"><?php echo e($user['email']); ?></p>
            <?php if ($user['phone']): ?><p class="text-muted"><?php echo e($user['phone']); ?></p><?php endif; ?>
            <?php if ($user['region']): ?><p class="text-muted">📍 <?php echo e($user['region']); ?></p><?php endif; ?>
            <p class="text-muted">注册于 <?php echo format_time($user['created_at'], 'Y-m-d'); ?></p>
        </div>

        <div class="profile-content">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <!-- 基本信息 -->
            <div class="card">
                <div class="card-header">
                    <h3>基本信息</h3>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="profile">
                        <?php echo csrf_field(); ?>
                        <div class="form-group">
                            <label>头像</label>
                            <div class="avatar-upload">
                                <div class="avatar-crop-preview" id="avatarPreview">
                                    <?php if ($user['avatar']): ?>
                                        <img src="<?php echo e($user['avatar']); ?>" alt="头像">
                                    <?php else: ?>
                                        <span class="avatar-placeholder-text"><?php echo mb_substr($user['nickname'] ?: $user['username'], 0, 1); ?></span>
                                    <?php endif; ?>
                                </div>
                                <label class="btn btn-outline btn-sm" for="avatar-input" style="cursor:pointer;">
                                    📷 选择头像
                                </label>
                                <input type="file" name="avatar" accept="image/*" id="avatar-input" style="display:none;">
                                <input type="hidden" name="avatar_crop" id="avatar-crop-input" value="">
                                <input type="hidden" name="avatar_keep" id="avatar-keep-input" value="<?php echo e($user['avatar']); ?>">
                                <p class="help-text">选择图片后裁剪为圆形头像，支持 jpg/png</p>
                            </div>
                        </div>

                        <!-- 头像裁剪弹窗 -->
                        <div class="crop-overlay" id="cropOverlay" style="display:none;">
                            <div class="crop-modal">
                                <div class="crop-modal-header">
                                    <span>裁剪头像</span>
                                    <button type="button" class="crop-close-btn" onclick="_closeCrop()">&times;</button>
                                </div>
                                <div class="crop-modal-body">
                                    <div class="crop-container">
                                        <canvas id="cropCanvas"></canvas>
                                    </div>
                                    <p class="help-text" style="text-align:center;margin:8px 0;">拖拽图片调整位置 • 滚轮缩放</p>
                                    <div class="crop-controls">
                                        <button type="button" class="btn btn-outline btn-sm" onclick="zoomCrop(-0.1)">🔍−</button>
                                        <button type="button" class="btn btn-outline btn-sm" onclick="zoomCrop(0.1)">🔍+</button>
                                        <button type="button" class="btn btn-outline btn-sm" onclick="rotateCrop()">↻ 旋转</button>
                                    </div>
                                </div>
                                <div class="crop-modal-actions">
                                    <button type="button" class="btn btn-outline" onclick="_closeCrop()">取消</button>
                                    <button type="button" class="btn btn-primary" onclick="_applyCrop()">确定裁剪</button>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="nickname">昵称</label>
                            <input type="text" id="nickname" name="nickname"
                                   value="<?php echo e($user['nickname']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="email">邮箱 <span class="required">*</span></label>
                            <input type="email" id="email" name="email"
                                   value="<?php echo e($user['email']); ?>" required>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="phone">手机号</label>
                                <input type="text" id="phone" name="phone" placeholder="选填"
                                       value="<?php echo e($user['phone']); ?>" pattern="1[3-9]\d{9}">
                                <p class="help-text">11位手机号</p>
                            </div>
                            <div class="form-group">
                                <label for="wechat">微信号</label>
                                <input type="text" id="wechat" name="wechat" placeholder="选填"
                                       value="<?php echo e($user['wechat']); ?>">
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="qq">QQ号</label>
                                <input type="text" id="qq" name="qq" placeholder="选填"
                                       value="<?php echo e($user['qq']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="birthday">生日</label>
                                <input type="date" id="birthday" name="birthday"
                                       value="<?php echo e($user['birthday']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>地区</label>
                            <div class="address-row">
                                <select id="region_province" name="region_province" class="address-select">
                                    <option value="">-- 省 --</option>
                                </select>
                                <select id="region_city" name="region_city" class="address-select" disabled>
                                    <option value="">-- 市 --</option>
                                </select>
                                <select id="region_district" name="region_district" class="address-select" disabled>
                                    <option value="">-- 区/县 --</option>
                                </select>
                                <input type="hidden" id="region_value" name="region" value="<?php echo e($user['region']); ?>">
                            </div>
                            <p class="help-text">选填，选择省市区后自动拼接完整地址</p>
                        </div>

                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" value="<?php echo e($user['username']); ?>" disabled>
                            <p class="help-text">用户名不可修改</p>
                        </div>
                        <button type="submit" class="btn btn-primary">保存修改</button>
                    </form>
                </div>
            </div>

            <!-- 修改密码 -->
            <div class="card" style="margin-top:20px;">
                <div class="card-header">
                    <h3>修改密码</h3>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="password">
                        <?php echo csrf_field(); ?>
                        <div class="form-group">
                            <label for="old_password">当前密码</label>
                            <input type="password" id="old_password" name="old_password" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">新密码</label>
                            <input type="password" id="new_password" name="new_password"
                                   required minlength="6" placeholder="至少6位">
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">确认新密码</label>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   required minlength="6">
                        </div>
                        <button type="submit" class="btn btn-primary">修改密码</button>
                    </form>
                </div>
            </div>

            <!-- 账号会话 / 退出登录 -->
            <div class="card card-danger" style="margin-top:20px;">
                <div class="card-header">
                    <h3>账号会话</h3>
                </div>
                <div class="card-body">
                    <div class="logout-block">
                        <div class="logout-info">
                            <p class="logout-title">退出登录</p>
                            <p class="logout-desc">
                                当前账号：
                                <strong><?php echo e($user['nickname'] ?: $user['username']); ?></strong>
                                （@<?php echo e($user['username']); ?>）
                            </p>
                            <p class="logout-desc text-muted">
                                上次登录：<?php echo $user['last_login'] ? format_time($user['last_login']) : '暂无记录'; ?>
                                <?php if (!empty($user['last_login_ip'])): ?>
                                    · IP <?php echo e($user['last_login_ip']); ?>
                                <?php endif; ?>
                            </p>
                            <p class="logout-desc text-muted">
                                退出后将清除本设备的登录状态<?php echo isset($_COOKIE[Auth::REMEMBER_COOKIE]) ? '（含「记住我」的自动登录令牌）' : ''; ?>，
                                需要重新输入密码才能进入。
                            </p>
                        </div>
                        <form method="post" action="<?php echo SITE_URL; ?>/logout.php" class="logout-form"
                              onsubmit="return confirm('确定要退出登录吗？');">
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="btn btn-danger">
                                <span aria-hidden="true">⎋</span> 退出登录
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script>
var ProfileConfig = {
    regionValue: '<?php echo addslashes(e($user['region'])); ?>'
};
</script>
<script src="<?php echo SITE_URL; ?>/assets/js/profile.js"></script>
