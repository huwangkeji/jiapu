<?php
/**
 * 后台 - UCenter 2.0 账号互通
 */
require_once __DIR__ . '/../includes/header_pre.php';
require_once __DIR__ . '/../includes/UCenter.php';
require_once __DIR__ . '/../includes/UCenterBridge.php';
Auth::requireAdmin();

$db = Database::getInstance();

$message = '';
$messageType = 'success';
$testResult = null;
$importResult = null;

// ── 处理动作 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $message = '非法请求，请刷新页面重试';
        $messageType = 'error';
    } else {
        $do = post_param('do');

        if ($do === 'save') {
            $pairs = [
                'uc_enabled'        => isset($_POST['uc_enabled']) ? 'true' : 'false',
                'uc_api'            => rtrim(trim(post_param('uc_api')), '/'),
                'uc_ip'             => trim(post_param('uc_ip')),
                'uc_key'            => trim(post_param('uc_key')),
                'uc_appid'          => (string)(int)post_param('uc_appid', 0),
                'uc_connect'        => post_param('uc_connect') === 'mysql' ? 'mysql' : 'post',
                'uc_charset'        => trim(post_param('uc_charset', 'utf-8')),
                'uc_dbhost'         => trim(post_param('uc_dbhost')),
                'uc_dbuser'         => trim(post_param('uc_dbuser')),
                'uc_dbpw'           => (string)post_param('uc_dbpw'),
                'uc_dbname'         => trim(post_param('uc_dbname')),
                'uc_dbcharset'      => trim(post_param('uc_dbcharset', 'utf8')),
                'uc_dbtablepre'     => trim(post_param('uc_dbtablepre', '`ucenter`.uc_')),
                'uc_sync_register'  => isset($_POST['uc_sync_register']) ? '1' : '0',
                'uc_sync_login'     => isset($_POST['uc_sync_login']) ? '1' : '0',
                'uc_sync_profile'   => isset($_POST['uc_sync_profile']) ? '1' : '0',
                'uc_sync_password'  => isset($_POST['uc_sync_password']) ? '1' : '0',
                'uc_allow_uc_login' => isset($_POST['uc_allow_uc_login']) ? '1' : '0',
                'uc_auto_create'    => isset($_POST['uc_auto_create']) ? '1' : '0',
            ];

            if ($pairs['uc_enabled'] === 'true') {
                if ($pairs['uc_api'] === '') {
                    $message = '启用时 UCenter 接口地址(UC_API)不能为空';
                    $messageType = 'error';
                } elseif (strlen($pairs['uc_key']) < 8) {
                    $message = '通信密钥(UC_KEY)至少需要 8 位';
                    $messageType = 'error';
                } elseif ((int)$pairs['uc_appid'] <= 0) {
                    $message = '应用 ID(UC_APPID)必须为正整数';
                    $messageType = 'error';
                }
            }

            if (!$message) {
                UCenter::saveConfig($pairs);
                Logger::info('UCenter', '更新 UCenter 互通配置', Auth::getUserId());
                $message = '配置已保存';
                $messageType = 'success';
            }
        } elseif ($do === 'test') {
            $testResult = UCenter::testConnection();
            Logger::info('UCenter', '测试连通性：' . ($testResult['success'] ? '成功' : '失败'), Auth::getUserId());
        } elseif ($do === 'import') {
            $limit = (int)post_param('import_limit', 500);
            $limit = max(1, min(5000, $limit));
            $importResult = UCenterBridge::importFromUc($limit);
        } elseif ($do === 'rebind') {
            // 按用户名反查并补齐 uc_uid 绑定
            $username = trim(post_param('username'));
            if ($username === '') {
                $message = '请输入用户名';
                $messageType = 'error';
            } else {
                $info = UCenter::userLogin($username, '', 0);
                $ucUid = (int)($info[0] ?? 0);
                $user = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
                if ($ucUid > 0 && $user) {
                    UCenterBridge::bindUcUid($user['id'], $ucUid);
                    $message = "已绑定：{$username} → UCenter UID {$ucUid}";
                    $messageType = 'success';
                } else {
                    $message = '绑定失败：' . ($user ? 'UCenter 中查不到该用户' : '本地不存在该用户');
                    $messageType = 'error';
                }
            }
        }
    }
}

