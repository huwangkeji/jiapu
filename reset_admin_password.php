<?php
/**
 * ============================================================
 *  管理员密码重置工具（应急通道）
 * ============================================================
 *  用途：当你忘记管理员密码、账号被锁定时，通过本文件安全地重置密码。
 *
 *  【安全校验（共 7 道）】
 *   1. 总开关   — .env 中 RESET_ADMIN_ENABLED 必须为 true（默认关闭）
 *   2. 访问密钥 — .env 中 RESET_ADMIN_KEY，与请求中的 key 严格比对（hash_equals）
 *   3. IP 白名单 — .env 中 RESET_ADMIN_IP（默认仅允许 127.0.0.1 / ::1）
 *   4. CSRF     — 表单提交必须携带一次性 csrf_token
 *   5. 频率限制 — 同一 IP 每 10 分钟最多 5 次尝试（logs/.reset_admin_throttle）
 *   6. 密码强度 — ≥10 位且同时包含大小写字母与数字
 *   7. 审计留痕 — 每次成功/失败均写入 logs 表与 logs/admin_reset.log
 *
 *  【使用步骤】
 *   1) 编辑 .env：
 *        RESET_ADMIN_ENABLED=true
 *        RESET_ADMIN_KEY=一串足够长的随机字符串
 *        RESET_ADMIN_IP=127.0.0.1,::1
 *   2) 浏览器访问 http://你的域名/reset_admin_password.php?key=你的密钥
 *   3) 用完后立刻把 RESET_ADMIN_ENABLED 改回 false
 *
 *  【命令行用法（无需开启 Web 通道，推荐）】
 *   php reset_admin_password.php --key=你的密钥 --username=admin --password=NewPass123
 *   省略 --password 会生成并显示一个强随机密码。
 *
 *  注意：命令行模式不受 RESET_ADMIN_ENABLED / IP 白名单限制，但仍需 --key。
 */

// ─────────────────────────── CLI 引导 ───────────────────────────
if (PHP_SAPI === 'cli') {
    require_once __DIR__ . '/includes/Env.php';
    Env::load(__DIR__ . '/.env');
    define('DB_HOST',    Env::get('DB_HOST', '127.0.0.1'));
    define('DB_PORT',    Env::get('DB_PORT', '3306'));
    define('DB_NAME',    Env::get('DB_NAME', 'family_tree'));
    define('DB_USER',    Env::get('DB_USER', 'root'));
    define('DB_PASS',    Env::get('DB_PASS', ''));
    define('DB_CHARSET', Env::get('DB_CHARSET', 'utf8mb4'));
    define('SITE_NAME',  Env::get('SITE_NAME', '族谱传承'));
    define('SITE_URL',   Env::get('SITE_URL', 'http://127.0.0.5'));
    date_default_timezone_set('Asia/Shanghai');
    require_once __DIR__ . '/includes/Security.php';
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/logger.php';

    $args = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([^=]+)=(.*)$/', $a, $m))      { $args[$m[1]] = $m[2]; }
        elseif (preg_match('/^--([^=]+)$/', $a, $m))        { $args[$m[1]] = true; }
    }

    $confKey = (string)Env::get('RESET_ADMIN_KEY', '');
    if ($confKey === '') {
        fwrite(STDERR, "[FAIL] 未配置 RESET_ADMIN_KEY，请先在 .env 中设置一个足够长的随机密钥。\n");
        exit(1);
    }
    if (empty($args['key']) || !hash_equals($confKey, (string)$args['key'])) {
        fwrite(STDERR, "[FAIL] 访问密钥错误或缺失：--key=...\n");
        exit(1);
    }

    $username = isset($args['username']) ? trim((string)$args['username']) : 'admin';
    $password = isset($args['password']) ? (string)$args['password'] : '';
    if ($password === '') {
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digit = '23456789';
        $pool  = $lower . $upper . $digit;
        $chars = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digit[random_int(0, strlen($digit) - 1)],
        ];
        for ($i = 0; $i < 9; $i++) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        $password = implode('', $chars);
    }

    $check = reset_admin_validate_password($password);
    if ($check !== true) {
        fwrite(STDERR, "[FAIL] {$check}\n");
        exit(1);
    }

    $result = reset_admin_do_reset($username, $password, 'cli');
    if (!$result['success']) {
        fwrite(STDERR, "[FAIL] {$result['message']}\n");
        exit(1);
    }
    echo "[OK] 账号 {$result['username']} (ID {$result['user_id']}) 密码已重置\n";
    echo "     新密码：{$password}\n";
    echo "     请立即登录修改，并把 .env 的 RESET_ADMIN_ENABLED 保持为 false。\n";
    exit(0);
}

