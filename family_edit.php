<?php
/**
 * 编辑家谱信息 - 含封面生成
 */
require_once __DIR__ . '/includes/header_pre.php';
require_once __DIR__ . '/includes/CoverGenerator.php';
Auth::requireLogin();

$db = Database::getInstance();
$userId = Auth::getUserId();
$familyId = (int)get_param('id', 0);

$family = $db->fetch("SELECT * FROM families WHERE id = ? AND user_id = ? AND status = 1", [$familyId, $userId]);
if (!$family) {
    $_SESSION['flash_message'] = '家谱不存在或无权操作';
    $_SESSION['flash_type'] = 'error';
    redirect(SITE_URL . '/my.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求';
    } else {
        $surname = post_param('surname');
        $title = post_param('title');
        $description = post_param('description');
        $origin = post_param('origin');
        $generationStart = (int)post_param('generation_start', 1);
        $isPublic = post_param('is_public') === '1' ? 1 : 0;
        $treeLayout = post_param('tree_layout', 'horizontal');
        $coverStyle = post_param('cover_style', '');

        if (empty($surname) || empty($title)) {
            $error = '姓氏和谱名为必填项';
        } else {
            $coverImage = $family['cover_image'];

            // 优先处理用户上传的封面
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK && $_FILES['cover_image']['size'] > 0) {
                $upload = upload_file($_FILES['cover_image'], 'cover');
                if ($upload['success']) {
                    $coverImage = $upload['path'];
                }
            } elseif (!empty($coverStyle)) {
                // 生成系统封面
                try {
                    $generator = new CoverGenerator();
                    $coverImage = $generator->generate($surname, $title, $origin, $coverStyle);
                } catch (Exception $e) {
                    // 失败不阻止编辑
                }
            }

            $db->execute(
                "UPDATE families SET surname=?, title=?, description=?, origin=?, generation_start=?, cover_image=?, is_public=?, tree_layout=? WHERE id=?",
                [$surname, $title, $description, $origin, $generationStart, $coverImage, $isPublic, $treeLayout, $familyId]
            );

            Logger::info('编辑家谱', "编辑家谱: {$title}", $userId);
            $_SESSION['flash_message'] = '保存成功';
            $_SESSION['flash_type'] = 'success';
            redirect(SITE_URL . '/family_edit.php?id=' . $familyId);
        }
    }
}

// 删除家谱
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'delete') {
    $db->execute("UPDATE families SET status = 0 WHERE id = ? AND user_id = ?", [$familyId, $userId]);
    Logger::info('删除家谱', "删除家谱: {$family['title']}", $userId);
    $_SESSION['flash_message'] = '家谱已删除';
    $_SESSION['flash_type'] = 'success';
    redirect(SITE_URL . '/my.php');
}