$cfg = UCenter::config();

// 统计
$boundCount = 0;
$columnReady = false;
try {
    $columnReady = UCenterBridge::ensureColumn();
    if ($columnReady) {
        $boundCount = (int)$db->count("SELECT COUNT(*) FROM users WHERE uc_uid > 0");
    }
} catch (\Exception $e) {
    $boundCount = 0;
}

$apiUrl = rtrim((string)SITE_URL, '/') . '/api/uc.php';

$pageTitle = 'UCenter 互通';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>UCenter 2.0 账号互通</h1>
            <span class="admin-time">与 Discuz! / UCenter 实现注册、登录、资料双向同步</span>
        </div>

        <?php if ($message): ?>
            <div class="admin-card" style="margin-bottom:16px;">
                <div class="admin-card-body">
                    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'error'; ?>" style="margin:0;">
                        <?php echo e($message); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 状态概览 -->
        <div class="admin-card" style="margin-bottom:16px;">
            <div class="admin-card-header"><h3>运行状态</h3></div>
            <div class="admin-card-body">
                <table class="admin-table">
                    <tbody>
                        <tr>
                            <th style="width:180px;">互通状态</th>
                            <td>
                                <?php if (UCenter::enabled()): ?>
                                    <span class="badge badge-success">已启用</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">未启用 / 配置不完整</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>UCenter 接口地址</th>
                            <td><?php echo e((string)$cfg['uc_api']); ?></td>
                        </tr>
                        <tr>
                            <th>应用 ID</th>
                            <td><?php echo e((string)$cfg['uc_appid']); ?></td>
                        </tr>
                        <tr>
                            <th>本应用回调地址</th>
                            <td>
                                <code style="background:#f4efe6;padding:3px 8px;border-radius:4px;"><?php echo e($apiUrl); ?></code>
                                <p class="help-text" style="margin:6px 0 0;color:#999;font-size:12px;">
                                    请在 UCenter 后台「应用管理 → 编辑本应用」中把「接口 URL」填写为上面的地址，
                                    并开启「同步登录」「接受通知」。
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>uc_uid 字段</th>
                            <td>
                                <?php if ($columnReady): ?>
                                    <span class="badge badge-success">已就绪</span>
                                    已绑定 <?php echo $boundCount; ?> 位用户
                                <?php else: ?>
                                    <span class="badge badge-danger">异常</span>
                                    users 表无法创建 uc_uid 字段，请检查数据库权限或重新执行 family_tree.sql
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <form method="post" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="test">
                    <button type="submit" class="btn btn-primary btn-sm">🔌 测试 UCenter 连通性</button>
                </form>
            </div>
        </div>

        <?php if ($testResult): ?>
        <div class="admin-card" style="margin-bottom:16px;">
            <div class="admin-card-header"><h3>连通性测试结果</h3></div>
            <div class="admin-card-body">
                <div class="alert alert-<?php echo $testResult['success'] ? 'success' : 'error'; ?>">
                    <?php echo e($testResult['message']); ?>
                </div>
                <?php if (!empty($testResult['detail']) && is_array($testResult['detail'])): ?>
                    <pre style="background:#2d1f10;color:#f3e9d8;padding:14px;border-radius:8px;overflow:auto;max-height:260px;font-size:12px;"><?php
                        echo e(print_r($testResult['detail'], true));
                    ?></pre>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 配置表单 -->
        <div class="admin-card" style="margin-bottom:16px;">
            <div class="admin-card-header"><h3>连接配置</h3></div>
            <div class="admin-card-body">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="save">

                    <div class="form-group">
                        <label class="checkbox-label" style="font-weight:600;">
                            <input type="checkbox" name="uc_enabled" value="1"
                                <?php echo strtolower((string)$cfg['uc_enabled']) === 'true' ? 'checked' : ''; ?>>
                            启用 UCenter 账号互通
                        </label>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="uc_api">UCenter 接口地址 (UC_API) <span class="required">*</span></label>
                            <input type="url" id="uc_api" name="uc_api" placeholder="http://bbs.example.com/uc_server"
                                   value="<?php echo e((string)$cfg['uc_api']); ?>">
                            <p class="help-text">指向 UCenter 的 uc_server 目录，不含 index.php</p>
                        </div>
                        <div class="form-group">
                            <label for="uc_ip">UCenter 服务器 IP (UC_IP)</label>
                            <input type="text" id="uc_ip" name="uc_ip" placeholder="留空则自动解析"
                                   value="<?php echo e((string)$cfg['uc_ip']); ?>">
                            <p class="help-text">服务器无法解析域名时填写</p>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="uc_key">通信密钥 (UC_KEY) <span class="required">*</span></label>
                            <input type="text" id="uc_key" name="uc_key" autocomplete="off"
                                   value="<?php echo e((string)$cfg['uc_key']); ?>">
                            <p class="help-text">与 UCenter 应用设置中的「通信密钥」完全一致</p>
                        </div>
                        <div class="form-group">
                            <label for="uc_appid">应用 ID (UC_APPID) <span class="required">*</span></label>
                            <input type="number" id="uc_appid" name="uc_appid" min="1"
                                   value="<?php echo e((string)$cfg['uc_appid']); ?>">
                            <p class="help-text">UCenter 应用列表中本应用的 ID</p>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="uc_connect">连接方式 (UC_CONNECT)</label>
                            <select id="uc_connect" name="uc_connect">
                                <option value="mysql" <?php echo $cfg['uc_connect'] === 'mysql' ? 'selected' : ''; ?>>mysql（同服务器推荐）</option>
                                <option value="post" <?php echo $cfg['uc_connect'] !== 'mysql' ? 'selected' : ''; ?>>post（远程 HTTP）</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="uc_charset">字符集 (UC_CHARSET)</label>
                            <input type="text" id="uc_charset" name="uc_charset"
                                   value="<?php echo e((string)$cfg['uc_charset']); ?>">
                        </div>
                    </div>

                    <hr style="margin:20px 0;border:none;border-top:1px solid #efe7d8;">

                    <h4 style="margin:0 0 10px;font-size:14px;">UCenter 数据库直连（仅「全量导入用户」时需要）</h4>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="uc_dbhost">数据库主机</label>
                            <input type="text" id="uc_dbhost" name="uc_dbhost" placeholder="localhost"
                                   value="<?php echo e((string)$cfg['uc_dbhost']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="uc_dbname">数据库名</label>
                            <input type="text" id="uc_dbname" name="uc_dbname" placeholder="ucenter"
                                   value="<?php echo e((string)$cfg['uc_dbname']); ?>">
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="uc_dbuser">数据库用户名</label>
                            <input type="text" id="uc_dbuser" name="uc_dbuser" autocomplete="off"
                                   value="<?php echo e((string)$cfg['uc_dbuser']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="uc_dbpw">数据库密码</label>
                            <input type="password" id="uc_dbpw" name="uc_dbpw" autocomplete="new-password"
                                   value="<?php echo e((string)$cfg['uc_dbpw']); ?>">
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label for="uc_dbcharset">数据库字符集</label>
                            <input type="text" id="uc_dbcharset" name="uc_dbcharset" placeholder="utf8"
                                   value="<?php echo e((string)$cfg['uc_dbcharset']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="uc_dbtablepre">UCenter 表前缀</label>
                            <input type="text" id="uc_dbtablepre" name="uc_dbtablepre" placeholder="`ucenter`.uc_"
                                   value="<?php echo e((string)$cfg['uc_dbtablepre']); ?>">
                        </div>
                    </div>

                    <hr style="margin:20px 0;border:none;border-top:1px solid #efe7d8;">

                    <h4 style="margin:0 0 10px;font-size:14px;">同步策略</h4>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px 20px;">
                        <label class="checkbox-label"><input type="checkbox" name="uc_sync_register" value="1" <?php echo UCenterBridge::opt('uc_sync_register', '1') === '1' ? 'checked' : ''; ?>> 本站注册 → 写入 UCenter</label>
                        <label class="checkbox-label"><input type="checkbox" name="uc_sync_login" value="1" <?php echo UCenterBridge::opt('uc_sync_login', '1') === '1' ? 'checked' : ''; ?>> 本站登录 → 广播同步登录</label>
                        <label class="checkbox-label"><input type="checkbox" name="uc_sync_password" value="1" <?php echo UCenterBridge::opt('uc_sync_password', '1') === '1' ? 'checked' : ''; ?>> 本站改密 → 同步 UCenter</label>
                        <label class="checkbox-label"><input type="checkbox" name="uc_sync_profile" value="1" <?php echo UCenterBridge::opt('uc_sync_profile', '1') === '1' ? 'checked' : ''; ?>> 本站改资料 → 同步邮箱</label>
                        <label class="checkbox-label"><input type="checkbox" name="uc_allow_uc_login" value="1" <?php echo UCenterBridge::opt('uc_allow_uc_login', '1') === '1' ? 'checked' : ''; ?>> 允许 UCenter 用户登录本站</label>
                        <label class="checkbox-label"><input type="checkbox" name="uc_auto_create" value="1" <?php echo UCenterBridge::opt('uc_auto_create', '1') === '1' ? 'checked' : ''; ?>> UCenter 用户自动建号</label>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top:20px;">保存配置</button>
                </form>
            </div>
        </div>

        <!-- 用户导入 -->
        <div class="admin-card" style="margin-bottom:16px;">
            <div class="admin-card-header"><h3>从 UCenter 导入用户</h3></div>
            <div class="admin-card-body">
                <p class="help-text" style="margin-top:0;">
                    读取 UCenter 的 <code>uc_members</code> 表，把用户导入本站：
                    用户名已存在的直接绑定 uc_uid，不存在的自动建号。
                    由于 UCenter 密码不可逆，导入的用户需要通过「UCenter 登录」或「找回密码」重置后才能使用本地密码。
                </p>
                <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="import">
                    <input type="number" name="import_limit" value="500" min="1" max="5000"
                           style="width:120px;padding:8px 10px;border:1px solid #ddd3c4;border-radius:6px;">
                    <span class="help-text" style="margin:0;">最多导入条数</span>
                    <button type="submit" class="btn btn-primary btn-sm"
                            onclick="return confirm('确定从 UCenter 导入用户？');">📥 开始导入</button>
                </form>

                <?php if ($importResult): ?>
                    <div style="margin-top:16px;">
                        <div class="alert alert-<?php echo empty($importResult['errors']) ? 'success' : 'warning'; ?>">
                            读取 <?php echo (int)$importResult['total']; ?> 条 ·
                            新建 <?php echo (int)$importResult['created']; ?> ·
                            绑定 <?php echo (int)$importResult['bound']; ?> ·
                            跳过 <?php echo (int)$importResult['skipped']; ?>
                        </div>
                        <?php if (!empty($importResult['errors'])): ?>
                            <pre style="background:#fff5f5;color:#a94442;padding:12px;border-radius:8px;max-height:200px;overflow:auto;font-size:12px;"><?php
                                echo e(implode("\n", array_slice($importResult['errors'], 0, 30)));
                            ?></pre>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 单个用户绑定 -->
        <div class="admin-card">
            <div class="admin-card-header"><h3>手动绑定账号</h3></div>
            <div class="admin-card-body">
                <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="rebind">
                    <input type="text" name="username" placeholder="本地用户名"
                           style="width:220px;padding:8px 10px;border:1px solid #ddd3c4;border-radius:6px;">
                    <button type="submit" class="btn btn-outline btn-sm">🔗 反查并绑定</button>
                    <span class="help-text" style="margin:0;">用于老账号未自动绑定的情况</span>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
