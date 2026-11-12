<?php
/**
 * 后台 - 系统日志
 */
require_once __DIR__ . '/../includes/header_pre.php';
Auth::requireAdmin();

$db = Database::getInstance();
$page = max(1, (int)get_param('page', 1));
$level = get_param('level');
$module = get_param('module');
$dateFrom = get_param('date_from');
$dateTo = get_param('date_to');

// 清理日志操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'clean') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = '非法请求';
        $_SESSION['flash_type'] = 'error';
        redirect(SITE_URL . '/admin/logs.php');
    }
    $days = (int)post_param('days', 90);
    Logger::clean($days);
    Logger::info('系统日志', "清理{$days}天前的日志", Auth::getUserId());
    $_SESSION['flash_message'] = '日志清理完成';
    $_SESSION['flash_type'] = 'success';
    redirect(SITE_URL . '/admin/logs.php');
}

// 构建查询
$where = "WHERE 1=1";
$params = [];

if ($level) {
    $where .= " AND l.level = ?";
    $params[] = $level;
}
if ($module) {
    $where .= " AND l.module = ?";
    $params[] = $module;
}
if ($dateFrom) {
    $where .= " AND l.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where .= " AND l.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$sql = "SELECT l.*, u.username FROM logs l LEFT JOIN users u ON l.user_id = u.id {$where} ORDER BY l.created_at DESC";
$result = $db->paginate($sql, $params, $page, 20);

// 获取模块列表（用于筛选）
$modules = $db->fetchAll("SELECT DISTINCT module FROM logs WHERE module != '' ORDER BY module");

// 日志统计
$logStats = Logger::getStats(7);

$pageTitle = '系统日志';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>系统日志</h1>
            <span>共 <?php echo $result['total']; ?> 条记录</span>
        </div>

        <!-- 统计 -->
        <div class="admin-stats" style="margin-bottom:20px;">
            <div class="admin-stat-card" style="border-left:4px solid #3498db;">
                <div class="stat-info">
                    <span class="stat-title">今日日志</span>
                    <span class="stat-value"><?php echo $logStats['today'] ?? 0; ?></span>
                </div>
            </div>
            <div class="admin-stat-card" style="border-left:4px solid #27ae60;">
                <div class="stat-info">
                    <span class="stat-title">总记录数</span>
                    <span class="stat-value"><?php echo $logStats['total'] ?? 0; ?></span>
                </div>
            </div>
            <div class="admin-stat-card" style="border-left:4px solid #e74c3c;">
                <div class="stat-info">
                    <span class="stat-title">7天内错误</span>
                    <span class="stat-value"><?php echo $logStats['level']['error'] ?? 0; ?></span>
                </div>
            </div>
            <div class="admin-stat-card" style="border-left:4px solid #f39c12;">
                <div class="stat-info">
                    <span class="stat-title">7天内警告</span>
                    <span class="stat-value"><?php echo $logStats['level']['warning'] ?? 0; ?></span>
                </div>
            </div>
        </div>

        <!-- 筛选 -->
        <div class="admin-card">
            <div class="admin-card-body">
                <form method="get" class="filter-form">
                    <select name="level">
                        <option value="">全部级别</option>
                        <option value="info" <?php echo $level === 'info' ? 'selected' : ''; ?>>Info</option>
                        <option value="warning" <?php echo $level === 'warning' ? 'selected' : ''; ?>>Warning</option>
                        <option value="error" <?php echo $level === 'error' ? 'selected' : ''; ?>>Error</option>
                        <option value="debug" <?php echo $level === 'debug' ? 'selected' : ''; ?>>Debug</option>
                    </select>
                    <select name="module">
                        <option value="">全部模块</option>
                        <?php foreach ($modules as $m): ?>
                            <option value="<?php echo e($m['module']); ?>" <?php echo $module === $m['module'] ? 'selected' : ''; ?>>
                                <?php echo e($m['module']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" value="<?php echo e($dateFrom); ?>" placeholder="开始日期">
                    <input type="date" name="date_to" value="<?php echo e($dateTo); ?>" placeholder="结束日期">
                    <button type="submit" class="btn btn-primary">筛选</button>
                    <a href="<?php echo SITE_URL; ?>/admin/logs.php" class="btn btn-outline">清除</a>
                </form>
            </div>
        </div>

        <!-- 日志列表 -->
        <div class="admin-card">
            <div class="admin-card-body" style="padding:0;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:150px;">时间</th>
                            <th style="width:80px;">级别</th>
                            <th style="width:100px;">用户</th>
                            <th style="width:100px;">模块</th>
                            <th>操作</th>
                            <th style="width:130px;">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['data'] as $log): ?>
                        <tr>
                            <td class="text-muted"><?php echo format_time($log['created_at']); ?></td>
                            <td><span class="log-level log-level-<?php echo $log['level']; ?>"><?php echo e($log['level']); ?></span></td>
                            <td><?php echo e($log['username'] ?? '-'); ?></td>
                            <td><?php echo e($log['module']); ?></td>
                            <td>
                                <?php echo e($log['action']); ?>
                                <?php if ($log['detail']): ?>
                                    <br><small class="text-muted"><?php echo e(str_cut($log['detail'], 60)); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?php echo e($log['ip']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($result['data'])): ?>
                        <tr><td colspan="6" class="text-center text-muted" style="padding:40px;">暂无日志记录</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php
        $baseUrl = SITE_URL . '/admin/logs.php?level=' . urlencode($level) . '&module=' . urlencode($module) . '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo);
        echo pagination_html($result['page'], $result['totalPages'], $baseUrl);
        ?>

        <!-- 清理日志 -->
        <div class="admin-card" style="margin-top:20px;">
            <div class="admin-card-header"><h3>清理日志</h3></div>
            <div class="admin-card-body">
                <form method="post" class="filter-form" onsubmit="return confirm('确定清理日志？')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="clean">
                    <select name="days">
                        <option value="30">清理30天前的日志</option>
                        <option value="60">清理60天前的日志</option>
                        <option value="90" selected>清理90天前的日志</option>
                        <option value="180">清理180天前的日志</option>
                    </select>
                    <button type="submit" class="btn btn-danger">执行清理</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
