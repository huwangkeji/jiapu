<?php
/**
 * 添加家族成员
 */
require_once __DIR__ . '/includes/header_pre.php';
Auth::requireLogin();

$db = Database::getInstance();
$userId = Auth::getUserId();
$familyId = (int)get_param('family_id', 0);
$parentId = (int)get_param('parent_id', 0);

// 验证家谱归属
$family = $db->fetch("SELECT * FROM families WHERE id = ? AND user_id = ? AND status = 1", [$familyId, $userId]);
if (!$family) {
    $_SESSION['flash_message'] = '家谱不存在或无权操作';
    $_SESSION['flash_type'] = 'error';
    redirect(SITE_URL . '/my.php');
}

// 获取可选的父节点
$parentOptions = $db->fetchAll(
    "SELECT id, name, generation, gender FROM members WHERE family_id = ? AND status = 1 ORDER BY generation ASC, sort_order ASC",
    [$familyId]
);

// 如果指定了父ID，获取父信息并自动计算世代
$parentMember = null;
$defaultGeneration = $family['generation_start'];
if ($parentId) {
    $parentMember = $db->fetch("SELECT * FROM members WHERE id = ? AND family_id = ? AND status = 1", [$parentId, $familyId]);
    if ($parentMember) {
        $defaultGeneration = $parentMember['generation'] + 1;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求';
    } else {
        $name = post_param('name');
        $gender = post_param('gender', 'male');
        $birthDate = post_param('birth_date');
        $deathDate = post_param('death_date');
        $generation = (int)post_param('generation', $defaultGeneration);
        $biography = post_param('biography');
        $postParentId = (int)post_param('parent_id', 0);
        $sortOrder = (int)post_param('sort_order', 0);

        if (empty($name)) {
            $error = '姓名为必填项';
        } else {
            $sql = "INSERT INTO members (family_id, name, gender, birth_date, death_date, generation, biography, parent_id, sort_order, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            $result = $db->execute($sql, [
                $familyId, $name, $gender, $birthDate, $deathDate,
                $generation, $biography, $postParentId ?: null, $sortOrder
            ]);

            if ($result) {
                Logger::info('添加成员', "在家谱{$family['title']}中添加成员: {$name}", $userId);
                $_SESSION['flash_message'] = '成员添加成功';
                $_SESSION['flash_type'] = 'success';

                // 如果选择继续添加，保留原父节点不变
                if (post_param('submit_type') === 'continue') {
                    $continueUrl = SITE_URL . "/member_add.php?family_id={$familyId}";
                    if ($postParentId > 0) {
                        $continueUrl .= "&parent_id={$postParentId}";
                    }
                    redirect($continueUrl);
                }
                redirect(SITE_URL . "/family_view.php?id={$familyId}");
            }
            $error = '添加失败，请稍后重试';
        }
    }
}

$pageTitle = '添加成员 - ' . $family['title'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>添加家族成员</h1>
        <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $familyId; ?>" class="btn btn-outline">返回家谱</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" class="form-wide">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="family_id" value="<?php echo $familyId; ?>">

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="name">姓名 <span class="required">*</span></label>
                        <input type="text" id="name" name="name" required placeholder="请输入姓名"
                               value="<?php echo e(post_param('name')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="gender">性别</label>
                        <select id="gender" name="gender">
                            <option value="male" <?php echo post_param('gender', 'male') === 'male' ? 'selected' : ''; ?>>男</option>
                            <option value="female" <?php echo post_param('gender') === 'female' ? 'selected' : ''; ?>>女</option>
                            <option value="unknown" <?php echo post_param('gender', 'male') === 'unknown' ? 'selected' : ''; ?>>未知</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="generation">世次</label>
                        <input type="number" id="generation" name="generation" min="1"
                               value="<?php echo post_param('generation', (string)$defaultGeneration); ?>">
                        <p class="help-text" id="genHint">
                            <?php if ($parentId && $parentMember): ?>
                            父/母为第<?php echo $parentMember['generation']; ?>世，子为第<?php echo $defaultGeneration; ?>世
                            <?php else: ?>
                            始祖/始迁祖，默认第<?php echo $defaultGeneration; ?>世
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="form-group">
                        <label for="parent_id">父/母节点</label>
                        <select id="parent_id" name="parent_id">
                            <option value="0">-- 无（始祖/始迁祖）--</option>
                            <?php foreach ($parentOptions as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $parentId == $p['id'] ? 'selected' : ''; ?>>
                                    第<?php echo $p['generation']; ?>世 · <?php echo e($p['name']); ?> (<?php echo gender_text($p['gender']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="birth_date">出生日期</label>
                        <input type="text" id="birth_date" name="birth_date" class="datepicker-input"
                               placeholder="如：1920年 或 1920-03-15"
                               value="<?php echo e(post_param('birth_date')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="death_date">逝世日期</label>
                        <input type="text" id="death_date" name="death_date" class="datepicker-input"
                               placeholder="如：1990年 或 1990-08-20"
                               value="<?php echo e(post_param('death_date')); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="sort_order">排序</label>
                    <input type="number" id="sort_order" name="sort_order" min="0"
                           value="<?php echo post_param('sort_order', '0'); ?>"
                           style="max-width:200px;">
                    <p class="help-text">数字越小越靠前</p>
                </div>

                <div class="form-group">
                    <label for="biography">生平简介</label>
                    <textarea id="biography" name="biography" rows="3"
                              placeholder="介绍生平事迹、功名等"><?php echo e(post_param('biography')); ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" name="submit_type" value="save" class="btn btn-primary btn-lg">保存</button>
                    <button type="submit" name="submit_type" value="continue" class="btn btn-outline btn-lg">保存并继续添加</button>
                    <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $familyId; ?>" class="btn btn-outline btn-lg">取消</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 世次自动计算：子 = 父 + 1
// 家谱规则：始祖为第1世，每下一辈世次递增1
(function() {
    var parentGen = {
        0: <?php echo $defaultGeneration; ?>
        <?php foreach ($parentOptions as $p): ?>
        ,<?php echo $p['id']; ?>: <?php echo $p['generation']; ?>
        <?php endforeach; ?>
    };
    var defaultGen = <?php echo $defaultGeneration; ?>;
    var genInput = document.getElementById('generation');
    var parentSel = document.getElementById('parent_id');
    if (!genInput || !parentSel) return;

    parentSel.addEventListener('change', function() {
        var pid = parseInt(this.value) || 0;
        if (pid === 0) {
            genInput.value = defaultGen;
            document.getElementById('genHint').textContent = '始祖/始迁祖，默认第' + defaultGen + '世';
        } else {
            var pGen = parentGen[pid] || 0;
            var childGen = pGen + 1;
            genInput.value = childGen;
            document.getElementById('genHint').textContent = '父/母为第' + pGen + '世，子为第' + childGen + '世';
        }
    });
    // 页面加载时已由PHP预设，无需额外触发
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