// ─────────────────────────── Web 引导 ───────────────────────────
require_once __DIR__ . '/includes/header_pre.php';

// ─────────────────────── 公共函数定义 ───────────────────────

/**
 * 密码强度校验：≥10 位，且同时包含大写、小写、数字
 * @return true|string
 */
function reset_admin_validate_password($password)
{
    if (mb_strlen($password) < 10) {
        return '密码长度不能少于 10 位';
    }
    if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)) {
        return '密码必须同时包含大写字母和小写字母';
    }
    if (!preg_match('/\d/', $password)) {
        return '密码必须包含至少一位数字';
    }
    if (preg_match('/\s/', $password)) {
        return '密码不能包含空格';
    }
    return true;
}

/**
 * 执行重置：更新密码 + 解冻账号 + 吊销持久令牌
 */
function reset_admin_do_reset($username, $newPassword, $channel = 'web')
{
    $db = Database::getInstance();
    $user = $db->fetch("SELECT id, username, role, status FROM users WHERE username = ? LIMIT 1", [$username]);
    if (!$user) {
        return ['success' => false, 'message' => '账号不存在'];
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);

    // 同时解冻账号（被禁用/被锁的管理员也能恢复）
    $db->execute(
        "UPDATE users SET password = ?, status = 1, updated_at = NOW() WHERE id = ?",
        [$hash, $user['id']]
    );

    // 吊销该用户所有「记住我」令牌，强制重新登录
    try {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `user_tokens` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `selector` CHAR(12) NOT NULL,
                `validator_hash` CHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_selector` (`selector`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='记住我持久登录令牌'"
        );
        $db->execute("DELETE FROM user_tokens WHERE user_id = ?", [$user['id']]);
    } catch (\Exception $e) {
        // 表不存在/无权限时忽略
    }

    // 同步到 UCenter（若开启）
    if (class_exists('UCenterBridge')) {
        try {
            UCenterBridge::syncPassword($user['id'], $newPassword);
        } catch (\Exception $e) {
            error_log('UCenter 密码同步失败: ' . $e->getMessage());
        }
    }

    return [
        'success'  => true,
        'user_id'  => $user['id'],
        'username' => $user['username'],
        'message'  => '密码重置成功',
    ];
}

/**
 * 写审计日志（logs 表 + 独立日志文件）
 */
function reset_admin_audit($level, $message)
{
    try {
        Logger::write($level, '密码重置', '重置管理员密码', $message, null);
    } catch (\Exception $e) {
        // 忽略
    }
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = sprintf(
        "[%s] [%s] %s | IP: %s | UA: %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $_SERVER['REMOTE_ADDR'] ?? 'cli',
        str_replace(["\r", "\n"], '', substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 180))
    );
    @file_put_contents($dir . '/admin_reset.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * 简易频率限制（文件存储）：每 IP 每 600 秒最多 5 次
 */
function reset_admin_throttle($ip)
{
    $file = __DIR__ . '/logs/.reset_admin_throttle';
    $data = [];
    if (is_file($file)) {
        $raw = json_decode((string)@file_get_contents($file), true);
        if (is_array($raw)) {
            $data = $raw;
        }
    }
    $now = time();
    $window = 600;
    $limit  = 5;

    $hits = array_values(array_filter($data[$ip] ?? [], function ($t) use ($now, $window) {
        return ($now - (int)$t) < $window;
    }));

    if (count($hits) >= $limit) {
        $data[$ip] = $hits;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return false;
    }

    $hits[] = $now;
    $data[$ip] = $hits;
    // 清理过期 IP 记录，避免文件无限增长
    foreach ($data as $k => $v) {
        $v = array_filter((array)$v, function ($t) use ($now, $window) { return ($now - (int)$t) < $window; });
        if (empty($v)) { unset($data[$k]); } else { $data[$k] = array_values($v); }
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

/**
 * IP 是否在白名单中（支持 CIDR，如 192.168.1.0/24）
 */
function reset_admin_ip_allowed($ip, $whitelist)
{
    $whitelist = trim((string)$whitelist);
    if ($whitelist === '' || $whitelist === '*') {
        return true;
    }
    foreach (explode(',', $whitelist) as $item) {
        $item = trim($item);
        if ($item === '') continue;
        if (strpos($item, '/') !== false) {
            [$subnet, $bits] = explode('/', $item, 2);
            $bits = (int)$bits;
            if (reset_admin_ip_in_cidr($ip, $subnet, $bits)) return true;
        } elseif (strtolower($item) === strtolower($ip)) {
            return true;
        }
    }
    return false;
}

function reset_admin_ip_in_cidr($ip, $subnet, $bits)
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
        filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if ($bits < 0 || $bits > 32) return false;
        $mask = -1 << (32 - $bits);
        return ((ip2long($ip) & $mask) === (ip2long($subnet) & $mask));
    }
    return false;
}

/**
 * 终止并渲染 403 页面
 */
function reset_admin_deny($title, $detail, $hint = '')
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . e($title) . '</title><style>'
       . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#f6f5f2;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;}'
       . '.box{background:#fff;border-radius:14px;box-shadow:0 6px 28px rgba(0,0,0,.08);padding:36px 40px;max-width:560px;}'
       . 'h1{margin:0 0 12px;font-size:20px;color:#c0392b;}'
       . 'p{margin:8px 0;color:#5b4b34;font-size:14px;line-height:1.8;}'
       . 'code{background:#f2efe8;padding:2px 6px;border-radius:4px;font-size:13px;color:#8b4513;}'
       . 'pre{background:#2d1f10;color:#f3e9d8;padding:14px 16px;border-radius:8px;overflow-x:auto;font-size:12.5px;line-height:1.7;}'
       . '</style></head><body><div class="box">'
       . '<h1>⛔ ' . e($title) . '</h1>'
       . '<p>' . nl2br(e($detail)) . '</p>'
       . ($hint ? '<pre>' . e($hint) . '</pre>' : '')
       . '<p style="color:#999;font-size:12px;margin-top:18px;">族谱传承 · 管理员密码重置工具</p>'
       . '</div></body></html>';
    exit;
}

// ─────────────────────── 安全校验（Web） ───────────────────────

$clientIp = Security::getClientIp();

// 1) 总开关
$enabled = strtolower((string)Env::get('RESET_ADMIN_ENABLED', 'false')) === 'true';
if (!$enabled) {
    reset_admin_deny(
        '重置通道未启用',
        "本工具默认关闭，以防止被恶意利用。\n请先在 .env 中开启：",
        "RESET_ADMIN_ENABLED=true\nRESET_ADMIN_KEY=请填写一串足够长的随机字符串\nRESET_ADMIN_IP=127.0.0.1,::1\n\n改完后重新访问：/reset_admin_password.php?key=你的密钥\n用完后请立即改回 RESET_ADMIN_ENABLED=false"
    );
}

// 2) 访问密钥
$confKey = (string)Env::get('RESET_ADMIN_KEY', '');
if ($confKey === '' || strlen($confKey) < 12) {
    reset_admin_deny(
        '访问密钥未配置',
        ".env 中的 RESET_ADMIN_KEY 缺失或过短（至少 12 位）。",
        "RESET_ADMIN_KEY=" . bin2hex(random_bytes(12))
    );
}

// 3) IP 白名单
$ipWhitelist = (string)Env::get('RESET_ADMIN_IP', '127.0.0.1,::1');
if (!reset_admin_ip_allowed($clientIp, $ipWhitelist)) {
    reset_admin_audit('warning', "IP 不在白名单，拒绝访问：{$clientIp}");
    reset_admin_deny(
        'IP 未授权',
        "当前 IP {$clientIp} 不在允许列表中。\n如需授权，请在 .env 的 RESET_ADMIN_IP 中添加。",
        "RESET_ADMIN_IP=127.0.0.1,::1,{$clientIp}"
    );
}

// 4) 频率限制
if (!reset_admin_throttle($clientIp)) {
    reset_admin_audit('warning', "触发频率限制，拒绝访问：{$clientIp}");
    reset_admin_deny('请求过于频繁', '同一 IP 在 10 分钟内尝试次数过多，请稍后再试。');
}

// 5) 校验 key
$reqKey = (string)($_POST['key'] ?? $_GET['key'] ?? '');
if ($reqKey === '' || !hash_equals($confKey, $reqKey)) {
    reset_admin_audit('warning', "访问密钥错误，拒绝访问：{$clientIp}");
    reset_admin_deny(
        '访问密钥错误',
        "URL 中必须携带正确的 key 参数：",
        SITE_URL . "/reset_admin_password.php?key=<你的 RESET_ADMIN_KEY>"
    );
}

// ─────────────────────────── 处理提交 ───────────────────────────

$error   = '';
$success = '';
$doneUser = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 6) CSRF
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = '表单已过期，请刷新页面后重试';
    } else {
        $username = trim((string)post_param('username', 'admin'));
        $password = (string)post_param('password');
        $confirm  = (string)post_param('password_confirm');

        if ($username === '') {
            $error = '请填写要重置的用户名';
        } elseif ($password !== $confirm) {
            $error = '两次输入的密码不一致';
        } else {
            $check = reset_admin_validate_password($password);
            if ($check !== true) {
                $error = $check;
            } else {
                $db = Database::getInstance();
                $target = $db->fetch("SELECT id, username, role FROM users WHERE username = ? LIMIT 1", [$username]);
                if (!$target) {
                    // 不泄露账号是否存在之外的信息
                    $error = '该账号不存在，请检查用户名';
                    reset_admin_audit('warning', "重置失败，账号不存在：{$username}");
                } elseif ($target['role'] !== 'admin') {
                    $error = '出于安全考虑，本工具只能重置 role=admin 的管理员账号';
                    reset_admin_audit('warning', "重置失败，目标非管理员：{$username}");
                } else {
                    $result = reset_admin_do_reset($username, $password, 'web');
                    if ($result['success']) {
                        $doneUser = $result['username'];
                        $success  = "账号「{$doneUser}」的密码已重置，请使用新密码登录。";
                        reset_admin_audit('info', "成功重置管理员密码：{$doneUser}（ID {$result['user_id']}）");
                    } else {
                        $error = $result['message'];
                        reset_admin_audit('error', "重置失败：{$username} - " . $result['message']);
                    }
                }
            }
        }
    }
}

// 管理员账号候选（方便下拉选择）
$adminOptions = [];
try {
    $adminOptions = Database::getInstance()->fetchAll(
        "SELECT id, username, nickname, status FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 50"
    );
} catch (\Exception $e) {
    $adminOptions = [];
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>管理员密码重置 - <?php echo e(SITE_NAME); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; background:#f6f5f2; color:#33281a;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;
               display:flex; align-items:center; justify-content:center; padding:32px 16px; }
        .wrap { width:100%; max-width:520px; }
        .card { background:#fff; border-radius:16px; box-shadow:0 8px 32px rgba(0,0,0,.08); overflow:hidden; }
        .card-head { background:linear-gradient(135deg,#8B4513,#CD853F); color:#fff; padding:24px 28px; }
        .card-head h1 { margin:0; font-size:19px; font-weight:600; letter-spacing:.5px; }
        .card-head p { margin:8px 0 0; font-size:13px; opacity:.9; line-height:1.6; }
        .card-body { padding:28px; }
        .alert { border-radius:8px; padding:12px 14px; font-size:13.5px; line-height:1.7; margin-bottom:18px; }
        .alert-error { background:#fdecea; border:1px solid #f5c6cb; color:#a94442; }
        .alert-success { background:#eafaf1; border:1px solid #b7e4c7; color:#1e7e46; }
        .form-group { margin-bottom:16px; }
        label { display:block; font-size:13px; font-weight:600; color:#5b4b34; margin-bottom:6px; }
        input, select { width:100%; padding:11px 13px; font-size:14px; border:1px solid #ddd3c4;
                        border-radius:8px; background:#fffaf3; outline:none; transition:border-color .15s; }
        input:focus, select:focus { border-color:#CD853F; }
        .help { font-size:12px; color:#9a8b76; margin-top:6px; line-height:1.6; }
        .btn { width:100%; padding:12px; font-size:15px; font-weight:600; color:#fff; cursor:pointer;
               border:none; border-radius:8px; background:linear-gradient(135deg,#8B4513,#CD853F); }
        .btn:hover { filter:brightness(1.06); }
        .tips { margin-top:22px; background:#fff8e6; border:1px solid #f0d99a; border-radius:8px;
                padding:14px 16px; font-size:12.5px; line-height:1.85; color:#856404; }
        .tips b { color:#6b4c00; }
        .tips code { background:#fff; padding:1px 5px; border-radius:3px; font-size:12px; }
        .meta { margin-top:18px; font-size:12px; color:#a89a86; text-align:center; line-height:1.8; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="card-head">
            <h1>🔑 管理员密码重置</h1>
            <p>应急通道 · 当前访问 IP：<?php echo e($clientIp); ?> · 每次操作均会记入系统日志</p>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="key" value="<?php echo e($reqKey); ?>">
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label for="username">目标管理员账号</label>
                    <?php if (!empty($adminOptions)): ?>
                    <select id="username" name="username">
                        <?php foreach ($adminOptions as $a): ?>
                            <option value="<?php echo e($a['username']); ?>"
                                <?php echo ($a['username'] === 'admin' || count($adminOptions) === 1) ? 'selected' : ''; ?>>
                                <?php echo e($a['username']); ?>
                                <?php if ($a['nickname']): ?>（<?php echo e($a['nickname']); ?>）<?php endif; ?>
                                <?php echo $a['status'] ? '' : ' · 已禁用'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="text" id="username" name="username" value="admin" required>
                    <?php endif; ?>
                    <p class="help">仅可重置 role=admin 的账号；重置后账号会自动恢复为启用状态。</p>
                </div>

                <div class="form-group">
                    <label for="password">新密码</label>
                    <input type="password" id="password" name="password" required
                           minlength="10" autocomplete="new-password" placeholder="至少10位，含大小写字母与数字">
                </div>

                <div class="form-group">
                    <label for="password_confirm">确认新密码</label>
                    <input type="password" id="password_confirm" name="password_confirm" required
                           minlength="10" autocomplete="new-password" placeholder="请再次输入新密码">
                </div>

                <button type="submit" class="btn">重置密码</button>
            </form>

            <div class="tips">
                <b>安全提示</b><br>
                1. 重置成功后该账号的所有「记住我」自动登录令牌会被吊销。<br>
                2. 用完请立刻编辑 <code>.env</code>，把 <code>RESET_ADMIN_ENABLED</code> 改回 <code>false</code>。<br>
                3. 建议定期更换 <code>RESET_ADMIN_KEY</code>，不要把密钥写进代码仓库。<br>
                4. 全部操作记录在 <code>logs/admin_reset.log</code> 与后台「系统日志」中。
            </div>

            <p class="meta">
                <?php echo e(SITE_NAME); ?> · <?php echo date('Y-m-d H:i:s'); ?><br>
                <a href="<?php echo e(SITE_URL); ?>/login.php" style="color:#8B4513;">前往登录页 →</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
