<?php
/**
 * 后台 - 自定义页面管理
 */
require_once __DIR__ . '/../includes/header_pre.php';
Auth::requireAdmin();

$db = Database::getInstance();
$success = '';
$error = '';
$editing = null;

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求';
    } else {
        $action = post_param('action');

        if ($action === 'save') {
            $id = (int)post_param('id', 0);
            $slug = trim(post_param('slug'));
            $title = trim(post_param('title'));
            $content = $_POST['content'] ?? ''; // 保存富文本HTML
            $isPublished = post_param('is_published', '1');

            if (empty($slug) || empty($title)) {
                $error = '页面标识和标题不能为空';
            } elseif (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
                $error = '页面标识只能包含小写字母、数字、下划线和连字符';
            } elseif (mb_strlen($slug) > 100) {
                $error = '页面标识不能超过100字符';
            } elseif (mb_strlen($title) > 200) {
                $error = '页面标题不能超过200字符';
            } else {
                if ($id > 0) {
                    // 更新
                    $existing = $db->fetch("SELECT id FROM custom_pages WHERE slug = ? AND id != ?", [$slug, $id]);
                    if ($existing) { $error = '页面标识已存在'; }
                    else {
                        $db->execute(
                            "UPDATE custom_pages SET slug=?, title=?, content=?, is_published=? WHERE id=?",
                            [$slug, $title, $content, $isPublished, $id]
                        );
                        $success = '页面已更新';
                    }
                } else {
                    // 新增
                    $existing = $db->fetch("SELECT id FROM custom_pages WHERE slug = ?", [$slug]);
                    if ($existing) { $error = '页面标识已存在'; }
                    else {
                        $db->execute(
                            "INSERT INTO custom_pages (slug, title, content, is_published, created_at) VALUES (?, ?, ?, ?, NOW())",
                            [$slug, $title, $content, $isPublished]
                        );
                        $success = '页面已创建';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)post_param('id', 0);
            $db->execute("DELETE FROM custom_pages WHERE id = ?", [$id]);
            $success = '页面已删除';
        }
    }
}

// 编辑模式
$editId = (int)get_param('edit', 0);
if ($editId > 0) {
    $editing = $db->fetch("SELECT * FROM custom_pages WHERE id = ?", [$editId]);
}

// 获取所有页面
$pages = $db->fetchAll("SELECT * FROM custom_pages ORDER BY created_at DESC");

$pageTitle = '自定义页面管理';
$isAdminPage = true;
$extraJs = 'rich-editor.js';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>自定义页面管理</h1>
            <span style="color:#999;font-size:13px;">可创建关于我们、联系方式等自定义页面，访问地址：/page.php?slug=标识</span>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

        <!-- 编辑/新增表单 -->
        <div class="admin-card" style="margin-bottom:20px;">
            <div class="admin-card-header">
                <h3><?php echo $editing ? '编辑页面' : '新增页面'; ?></h3>
            </div>
            <div class="admin-card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo $editing['id']; ?>"><?php endif; ?>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>页面标识（slug）<span class="required">*</span></label>
                            <input type="text" name="slug" required
                                   placeholder="如: about, contact"
                                   value="<?php echo e($editing ? $editing['slug'] : post_param('slug')); ?>"
                                   pattern="[a-z0-9_-]+">
                            <p class="help-text">访问地址: <?php echo SITE_URL; ?>/page.php?slug=<strong>标识</strong></p>
                        </div>
                        <div class="form-group">
                            <label>页面标题<span class="required">*</span></label>
                            <input type="text" name="title" required
                                   value="<?php echo e($editing ? $editing['title'] : post_param('title')); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>页面内容</label>
                        <textarea name="content" id="richEditorContent" rows="12"
                                  placeholder="请在此编辑页面内容..."><?php echo e($editing ? $editing['content'] : post_param('content')); ?></textarea>
                        <p class="help-text">支持 HTML 格式。使用 &lt;h3&gt;标题&lt;/h3&gt;、&lt;p&gt;段落&lt;/p&gt;、&lt;ul&gt;&lt;li&gt;列表&lt;/li&gt;&lt;/ul&gt;</p>
                    </div>
                    <div class="form-group">
                        <label>状态</label>
                        <select name="is_published">
                            <option value="1" <?php echo ($editing && $editing['is_published']) || (!$editing) ? 'selected' : ''; ?>>发布</option>
                            <option value="0" <?php echo ($editing && !$editing['is_published']) ? 'selected' : ''; ?>>草稿</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?php echo $editing ? '更新页面' : '创建页面'; ?>
                    </button>
                    <?php if ($editing): ?>
                        <a href="?page=custom_pages" class="btn btn-outline">取消编辑</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- 页面列表 -->
        <div class="admin-card">
            <div class="admin-card-header"><h3>现有页面</h3></div>
            <div class="admin-card-body">
                <?php if (empty($pages)): ?>
                    <p style="color:#999;">暂无自定义页面</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>标识</th>
                            <th>标题</th>
                            <th>状态</th>
                            <th>更新时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $p): ?>
                        <tr>
                            <td><code><?php echo e($p['slug']); ?></code></td>
                            <td><?php echo e($p['title']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $p['is_published'] ? 'success' : 'warning'; ?>">
                                    <?php echo $p['is_published'] ? '已发布' : '草稿'; ?>
                                </span>
                            </td>
                            <td><?php echo e($p['updated_at']); ?></td>
                            <td>
                                <a href="?page=custom_pages&edit=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline">编辑</a>
                                <a href="<?php echo SITE_URL; ?>/page.php?slug=<?php echo urlencode($p['slug']); ?>" target="_blank" class="btn btn-sm btn-outline">查看</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('确定删除此页面？')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    RichEditor.init('#richEditorContent');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
