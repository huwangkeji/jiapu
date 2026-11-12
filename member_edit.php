<?php
/**
 * 编辑家族成员
 */
require_once __DIR__ . '/includes/header_pre.php';
Auth::requireLogin();

/**
 * 递归软删除成员及其所有后代
 * $visited 防止脏数据成环导致无限递归（栈溢出）
 */
function deleteMemberTree($db, $memberId, $familyId, &$visited = []) {
    if (isset($visited[$memberId])) {
        return;
    }
    $visited[$memberId] = true;

    // 查找所有直接子女
    $children = $db->fetchAll(
        "SELECT id FROM members WHERE parent_id = ? AND family_id = ? AND status = 1",
        [$memberId, $familyId]
    );
    foreach ($children as $child) {
        deleteMemberTree($db, $child['id'], $familyId, $visited);
    }
    // 删除当前节点
    $db->execute("UPDATE members SET status = 0 WHERE id = ? AND family_id = ?", [$memberId, $familyId]);
}

/**
 * 统计成员的后代数量（用于提示）
 */
function countDescendants($db, $memberId, $familyId, &$visited = []) {
    if (isset($visited[$memberId])) {
        return 0;
    }
    $visited[$memberId] = true;

    $count = 0;
    $children = $db->fetchAll(
        "SELECT id FROM members WHERE parent_id = ? AND family_id = ? AND status = 1",
        [$memberId, $familyId]
    );
    foreach ($children as $child) {
        $count += 1 + countDescendants($db, $child['id'], $familyId, $visited);
    }
    return $count;
}

/**
 * 获取成员的全部后代 ID（迭代实现，防环）
 */
function getDescendantIds($db, $memberId, $familyId) {
    $ids = [];
    $stack = [$memberId];
    $seen = [$memberId => true];
    while (!empty($stack)) {
        $current = array_pop($stack);
        $children = $db->fetchAll(
            "SELECT id FROM members WHERE parent_id = ? AND family_id = ? AND status = 1",
            [$current, $familyId]
        );
        foreach ($children as $child) {
            $cid = (int)$child['id'];
            if (isset($seen[$cid])) {
                continue; // 脏数据成环保护
            }
            $seen[$cid] = true;
            $ids[] = $cid;
            $stack[] = $cid;
        }
    }
    return $ids;
}

$db = Database::getInstance();
$userId = Auth::getUserId();
$memberId = (int)get_param('id', 0);
$familyId = (int)get_param('family_id', 0);

$member = $db->fetch("SELECT m.*, f.user_id as owner_id FROM members m JOIN families f ON m.family_id = f.id WHERE m.id = ? AND f.id = ? AND m.status = 1", [$memberId, $familyId]);

if (!$member || $member['owner_id'] != $userId) {
    $_SESSION['flash_message'] = '成员不存在或无权操作';
    $_SESSION['flash_type'] = 'error';
    redirect(SITE_URL . '/my.php');
}

$family = $db->fetch("SELECT * FROM families WHERE id = ? AND user_id = ?", [$familyId, $userId]);
$defaultGeneration = $family['generation_start'] ?? 1;

// 获取可选的父节点
$parentOptions = $db->fetchAll(
    "SELECT id, name, generation, gender FROM members WHERE family_id = ? AND id != ? AND status = 1 ORDER BY generation ASC, sort_order ASC",
    [$familyId, $memberId]
);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求';
    } else {
        $action = post_param('action');

        if ($action === 'delete') {
            // 递归软删除该成员及其所有后代
            deleteMemberTree($db, $memberId, $familyId);
            Logger::info('删除成员', "删除成员: {$member['name']}（含后代）", $userId);
            $_SESSION['flash_message'] = '成员及其后代已删除';
            $_SESSION['flash_type'] = 'success';
            redirect(SITE_URL . "/family_view.php?id={$familyId}");
        } else {
            $name = post_param('name');
            $gender = post_param('gender', 'male');
            $birthDate = post_param('birth_date');
            $deathDate = post_param('death_date');
            $generation = (int)post_param('generation', $member['generation']);
            $biography = post_param('biography');
            $postParentId = (int)post_param('parent_id', 0);
            $sortOrder = (int)post_param('sort_order', 0);

            if (empty($name)) {
                $error = '姓名为必填项';
            } elseif ($postParentId && $postParentId === (int)$memberId) {
                // BUGFIX: 之前允许把成员的父亲设成自己，形成环导致世系图丢人、递归删除栈溢出
                $error = '不能将成员的父亲设为本人';
            } elseif ($postParentId && in_array($postParentId, getDescendantIds($db, $memberId, $familyId), true)) {
                $error = '不能将成员的父亲设为其后代（会形成循环世系）';
            } else {
                $db->execute(
                    "UPDATE members SET name=?, gender=?, birth_date=?, death_date=?, generation=?, biography=?, parent_id=?, sort_order=? WHERE id=?",
                    [$name, $gender, $birthDate, $deathDate, $generation, $biography,
                     $postParentId ?: null, $sortOrder, $memberId]
                );

                Logger::info('编辑成员', "编辑成员: {$name}", $userId);
                $_SESSION['flash_message'] = '保存成功';
                $_SESSION['flash_type'] = 'success';
                redirect(SITE_URL . "/member_edit.php?id={$memberId}&family_id={$familyId}");
            }
        }
    }
}

