<?php
/**
 * 后台 - 友情链接管理
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
            $name = trim(post_param('name'));
            $url = trim(post_param('url'));
            $logo = trim(post_param('logo', ''));
            $desc = trim(post_param('description', ''));
            $sortOrder = (int)post_param('sort_order', 0);
            $isActive = post_param('is_active', '1') === '1' ? 1 : 0;

            if (empty($name) || empty($url)) {
                $error = '名称和链接不能为空';
            } elseif (mb_strlen($name) > 100) {
                $error = '链接名称不能超过100字符';
            } elseif (mb_strlen($url) > 500) {
                $error = '链接地址不能超过500字符';
            } elseif ($id > 0) {
                $db->execute("UPDATE links SET name=?, url=?, logo=?, description=?, sort_order=?, is_active=? WHERE id=?",
                    [$name, $url, $logo, $desc, $sortOrder, $isActive, $id]);
                $success = '链接已更新';
            } else {
                $db->execute("INSERT INTO links (name, url, logo, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)",
                    [$name, $url, $logo, $desc, $sortOrder, $isActive]);
                $success = '链接已添加';
            }
        } elseif ($action === 'delete') {
            $id = (int)post_param('id', 0);
            $db->execute("DELETE FROM links WHERE id = ?", [$id]);
            $success = '链接已删除';
        }
    }
}

$editId = (int)get_param('edit', 0);
if ($editId > 0) {
    $editing = $db->fetch("SELECT * FROM links WHERE id = ?", [$editId]);
}

$links = $db->fetchAll("SELECT * FROM links ORDER BY sort_order ASC, id ASC");

$pageTitle = '友情链接管理';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>友情链接管理</h1>
            <span style="color:#999;font-size:13px;">管理网站友链，将显示在页面底部</span>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

        <div class="admin-card" style="margin-bottom:20px;">
            <div class="admin-card-header">
                <h3><?php echo $editing ? '编辑链接' : '添加链接'; ?></h3>
            </div>
            <div class="admin-card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo $editing['id']; ?>"><?php endif; ?>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>链接名称 <span class="required">*</span></label>
                            <input type="text" name="name" required placeholder="如：族谱网"
                                   value="<?php echo e($editing ? $editing['name'] : post_param('name')); ?>">
                        </div>
                        <div class="form-group">
                            <label>链接地址 <span class="required">*</span></label>
                            <input type="text" name="url" required placeholder="https://..."
                                   value="<?php echo e($editing ? $editing['url'] : post_param('url')); ?>">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>描述</label>
                            <input type="text" name="description" placeholder="链接简介"
                                   value="<?php echo e($editing ? $editing['description'] : post_param('description', '')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Logo 图片路径</label>
                            <input type="text" name="logo" placeholder="留空则纯文字 /uploads/xxx.png"
                                   value="<?php echo e($editing ? $editing['logo'] : post_param('logo', '')); ?>">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>排序</label>
                            <input type="number" name="sort_order" min="0"
                                   value="<?php echo e($editing ? $editing['sort_order'] : post_param('sort_order', '0')); ?>">
                        </div>
                        <div class="form-group">
                            <label>状态</label>
                            <select name="is_active">
                                <option value="1" <?php echo !$editing || $editing['is_active'] ? 'selected' : ''; ?>>启用</option>
                                <option value="0" <?php echo ($editing && !$editing['is_active']) ? 'selected' : ''; ?>>禁用</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo $editing ? '更新' : '添加'; ?></button>
                    <?php if ($editing): ?><a href="links.php" class="btn btn-outline">取消</a><?php endif; ?>
                </form>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-header"><h3>链接列表（<?php echo count($links); ?>条）</h3></div>
            <div class="admin-card-body" style="padding:0;">
                <?php if (empty($links)): ?>
                    <p style="padding:20px;color:#999;">暂无友链，添加后将在网站底部显示</p>
                <?php else: ?>
                <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>排序</th><th>名称</th><th>链接</th><th>描述</th><th>状态</th><th>操作</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($links as $l): ?>
                    <tr>
                        <td><?php echo $l['sort_order']; ?></td>
                        <td><strong><?php echo e($l['name']); ?></strong></td>
                        <td><code style="font-size:11px;"><?php echo e(mb_strlen($l['url']) > 40 ? mb_substr($l['url'],0,40).'...' : $l['url']); ?></code></td>
                        <td><span style="font-size:12px;color:#999;"><?php echo e($l['description'] ?: '-'); ?></span></td>
                        <td><span class="badge badge-<?php echo $l['is_active'] ? 'success' : 'danger'; ?>"><?php echo $l['is_active'] ? '启用' : '禁用'; ?></span></td>
                        <td>
                            <a href="?edit=<?php echo $l['id']; ?>" class="btn btn-sm btn-outline">编辑</a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('确定删除？')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $l['id']; ?>">
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
