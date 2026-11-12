<?php
/**
 * 创建家谱 - 含封面生成
 */
require_once __DIR__ . '/includes/header_pre.php';
require_once __DIR__ . '/includes/CoverGenerator.php';
Auth::requireLogin();

$db = Database::getInstance();
$userId = Auth::getUserId();
$error = '';
$createdFamily = null;
$createdCover = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AJAX 布局设置请求（独立处理，不依赖 $createdFamily 变量）
    if (post_param('action') === 'set_layout') {
        header('Content-Type: application/json; charset=utf-8');
        $layout = post_param('tree_layout', 'horizontal');
        if (!in_array($layout, ['horizontal', 'vertical'])) {
            $layout = 'horizontal';
        }
        $fid = (int)post_param('family_id', 0);
        if ($fid <= 0) {
            echo json_encode(['success' => false, 'message' => '缺少家谱ID']);
            exit;
        }
        $family = $db->fetch("SELECT id FROM families WHERE id = ? AND user_id = ? AND status = 1", [$fid, $userId]);
        if (!$family) {
            echo json_encode(['success' => false, 'message' => '家谱不存在']);
            exit;
        }
        $db->execute("UPDATE families SET tree_layout = ? WHERE id = ?", [$layout, $fid]);
        echo json_encode(['success' => true, 'message' => '布局已保存']);
        exit;
    }

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求';
    } else {
        $surname = post_param('surname');
        $title = post_param('title');
        $description = post_param('description');
        $origin = post_param('origin');
        $generationStart = (int)post_param('generation_start', 1);
        $isPublic = post_param('is_public') === '1' ? 1 : 0;
        $coverStyle = post_param('cover_style', 'ancient');

        if (empty($surname) || empty($title)) {
            $error = '姓氏和谱名为必填项';
        } else {
            // 生成封面
            $coverImage = '';
            try {
                $generator = new CoverGenerator();
                $coverImage = $generator->generate($surname, $title, $origin, $coverStyle);
            } catch (Exception $e) {
                // 封面生成失败不阻止创建
            }

            $sql = "INSERT INTO families (user_id, surname, title, description, origin, generation_start, cover_image, is_public, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            $result = $db->execute($sql, [
                $userId, $surname, $title, $description, $origin,
                $generationStart, $coverImage, $isPublic
            ]);

            if ($result) {
                $familyId = $db->lastInsertId();
                Logger::info('创建家谱', "创建家谱: {$title}", $userId);
                $createdFamily = $db->fetch("SELECT * FROM families WHERE id = ?", [$familyId]);
                $createdCover = $coverImage;
            } else {
                $error = '创建失败，请稍后重试';
            }
        }
    }
}

