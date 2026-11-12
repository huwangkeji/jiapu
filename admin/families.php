<?php
/**
 * 后台 - 家谱管理
 */
require_once __DIR__ . '/../includes/header_pre.php';
Auth::requireAdmin();

$db = Database::getInstance();
$page = max(1, (int)get_param('page', 1));
$keyword = get_param('keyword');

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = '非法请求';
        $_SESSION['flash_type'] = 'error';
    } else {
        $familyId = (int)post_param('family_id', 0);
        $action = post_param('action');

        if ($action === 'toggle_public') {
            $f = $db->fetch("SELECT is_public, title FROM families WHERE id = ?", [$familyId]);
            if ($f) {
                $newVal = $f['is_public'] ? 0 : 1;
                $db->execute("UPDATE families SET is_public = ? WHERE id = ?", [$newVal, $familyId]);
                Logger::info('家谱管理', ($newVal ? '设为公开' : '设为私密') . ": {$f['title']}", Auth::getUserId());
                $_SESSION['flash_message'] = $newVal ? '已设为公开' : '已设为私密';
                $_SESSION['flash_type'] = 'success';
            }
        } elseif ($action === 'delete') {
            $f = $db->fetch("SELECT title FROM families WHERE id = ?", [$familyId]);
            if ($f) {
                $db->execute("UPDATE families SET status = 0 WHERE id = ?", [$familyId]);
                Logger::info('家谱管理', "删除家谱: {$f['title']}", Auth::getUserId());
                $_SESSION['flash_message'] = '家谱已删除';
                $_SESSION['flash_type'] = 'success';
            }
        }
    }
    redirect(SITE_URL . '/admin/families.php?page=' . $page);
}

// 查询
$where = "WHERE f.status = 1";
$params = [];
if ($keyword) {
    $where .= " AND (f.title LIKE ? OR f.surname LIKE ?)";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

$sql = "SELECT f.*, u.username, u.nickname as creator_name,
        (SELECT COUNT(*) FROM members m WHERE m.family_id = f.id AND m.status = 1) as member_count
        FROM families f
        LEFT JOIN users u ON f.user_id = u.id
        {$where}
        ORDER BY f.created_at DESC";

$result = $db->paginate($sql, $params, $page);

$pageTitle = '家谱管理';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>家谱管理</h1>
            <span>共 <?php echo $result['total']; ?> 部家谱</span>
        </div>

        <div class="admin-card">
            <div class="admin-card-body">
                <form method="get" class="search-form-inline">
                    <input type="text" name="keyword" placeholder="搜索姓氏/谱名" value="<?php echo e($keyword); ?>">
                    <button type="submit" class="btn btn-primary">搜索</button>
                    <?php if ($keyword): ?>
                        <a href="<?php echo SITE_URL; ?>/admin/families.php" class="btn btn-outline">清除</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-body" style="padding:0;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>姓氏</th>
                            <th>谱名</th>
                            <th>创建者</th>
                            <th>成员数</th>
                            <th>发源地</th>
                            <th>公开状态</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['data'] as $f): ?>
                        <tr>
                            <td><?php echo $f['id']; ?></td>
                            <td><strong><?php echo e($f['surname']); ?></strong></td>
                            <td><?php echo e($f['title']); ?></td>
                            <td><?php echo e($f['creator_name']); ?></td>
                            <td><?php echo $f['member_count']; ?></td>
                            <td><?php echo e($f['origin'] ?: '-'); ?></td>
                            <td>
                                <span class="badge <?php echo $f['is_public'] ? 'badge-success' : 'badge-default'; ?>">
                                    <?php echo $f['is_public'] ? '公开' : '私密'; ?>
                                </span>
                            </td>
                            <td><?php echo format_time($f['created_at'], 'Y-m-d'); ?></td>
                            <td>
                                <div class="action-btns">
                                    <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-outline">查看</a>
                                    <form method="post" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="family_id" value="<?php echo $f['id']; ?>">
                                        <input type="hidden" name="action" value="toggle_public">
                                        <button type="submit" class="btn btn-sm btn-outline">
                                            <?php echo $f['is_public'] ? '设为私密' : '设为公开'; ?>
                                        </button>
                                    </form>
                                    <form method="post" style="display:inline;"
                                          onsubmit="return confirm('确定删除此家谱？')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="family_id" value="<?php echo $f['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($result['data'])): ?>
                        <tr><td colspan="9" class="text-center text-muted" style="padding:40px;">暂无家谱</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php echo pagination_html($result['page'], $result['totalPages'], SITE_URL . '/admin/families.php?keyword=' . urlencode($keyword)); ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
