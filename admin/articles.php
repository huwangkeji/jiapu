<?php
/**
 * 后台 - 文章管理模块
 */
require_once __DIR__ . '/../includes/header_pre.php';
Auth::requireAdmin();

$db = Database::getInstance();
$userId = Auth::getUserId();
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
            $title = trim(post_param('title'));
            $slug = trim(post_param('slug'));
            $content = $_POST['content'] ?? '';
            $excerpt = trim(post_param('excerpt', ''));
            $category = trim(post_param('category', ''));
            $isPublished = post_param('is_published', '1') === '1' ? 1 : 0;
            $isPinned = post_param('is_pinned', '0') === '1' ? 1 : 0;

            if (empty($title)) {
                $error = '文章标题不能为空';
            } elseif (mb_strlen($title) > 255) {
                $error = '文章标题不能超过255个字符';
            } elseif (empty($slug)) {
                $error = 'URL标识不能为空';
            } elseif (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
                $error = 'URL标识只能包含小写字母、数字、下划线和连字符';
            } else {
                // 检查 slug 唯一性
                $existing = $id > 0
                    ? $db->fetch("SELECT id FROM articles WHERE slug = ? AND id != ?", [$slug, $id])
                    : $db->fetch("SELECT id FROM articles WHERE slug = ?", [$slug]);
                if ($existing) {
                    $error = 'URL标识已存在，请换一个';
                } elseif ($id > 0) {
                    $db->execute(
                        "UPDATE articles SET title=?, slug=?, content=?, excerpt=?, category=?, is_published=?, is_pinned=? WHERE id=?",
                        [$title, $slug, $content, $excerpt, $category, $isPublished, $isPinned, $id]
                    );
                    $success = '文章已更新';
                } else {
                    $db->execute(
                        "INSERT INTO articles (title, slug, content, excerpt, category, author_id, is_published, is_pinned, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [$title, $slug, $content, $excerpt, $category, $userId, $isPublished, $isPinned]
                    );
                    $success = '文章已创建';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)post_param('id', 0);
            $db->execute("DELETE FROM articles WHERE id = ?", [$id]);
            $success = '文章已删除';
        }
    }
}

$editId = (int)get_param('edit', 0);
if ($editId > 0) {
    $editing = $db->fetch("SELECT * FROM articles WHERE id = ?", [$editId]);
}

