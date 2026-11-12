<?php
/**
 * 后台 - 会员详情（AJAX片段）
 */
require_once __DIR__ . '/../includes/header_pre.php';
Auth::requireAdmin();

$db = Database::getInstance();
$id = (int)get_param('id', 0);
if (!$id) { echo '<p>参数错误</p>'; exit; }

$user = $db->fetch("SELECT u.*,
    (SELECT COUNT(*) FROM families f WHERE f.user_id = u.id AND f.status = 1) as family_count,
    (SELECT COUNT(*) FROM members m JOIN families f ON m.family_id = f.id WHERE f.user_id = u.id AND m.status = 1) as member_count
    FROM users u WHERE u.id = ?", [$id]);

if (!$user) { echo '<p>用户不存在</p>'; exit; }
?>

<div class="detail-grid">
    <div class="detail-section">
        <h4>基本信息</h4>
        <table class="detail-table">
            <tr><td>用户ID</td><td><?php echo $user['id']; ?></td></tr>
            <tr><td>用户名</td><td><strong><?php echo e($user['username']); ?></strong></td></tr>
            <tr><td>昵称</td><td><?php echo e($user['nickname']); ?></td></tr>
            <tr><td>邮箱</td><td><?php echo e($user['email']); ?></td></tr>
            <tr><td>手机号</td><td><?php echo e($user['phone'] ?: '-'); ?></td></tr>
            <tr><td>微信号</td><td><?php echo e($user['wechat'] ?: '-'); ?></td></tr>
            <tr><td>QQ号</td><td><?php echo e($user['qq'] ?: '-'); ?></td></tr>
            <tr><td>生日</td><td><?php echo $user['birthday'] ?: '-'; ?></td></tr>
            <tr><td>地区</td><td><?php echo e($user['region'] ?: '-'); ?></td></tr>
            <tr><td>角色</td><td><?php echo $user['role'] === 'admin' ? '管理员' : '普通用户'; ?></td></tr>
            <tr><td>状态</td><td><?php echo $user['status'] ? '正常' : '已禁用'; ?></td></tr>
        </table>
    </div>
    <div class="detail-section">
        <h4>账户信息</h4>
        <table class="detail-table">
            <tr><td>注册时间</td><td><?php echo $user['created_at']; ?></td></tr>
            <tr><td>最后登录</td><td><?php echo $user['last_login'] ?: '从未登录'; ?></td></tr>
            <tr><td>家谱数量</td><td><?php echo $user['family_count']; ?></td></tr>
            <tr><td>成员数量</td><td><?php echo $user['member_count']; ?></td></tr>
        </table>
    </div>
    <div class="detail-section">
        <h4>IP 与浏览器信息</h4>
        <table class="detail-table">
            <tr><td>注册IP</td><td><?php echo e($user['register_ip'] ?: '-'); ?></td></tr>
            <tr><td>注册浏览器</td><td style="font-size:11px;word-break:break-all;"><?php echo e($user['register_ua'] ?: '-'); ?></td></tr>
            <tr><td>最后登录IP</td><td><?php echo e($user['last_login_ip'] ?: '-'); ?></td></tr>
            <tr><td>最后登录浏览器</td><td style="font-size:11px;word-break:break-all;"><?php echo e($user['last_login_ua'] ?: '-'); ?></td></tr>
        </table>
    </div>
</div>
