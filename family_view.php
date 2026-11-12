<?php
/**
 * 查看家谱详情 + 世系图（缩放平移、导出PDF、分享链接）
 */
require_once __DIR__ . '/includes/header_pre.php';

$db = Database::getInstance();
$familyId = (int)get_param('id', 0);
$shareToken = get_param('token', '');

$family = $db->fetch(
    "SELECT f.*, u.nickname as creator_name
     FROM families f
     LEFT JOIN users u ON f.user_id = u.id
     WHERE f.id = ? AND f.status = 1",
    [$familyId]
);

if (!$family) {
    $_SESSION['flash_message'] = '家谱不存在';
    $_SESSION['flash_type'] = 'error';
    redirect(SITE_URL . '/family.php');
}

// 权限检查：公开 / 本人 / 有效分享token
$isOwner = Auth::isLoggedIn() && Auth::getUserId() == $family['user_id'];
$isShared = !empty($shareToken) && !empty($family['share_token']) && $shareToken === $family['share_token'];
$canView = $family['is_public'] || $isOwner || $isShared;

if (!$canView) {
    $_SESSION['flash_message'] = '无权查看此家谱';
    $_SESSION['flash_type'] = 'error';
    redirect(SITE_URL . '/family.php');
}

// 处理AJAX请求：生成/取消分享链接
if ($isOwner && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'generate_share') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            json_response(['success' => false, 'message' => '非法请求']);
        }
        $token = bin2hex(random_bytes(16));
        $db->execute("UPDATE families SET share_token = ? WHERE id = ?", [$token, $familyId]);
        $family['share_token'] = $token;
        $shareUrl = SITE_URL . '/family_view.php?id=' . $familyId . '&token=' . $token;
        json_response(['success' => true, 'message' => '分享链接已生成', 'data' => ['url' => $shareUrl]]);
    }
    if ($_POST['action'] === 'cancel_share') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            json_response(['success' => false, 'message' => '非法请求']);
        }
        $db->execute("UPDATE families SET share_token = NULL WHERE id = ?", [$familyId]);
        $family['share_token'] = null;
        json_response(['success' => true, 'message' => '分享链接已取消']);
    }
    exit;
}

// 获取成员列表（按世代和排序）
$members = $db->fetchAll(
    "SELECT * FROM members WHERE family_id = ? AND status = 1 ORDER BY generation ASC, sort_order ASC, id ASC",
    [$familyId]
);

// 构建树形结构
$tree = [];
$memberMap = [];
foreach ($members as $m) {
    $memberMap[$m['id']] = $m;
    $m['children'] = [];
    $tree[$m['id']] = $m;
}

// 构建父子关系
$rootMembers = [];
foreach ($tree as $id => &$m) {
    if ($m['parent_id'] && isset($tree[$m['parent_id']])) {
        $tree[$m['parent_id']]['children'][] = &$m;
    } else {
        $rootMembers[] = &$m;
    }
}
unset($m);

// 统计信息
$memberCount = count($members);
$generations = $memberCount > 0 ? max(array_column($members, 'generation')) : 0;
$maleCount = count(array_filter($members, function($m) { return $m['gender'] === 'male'; }));
$femaleCount = count(array_filter($members, function($m) { return $m['gender'] === 'female'; }));
$shareUrl = !empty($family['share_token']) ? SITE_URL . '/family_view.php?id=' . $familyId . '&token=' . $family['share_token'] : '';

