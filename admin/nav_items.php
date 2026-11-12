<?php
/**
 * 后台 - 导航栏管理
 */
require_once __DIR__ . '/../includes/header_pre.php';
Auth::requireAdmin();

$db = Database::getInstance();
$success = '';
$error = '';
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求';
    } else {
        $action = post_param('action');

        if ($action === 'save') {
            $id = (int)post_param('id', 0);
            $label = trim(post_param('label'));
            $url = trim(post_param('url'));
            $target = post_param('target', '_self');
            $sortOrder = (int)post_param('sort_order', 0);
            $isActive = post_param('is_active', '1') === '1' ? 1 : 0;
            $position = post_param('position', 'main');

            if (empty($label)) {
                $error = '导航名称不能为空';
            } elseif (mb_strlen($label) > 100) {
                $error = '导航名称不能超过100字符';
            } elseif (empty($url)) {
                $error = '链接地址不能为空';
            } elseif (mb_strlen($url) > 500) {
                $error = '链接地址不能超过500字符';
            } else {
                if ($id > 0) {
                    $db->execute(
                        "UPDATE nav_items SET label=?, url=?, target=?, sort_order=?, is_active=?, position=? WHERE id=?",
                        [$label, $url, $target, $sortOrder, $isActive, $position, $id]
                    );
                    $success = '导航已更新';
                } else {
                    $db->execute(
                        "INSERT INTO nav_items (label, url, target, sort_order, is_active, position, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                        [$label, $url, $target, $sortOrder, $isActive, $position]
                    );
                    $success = '导航已创建';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)post_param('id', 0);
            $db->execute("DELETE FROM nav_items WHERE id = ?", [$id]);
            $success = '导航已删除';
        } elseif ($action === 'toggle') {
            $id = (int)post_param('id', 0);
            $item = $db->fetch("SELECT is_active FROM nav_items WHERE id = ?", [$id]);
            if ($item) {
                $newVal = $item['is_active'] ? 0 : 1;
                $db->execute("UPDATE nav_items SET is_active = ? WHERE id = ?", [$newVal, $id]);
                $_SESSION['flash_message'] = $newVal ? '已启用' : '已禁用';
                $_SESSION['flash_type'] = 'success';
            }
            redirect(SITE_URL . '/admin/nav_items.php');
        }
    }
}

$editId = (int)get_param('edit', 0);
if ($editId > 0) {
    $editing = $db->fetch("SELECT * FROM nav_items WHERE id = ?", [$editId]);
}

$navItems = $db->fetchAll("SELECT * FROM nav_items ORDER BY position, sort_order ASC, id ASC");

$pageTitle = '导航栏管理';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>导航栏管理</h1>
            <span style="color:#999;font-size:13px;">自定义前台顶部导航栏的内容和顺序</span>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
        <?php
            $flash = Flash::get();
            if ($flash):
        ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
        <?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

        <!-- 编辑/新增表单 -->
        <div class="admin-card" style="margin-bottom:20px;">
            <div class="admin-card-header">
                <h3><?php echo $editing ? '编辑导航' : '新增导航'; ?></h3>
            </div>
            <div class="admin-card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo $editing['id']; ?>"><?php endif; ?>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>导航名称 <span class="required">*</span></label>
                            <input type="text" name="label" required maxlength="100"
                                   placeholder="如：首页、家谱馆、关于我们"
                                   value="<?php echo e($editing ? $editing['label'] : post_param('label')); ?>">
                        </div>
                        <div class="form-group">
                            <label>链接地址 <span class="required">*</span></label>
                            <input type="text" name="url" required maxlength="500"
                                   placeholder="如：/family.php 或 /page.php?slug=about"
                                   value="<?php echo e($editing ? $editing['url'] : post_param('url')); ?>">
                            <p class="help-text">支持相对路径(/)或完整URL(https://...)</p>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>排序</label>
                            <input type="number" name="sort_order" min="0"
                                   value="<?php echo e($editing ? $editing['sort_order'] : post_param('sort_order', '0')); ?>">
                            <p class="help-text">数字越小越靠前</p>
                        </div>
                        <div class="form-group">
                            <label>位置</label>
                            <select name="position">
                                <option value="main" <?php echo ($editing && $editing['position'] === 'main') ? 'selected' : ''; ?>>主导航（顶部中央）</option>
                                <option value="top" <?php echo ($editing && $editing['position'] === 'top') ? 'selected' : ''; ?>>顶部栏</option>
                                <option value="footer" <?php echo ($editing && $editing['position'] === 'footer') ? 'selected' : ''; ?>>底部链接</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>打开方式</label>
                            <select name="target">
                                <option value="_self" <?php echo ($editing && $editing['target'] === '_self') ? 'selected' : ''; ?>>当前页 (_self)</option>
                                <option value="_blank" <?php echo ($editing && $editing['target'] === '_blank') ? 'selected' : ''; ?>>新窗口 (_blank)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>状态</label>
                            <select name="is_active">
                                <option value="1" <?php echo ($editing && $editing['is_active']) || (!$editing) ? 'selected' : ''; ?>>启用</option>
                                <option value="0" <?php echo ($editing && !$editing['is_active']) ? 'selected' : ''; ?>>禁用</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?php echo $editing ? '更新导航' : '添加导航'; ?>
                    </button>
                    <?php if ($editing): ?>
                        <a href="nav_items.php" class="btn btn-outline">取消编辑</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- 导航列表 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3>现有导航项（<?php echo count($navItems); ?>条）</h3>
            </div>
            <div class="admin-card-body">
                <?php if (empty($navItems)): ?>
                    <p style="color:#999;">暂无导航项，请添加上方的表单新增</p>
                <?php else: ?>
                <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:60px;">排序</th>
                            <th>名称</th>
                            <th>链接</th>
                            <th>位置</th>
                            <th style="width:70px;">状态</th>
                            <th style="width:70px;">窗口</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($navItems as $item): ?>
                        <tr>
                            <td><?php echo $item['sort_order']; ?></td>
                            <td><strong><?php echo e($item['label']); ?></strong></td>
                            <td><code style="font-size:12px;"><?php echo e($item['url']); ?></code></td>
                            <td>
                                <span class="badge badge-<?php echo $item['position'] === 'main' ? 'primary' : ($item['position'] === 'footer' ? 'warning' : 'info'); ?>">
                                    <?php
                                        $posMap = ['main' => '主导航', 'top' => '顶部', 'footer' => '底部'];
                                        echo $posMap[$item['position']] ?? $item['position'];
                                    ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $item['is_active'] ? 'btn-success' : 'btn-danger'; ?>"
                                            title="点击切换">
                                        <?php echo $item['is_active'] ? '✓ 启用' : '✕ 禁用'; ?>
                                    </button>
                                </form>
                            </td>
                            <td><?php echo $item['target'] === '_blank' ? '🔗 新窗' : '当前'; ?></td>
                            <td>
                                <a href="?edit=<?php echo $item['id']; ?>" class="btn btn-sm btn-outline">编辑</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('确定删除此导航？')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