$styles = CoverGenerator::getStyles();
$pageTitle = '编辑家谱';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>编辑家谱</h1>
        <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $familyId; ?>" class="btn btn-outline">返回查看</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="form-wide">
                <?php echo csrf_field(); ?>
                <div class="form-row-2">
                    <div class="form-group">
                        <label for="surname">姓氏 <span class="required">*</span></label>
                        <input type="text" id="surname" name="surname" required
                               value="<?php echo e($family['surname']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="title">谱名 <span class="required">*</span></label>
                        <input type="text" id="title" name="title" required
                               value="<?php echo e($family['title']); ?>">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>发源地 / 籍贯</label>
                        <div class="address-selector">
                            <select id="origin_province" name="origin_province" required class="form-select">
                                <option value="">-- 请选择省份 --</option>
                            </select>
                            <select id="origin_city" name="origin_city" disabled class="form-select">
                                <option value="">-- 请选择城市 --</option>
                            </select>
                            <select id="origin_district" name="origin_district" disabled class="form-select">
                                <option value="">-- 请选择区县 --</option>
                            </select>
                            <input type="hidden" id="origin_value" name="origin" value="<?php echo e($family['origin']); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="generation_start">起始世次</label>
                        <input type="number" id="generation_start" name="generation_start"
                               min="1" value="<?php echo $family['generation_start']; ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="description">家谱简介</label>
                    <textarea id="description" name="description" rows="4"><?php echo e($family['description']); ?></textarea>
                </div>

                <!-- 封面管理区域 -->
                <div class="form-group">
                    <label>家谱封面</label>
                    <div class="cover-manager">
                        <!-- 当前封面预览 -->
                        <div class="cover-preview-box">
                            <?php if ($family['cover_image']): ?>
                                <img src="<?php echo SITE_URL . '/' . $family['cover_image']; ?>" alt="当前封面" style="max-height:180px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,0.1);">
                            <?php else: ?>
                                <div class="cover-placeholder-edit">
                                    <span style="font-size:32px;font-weight:bold;color:#8B4513;"><?php echo e($family['surname']); ?></span>
                                    <span style="font-size:12px;color:#999;">暂未设置封面</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="cover-options">
                            <p class="help-text" style="margin:0 0 8px;">选择系统封面样式（共<?php echo count($styles); ?>种），或上传自定义图片：</p>

                            <!-- 系统封面样式选择 -->
                            <div class="cover-style-grid" style="margin-bottom:12px;">
                                <?php $selectedStyle = post_param('cover_style', ''); ?>
                                <?php $previewSurname = $family['surname'] ?? '李'; ?>
                                <?php foreach ($styles as $key => $style): ?>
                                <label class="cover-style-item <?php echo $selectedStyle === $key ? 'active' : ''; ?>"
                                       onclick="selectCoverStyle('<?php echo $key; ?>', this)">
                                    <input type="radio" name="cover_style" value="<?php echo $key; ?>"
                                           <?php echo $selectedStyle === $key ? 'checked' : ''; ?>>
                                    <div class="cover-style-preview">
                                        <img src="<?php echo SITE_URL; ?>/cover_preview.php?style=<?php echo $key; ?>&surname=<?php echo urlencode($previewSurname); ?>"
                                             alt="<?php echo e($style['name']); ?>"
                                             class="cover-preview-img"
                                             data-style="<?php echo $key; ?>"
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <span class="cover-style-icon-fallback" style="display:none;">
                                            <?php echo mb_substr($style['name'], 0, 1); ?>
                                        </span>
                                    </div>
                                    <span class="cover-style-name"><?php echo e($style['name']); ?></span>
                                    <span class="cover-style-desc"><?php echo e($style['desc']); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <!-- 上传自定义封面 -->
                            <div class="cover-upload-row">
                                <input type="file" name="cover_image" accept="image/*" style="flex:1;">
                                <span class="help-text" style="margin:0;">或上传自定义图片</span>
                            </div>
                        </div>
                    </div>
                    <p class="help-text" style="margin-top:8px;">选择系统样式并保存，将自动生成新封面；上传图片则覆盖系统封面</p>
                </div>

                <div class="form-group">
                    <label>是否公开</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="is_public" value="0" <?php echo !$family['is_public'] ? 'checked' : ''; ?>> 私密
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="is_public" value="1" <?php echo $family['is_public'] ? 'checked' : ''; ?>> 公开
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label>世系图布局</label>
                    <div class="radio-group">
                        <label class="radio-label layout-radio">
                            <input type="radio" name="tree_layout" value="horizontal" <?php echo (($family['tree_layout'] ?? 'horizontal') === 'horizontal') ? 'checked' : ''; ?>>
                            📊 横向排列（左→右展开，适合屏幕查看）
                        </label>
                        <label class="radio-label layout-radio">
                            <input type="radio" name="tree_layout" value="vertical" <?php echo (($family['tree_layout'] ?? 'horizontal') === 'vertical') ? 'checked' : ''; ?>>
                            📋 竖向排列（上→下展开，适合打印）
                        </label>
                    </div>
                    <p class="help-text" style="color:#e67e22;margin-top:8px;font-size:12px;">
                        ⚠️ 温馨提示：正式录入数据后，不建议随意切换布局样式，可能导致连接线错位。
                    </p>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg">保存修改</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 危险操作 -->
    <div class="card card-danger" style="margin-top:20px;">
        <div class="card-header"><h3>危险操作</h3></div>
        <div class="card-body">
            <p>删除家谱将同时删除所有成员数据，此操作不可恢复！</p>
            <button type="button" class="btn btn-danger" onclick="showDeleteModal()">删除家谱</button>
        </div>
    </div>