$pageTitle = $family['title'];
$extraCss = 'tree.css';
$treeLayout = $family['tree_layout'] ?? 'horizontal';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="family-view-header">
        <div class="family-view-cover" style="background: linear-gradient(135deg, #8B4513 0%, #D2691E 50%, #CD853F 100%);">
            <div class="family-view-cover-inner">
                <h1><?php echo e($family['surname']); ?>氏族谱</h1>
                <h2><?php echo e($family['title']); ?></h2>
                <?php if ($family['origin']): ?>
                    <p>源出 <?php echo e($family['origin']); ?></p>
                <?php endif; ?>
                <?php if ($isShared): ?>
                    <p style="font-size:12px;opacity:0.7;">🔗 通过分享链接查看</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 统计卡片 -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-number"><?php echo $memberCount; ?></div>
            <div class="stat-label">总人数</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $generations; ?></div>
            <div class="stat-label">传承世代</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $maleCount; ?></div>
            <div class="stat-label">男</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $femaleCount; ?></div>
            <div class="stat-label">女</div>
        </div>
    </div>

    <!-- 世系树缩略图 -->
    <?php if ($memberCount > 0): ?>
    <div class="tree-thumb">
        <div class="tree-thumb-header">
            <h3>🌳 世系概览</h3>
            <button class="tree-thumb-toggle" onclick="this.closest('.tree-thumb').classList.toggle('collapsed')" title="折叠/展开">▾</button>
        </div>
        <div class="tree-thumb-body">
            <?php
            $renderThumb = function($nodes, $depth = 0) use (&$renderThumb) {
                if ($depth > 3 || empty($nodes)) return;
                echo '<ul>';
                foreach ($nodes as $node) {
                    $icon = $node['gender'] === 'male' ? '♂' : ($node['gender'] === 'female' ? '♀' : '');
                    $genLabel = $node['generation'] > 0 ? "G{$node['generation']}" : '';
                    echo '<li>';
                    echo '<span class="thumb-node" data-mid="' . $node['id'] . '" title="' . e($node['name']) . '" onclick="scrollToMember(' . $node['id'] . ')">';
                    echo '<i class="g-icon">' . $icon . '</i>' . e(mb_substr($node['name'], 0, 4));
                    if ($genLabel) echo '<em>' . $genLabel . '</em>';
                    echo '</span>';
                    if (!empty($node['children'])) {
                        $renderThumb($node['children'], $depth + 1);
                    }
                    echo '</li>';
                }
                echo '</ul>';
            };
            $renderThumb($rootMembers);
            ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 操作栏 -->
    <div class="action-bar">
        <?php if ($isOwner): ?>
            <a href="<?php echo SITE_URL; ?>/member_add.php?family_id=<?php echo $familyId; ?>" class="btn btn-primary">添加成员</a>
            <a href="<?php echo SITE_URL; ?>/family_edit.php?id=<?php echo $familyId; ?>" class="btn btn-outline">编辑家谱</a>
            <a href="<?php echo SITE_URL; ?>/export.php" class="btn btn-outline btn-export-link">📚 编目成书</a>
        <?php endif; ?>
        <button class="btn btn-outline" onclick="toggleView('tree')">世系图</button>
        <button class="btn btn-outline" onclick="toggleView('list')">成员列表</button>
        <?php if (!empty($members)): ?>
            <!-- 缩放控制 -->
            <span class="zoom-controls" id="zoomControls" style="display:none;">
                <button class="btn btn-outline btn-sm" onclick="zoomTree(-0.2)" title="缩小">🔍−</button>
                <span class="zoom-label" id="zoomLabel">100%</span>
                <button class="btn btn-outline btn-sm" onclick="zoomTree(0.2)" title="放大">🔍+</button>
                <button class="btn btn-outline btn-sm" onclick="resetZoom()" title="重置">↺</button>
                <button class="btn btn-outline btn-sm" id="fixLayoutBtn" onclick="fixTreeLayout()" title="修复连接线错位">🔧 修复布局</button>
            </span>
            <!-- 导出PDF -->
            <button class="btn btn-outline" onclick="exportTreePDF()">📄 导出PDF</button>
            <!-- 全屏查看 -->
            <button class="btn btn-outline" id="fullscreenBtn" onclick="toggleFullscreen()">📺 全屏查看</button>
            <!-- 人员查找 -->
            <span class="tree-search" id="treeSearchBox" style="display:none;">
                <input type="text" id="treeSearchInput" placeholder="输入姓名查找…" style="padding:5px 10px;border:1px solid #ddd;border-radius:4px;font-size:13px;width:140px;">
                <button class="btn btn-outline btn-sm" onclick="searchMember()">🔍 查找</button>
            </span>
            <!-- 分享链接 -->
            <?php if ($isOwner): ?>
                <?php if ($shareUrl): ?>
                    <button class="btn btn-outline btn-share" onclick="copyShareLink()" id="shareBtn">🔗 复制分享链接</button>
                    <button class="btn btn-outline btn-sm" onclick="cancelShareLink()" title="取消分享">✕</button>
                <?php else: ?>
                    <button class="btn btn-outline" id="generateShareBtn" onclick="generateShareLink()">🔗 生成分享链接</button>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- 家谱简介 -->
    <?php if ($family['description']): ?>
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><h3>家谱简介</h3></div>
        <div class="card-body">
            <p class="family-description"><?php echo nl2br(e($family['description'])); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- 世系图视图 -->
    <div id="tree-view" class="card">
        <div class="card-header">
            <h3>世系图</h3>
            <span class="tree-tips" style="font-size:12px;color:#999;">💡 滚轮缩放 · 拖拽平移 · 点击节点查看详情</span>
        </div>
        <div class="card-body tree-container" id="treeContainer">
            <?php if (empty($members)): ?>
                <div class="empty-state">
                    <p>暂无成员，请先添加始祖信息</p>
                    <?php if ($isOwner): ?>
                        <a href="<?php echo SITE_URL; ?>/member_add.php?family_id=<?php echo $familyId; ?>" class="btn btn-primary">添加始祖</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="tree-viewport" id="treeViewport">
                    <div class="tree-scroll layout-<?php echo $treeLayout; ?>" id="treeScroll">
                        <ul class="tree">
                            <?php echo renderTree($rootMembers, $familyId, $isOwner); ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 成员列表视图 -->
    <div id="list-view" class="card" style="display:none;">
        <div class="card-header"><h3>成员列表</h3></div>
        <div class="card-body">
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>姓名</th>
                        <th>性别</th>
                        <th>世次</th>
                        <th>出生</th>
                        <th>逝世</th>
                        <?php if ($isOwner): ?><th>操作</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                    <tr>
                        <td>
                            <?php if ($isOwner): ?>
                                <a href="<?php echo SITE_URL; ?>/member_edit.php?id=<?php echo $m['id']; ?>&family_id=<?php echo $familyId; ?>">
                                    <?php echo e($m['name']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo e($m['name']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo gender_text($m['gender']); ?></td>
                        <td>第<?php echo $m['generation']; ?>世</td>
                        <td><?php echo e($m['birth_date'] ?: '-'); ?></td>
                        <td><?php echo e($m['death_date'] ?: '-'); ?></td>
                        <?php if ($isOwner): ?>
                        <td>
                            <a href="<?php echo SITE_URL; ?>/member_edit.php?id=<?php echo $m['id']; ?>&family_id=<?php echo $familyId; ?>" class="btn btn-sm btn-outline">编辑</a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<!-- 分享链接弹窗 -->
<div id="shareModal" class="modal-overlay" style="display:none;">
    <?php echo csrf_field(); ?>
    <div class="modal-dialog">
        <div class="modal-header"><h3>🔗 分享家谱</h3></div>
        <div class="modal-body">
            <p style="margin-bottom:12px;">将此链接分享给他人，即可查看此家谱：</p>
            <div class="share-link-box">
                <input type="text" id="shareLinkInput" readonly value="" style="width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
            </div>
            <div class="modal-actions" style="margin-top:12px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('shareModal').style.display='none'">关闭</button>
                <button type="button" class="btn btn-primary" onclick="copyShareLink()">复制链接</button>
            </div>
        </div>
    </div>
</div>

<!-- 取消分享确认弹窗 -->
<div id="cancelShareModal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog modal-danger">
        <div class="modal-header"><h3>⚠️ 取消分享</h3></div>
        <div class="modal-body">
            <p style="font-size:15px;margin-bottom:8px;">取消后<strong style="color:#e74c3c;">分享链接将失效</strong>，其他人将无法通过之前的链接访问此家谱。</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeCancelShareModal()">保留分享</button>
                <button type="button" class="btn btn-danger" onclick="confirmCancelShare()">确认取消</button>
            </div>
        </div>
    </div>
</div>

<?php
/**
 * 递归渲染世系树
 */
function renderTree($members, $familyId, $isOwner) {
    $html = '';
    foreach ($members as $member) {
        $genderClass = $member['gender'] === 'male' ? 'male' : ($member['gender'] === 'female' ? 'female' : '');
        $hasChildren = !empty($member['children']);
        $html .= '<li class="tree-node">';
        $html .= '<div class="node-content ' . $genderClass . '" onclick="event.stopPropagation();">';
        $avatarChar = mb_substr($member['name'], -1);
        if (!empty($member['avatar'])) {
            $html .= '<div class="node-avatar"><img src="' . SITE_URL . '/' . $member['avatar'] . '" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"></div>';
        } else {
            $html .= '<div class="node-avatar">' . $avatarChar . '</div>';
        }
        $html .= '<div class="node-info">';
        $html .= '<span class="node-name">' . e($member['name']) . '</span>';
        $html .= '<span class="node-gen">第' . $member['generation'] . '世</span>';
        if ($member['birth_date']) {
            $html .= '<span class="node-date">' . e($member['birth_date']);
            if ($member['death_date']) {
                $html .= ' - ' . e($member['death_date']);
            }
            $html .= '</span>';
        }
        if ($isOwner) {
            $html .= '<div class="node-actions">';
            $html .= '<a href="' . SITE_URL . '/member_add.php?family_id=' . $familyId . '&parent_id=' . $member['id'] . '" class="btn-tiny" title="添加子女">+</a>';
            $html .= '<a href="' . SITE_URL . '/member_edit.php?id=' . $member['id'] . '&family_id=' . $familyId . '" class="btn-tiny" title="编辑">✎</a>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';
        if ($hasChildren) {
            $html .= '<ul>' . renderTree($member['children'], $familyId, $isOwner) . '</ul>';
        }
        $html .= '</li>';
    }
    return $html;
}
?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- ========== 缩放平移 / 导出PDF / 分享链接 ========== -->
<script>
(function() {
    // ---- 视图切换 ----
    window.toggleView = function(v) {
        document.getElementById('tree-view').style.display = v === 'tree' ? '' : 'none';
        document.getElementById('list-view').style.display = v === 'list' ? '' : 'none';
        document.getElementById('zoomControls').style.display = v === 'tree' ? '' : 'none';
        document.getElementById('treeSearchBox').style.display = v === 'tree' ? '' : 'none';
        if (v === 'tree') setTimeout(updateZoomLabel, 50);
    };

    // ---- 缩放平移 ----
    var scale = 1, minScale = 0.3, maxScale = 3;
    var panX = 0, panY = 0, isPanning = false, lastX, lastY;
    var treeScroll = document.getElementById('treeScroll');
    var treeViewport = document.getElementById('treeViewport');
    if (!treeScroll || !treeViewport) return;

    function applyTransform() {
        treeScroll.style.transform = 'translate(' + panX + 'px, ' + panY + 'px) scale(' + scale + ')';
        treeScroll.style.transformOrigin = 'top center';
        updateZoomLabel();
    }

    window.zoomTree = function(delta) {
        var newScale = Math.max(minScale, Math.min(maxScale, scale + delta));
        if (newScale === scale) return;
        scale = newScale;
        applyTransform();
    };

    window.resetZoom = function() {
        scale = 1; panX = 0; panY = 0;
        applyTransform();
    };

    window.fixTreeLayout = function() {
        var btn = document.getElementById('fixLayoutBtn');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ 修复中...'; }

        // 1. 重置缩放和偏移
        scale = 1; panX = 0; panY = 0;
        applyTransform();

        // 2. 清除搜索高亮和输入
        document.querySelectorAll('.tree .node-content').forEach(function(el) {
            el.style.outline = ''; el.style.animation = '';
        });
        var si = document.getElementById('treeSearchInput');
        if (si) si.value = '';

        // 3. 多阶段 DOM 回流强制连接线重算
        var vp = document.getElementById('treeViewport');
        var ts = document.getElementById('treeScroll');
        if (ts && vp) {
            // 阶段A: visibility 触发回流（比 display:none 更平滑）
            ts.style.visibility = 'hidden';
            void ts.offsetHeight;
            ts.style.visibility = '';
            void ts.offsetHeight;

            // 阶段B: 滚动归零 + 延迟确认
            vp.scrollLeft = 0;
            vp.scrollTop = 0;

            setTimeout(function() {
                void ts.offsetHeight;
                vp.scrollLeft = 0;
                vp.scrollTop = 0;

                if (btn) { btn.disabled = false; btn.textContent = '🔧 修复布局'; }
                alert(
                    '✅ 布局已修复！\n\n' +
                    '• 缩放重置为 100%\n' +
                    '• 平移位置归零\n' +
                    '• 连接线强制重绘\n\n' +
                    '如仍有问题请 Ctrl+F5 强刷'
                );
            }, 100);
        } else {
            if (btn) { btn.disabled = false; btn.textContent = '🔧 修复布局'; }
            alert('布局已重置。');
        }
    };

    // ---- 人员查找 ----
    window.searchMember = function() {
        var input = document.getElementById('treeSearchInput');
        var name = (input ? input.value.trim() : '');
        if (!name) { alert('请输入要查找的姓名'); return; }

        // 清除之前的搜索效果
        document.querySelectorAll('.tree .node-content').forEach(function(el) {
            el.style.outline = '';
            el.style.background = '';
        });

        // 查找匹配节点
        var found = null;
        var nodes = document.querySelectorAll('.tree .node-name');
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].textContent.trim() === name) {
                found = nodes[i].closest('.node-content');
                break;
            }
        }
        if (!found) {
            // 模糊匹配
            for (var i = 0; i < nodes.length; i++) {
                if (nodes[i].textContent.indexOf(name) !== -1) {
                    found = nodes[i].closest('.node-content');
                    break;
                }
            }
        }

        if (!found) {
            alert('未找到成员：' + name);
            return;
        }

        // 高亮找到的节点
        found.style.outline = '3px solid #dc2626';
        found.style.outlineOffset = '4px';
        found.style.zIndex = '10';

        // 计算节点在视口中的位置并平移
        var vp = document.getElementById('treeViewport');
        var ts = document.getElementById('treeScroll');
        if (!vp || !ts) return;

        var vpRect = vp.getBoundingClientRect();
        var nodeRect = found.getBoundingClientRect();
        
        // 目标：节点居中于视口
        var targetX = (vpRect.width / 2) - (nodeRect.left - vpRect.left + nodeRect.width / 2);
        var targetY = (vpRect.height / 2) - (nodeRect.top - vpRect.top + nodeRect.height / 2);
        
        // 结合当前scale调整
        panX += targetX / scale;
        panY += targetY / scale;
        applyTransform();

        // 动画闪烁提示
        var count = 0;
        var flash = setInterval(function() {
            found.style.outline = (count % 2 === 0) ? '3px solid #dc2626' : '3px solid transparent';
            count++;
            if (count >= 6) {
                clearInterval(flash);
                found.style.outline = '3px solid #dc2626';
            }
        }, 300);
    };

    // 搜索框回车触发
    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('treeSearchInput');
        if (input) {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') searchMember();
            });
        }

        // ★ 自动修复连接线（页面加载后延迟执行，确保CSS完全解析）
        var ts = document.getElementById('treeScroll');
        var vp = document.getElementById('treeViewport');
        if (ts && vp) {
            // 阶段1: 立即触发一次回流
            void ts.offsetHeight;
            // 阶段2: 延迟二次回流（等字体、图片等异步资源就位）
            setTimeout(function() {
                ts.style.visibility = 'hidden';
                void ts.offsetHeight;
                ts.style.visibility = '';
                void ts.offsetHeight; // 最终确认
            }, 80);
            // 阶段3: 更长延迟处理动态内容
            setTimeout(function() {
                void ts.offsetHeight;
            }, 300);
        }
    });

    window.updateZoomLabel = function() {
        var el = document.getElementById('zoomLabel');
        if (el) el.textContent = Math.round(scale * 100) + '%';
    };

    // 鼠标滚轮缩放
    treeViewport.addEventListener('wheel', function(e) {
        e.preventDefault();
        var rect = treeViewport.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var my = e.clientY - rect.top;
        var delta = e.deltaY < 0 ? 0.1 : -0.1;
        var newScale = Math.max(minScale, Math.min(maxScale, scale + delta));
        if (newScale === scale) return;
        // 以鼠标位置为中心缩放
        var ratio = newScale / scale;
        panX = mx - ratio * (mx - panX);
        panY = my - ratio * (my - panY);
        scale = newScale;
        applyTransform();
    }, { passive: false });

    // 鼠标拖拽平移
    treeViewport.addEventListener('mousedown', function(e) {
        if (e.target.closest('.node-content') || e.target.closest('.btn-tiny')) return;
        isPanning = true;
        lastX = e.clientX;
        lastY = e.clientY;
        treeViewport.style.cursor = 'grabbing';
        e.preventDefault();
    });

    window.addEventListener('mousemove', function(e) {
        if (!isPanning) return;
        panX += e.clientX - lastX;
        panY += e.clientY - lastY;
        lastX = e.clientX;
        lastY = e.clientY;
        applyTransform();
    });

    window.addEventListener('mouseup', function() {
        if (isPanning) {
            isPanning = false;
            treeViewport.style.cursor = '';
        }
    });

    // 触摸支持
    var touchDist = 0, touchScale = 1, touchPanX = 0, touchPanY = 0;
    treeViewport.addEventListener('touchstart', function(e) {
        if (e.touches.length === 1 && !e.target.closest('.node-content')) {
            isPanning = true;
            lastX = e.touches[0].clientX;
            lastY = e.touches[0].clientY;
        }
        if (e.touches.length === 2) {
            isPanning = false;
            var dx = e.touches[0].clientX - e.touches[1].clientX;
            var dy = e.touches[0].clientY - e.touches[1].clientY;
            touchDist = Math.sqrt(dx*dx + dy*dy);
            touchScale = scale;
            touchPanX = panX;
            touchPanY = panY;
        }
    }, { passive: false });

    treeViewport.addEventListener('touchmove', function(e) {
        if (isPanning && e.touches.length === 1) {
            panX += e.touches[0].clientX - lastX;
            panY += e.touches[0].clientY - lastY;
            lastX = e.touches[0].clientX;
            lastY = e.touches[0].clientY;
            applyTransform();
        }
        if (e.touches.length === 2) {
            var dx = e.touches[0].clientX - e.touches[1].clientX;
            var dy = e.touches[0].clientY - e.touches[1].clientY;
            var newDist = Math.sqrt(dx*dx + dy*dy);
            scale = Math.max(minScale, Math.min(maxScale, touchScale * (newDist / touchDist)));
            applyTransform();
        }
    }, { passive: false });

    treeViewport.addEventListener('touchend', function() { isPanning = false; });

    // ---- 全屏切换 ----
    window.toggleFullscreen = function() {
        var el = document.getElementById('treeViewport');
        var btn = document.getElementById('fullscreenBtn');
        if (!el || !btn) return;

        if (!document.fullscreenElement && !document.webkitFullscreenElement) {
            // 进入全屏
            if (el.requestFullscreen) {
                el.requestFullscreen();
            } else if (el.webkitRequestFullscreen) {
                el.webkitRequestFullscreen();
            }
            btn.textContent = '✕ 退出全屏';
        } else {
            // 退出全屏
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            }
            btn.textContent = '📺 全屏查看';
        }
    };

    // 监听全屏变化事件（ESC退出时同步按钮）
    document.addEventListener('fullscreenchange', function() {
        var btn = document.getElementById('fullscreenBtn');
        if (!btn) return;
        if (!document.fullscreenElement && !document.webkitFullscreenElement) {
            btn.textContent = '📺 全屏查看';
        } else {
            btn.textContent = '✕ 退出全屏';
        }
    });
    document.addEventListener('webkitfullscreenchange', function() {
        var btn = document.getElementById('fullscreenBtn');
        if (!btn) return;
        if (!document.webkitFullscreenElement) {
            btn.textContent = '📺 全屏查看';
        } else {
            btn.textContent = '✕ 退出全屏';
        }
    });

    // ---- 导出PDF ----
    window.exportTreePDF = function() {
        var siteName = <?php echo json_encode(get_site_setting('site_name', '族谱传承')); ?>;
        var printHeader = <?php echo json_encode(get_site_setting('print_header', '') ?: get_site_setting('site_name', '族谱传承')); ?>;
        var aboutInfo = <?php echo json_encode(get_site_setting('about_info', '传承家族历史，弘扬优良家风')); ?>;
        var familySurname = <?php echo json_encode($family['surname']); ?>;
        var familyTitle = <?php echo json_encode($family['title']); ?>;
        var familyOrigin = <?php echo json_encode($family['origin']); ?>;
        var siteUrl = <?php echo json_encode(SITE_URL); ?>;

        var printWindow = window.open('', '_blank', 'width=1200,height=800');
        printWindow.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + familySurname + '氏族谱 - ' + siteName + '</title>');
        // 内联 tree.css
        var treeCSSLink = document.querySelector('link[href*="tree.css"]');
        if (treeCSSLink) {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', treeCSSLink.href, false);
            try { xhr.send(); } catch(e) {}
            if (xhr.status === 200) {
                printWindow.document.write('<style>' + xhr.responseText + '</style>');
            }
        }
        printWindow.document.write('<style>');
        printWindow.document.write('@page { size: A4 landscape; margin: 12mm; }');
        printWindow.document.write('* { box-sizing: border-box; }');
        printWindow.document.write('body { font-family: "Microsoft YaHei", "PingFang SC", sans-serif; color: #333; margin: 0; padding: 0; min-height: 100vh; display: flex; flex-direction: column; }');
        printWindow.document.write('.print-page { flex: 1; display: flex; flex-direction: column; }');
        printWindow.document.write('.print-header { text-align: center; padding: 16px 0 12px; border-bottom: 3px double #8B4513; margin-bottom: 16px; }');
        printWindow.document.write('.print-header h1 { font-size: 26px; color: #8B4513; margin: 0 0 6px; font-weight: 700; letter-spacing: 4px; }');
        printWindow.document.write('.print-header .family-name { font-size: 18px; color: #555; margin: 0; }');
        printWindow.document.write('.print-header .family-origin { font-size: 13px; color: #999; margin: 4px 0 0; }');
        printWindow.document.write('.print-content { flex: 1; display: flex; justify-content: center; }');
        printWindow.document.write('.print-footer { margin-top: auto; padding: 10px 0 4px; border-top: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: flex-end; font-size: 11px; color: #999; }');
        printWindow.document.write('.print-footer .about { max-width: 60%; }');
        printWindow.document.write('.print-footer .signature { text-align: right; }');
        printWindow.document.write('.print-footer .signature span { display: block; }');
        printWindow.document.write('.tree { display: flex; justify-content: center; }');
        printWindow.document.write('.tree, .tree ul { list-style: none; margin: 0; padding: 0; position: relative; }');
        printWindow.document.write('.tree ul { display: flex; justify-content: center; padding-top: 30px; }');
        printWindow.document.write('.node-actions, .btn-tiny { display: none !important; }');
        printWindow.document.write('.tree-viewport { overflow: visible !important; }');
        printWindow.document.write('.tree-scroll { transform: none !important; }');
        printWindow.document.write('</style></head><body><div class="print-page">');

        // 页眉：网站名称 + 家谱信息
        printWindow.document.write('<div class="print-header">');
        printWindow.document.write('<h1>' + printHeader + '</h1>');
        printWindow.document.write('<div class="family-name">' + familySurname + '氏族谱 — ' + familyTitle + '</div>');
        if (familyOrigin) printWindow.document.write('<div class="family-origin">源出 ' + familyOrigin + '</div>');
        printWindow.document.write('</div>');

        // 树形图内容
        printWindow.document.write('<div class="print-content">');
        var treeHTML = document.getElementById('treeScroll').innerHTML;
        printWindow.document.write('<div style="overflow:visible;"><ul class="tree">' + treeHTML + '</ul></div>');
        printWindow.document.write('</div>');

        // 页脚：关于信息 | 日期/签名
        var today = new Date();
        var dateStr = today.getFullYear() + '年' + (today.getMonth()+1) + '月' + today.getDate() + '日';
        printWindow.document.write('<div class="print-footer">');
        printWindow.document.write('<div class="about">' + aboutInfo + '</div>');
        printWindow.document.write('<div class="signature"><span>' + dateStr + '</span><span>' + siteName + '</span></div>');
        printWindow.document.write('</div>');

        printWindow.document.write('</div></body></html>');
        printWindow.document.close();

        setTimeout(function() {
            printWindow.print();
        }, 500);
    };

    // ---- 分享链接 ----
    window.generateShareLink = function() {
        var btn = document.getElementById('generateShareBtn');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ 正在生成...'; }
        var csrfEl = document.querySelector('input[name="csrf_token"]');
        if (!csrfEl) { alert('CSRF token missing, please refresh page'); if (btn) { btn.disabled = false; btn.textContent = '🔗 生成分享链接'; } return; }
        var formData = new FormData();
        formData.append('action', 'generate_share');
        formData.append('csrf_token', csrfEl.value);
        fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    document.getElementById('shareLinkInput').value = data.data.url;
                    document.getElementById('shareModal').style.display = 'flex';
                    // 替换当前按钮为分享状态按钮
                    btn.outerHTML = '<button class="btn btn-outline btn-share" onclick="copyShareLink()" id="shareBtn">🔗 复制分享链接</button> <button class="btn btn-outline btn-sm" onclick="cancelShareLink()" title="取消分享">✕</button>';
                } else {
                    alert(data.message || '操作失败');
                    if (btn) { btn.disabled = false; btn.textContent = '🔗 生成分享链接'; }
                }
            })
            .catch(function(err) {
                alert('请求失败: ' + (err.message || '请检查网络连接'));
                console.error(err);
                if (btn) { btn.disabled = false; btn.textContent = '🔗 生成分享链接'; }
            });
    };

    window.copyShareLink = function() {
        var input = document.getElementById('shareLinkInput');
        if (!input.value) return;
        input.select();
        document.execCommand('copy');
        // 简单的复制提示
        var btn = document.getElementById('shareBtn');
        if (btn) {
            var origText = btn.textContent;
            btn.textContent = '✅ 已复制！';
            setTimeout(function() { btn.textContent = origText; }, 2000);
        }
        document.getElementById('shareModal').style.display = 'none';
    };

    window.cancelShareLink = function() {
        document.getElementById('cancelShareModal').style.display = 'flex';
    };
    window.confirmCancelShare = function() {
        document.getElementById('cancelShareModal').style.display = 'none';
        var csrfEl = document.querySelector('input[name="csrf_token"]');
        if (!csrfEl) { alert('CSRF token missing'); return; }
        var formData = new FormData();
        formData.append('action', 'cancel_share');
        formData.append('csrf_token', csrfEl.value);
        fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) location.reload();
                else alert(data.message || '操作失败');
            })
            .catch(function(err) {
                alert('请求失败');
                console.error(err);
            });
    };
    window.closeCancelShareModal = function() {
        document.getElementById('cancelShareModal').style.display = 'none';
    };

    // 如果有分享链接且有分享弹窗输入框，填入值
    <?php if ($shareUrl): ?>
    document.getElementById('shareLinkInput').value = <?php echo json_encode($shareUrl); ?>;
    <?php endif; ?>

    // 点击遮罩关闭分享弹窗
    document.getElementById('shareModal').addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

    // 点击遮罩关闭取消分享弹窗
    var cancelM = document.getElementById('cancelShareModal');
    if (cancelM) {
        cancelM.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    }

    // 初始显示缩放控件和搜索框（如果在树视图）
    if (document.getElementById('tree-view').style.display !== 'none') {
        document.getElementById('zoomControls').style.display = '';
        document.getElementById('treeSearchBox').style.display = '';
    }

    applyTransform();
})();

// 从缩略图滚动到世系图并高亮成员
function scrollToMember(mid) {
    // 折叠缩略图
    var thumb = document.querySelector('.tree-thumb');
    if (thumb) thumb.classList.add('collapsed');

    // 滚动到世系图
    var treeSec = document.querySelector('.family-tree-section');
    if (treeSec) {
        treeSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // 高亮目标成员
    setTimeout(function() {
        var node = document.querySelector('.tree-node[data-member-id="' + mid + '"]');
        if (!node) node = document.querySelector('.member-card-' + mid);
        if (node) {
            node.scrollIntoView({ behavior: 'smooth', block: 'center' });
            node.classList.add('highlight-blink');
            setTimeout(function() { node.classList.remove('highlight-blink'); }, 2500);
        }
    }, 500);
}
</script>