$pageTitle = '编辑成员 - ' . $member['name'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <h1>编辑成员信息</h1>
        <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $familyId; ?>" class="btn btn-outline">返回家谱</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" class="form-wide">
                <?php echo csrf_field(); ?>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="name">姓名 <span class="required">*</span></label>
                        <input type="text" id="name" name="name" required
                               value="<?php echo e($member['name']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="gender">性别</label>
                        <select id="gender" name="gender">
                            <option value="male" <?php echo $member['gender'] === 'male' ? 'selected' : ''; ?>>男</option>
                            <option value="female" <?php echo $member['gender'] === 'female' ? 'selected' : ''; ?>>女</option>
                            <option value="unknown" <?php echo $member['gender'] === 'unknown' ? 'selected' : ''; ?>>未知</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="generation">世次</label>
                        <input type="number" id="generation" name="generation" min="1"
                               value="<?php echo $member['generation']; ?>">
                        <p class="help-text" id="genHint">
                            <?php if ($member['parent_id']): 
                                $pMember = $db->fetch("SELECT generation, name FROM members WHERE id = ?", [$member['parent_id']]);
                                if ($pMember): ?>
                            父/母为第<?php echo $pMember['generation']; ?>世，子为第<?php echo $member['generation']; ?>世
                            <?php endif; else: ?>
                            始祖/始迁祖，默认第<?php echo $defaultGeneration; ?>世
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="form-group">
                        <label for="parent_id">父/母节点</label>
                        <select id="parent_id" name="parent_id">
                            <option value="0">-- 无（始祖/始迁祖）--</option>
                            <?php foreach ($parentOptions as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $member['parent_id'] == $p['id'] ? 'selected' : ''; ?>>
                                    第<?php echo $p['generation']; ?>世 · <?php echo e($p['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="birth_date">出生日期</label>
                        <input type="text" id="birth_date" name="birth_date" class="datepicker-input"
                               value="<?php echo e($member['birth_date']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="death_date">逝世日期</label>
                        <input type="text" id="death_date" name="death_date" class="datepicker-input"
                               value="<?php echo e($member['death_date']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="sort_order">排序</label>
                    <input type="number" id="sort_order" name="sort_order" min="0"
                           value="<?php echo $member['sort_order']; ?>"
                           style="max-width:200px;">
                </div>

                <div class="form-group">
                    <label for="biography">生平简介</label>
                    <textarea id="biography" name="biography" rows="3"><?php echo e($member['biography']); ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg">保存修改</button>
                    <a href="<?php echo SITE_URL; ?>/family_view.php?id=<?php echo $familyId; ?>" class="btn btn-outline btn-lg">取消</a>
                </div>
            </form>
        </div>
    </div>

    <!-- 删除成员 -->
    <?php $descCount = countDescendants($db, $memberId, $familyId); ?>
    <div class="card card-danger" style="margin-top:20px;">
        <div class="card-header"><h3>删除成员</h3></div>
        <div class="card-body">
            <p>删除后将<strong style="color:#e74c3c;">级联删除</strong>该成员及其所有后代。此操作不可恢复。</p>
            <?php if ($descCount > 0): ?>
            <p style="color:#e74c3c;font-size:13px;">⚠ 该成员有 <strong><?php echo $descCount; ?></strong> 名后代将一并删除。</p>
            <?php endif; ?>
            <form method="post" id="deleteMemberForm">
                <input type="hidden" name="action" value="delete">
                <?php echo csrf_field(); ?>
                <button type="button" class="btn btn-danger" onclick="showDeleteMemberModal()">删除成员</button>
            </form>
        </div>
    </div>
</div>

<!-- 删除成员确认弹窗 -->
<div id="deleteMemberModal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog modal-danger">
        <div class="modal-header">
            <h3>⚠️ 删除成员</h3>
        </div>
        <div class="modal-body">
            <p style="font-size:15px;margin-bottom:8px;">此操作<strong style="color:#e74c3c;">不可恢复</strong>！</p>
            <p style="color:#666;">成员 <strong>"<?php echo e($member['name']); ?>"</strong> 及其全部后代将被<strong style="color:#e74c3c;">级联删除</strong>。</p>
            <?php if ($descCount > 0): ?>
            <p style="color:#e74c3c;font-size:13px;margin-top:8px;padding:8px;background:#fff5f5;border-radius:4px;">⚠ 将一并删除 <strong><?php echo $descCount; ?></strong> 名后代成员。</p>
            <?php endif; ?>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="hideDeleteMemberModal()">取消</button>
                <button type="button" class="btn btn-danger" onclick="confirmDeleteMember()">确认删除</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script>
function showDeleteMemberModal() {
    document.getElementById('deleteMemberModal').style.display = 'flex';
}
function hideDeleteMemberModal() {
    document.getElementById('deleteMemberModal').style.display = 'none';
}
function confirmDeleteMember() {
    document.getElementById('deleteMemberForm').submit();
}
document.getElementById('deleteMemberModal').addEventListener('click', function(e) {
    if (e.target === this) hideDeleteMemberModal();
});
</script>

<script>
// 世次自动计算：子 = 父 + 1
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
})();
</script>
