<?php
/**
 * 后台 - 首页独立设置页面
 */
require_once __DIR__ . '/../includes/header_pre.php';
Auth::requireAdmin();

$db = Database::getInstance();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '非法请求';
    } else {
        $action = post_param('action');

        if ($action === 'save_settings') {
            // 关键修复：只保存实际 POST 提交的字段，不覆盖未提交的 Tab 字段
            // 之前的问题是每个 Tab 保存时会把其他 Tab 的字段用默认值覆盖，导致互相清空
            $settings = [];
            foreach ($_POST as $key => $val) {
                // 跳过非设置字段
                if ($key === 'action' || $key === 'csrf_token') continue;
                // 只处理首页相关和配色设置（防止意外写入其他字段）
                if (strpos($key, 'home_') === 0 || $key === 'site_primary_color' || $key === 'site_accent_color') {
                    // 取色器的 text 同步字段跳过（只取 color input 的值）
                    if (substr($key, -5) === '_text') continue;
                    $settings[$key] = is_array($val) ? implode(',', $val) : trim($val);
                }
            }

            if (empty($settings)) {
                $error = '没有检测到任何提交的数据，请确认表单填写完整';
            } else {
                foreach ($settings as $key => $val) {
                    $db->execute(
                        "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                        [$key, $val]
                    );
                }
                Logger::info('首页设置', '更新首页设置', Auth::getUserId());
                $success = '首页设置已保存';
            }
        }

        elseif ($action === 'save_slide') {
            $id = (int)post_param('id', 0);
            $title = trim(post_param('slide_title'));
            $subtitle = trim(post_param('slide_subtitle'));
            $image = trim(post_param('slide_image'));
            $linkUrl = trim(post_param('slide_link_url'));
            $linkText = trim(post_param('slide_link_text'));
            $sortOrder = (int)post_param('slide_sort', 0);
            $isActive = post_param('slide_active', '1') === '1' ? 1 : 0;

            if ($id > 0) {
                $db->execute(
                    "UPDATE slides SET title=?, subtitle=?, image=?, link_url=?, link_text=?, sort_order=?, is_active=? WHERE id=?",
                    [$title, $subtitle, $image, $linkUrl, $linkText, $sortOrder, $isActive, $id]
                );
                $success = '幻灯片已更新';
            } else {
                $db->execute(
                    "INSERT INTO slides (title, subtitle, image, link_url, link_text, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$title, $subtitle, $image, $linkUrl, $linkText, $sortOrder, $isActive]
                );
                $success = '幻灯片已添加';
            }
        }

        elseif ($action === 'delete_slide') {
            $id = (int)post_param('id', 0);
            $db->execute("DELETE FROM slides WHERE id = ?", [$id]);
            $success = '幻灯片已删除';
        }

        // 重置核心价值观为预设内容
        elseif ($action === 'reset_values') {
            $presets = [
                ['icon' => '🇨🇳', 'title' => '富强', 'desc' => '国家繁荣昌盛，人民幸福安康，家族兴旺发达'],
                ['icon' => '🏛️', 'title' => '民主', 'desc' => '人人平等参与，尊重每个家庭成员的意见与选择'],
                ['icon' => '📚', 'title' => '文明', 'desc' => '传承优良家风，弘扬中华优秀传统文化与美德'],
                ['icon' => '🕊️', 'title' => '和谐', 'desc' => '家庭和睦邻里互助，建立温暖有爱的家族纽带'],
                ['icon' => '❤️', 'title' => '爱国', 'desc' => '热爱祖国，热爱人民，维护国家统一和民族团结'],
                ['icon' => '💼', 'title' => '敬业', 'desc' => '爱岗敬业，尽职尽责，精益求精追求卓越'],
                ['icon' => '🤝', 'title' => '诚信', 'desc' => '诚实守信，言行一致，重信守约说到做到'],
                ['icon' => '😊', 'title' => '友善', 'desc' => '与人为善，助人为乐，和睦邻里互敬互爱'],
            ];
            for ($i = 1; $i <= 8; $i++) {
                $p = $presets[$i - 1];
                $db->execute("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ["home_value{$i}_icon", $p['icon']]);
                $db->execute("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ["home_value{$i}_title", $p['title']]);
                $db->execute("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ["home_value{$i}_desc", $p['desc']]);
            }
            $success = '核心价值观已重置为预设内容';
        }
    }
}

// 读取当前设置
$settings = [];
$res = $db->fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'home_%' OR setting_key IN ('site_primary_color', 'site_accent_color')");
foreach ($res as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$slides = $db->fetchAll("SELECT * FROM slides ORDER BY sort_order ASC, id ASC");
$editingSlide = null;
$editSlideId = (int)get_param('edit_slide', 0);
if ($editSlideId > 0) {
    $editingSlide = $db->fetch("SELECT * FROM slides WHERE id = ?", [$editSlideId]);
}

$pageTitle = '首页设置';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>首页独立设置</h1>
            <span style="color:#999;font-size:13px;">自定义前台首页的所有外观和内容模块</span>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

        <!-- ====== TAB 切换 ====== -->
        <div class="tab-nav" style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #e8e8e8;">
            <a href="#tab-general" class="tab-link active" onclick="switchTab(event,'tab-general')"
               style="padding:10px 20px;text-decoration:none;color:inherit;border-bottom:2px solid transparent;margin-bottom:-2px;font-weight:bold;">📐 外观布局</a>
            <a href="#tab-slides" class="tab-link" onclick="switchTab(event,'tab-slides')"
               style="padding:10px 20px;text-decoration:none;color:inherit;border-bottom:2px solid transparent;margin-bottom:-2px;">🖼️ 轮播管理</a>
            <a href="#tab-modules" class="tab-link" onclick="switchTab(event,'tab-modules')"
               style="padding:10px 20px;text-decoration:none;color:inherit;border-bottom:2px solid transparent;margin-bottom:-2px;">📋 模块开关</a>
            <a href="#tab-content" class="tab-link" onclick="switchTab(event,'tab-content')"
               style="padding:10px 20px;text-decoration:none;color:inherit;border-bottom:2px solid transparent;margin-bottom:-2px;">📝 页面内容</a>
        </div>

        <!-- ====== TAB: 外观布局 ====== -->
        <div class="tab-content active" id="tab-general">
            <div class="admin-card">
                <div class="admin-card-body">
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_settings">

                        <h3 style="margin:0 0 16px;">Hero 英雄区设置</h3>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>主标题</label>
                                <input type="text" name="home_hero_title"
                                       value="<?php echo e($settings['home_hero_title'] ?? '族谱传承 · 家谱管理系统'); ?>">
                            </div>
                            <div class="form-group">
                                <label>副标题</label>
                                <input type="text" name="home_hero_subtitle"
                                       value="<?php echo e($settings['home_hero_subtitle'] ?? '凡国必有史，有家必有谱。数字化传承家族记忆，让每一份血脉相连永不遗忘。'); ?>">
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label>背景图 URL</label>
                                <input type="text" name="home_hero_bg" id="heroBgInput"
                                       placeholder="留空使用默认渐变 /uploads/xxx.jpg"
                                       value="<?php echo e($settings['home_hero_bg'] ?? ''); ?>">
                                <p class="help-text">支持上传到 uploads/ 目录后引用，或填写外部URL</p>
                            </div>
                            <div class="form-group">
                                <label>遮罩颜色</label>
                                <input type="text" name="home_hero_bg_overlay"
                                       placeholder="rgba(20,15,10,0.55)"
                                       value="<?php echo e($settings['home_hero_bg_overlay'] ?? 'rgba(20,15,10,0.55)'); ?>">
                                <p class="help-text">深色遮罩让文字更清晰，如 rgba(0,0,0,0.6)</p>
                            </div>
                        </div>

                        <h3 style="margin:20px 0 16px;">🎨 配色方案</h3>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>主题色</label>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="color" name="site_primary_color" id="site_primary_color" value="<?php echo e($settings['site_primary_color'] ?? '#2d1f10'); ?>"
                                           style="width:48px;height:36px;border:1px solid #d0c5b5;border-radius:4px;cursor:pointer;"
                                           oninput="document.getElementById('site_primary_color_text').value = this.value">
                                    <input type="text" name="site_primary_color_text" id="site_primary_color_text" value="<?php echo e($settings['site_primary_color'] ?? '#2d1f10'); ?>"
                                           style="flex:1;" oninput="document.getElementById('site_primary_color').value = this.value">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>强调色</label>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="color" name="site_accent_color" id="site_accent_color" value="<?php echo e($settings['site_accent_color'] ?? '#c4982e'); ?>"
                                           style="width:48px;height:36px;border:1px solid #d0c5b5;border-radius:4px;cursor:pointer;"
                                           oninput="document.getElementById('site_accent_color_text').value = this.value">
                                    <input type="text" name="site_accent_color_text" id="site_accent_color_text" value="<?php echo e($settings['site_accent_color'] ?? '#c4982e'); ?>"
                                           style="flex:1;" oninput="document.getElementById('site_accent_color').value = this.value">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">保存外观设置</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ====== TAB: 轮播管理 ====== -->
        <div class="tab-content" id="tab-slides" style="display:none;">
            <!-- 新增/编辑轮播 -->
            <div class="admin-card" style="margin-bottom:20px;">
                <div class="admin-card-header">
                    <h3><?php echo $editingSlide ? '编辑幻灯片' : '新增幻灯片'; ?></h3>
                </div>
                <div class="admin-card-body">
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_slide">
                        <?php if ($editingSlide): ?><input type="hidden" name="id" value="<?php echo $editingSlide['id']; ?>"><?php endif; ?>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label>标题</label>
                                <input type="text" name="slide_title"
                                       value="<?php echo e($editingSlide ? $editingSlide['title'] : ''); ?>"
                                       placeholder="如：传承家族历史">
                            </div>
                            <div class="form-group">
                                <label>副标题</label>
                                <input type="text" name="slide_subtitle"
                                       value="<?php echo e($editingSlide ? $editingSlide['subtitle'] : ''); ?>">
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label>图片路径</label>
                                <input type="text" name="slide_image"
                                       value="<?php echo e($editingSlide ? $editingSlide['image'] : ''); ?>"
                                       placeholder="留空使用主题渐变 /uploads/slide.jpg">
                            </div>
                            <div class="form-group">
                                <label>排序</label>
                                <input type="number" name="slide_sort" min="0"
                                       value="<?php echo e($editingSlide ? $editingSlide['sort_order'] : '0'); ?>">
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label>链接地址</label>
                                <input type="text" name="slide_link_url"
                                       value="<?php echo e($editingSlide ? $editingSlide['link_url'] : ''); ?>"
                                       placeholder="如：/family.php">
                            </div>
                            <div class="form-group">
                                <label>链接文字</label>
                                <input type="text" name="slide_link_text"
                                       value="<?php echo e($editingSlide ? $editingSlide['link_text'] : ''); ?>"
                                       placeholder="如：了解更多">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>状态</label>
                            <select name="slide_active">
                                <option value="1" <?php echo !$editingSlide || $editingSlide['is_active'] ? 'selected' : ''; ?>>启用</option>
                                <option value="0" <?php echo ($editingSlide && !$editingSlide['is_active']) ? 'selected' : ''; ?>>禁用</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary"><?php echo $editingSlide ? '更新' : '添加'; ?></button>
                        <?php if ($editingSlide): ?>
                            <a href="homepage.php" class="btn btn-outline">取消编辑</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- 轮播列表 -->
            <div class="admin-card">
                <div class="admin-card-header"><h3>幻灯片列表（<?php echo count($slides); ?>张）</h3></div>
                <div class="admin-card-body" style="padding:0;">
                    <?php if (empty($slides)): ?>
                        <p style="padding:20px;color:#999;">暂无幻灯片，请添加上方的表单</p>
                    <?php else: ?>
                    <div class="table-wrap">
                    <table class="table">
                        <thead><tr>
                            <th>排序</th><th>标题</th><th>图片</th><th>链接</th><th>状态</th><th>操作</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($slides as $s): ?>
                        <tr>
                            <td><?php echo $s['sort_order']; ?></td>
                            <td><strong><?php echo e($s['title'] ?: '（无标题）'); ?></strong></td>
                            <td><code style="font-size:11px;"><?php echo e($s['image'] ?: '默认'); ?></code></td>
                            <td><?php echo $s['link_url'] ? '<span style="color:green;">已设置</span>' : '-'; ?></td>
                            <td><span class="badge badge-<?php echo $s['is_active'] ? 'success' : 'danger'; ?>"><?php echo $s['is_active'] ? '启用' : '禁用'; ?></span></td>
                            <td>
                                <a href="?tab=slides&edit_slide=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline">编辑</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('确定删除？')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_slide">
                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
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

        <!-- ====== TAB: 模块开关 ====== -->
        <div class="tab-content" id="tab-modules" style="display:none;">
            <div class="admin-card">
                <div class="admin-card-body">
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_settings">

                        <h3 style="margin:0 0 16px;">📋 首页模块开关</h3>

                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:20px;">
                            <?php
                            $toggles = [
                                'home_show_banner'   => ['📽️', '轮播 Banner', '1'],
                                'home_show_search'   => ['🔍', '搜索框', '1'],
                                'home_show_stats'    => ['📊', '统计数字', '1'],
                                'home_show_features' => ['⚡', '功能卡片', '1'],
                                'home_show_articles' => ['📝', '文章列表', '1'],
                                'home_show_about'    => ['📖', '网站介绍区', '1'],
                                'home_show_values'   => ['💎', '核心价值观卡片', '1'],
                            ];
                            foreach ($toggles as $key => $info):
                                $val = $settings[$key] ?? $info[2];
                            ?>
                            <label style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:#fafafa;border-radius:8px;cursor:pointer;">
                                <span style="font-size:20px;"><?php echo $info[0]; ?></span>
                                <span style="flex:1;"><?php echo $info[1]; ?></span>
                                <input type="hidden" name="<?php echo $key; ?>" value="0">
                                <input type="checkbox" name="<?php echo $key; ?>" value="1"
                                       <?php echo $val === '1' ? 'checked' : ''; ?>
                                       onchange="this.previousElementSibling.value=this.checked?'1':'0'"
                                       style="width:18px;height:18px;">
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <h3 style="margin:16px 0;">区域标题自定义</h3>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>功能卡片区标题</label>
                                <input type="text" name="home_features_title"
                                       value="<?php echo e($settings['home_features_title'] ?? '核心功能'); ?>">
                            </div>
                            <div class="form-group">
                                <label>文章区标题</label>
                                <input type="text" name="home_articles_title"
                                       value="<?php echo e($settings['home_articles_title'] ?? '家族资讯'); ?>">
                            </div>
                        </div>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>统计区标题</label>
                                <input type="text" name="home_stats_title"
                                       value="<?php echo e($settings['home_stats_title'] ?? '平台数据'); ?>">
                            </div>
                            <div class="form-group">
                                <label>首页文章数量</label>
                                <input type="number" name="home_articles_count" min="1" max="20"
                                       value="<?php echo e($settings['home_articles_count'] ?? '6'); ?>">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">保存模块设置</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ====== TAB: 页面内容 ====== -->
        <div class="tab-content" id="tab-content" style="display:none;">
            <div class="admin-card">
                <div class="admin-card-body">
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_settings">

                        <h3 style="margin:0 0 16px;">📖 网站介绍区</h3>
                        <div class="form-group">
                            <label>介绍区标题</label>
                            <input type="text" name="home_about_title"
                                   value="<?php echo e($settings['home_about_title'] ?? '关于族谱传承'); ?>">
                        </div>
                        <div class="form-group">
                            <label>介绍区内容 (HTML)</label>
                            <textarea name="home_about_content" rows="6"
                                      placeholder="支持HTML格式"><?php echo e($settings['home_about_content'] ?? '<p style="font-size:15px;line-height:1.8;color:#555;">族谱传承是一个专注于家族文化数字化的在线平台。我们致力于帮助每一个家庭建立、管理和传承属于自己的家谱，让珍贵的家族记忆以数字化形式永久保存。</p>
<p style="font-size:15px;line-height:1.8;color:#555;margin-top:10px;">无论您的家族人口众多还是刚刚起步，我们都提供简单易用的工具，让您轻松记录先人信息、梳理世系关系、编目成书导出分享。传承家风，从这里开始。</p>'); ?></textarea>
                        </div>

                        <h3 style="margin:20px 0 16px;">💎 核心价值观卡片</h3>
                        <p style="color:#999;font-size:13px;margin-bottom:16px;">4张卡片展示社会核心价值理念</p>

                        <?php for ($i = 1; $i <= 8; $i++):
                            $iconDefaults  = ['', '🇨🇳', '🏛️', '📚', '🕊️', '❤️', '💼', '🤝', '😊'];
                            $titleDefaults = ['', '富强', '民主', '文明', '和谐', '爱国', '敬业', '诚信', '友善'];
                            $descDefaults  = ['',
                                '国家繁荣昌盛，人民幸福安康，家族兴旺发达',
                                '人人平等参与，尊重每个家庭成员的意见与选择',
                                '传承优良家风，弘扬中华优秀传统文化与美德',
                                '家庭和睦邻里互助，建立温暖有爱的家族纽带',
                                '热爱祖国，热爱人民，维护国家统一和民族团结',
                                '爱岗敬业，尽职尽责，精益求精追求卓越',
                                '诚实守信，言行一致，重信守约说到做到',
                                '与人为善，助人为乐，和睦邻里互敬互爱',
                            ];
                            // 如果数据库存的是测试值，显示预设值
                            $vIcon  = $settings["home_value{$i}_icon"] ?? '';
                            $vTitle = $settings["home_value{$i}_title"] ?? '';
                            $vDesc  = $settings["home_value{$i}_desc"] ?? '';
                            if ($vIcon === '' || $vIcon === '测试' || mb_strlen($vIcon) > 4) $vIcon = $iconDefaults[$i];
                            if ($vTitle === '' || $vTitle === '测试' || mb_strlen($vTitle) > 6) $vTitle = $titleDefaults[$i];
                            if ($vDesc === '' || $vDesc === '测试') $vDesc = $descDefaults[$i];
                        ?>
                        <div style="background:#fafafa;border-radius:8px;padding:16px;margin-bottom:12px;border-left:3px solid var(--accent, #c4982e);">
                            <strong style="font-size:14px;">卡片 <?php echo $i; ?></strong>
                            <div style="margin-top:8px;">
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label>图标（emoji）</label>
                                    <input type="text" name="home_value<?php echo $i; ?>_icon" maxlength="8"
                                           value="<?php echo e($vIcon); ?>"
                                           placeholder="如：🇨🇳"
                                           style="font-size:22px;width:80px;text-align:center;">
                                </div>
                                <div class="form-row-2">
                                    <div class="form-group">
                                        <label>标题</label>
                                        <input type="text" name="home_value<?php echo $i; ?>_title"
                                               value="<?php echo e($vTitle); ?>"
                                               placeholder="如：富强">
                                    </div>
                                    <div class="form-group">
                                        <label>描述</label>
                                        <input type="text" name="home_value<?php echo $i; ?>_desc"
                                               value="<?php echo e($vDesc); ?>"
                                               placeholder="卡片描述文字">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>

                        <button type="submit" class="btn btn-primary">保存页面内容</button>
                        <button type="submit" class="btn btn-warning" name="action" value="reset_values" style="margin-left:8px;" onclick="return confirm('确认重置为核心价值观预设内容？')">🔄 重置为预设</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(e, tabId) {
    e.preventDefault();
    document.querySelectorAll('.tab-link').forEach(function(t) { t.classList.remove('active'); t.style.color = ''; t.style.borderBottomColor = 'transparent'; });
    e.target.classList.add('active');
    e.target.style.color = 'var(--accent, #c4982e)';
    e.target.style.borderBottomColor = 'var(--accent, #c4982e)';
    document.querySelectorAll('.tab-content').forEach(function(t) { t.style.display = 'none'; });
    document.getElementById(tabId).style.display = '';
}
// 初始化 tab
(function() {
    var hash = window.location.hash || '#tab-general';
    var link = document.querySelector('.tab-link[href="' + hash + '"]');
    if (link) link.click();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