</div>

<!-- 删除确认弹窗 -->
<div id="deleteModal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog modal-danger">
        <div class="modal-header">
            <h3>⚠️ 删除家谱</h3>
        </div>
        <div class="modal-body">
            <div id="deleteStep1">
                <p style="font-size:15px;margin-bottom:8px;">此操作<strong style="color:#e74c3c;">不可恢复</strong>！</p>
                <p style="color:#666;">家谱"<strong><?php echo e($family['title']); ?></strong>"及其所有成员数据将被永久删除。</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="hideDeleteModal()">取消</button>
                    <button type="button" class="btn btn-danger" onclick="showStep2()">确认删除</button>
                </div>
            </div>
            <div id="deleteStep2" style="display:none;">
                <p style="font-size:14px;color:#e74c3c;margin-bottom:12px;">请再次确认：此操作无法撤销！</p>
                <div class="form-group">
                    <label for="deleteConfirmInput">请输入家谱名称 <strong>"<?php echo e($family['title']); ?>"</strong> 以确认：</label>
                    <input type="text" id="deleteConfirmInput" placeholder="输入家谱名称确认" style="width:100%;margin-top:4px;" oninput="checkDeleteInput()">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="hideDeleteModal()">我再想想</button>
                    <form method="post" id="deleteForm" style="display:inline;">
                        <input type="hidden" name="action" value="delete">
                        <?php echo csrf_field(); ?>
                        <button type="submit" id="deleteFinalBtn" class="btn btn-danger" disabled>永久删除</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script>
(function initAddr() {
    if (typeof AddressSelector === 'undefined') {
        setTimeout(initAddr, 100);
        return;
    }
    var sel = document.getElementById('origin_province');
    if (!sel) { setTimeout(initAddr, 100); return; }

    new AddressSelector('origin_province', 'origin_city', 'origin_district', {
        hiddenInput: 'origin_value',
        originValue: '<?php echo addslashes($family['origin'] ?? ''); ?>'
    });
})();

// 封面样式选择
function selectCoverStyle(key, el) {
    document.querySelectorAll('.cover-style-item').forEach(function(item) {
        item.classList.remove('active');
    });
    el.classList.add('active');
    el.querySelector('input[type="radio"]').checked = true;
}

// 删除弹窗
function showDeleteModal() {
    document.getElementById('deleteModal').style.display = 'flex';
    document.getElementById('deleteStep1').style.display = 'block';
    document.getElementById('deleteStep2').style.display = 'none';
    var inp = document.getElementById('deleteConfirmInput');
    if (inp) inp.value = '';
    var btn = document.getElementById('deleteFinalBtn');
    if (btn) btn.disabled = true;
}
function hideDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
function showStep2() {
    document.getElementById('deleteStep1').style.display = 'none';
    document.getElementById('deleteStep2').style.display = 'block';
    document.getElementById('deleteConfirmInput').focus();
}
function checkDeleteInput() {
    var expected = <?php echo json_encode($family['title'], JSON_UNESCAPED_UNICODE); ?>;
    var input = document.getElementById('deleteConfirmInput').value.trim();
    document.getElementById('deleteFinalBtn').disabled = (input !== expected);
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) hideDeleteModal();
});
</script>
