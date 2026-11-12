<?php
/**
 * 后台管理 - 仪表盘
 */
require_once __DIR__ . '/../includes/header_pre.php';
Auth::requireAdmin();

$db = Database::getInstance();

// 统计数据
$userCount = $db->count("SELECT COUNT(*) FROM users WHERE status = 1");
$familyCount = $db->count("SELECT COUNT(*) FROM families WHERE status = 1");
$memberCount = $db->count("SELECT COUNT(*) FROM members WHERE status = 1");
$todayLogin = $db->count("SELECT COUNT(*) FROM users WHERE DATE(last_login) = CURDATE()");
$todayRegister = $db->count("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()");

// 最近7天注册趋势
$weekStats = $db->fetchAll(
    "SELECT DATE(created_at) as date, COUNT(*) as cnt FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date ASC"
);

// 最近日志
$recentLogs = $db->fetchAll(
    "SELECT l.*, u.username FROM logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 15"
);

// 最新用户
$recentUsers = $db->fetchAll(
    "SELECT id, username, nickname, email, role, status, created_at, last_login FROM users ORDER BY created_at DESC LIMIT 5"
);

$pageTitle = '后台管理';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>仪表盘</h1>
            <span class="admin-time"><?php echo date('Y年m月d日 H:i'); ?></span>
        </div>

        <!-- 统计卡片 -->
        <div class="admin-stats">
            <div class="admin-stat-card" style="border-left: 4px solid #3498db;">
                <div class="stat-info">
                    <span class="stat-title">总用户数</span>
                    <span class="stat-value"><?php echo $userCount; ?></span>
                </div>
                <div class="stat-icon" style="color:#3498db;">👥</div>
            </div>
            <div class="admin-stat-card" style="border-left: 4px solid #27ae60;">
                <div class="stat-info">
                    <span class="stat-title">家谱总数</span>
                    <span class="stat-value"><?php echo $familyCount; ?></span>
                </div>
                <div class="stat-icon" style="color:#27ae60;">📖</div>
            </div>
            <div class="admin-stat-card" style="border-left: 4px solid #e67e22;">
                <div class="stat-info">
                    <span class="stat-title">成员总数</span>
                    <span class="stat-value"><?php echo $memberCount; ?></span>
                </div>
                <div class="stat-icon" style="color:#e67e22;">👤</div>
            </div>
            <div class="admin-stat-card" style="border-left: 4px solid #9b59b6;">
                <div class="stat-info">
                    <span class="stat-title">今日登录</span>
                    <span class="stat-value"><?php echo $todayLogin; ?></span>
                </div>
                <div class="stat-icon" style="color:#9b59b6;">🔑</div>
            </div>
        </div>

        <div class="admin-row">
            <!-- 最近日志 -->
            <div class="admin-card" style="flex:2;">
                <div class="admin-card-header">
                    <h3>最近操作日志</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/logs.php" class="link">查看全部</a>
                </div>
                <div class="admin-card-body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>用户</th>
                                <th>模块</th>
                                <th>操作</th>
                                <th>级别</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td><?php echo format_time($log['created_at'], 'm-d H:i'); ?></td>
                                <td><?php echo e($log['username'] ?? '系统'); ?></td>
                                <td><?php echo e($log['module']); ?></td>
                                <td><?php echo e(str_cut($log['action'], 30)); ?></td>
                                <td><span class="log-level log-level-<?php echo $log['level']; ?>"><?php echo e($log['level']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 最新用户 -->
            <div class="admin-card" style="flex:1;">
                <div class="admin-card-header">
                    <h3>最新注册</h3>
                    <a href="<?php echo SITE_URL; ?>/admin/users.php" class="link">管理</a>
                </div>
                <div class="admin-card-body">
                    <?php foreach ($recentUsers as $u): ?>
                    <div class="recent-user">
                        <span class="recent-user-avatar"><?php echo mb_substr($u['nickname'] ?: $u['username'], 0, 1); ?></span>
                        <div>
                            <strong><?php echo e($u['nickname'] ?: $u['username']); ?></strong>
                            <p class="text-muted"><?php echo friendly_time($u['created_at']); ?></p>
                        </div>
                        <?php if ($u['role'] === 'admin'): ?>
                            <span class="badge badge-warning">管理员</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
