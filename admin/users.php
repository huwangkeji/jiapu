<?php
/**
 * 后台 - 会员管理
 */
require_once __DIR__ . '/../includes/header_pre.php';
Auth::requireAdmin();

$db = Database::getInstance();
$page = max(1, (int)get_param('page', 1));
$keyword = get_param('keyword');
$action = post_param('action');

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = '非法请求';
        $_SESSION['flash_type'] = 'error';
    } else {
        $targetId = (int)post_param('user_id', 0);

        // 安全护栏：管理员不能对自己执行禁用 / 降级 / 删除，避免把自己锁在门外
        $selfGuard = ($targetId === (int)Auth::getUserId());

        if ($action === 'toggle_status') {
            $user = $db->fetch("SELECT status, username FROM users WHERE id = ?", [$targetId]);
            if ($user && $selfGuard) {
                $_SESSION['flash_message'] = '不能禁用当前登录的账号';
                $_SESSION['flash_type'] = 'error';
            } elseif ($user) {
                $newStatus = $user['status'] ? 0 : 1;
                $db->execute("UPDATE users SET status = ? WHERE id = ?", [$newStatus, $targetId]);
                Logger::info('会员管理', ($newStatus ? '启用' : '禁用') . "用户: {$user['username']}", Auth::getUserId());
                $_SESSION['flash_message'] = $newStatus ? '已启用' : '已禁用';
                $_SESSION['flash_type'] = 'success';
            }
        } elseif ($action === 'toggle_role') {
            $user = $db->fetch("SELECT role, username FROM users WHERE id = ?", [$targetId]);
            if ($user && $selfGuard) {
                $_SESSION['flash_message'] = '不能变更当前登录账号的角色';
                $_SESSION['flash_type'] = 'error';
            } elseif ($user && $user['username'] !== 'admin') {
                $newRole = $user['role'] === 'admin' ? 'user' : 'admin';
                $db->execute("UPDATE users SET role = ? WHERE id = ?", [$newRole, $targetId]);
                Logger::info('会员管理', "变更角色: {$user['username']} -> {$newRole}", Auth::getUserId());
                $_SESSION['flash_message'] = '角色已更新';
                $_SESSION['flash_type'] = 'success';
            }
        } elseif ($action === 'delete') {
            $user = $db->fetch("SELECT username FROM users WHERE id = ?", [$targetId]);
            if ($user && $selfGuard) {
                $_SESSION['flash_message'] = '不能删除当前登录的账号';
                $_SESSION['flash_type'] = 'error';
            } elseif ($user && $user['username'] !== 'admin') {
                // BUGFIX: 提示文案写着「所有家谱数据将被删除」，但此前只删了用户，
                // 家谱与成员变成孤儿数据。这里改为在同一事务里级联清理。
                try {
                    $db->transaction(function ($db) use ($targetId) {
                        $db->execute(
                            "DELETE FROM members WHERE family_id IN (SELECT id FROM families WHERE user_id = ?)",
                            [$targetId]
                        );
                        $db->execute("DELETE FROM families WHERE user_id = ?", [$targetId]);
                        $db->execute("DELETE FROM user_tokens WHERE user_id = ?", [$targetId]);
                        $db->execute("DELETE FROM users WHERE id = ?", [$targetId]);
                    });
                } catch (\Exception $e) {
                    // user_tokens 表可能不存在，降级为不删令牌
                    try {
                        $db->transaction(function ($db) use ($targetId) {
                            $db->execute(
                                "DELETE FROM members WHERE family_id IN (SELECT id FROM families WHERE user_id = ?)",
                                [$targetId]
                            );
                            $db->execute("DELETE FROM families WHERE user_id = ?", [$targetId]);
                            $db->execute("DELETE FROM users WHERE id = ?", [$targetId]);
                        });
                    } catch (\Exception $e2) {
                        $_SESSION['flash_message'] = '删除失败：' . $e2->getMessage();
                        $_SESSION['flash_type'] = 'error';
                        $user = null;
                    }
                }

                if ($user) {
                    // 同步删除 UCenter 账号
                    if (class_exists('UCenterBridge')) {
                        try {
                            UCenterBridge::syncDelete($targetId);
                        } catch (\Exception $e) {
                            error_log('UCenter 删号同步失败: ' . $e->getMessage());
                        }
                    }
                    Logger::info('会员管理', "删除用户及其家谱数据: {$user['username']}", Auth::getUserId());
                    $_SESSION['flash_message'] = '用户及其家谱数据已删除';
                    $_SESSION['flash_type'] = 'success';
                }
            }
        } elseif ($action === 'reset_password') {
            $user = $db->fetch("SELECT username, email FROM users WHERE id = ?", [$targetId]);
            if ($user) {
                // 生成随机安全密码
                $newPassword = bin2hex(random_bytes(6)); // 12位随机密码
                $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
                $db->execute("UPDATE users SET password = ? WHERE id = ?", [$hash, $targetId]);
                // 同步到 UCenter 并吊销该用户的持久登录令牌
                if (class_exists('UCenterBridge')) {
                    try {
                        UCenterBridge::syncPassword($targetId, $newPassword);
                    } catch (\Exception $e) {
                        error_log('UCenter 密码同步失败: ' . $e->getMessage());
                    }
                }
                try {
                    $db->execute("DELETE FROM user_tokens WHERE user_id = ?", [$targetId]);
                } catch (\Exception $e) {
                    // 表不存在时忽略
                }
                Logger::info('会员管理', "重置密码: {$user['username']}", Auth::getUserId());
                // 尝试发送邮件通知
                $mailSent = false;
                if (!empty($user['email']) && class_exists('Mailer')) {
                    try {
                        $mailer = new Mailer();
                        $mailSent = $mailer->send($user['email'], '密码重置通知',
                            "<p>您好 {$user['username']}，</p>
                             <p>您的密码已被管理员重置。</p>
                             <p><strong>新密码：{$newPassword}</strong></p>
                             <p>请登录后立即修改密码。</p>"
                        );
                    } catch (Exception $e) {
                        error_log('重置密码邮件发送失败: ' . $e->getMessage());
                    }
                }
                // 只在后台显示密码（邮件发送失败或用户无邮箱时）
                if ($mailSent) {
                    $_SESSION['flash_message'] = '密码已重置，新密码已发送至用户邮箱';
                } else {
                    $_SESSION['flash_message'] = '密码已重置，新密码: ' . $newPassword . '（请安全告知用户）';
                }
                $_SESSION['flash_type'] = 'success';
            }
        }
    }
    redirect(SITE_URL . '/admin/users.php?page=' . $page . '&keyword=' . urlencode($keyword));
}

