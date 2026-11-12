<?php
/**
 * 后台 - 网站设置
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
        $settings = [
            'site_name'        => post_param('site_name'),
            'site_subtitle'    => post_param('site_subtitle'),
            'site_keywords'    => post_param('site_keywords'),
            'site_description' => post_param('site_description'),
            'site_icp'         => post_param('site_icp'),
            'site_footer'      => post_param('site_footer'),
            'logo_text'        => post_param('logo_text'),
            'primary_color'    => post_param('primary_color'),
            'allow_register'   => post_param('allow_register', '0'),
            'captcha_enabled'  => post_param('captcha_enabled', '0'),
            'print_header'     => post_param('print_header'),
            'about_info'       => post_param('about_info'),
            'hero_title'       => post_param('hero_title'),
            'hero_subtitle'    => post_param('hero_subtitle'),
            'hero_search_placeholder' => post_param('hero_search_placeholder'),
            'about_title'      => post_param('about_title'),
            'about_content'    => post_param('about_content'),
            'agreement_content' => $_POST['agreement_content'] ?? '',
            'privacy_content'  => $_POST['privacy_content'] ?? '',
            'smtp_host'        => post_param('smtp_host'),
            'smtp_port'        => post_param('smtp_port', '587'),
            'smtp_user'        => post_param('smtp_user'),
            'smtp_pass'        => post_param('smtp_pass'),
            'smtp_encryption'  => post_param('smtp_encryption', 'tls'),
            'smtp_from_name'   => post_param('smtp_from_name', SITE_NAME),
            'smtp_from_email'  => post_param('smtp_from_email'),
            'email_welcome_subject' => post_param('email_welcome_subject'),
            'email_welcome_body'    => $_POST['email_welcome_body'] ?? '',
            'email_reset_subject'   => post_param('email_reset_subject'),
            'email_reset_body'      => $_POST['email_reset_body'] ?? '',
        ];

        // 长度校验
        $errors = [];
        if (mb_strlen($settings['site_name']) > 100) $errors[] = '网站名称不能超过100字符';
        if (mb_strlen($settings['site_subtitle']) > 200) $errors[] = '副标题不能超过200字符';
        if (mb_strlen($settings['site_keywords']) > 200) $errors[] = '关键词不能超过200字符';

        if (empty($errors)) {
            $changed = false;
            foreach ($settings as $key => $value) {
                $old = get_site_setting($key);
                if ($old !== $value) {
                    set_site_setting($key, $value);
                    $changed = true;
                }
            }

            Logger::info('网站设置', '管理员更新网站设置', Auth::getUserId());
            $success = $changed ? '设置已保存' : '未做任何修改';
        } else {
            $error = implode('；', $errors);
        }
    }
}

$pageTitle = '网站设置';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>网站设置</h1>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form method="post" class="admin-card">
            <?php echo csrf_field(); ?>
            <div class="admin-card-header"><h3>基本设置</h3></div>
            <div class="admin-card-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label>网站名称</label>
                        <input type="text" name="site_name" maxlength="100"
                               value="<?php echo e(get_site_setting('site_name', '族谱传承')); ?>">
                    </div>
                    <div class="form-group">
                        <label>网站副标题</label>
                        <input type="text" name="site_subtitle"
                               value="<?php echo e(get_site_setting('site_subtitle')); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>SEO关键词</label>
                    <input type="text" name="site_keywords"
                           value="<?php echo e(get_site_setting('site_keywords')); ?>"
                           placeholder="多个关键词用英文逗号分隔">
                </div>
                <div class="form-group">
                    <label>SEO描述</label>
                    <textarea name="site_description" rows="2"><?php echo e(get_site_setting('site_description')); ?></textarea>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Logo文字</label>
                        <input type="text" name="logo_text" maxlength="2"
                               value="<?php echo e(get_site_setting('logo_text', '族')); ?>">
                        <p class="help-text">导航栏Logo显示的单字</p>
                    </div>
                    <div class="form-group">
                        <label>主题色</label>
                        <input type="color" name="primary_color"
                               value="<?php echo e(get_site_setting('primary_color', '#8B4513')); ?>"
                               style="height:40px;cursor:pointer;">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>ICP备案号</label>
                        <input type="text" name="site_icp"
                               value="<?php echo e(get_site_setting('site_icp')); ?>">
                    </div>
                    <div class="form-group">
                        <label>是否开放注册</label>
                        <select name="allow_register">
                            <option value="1" <?php echo get_site_setting('allow_register', '1') === '1' ? 'selected' : ''; ?>>开放注册</option>
                            <option value="0" <?php echo get_site_setting('allow_register', '1') === '0' ? 'selected' : ''; ?>>关闭注册</option>
                        </select>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>登录验证码</label>
                        <select name="captcha_enabled">
                            <option value="1" <?php echo get_site_setting('captcha_enabled', '0') === '1' ? 'selected' : ''; ?>>开启验证码</option>
                            <option value="0" <?php echo get_site_setting('captcha_enabled', '0') !== '1' ? 'selected' : ''; ?>>关闭验证码</option>
                        </select>
                        <p class="help-text">开启后登录页需要输入验证码</p>
                    </div>
                </div>
                <div class="form-group">
                    <label>底部版权信息</label>
                    <input type="text" name="site_footer"
                           value="<?php echo e(get_site_setting('site_footer')); ?>">
                </div>
                <div class="form-group">
                    <label>打印页面顶部标题</label>
                    <input type="text" name="print_header"
                           value="<?php echo e(get_site_setting('print_header')); ?>"
                           placeholder="留空则使用网站名称">
                    <p class="help-text">导出PDF时显示在顶部的标题</p>
                </div>
                <div class="form-group">
                    <label>关于信息（打印页底部）</label>
                    <textarea name="about_info" rows="2"
                              placeholder="自定义关于信息，将显示在打印页底部"><?php echo e(get_site_setting('about_info')); ?></textarea>
                    <p class="help-text">可在打印/分享页面底部显示，支持简短介绍</p>
                </div>
            </div>

            <!-- ====== 首页内容设置 ====== -->
            <div class="admin-card-header"><h3>首页内容设置</h3></div>
            <div class="admin-card-body">
                <div class="form-group">
                    <label>Hero 大标题</label>
                    <input type="text" name="hero_title"
                           value="<?php echo e(get_site_setting('hero_title')); ?>"
                           placeholder="家之有谱 犹国之有史也">
                </div>
                <div class="form-group">
                    <label>Hero 副标题/描述</label>
                    <textarea name="hero_subtitle" rows="2"
                              placeholder="传承中华文化，弘扬优良家风..."><?php echo e(get_site_setting('hero_subtitle')); ?></textarea>
                </div>
                <div class="form-group">
                    <label>搜索框提示文字</label>
                    <input type="text" name="hero_search_placeholder"
                           value="<?php echo e(get_site_setting('hero_search_placeholder')); ?>"
                           placeholder="搜索姓氏或家谱名称...">
                </div>
                <div class="form-group">
                    <label>关于区域标题</label>
                    <input type="text" name="about_title"
                           value="<?php echo e(get_site_setting('about_title')); ?>"
                           placeholder="关于族谱传承">
                </div>
                <div class="form-group">
                    <label>关于区域内容</label>
                    <textarea name="about_content" rows="5"
                              placeholder="族谱，又称家谱..."><?php echo e(get_site_setting('about_content')); ?></textarea>
                    <p class="help-text">每行一段文字</p>
                </div>
            </div>

            <!-- ====== 协议/隐私政策内容 ====== -->
            <div class="admin-card-header"><h3>用户协议 & 隐私政策</h3></div>
            <div class="admin-card-body">
                <div class="form-group">
                    <label>用户协议内容（支持HTML）</label>
                    <textarea name="agreement_content" rows="8" style="font-family:monospace;"
                              placeholder="使用 &lt;h3&gt;标题&lt;/h3&gt;&lt;p&gt;段落&lt;/p&gt; 格式"><?php echo e(get_site_setting('agreement_content')); ?></textarea>
                </div>
                <div class="form-group">
                    <label>隐私政策内容（支持HTML）</label>
                    <textarea name="privacy_content" rows="8" style="font-family:monospace;"
                              placeholder="使用 &lt;h3&gt;标题&lt;/h3&gt;&lt;p&gt;段落&lt;/p&gt; 格式"><?php echo e(get_site_setting('privacy_content')); ?></textarea>
                </div>
            </div>
            <div class="admin-card-footer">
                <!-- 邮箱SMTP配置 -->
                <div class="admin-card" style="margin-bottom:20px;">
                    <div class="admin-card-header"><h3>📧 邮箱SMTP配置</h3></div>
                    <div class="admin-card-body">
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>SMTP服务器</label>
                                <input type="text" name="smtp_host" placeholder="如 smtp.qq.com"
                                       value="<?php echo e(get_site_setting('smtp_host')); ?>">
                            </div>
                            <div class="form-group">
                                <label>端口</label>
                                <input type="number" name="smtp_port" placeholder="587"
                                       value="<?php echo e(get_site_setting('smtp_port', '587')); ?>">
                            </div>
                        </div>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>邮箱账号</label>
                                <input type="text" name="smtp_user" placeholder="如 your@qq.com"
                                       value="<?php echo e(get_site_setting('smtp_user')); ?>">
                            </div>
                            <div class="form-group">
                                <label>授权码/密码</label>
                                <input type="password" name="smtp_pass" placeholder="QQ邮箱请使用授权码"
                                       value="<?php echo e(get_site_setting('smtp_pass')); ?>">
                            </div>
                        </div>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>加密方式</label>
                                <select name="smtp_encryption">
                                    <option value="tls" <?php echo get_site_setting('smtp_encryption', 'tls') === 'tls' ? 'selected' : ''; ?>>TLS (推荐)</option>
                                    <option value="ssl" <?php echo get_site_setting('smtp_encryption') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>发件人名称</label>
                                <input type="text" name="smtp_from_name" placeholder="如 族谱传承"
                                       value="<?php echo e(get_site_setting('smtp_from_name', SITE_NAME)); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>发件人邮箱</label>
                            <input type="email" name="smtp_from_email" placeholder="显示给收件人的邮箱地址"
                                   value="<?php echo e(get_site_setting('smtp_from_email')); ?>">
                        </div>
                        <p class="help-text">
                            💡 QQ邮箱：smtp.qq.com:587(TLS)，密码填授权码（QQ邮箱设置→账户→POP3/SMTP→生成授权码）<br>
                            💡 163邮箱：smtp.163.com:465(SSL)，密码填授权码
                        </p>
                    </div>
                </div>

                <!-- 邮件模板配置 -->
                <div class="admin-card" style="margin-bottom:20px;">
                    <div class="admin-card-header"><h3>📨 邮件模板</h3></div>
                    <div class="admin-card-body">
                        <div class="form-group">
                            <label>注册欢迎邮件主题</label>
                            <input type="text" name="email_welcome_subject" placeholder="如 欢迎加入{site_name}"
                                   value="<?php echo e(get_site_setting('email_welcome_subject', '欢迎加入{site_name}——族谱传承平台')); ?>">
                        </div>
                        <div class="form-group">
                            <label>注册欢迎邮件内容</label>
                            <textarea name="email_welcome_body" rows="6"
                                      placeholder="支持变量: {username}用户名 {email}邮箱 {site_name}网站名 {site_url}网址"><?php echo e(get_site_setting('email_welcome_body', '<h3>亲爱的{username}：</h3><p>欢迎加入{site_name}！</p><p>您的账号已注册成功，现在可以开始创建家谱、记录家族历史了。</p><p><a href="{site_url}">立即进入 >></a></p><p>如有问题请回复此邮件。</p>')); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>找回密码邮件主题</label>
                            <input type="text" name="email_reset_subject" placeholder="如 {site_name} - 密码重置验证码"
                                   value="<?php echo e(get_site_setting('email_reset_subject', '{site_name}——密码重置验证码')); ?>">
                        </div>
                        <div class="form-group">
                            <label>找回密码邮件内容</label>
                            <textarea name="email_reset_body" rows="6"
                                      placeholder="变量: {username}用户名 {code}验证码 {expire}有效期(分钟) {site_name}"><?php echo e(get_site_setting('email_reset_body', '<h3>{username} 您好：</h3><p>您正在重置密码，验证码为：</p><h2 style="text-align:center;color:#8B4513;">{code}</h2><p>有效期 {expire} 分钟，请勿泄露。</p>')); ?></textarea>
                        </div>
                        <p class="help-text">💡 可用变量：{username}、{email}、{site_name}、{site_url}、{code}（验证码）、{expire}（有效期分钟数）</p>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">保存设置</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