$styles = CoverGenerator::getStyles();
$pageTitle = '创建家谱';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>创建家谱</h1>
        <a href="<?php echo SITE_URL; ?>/my.php" class="btn btn-outline">返回列表</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($createdFamily): ?>
    <!-- 创建成功弹窗 -->
    <div class="success-modal-overlay" id="successModal">
        <div class="success-modal">
            <!-- 第一步：成功提示 + 封面预览 + 布局选择 -->
            <div id="step1_layout">
                <div class="success-modal-header">
                    <span class="success-check">✅</span>
                    <h2>家谱创建成功！</h2>
                    <p>您的家谱封面已自动生成</p>
                </div>
                <div class="success-modal-body">
                    <div class="success-cover-preview">
                        <?php if ($createdCover): ?>
                            <img src="<?php echo SITE_URL . '/' . $createdCover; ?>" alt="封面预览"
                                 style="width:100%;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.15);">
                        <?php else: ?>
                            <div class="cover-placeholder">
                                <span style="font-size:48px;"><?php echo e($createdFamily['surname']); ?></span>
                                <span style="font-size:14px;">氏族谱</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="success-info">
                        <p><strong><?php echo e($createdFamily['surname']); ?>氏族谱</strong> - <?php echo e($createdFamily['title']); ?></p>
                        <?php if ($createdFamily['origin']): ?>
                            <p style="color:#999;font-size:13px;">源出 <?php echo e($createdFamily['origin']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 布局选择区域 -->
                <div class="layout-select-area">
                    <h3 class="layout-select-title">📐 请选择世系图布局方式</h3>
                    <p class="layout-select-desc">选择一种适合您的展示方式，后续可在编辑家谱中修改</p>
                    <div class="layout-options">
                        <label class="layout-option-card active" data-layout="horizontal"
                               onclick="selectLayout('horizontal', this)">
                            <input type="radio" name="tree_layout_choice" value="horizontal" checked hidden>
                            <div class="layout-icon">📊</div>
                            <span class="layout-name">横向排列</span>
                            <span class="layout-detail">左→右展开，<br>适合屏幕查看</span>
                            <span class="layout-check">✓</span>
                        </label>
                        <label class="layout-option-card" data-layout="vertical"
                               onclick="selectLayout('vertical', this)">
                            <input type="radio" name="tree_layout_choice" value="vertical" hidden>
                            <div class="layout-icon">📋</div>
                            <span class="layout-name">竖向排列</span>
                            <span class="layout-detail">上→下展开，<br>适合打印导出</span>
                            <span class="layout-check">✓</span>
                        </label>
                    </div>
                    <button class="btn btn-primary btn-lg layout-confirm-btn" onclick="confirmLayout()">
                        确认布局并继续 →
                    </button>
                </div>
            </div>

            <!-- 第二步：完成（确认后显示） -->
            <div id="step2_done" style="display:none;">
                <div class="success-modal-header">
                    <span class="success-check">✅</span>
                    <h2>设置完成！</h2>
                    <p>世系图已设置为 <strong id="chosenLayoutName"></strong></p>
                </div>
                <div class="success-modal-body" style="justify-content:center;">
                    <div class="success-cover-preview">
                        <?php if ($createdCover): ?>
                            <img src="<?php echo SITE_URL . '/' . $createdCover; ?>" alt="封面预览"
                                 style="width:100%;border-radius:8px;">
                        <?php else: ?>
                            <div class="cover-placeholder">
                                <span style="font-size:36px;"><?php echo e($createdFamily['surname']); ?>氏族谱</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="success-modal-actions">
                    <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $createdFamily['id']; ?>" class="btn btn-outline">查看家谱</a>
                    <a href="<?php echo SITE_URL; ?>/member_add.php?family_id=<?php echo $createdFamily['id']; ?>" class="btn btn-primary">开始添加成员 →</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" class="form-wide" id="createForm">
                <?php echo csrf_field(); ?>
                <div class="form-row-2">
                    <div class="form-group">
                        <label for="surname">姓氏 <span class="required">*</span></label>
                        <input type="text" id="surname" name="surname" required
                               placeholder="如：王、李、张" maxlength="50"
                               value="<?php echo e(post_param('surname')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="title">谱名 <span class="required">*</span></label>
                        <input type="text" id="title" name="title" required
                               placeholder="如：王氏族谱、太原王氏支谱"
                               value="<?php echo e(post_param('title')); ?>">
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
                            <input type="hidden" id="origin_value" name="origin" value="<?php echo e(post_param('origin')); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="generation_start">起始世次</label>
                        <input type="number" id="generation_start" name="generation_start"
                               min="1" value="<?php echo post_param('generation_start', '1'); ?>">
                        <p class="help-text">第一世祖对应的世次编号</p>
                    </div>
                </div>

                <!-- 封面样式选择 -->
                <div class="form-group">
                    <label>封面样式 <span class="cover-preview-tip" style="font-size:12px;color:#999;">（共<?php echo count($styles); ?>种，点击选择）</span></label>
                    <div class="cover-style-grid">
                        <?php $selectedStyle = post_param('cover_style', 'ancient'); ?>
                        <?php $previewSurname = post_param('surname', '李'); ?>
                        <?php foreach ($styles as $key => $style): ?>
                        <label class="cover-style-item <?php echo $selectedStyle === $key ? 'active' : ''; ?>"
                               onclick="selectStyle('<?php echo $key; ?>', this)">
                            <input type="radio" name="cover_style" value="<?php echo $key; ?>"
                                   <?php echo $selectedStyle === $key ? 'checked' : ''; ?> hidden>
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
                    <p class="help-text">选择一种封面风格，系统将自动生成家谱封面。修改姓氏后预览图自动更新</p>
                </div>

                <div class="form-group">
                    <label for="description">家谱简介</label>
                    <textarea id="description" name="description" rows="4"
                              placeholder="介绍家族渊源、迁徙历史等"><?php echo e(post_param('description')); ?></textarea>
                </div>
                <div class="form-group">
                    <label>是否公开</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="is_public" value="0" checked> 私密（仅自己可见）
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="is_public" value="1"> 公开（所有人可见）
                        </label>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg">创建家谱</button>
                    <a href="<?php echo SITE_URL; ?>/my.php" class="btn btn-outline btn-lg">取消</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script>
var FamilyCreateConfig = {
    siteUrl: '<?php echo SITE_URL; ?>',
    originValue: '<?php echo addslashes(e(post_param('origin'))); ?>',
    familyId: <?php echo (int)$createdFamily['id']; ?>,
    selectedLayout: 'horizontal'
};

function selectLayout(layout, el) {
    FamilyCreateConfig.selectedLayout = layout;
    document.querySelectorAll('.layout-option-card').forEach(function(card) {
        card.classList.remove('active');
    });
    el.classList.add('active');
}

function confirmLayout() {
    var btn = document.querySelector('.layout-confirm-btn');
    if (btn) {
        btn.disabled = true;
        btn.textContent = '⏳ 保存设置中...';
    }

    var formData = new FormData();
    formData.append('action', 'set_layout');
    formData.append('family_id', FamilyCreateConfig.familyId);
    formData.append('tree_layout', FamilyCreateConfig.selectedLayout);
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var layoutName = FamilyCreateConfig.selectedLayout === 'horizontal' ? '横向排列' : '竖向排列';
                document.getElementById('chosenLayoutName').textContent = layoutName;
                document.getElementById('step1_layout').style.display = 'none';
                document.getElementById('step2_done').style.display = '';
            } else {
                alert(data.message || '保存失败，请重试');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '确认布局并继续 →';
                }
            }
        })
        .catch(function(err) {
            alert('网络错误：' + err.message);
            if (btn) {
                btn.disabled = false;
                btn.textContent = '确认布局并继续 →';
            }
        });
}
</script>
<script src="<?php echo SITE_URL; ?>/assets/js/family-create.js"></script>