// 分页
$page = max(1, (int)get_param('p', 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$totalArticles = $db->fetch("SELECT COUNT(*) AS cnt FROM articles")['cnt'];
$totalPages = ceil($totalArticles / $perPage);

$articles = $db->fetchAll(
    "SELECT a.*, u.username AS author_name FROM articles a
     LEFT JOIN users u ON a.author_id = u.id
     ORDER BY a.is_pinned DESC, a.created_at DESC
     LIMIT $perPage OFFSET $offset"
);

// 统计
$stats = [
    'total' => $totalArticles,
    'published' => $db->fetch("SELECT COUNT(*) FROM articles WHERE is_published = 1")['cnt'] ?? 0,
    'draft' => $db->fetch("SELECT COUNT(*) FROM articles WHERE is_published = 0")['cnt'] ?? 0,
];

$pageTitle = '文章管理';
$isAdminPage = true;
$extraJs = 'rich-editor.js';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>文章管理</h1>
            <span style="color:#999;font-size:13px;">创建和管理网站文章、公告、资讯内容</span>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

        <!-- 统计 -->
        <div class="stats-row" style="margin-bottom:20px;">
            <div class="stat-card">
                <span class="stat-num"><?php echo $stats['total']; ?></span>
                <span class="stat-label">全部文章</span>
            </div>
            <div class="stat-card">
                <span class="stat-num" style="color:#27ae60;"><?php echo $stats['published']; ?></span>
                <span class="stat-label">已发布</span>
            </div>
            <div class="stat-card">
                <span class="stat-num" style="color:#e67e22;"><?php echo $stats['draft']; ?></span>
                <span class="stat-label">草稿</span>
            </div>
        </div>

        <!-- 编辑/新增表单 -->
        <div class="admin-card" style="margin-bottom:20px;">
            <div class="admin-card-header">
                <h3><?php echo $editing ? '编辑文章' : '新建文章'; ?></h3>
                <button class="btn btn-sm btn-outline" onclick="this.closest('.admin-card').querySelector('.admin-card-body').classList.toggle('hidden')">
                    <?php echo $editing ? '收起' : '展开'; ?>
                </button>
            </div>
            <div class="admin-card-body <?php echo !$editing ? 'hidden' : ''; ?>">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo $editing['id']; ?>"><?php endif; ?>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>文章标题 <span class="required">*</span></label>
                            <input type="text" name="title" required maxlength="255"
                                   placeholder="如：王氏家族迁徙史考"
                                   value="<?php echo e($editing ? $editing['title'] : post_param('title')); ?>"
                                   oninput="document.querySelector('input[name=slug]').value = pinyinSlug(this.value)">
                        </div>
                        <div class="form-group">
                            <label>URL标识 <span class="required">*</span></label>
                            <input type="text" name="slug" required placeholder="如：wangshi-history"
                                   pattern="[a-z0-9_-]+"
                                   value="<?php echo e($editing ? $editing['slug'] : post_param('slug')); ?>">
                            <p class="help-text">访问地址: <?php echo SITE_URL; ?>/article.php?slug=<strong>标识</strong></p>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>分类</label>
                            <input type="text" name="category" placeholder="如：家族历史、谱学知识、通知公告"
                                   value="<?php echo e($editing ? $editing['category'] : post_param('category', '')); ?>">
                        </div>
                        <div class="form-group">
                            <label>摘要</label>
                            <input type="text" name="excerpt" placeholder="文章简介，留空则自动截取"
                                   value="<?php echo e($editing ? $editing['excerpt'] : post_param('excerpt', '')); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>文章内容</label>
                        <textarea name="content" id="richEditorContent" rows="15"
                                  placeholder="请在此编辑文章内容..."><?php echo e($editing ? $editing['content'] : post_param('content')); ?></textarea>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>状态</label>
                            <select name="is_published">
                                <option value="1" <?php echo ($editing && $editing['is_published']) || (!$editing) ? 'selected' : ''; ?>>发布</option>
                                <option value="0" <?php echo ($editing && !$editing['is_published']) ? 'selected' : ''; ?>>草稿</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>置顶</label>
                            <select name="is_pinned">
                                <option value="0" <?php echo !($editing && $editing['is_pinned']) ? 'selected' : ''; ?>>否</option>
                                <option value="1" <?php echo ($editing && $editing['is_pinned']) ? 'selected' : ''; ?>>是</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?php echo $editing ? '更新文章' : '创建文章'; ?>
                    </button>
                    <?php if ($editing): ?>
                        <a href="articles.php" class="btn btn-outline">取消编辑</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- 文章列表 -->
        <div class="admin-card">
            <div class="admin-card-header">
                <h3>文章列表（共<?php echo $totalArticles; ?>篇）</h3>
            </div>
            <div class="admin-card-body" style="padding:0;">
                <?php if (empty($articles)): ?>
                    <p style="color:#999;padding:20px;">暂无文章，点击上方"新建文章"创建第一篇</p>
                <?php else: ?>
                <table class="table table--compact" style="table-layout:fixed;width:100%;min-width:600px;">
                    <colgroup>
                        <col style="width:40px;">
                        <col>
                        <col style="width:70px;">
                        <col style="width:160px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>标题 / 信息</th>
                            <th style="width:70px;">状态</th>
                            <th style="width:170px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($articles as $a): ?>
                        <tr>
                            <td><?php echo $a['is_pinned'] ? '📌' : $a['id']; ?></td>
                            <td>
                                <a href="<?php echo SITE_URL; ?>/article.php?slug=<?php echo urlencode($a['slug']); ?>" target="_blank" style="color:#333;text-decoration:none;font-weight:500;">
                                    <?php echo e($a['title']); ?>
                                </a>
                                <?php if ($a['is_pinned']): ?>
                                    <span class="badge" style="background:#fef0f0;color:#e74c3c;margin-left:6px;">置顶</span>
                                <?php endif; ?>
                                <div style="margin-top:4px;font-size:12px;color:#999;">
                                    <?php if ($a['category']): ?>
                                        <span style="background:#f0f0f0;padding:1px 6px;border-radius:3px;margin-right:8px;"><?php echo e($a['category']); ?></span>
                                    <?php endif; ?>
                                    <span>👁 <?php echo (int)$a['view_count']; ?></span>
                                    <span style="margin-left:8px;">🕐 <?php echo substr($a['updated_at'], 0, 10); ?></span>
                                    <?php if ($a['author_name']): ?>
                                        <span style="margin-left:8px;">✍️ <?php echo e($a['author_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $a['is_published'] ? 'success' : 'warning'; ?>">
                                    <?php echo $a['is_published'] ? '已发布' : '草稿'; ?>
                                </span>
                            </td>
                            <td style="white-space:nowrap;">
                                <a href="?edit=<?php echo $a['id']; ?>" class="btn btn-sm btn-primary" title="修改文章">✏️ 修改</a>
                                <button type="button" class="btn btn-sm btn-danger" title="删除文章"
                                        onclick="deleteArticle(<?php echo $a['id']; ?>, '<?php echo e(addslashes($a['title'])); ?>')">🗑 删除</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="admin-card-footer" style="text-align:center;padding:12px;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?p=<?php echo $i; ?><?php echo $editId ? '&edit='.$editId : ''; ?>"
                       class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>"
                       style="margin:0 2px;"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// 初始化富文本编辑器
document.addEventListener('DOMContentLoaded', function() {
    RichEditor.init('#richEditorContent');
});

// 删除确认
function deleteArticle(id, title) {
    if (!confirm('确定删除文章 "' + title + '"？\n\n此操作不可恢复！')) return;

    var form = document.createElement('form');
    form.method = 'post';
    form.innerHTML = '<input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">' +
                     '<input type="hidden" name="action" value="delete">' +
                     '<input type="hidden" name="id" value="' + id + '">';
    document.body.appendChild(form);
    form.submit();
}

// 简易拼音 slug 生成
function pinyinSlug(text) {
    if (!text) return '';
    return text.replace(/[^a-zA-Z0-9\u4e00-\u9fa5]/g, '-')
               .replace(/-+/g, '-')
               .replace(/^-|-$/g, '')
               .substring(0, 40)
               .toLowerCase();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