// 查询用户列表
$where = "WHERE 1=1";
$params = [];
if ($keyword) {
    $where .= " AND (username LIKE ? OR nickname LIKE ? OR email LIKE ?)";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

$sql = "SELECT u.*,
        (SELECT COUNT(*) FROM families f WHERE f.user_id = u.id AND f.status = 1) as family_count
        FROM users u {$where} ORDER BY u.created_at DESC";

$result = $db->paginate($sql, $params, $page);

$pageTitle = '会员管理';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>会员管理</h1>
            <span>共 <?php echo $result['total']; ?> 位用户</span>
        </div>

        <!-- 搜索 -->
        <div class="admin-card">
            <div class="admin-card-body">
                <form method="get" class="search-form-inline">
                    <input type="text" name="keyword" placeholder="搜索用户名/昵称/邮箱"
                           value="<?php echo e($keyword); ?>">
                    <button type="submit" class="btn btn-primary">搜索</button>
                    <?php if ($keyword): ?>
                        <a href="<?php echo SITE_URL; ?>/admin/users.php" class="btn btn-outline">清除</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- 用户列表 -->
        <div class="admin-card">
            <div class="admin-card-body" style="padding:0;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>昵称</th>
                            <th>邮箱</th>
                            <th>角色</th>
                            <th>家谱数</th>
                            <th>状态</th>
                            <th>注册时间</th>
                            <th>最后登录</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['data'] as $u): ?>
                        <tr>
                            <td><?php echo $u['id']; ?></td>
                            <td><strong><?php echo e($u['username']); ?></strong></td>
                            <td><?php echo e($u['nickname']); ?></td>
                            <td><?php echo e($u['email']); ?></td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge badge-warning">管理员</span>
                                <?php else: ?>
                                    <span class="badge badge-info">普通用户</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $u['family_count']; ?></td>
                            <td>
                                <span class="badge <?php echo $u['status'] ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $u['status'] ? '正常' : '禁用'; ?>
                                </span>
                            </td>
                            <td><?php echo format_time($u['created_at']); ?></td>
                            <td><?php echo format_time($u['last_login']); ?></td>
                            <td>
                                <div class="action-btns">
                                    <button type="button" class="btn btn-sm btn-info"
                                            onclick="showUserDetail('<?php echo $u['id']; ?>')">
                                        查看
                                    </button>
                                    <?php if ($u['username'] !== 'admin'): ?>
                                        <form method="post" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <button type="submit" class="btn btn-sm <?php echo $u['status'] ? 'btn-warning' : 'btn-success'; ?>"
                                                    onclick="return confirm('确定<?php echo $u['status'] ? '禁用' : '启用'; ?>此用户？')">
                                                <?php echo $u['status'] ? '禁用' : '启用'; ?>
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="action" value="toggle_role">
                                            <button type="submit" class="btn btn-sm btn-outline">
                                                <?php echo $u['role'] === 'admin' ? '降为用户' : '升为管理员'; ?>
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <button type="submit" class="btn btn-sm btn-outline"
                                                    onclick="return confirm('确定重置此用户密码？')">
                                                重置密码
                                            </button>
                                        </form>
                                        <form method="post" style="display:inline;"
                                              onsubmit="return confirm('确定删除此用户？所有家谱数据将被删除！')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">超级管理员</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php echo pagination_html($result['page'], $result['totalPages'], SITE_URL . '/admin/users.php?keyword=' . urlencode($keyword)); ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<!-- 会员详情弹窗 -->
<div class="crop-overlay" id="userDetailOverlay" style="display:none;">
    <div class="crop-modal" style="width:560px;">
        <div class="crop-modal-header">
            <span>会员详情</span>
            <button type="button" class="crop-close-btn" onclick="closeUserDetail()">&times;</button>
        </div>
        <div class="crop-modal-body" id="userDetailBody" style="max-height:60vh;overflow-y:auto;">
            <p style="text-align:center;color:#999;">加载中...</p>
        </div>
        <div class="crop-modal-actions">
            <button type="button" class="btn btn-outline" onclick="closeUserDetail()">关闭</button>
        </div>
    </div>
</div>

<script>
function showUserDetail(userId) {
    document.getElementById('userDetailOverlay').style.display = 'flex';
    document.getElementById('userDetailBody').innerHTML = '<p style="text-align:center;color:#999;">加载中...</p>';
    fetch('<?php echo SITE_URL; ?>/admin/user_detail.php?id=' + userId)
        .then(function(r) { return r.text(); })
        .then(function(html) {
            document.getElementById('userDetailBody').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('userDetailBody').innerHTML = '<p style="text-align:center;color:red;">加载失败</p>';
        });
}
function closeUserDetail() {
    document.getElementById('userDetailOverlay').style.display = 'none';
}
// 点击遮罩关闭
document.getElementById('userDetailOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeUserDetail();
});
</script>
