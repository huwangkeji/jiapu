<?php
/**
 * 族谱传承 - 核心配置文件 v2.0
 * 运行环境: PHP 7.4 + MySQL 5.7 + Nginx
 *
 * 加固特性：
 * - 敏感信息移至 .env 文件
 * - 安全 Session 初始化（指纹绑定 + HttpOnly + SameSite）
 * - 全局输入净化（NULL字节 + 控制字符）
 * - 安全响应头（CSP / X-Frame-Options 等）
 * - 防路径穿越保护
 */

// ── 防止直接访问本文件 ──
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// ── 隐藏 PHP 版本头 ──
header_remove('X-Powered-By');

// ── 加载环境变量 ──
require_once __DIR__ . '/includes/Env.php';
Env::load(__DIR__ . '/.env');

// ── 数据库配置 ──
define('DB_HOST',    Env::get('DB_HOST', '127.0.0.1'));
define('DB_PORT',    Env::get('DB_PORT', '3306'));
define('DB_NAME',    Env::get('DB_NAME', 'family_tree'));
define('DB_USER',    Env::get('DB_USER', 'root'));
define('DB_PASS',    Env::get('DB_PASS', ''));
define('DB_CHARSET', Env::get('DB_CHARSET', 'utf8mb4'));

// ── 站点配置 ──
define('SITE_NAME',    Env::get('SITE_NAME', '族谱传承'));
define('SITE_URL',     Env::get('SITE_URL', 'http://127.0.0.2'));
define('SITE_VERSION', Env::get('SITE_VERSION', '2.0.0'));

// ── 会话配置 ──
define('SESSION_LIFETIME', Env::int('SESSION_LIFETIME', 7200));

// ── 安全配置 ──
define('SECURITY_SALT', Env::get('SECURITY_SALT', 'family_tree_salt_v1'));

// ── 上传配置 ──
define('UPLOAD_MAX_SIZE',  Env::int('UPLOAD_MAX_SIZE', 5242880)); // 5MB
define('UPLOAD_PATH',      __DIR__ . '/uploads/');
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// ── 分页配置 ──
define('PAGE_SIZE', Env::int('PAGE_SIZE', 15));

// ── 时区 ──
date_default_timezone_set('Asia/Shanghai');

// ── 错误报告（生产环境关闭，只写日志） ──
$debug = Env::get('APP_DEBUG', 'false') === 'true';
define('APP_DEBUG', $debug);
if ($debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/logs/php_error.log');
}

// ── 安全 Session 初始化 ──
require_once __DIR__ . '/includes/Security.php';
Security::initSecureSession();

// ── 全局输入净化（去除NULL字节和控制字符） ──
Security::sanitizeInput($_GET);
Security::sanitizeInput($_POST);
Security::sanitizeInput($_REQUEST);

// ── CSP 额外放行域名（UCenter 跨应用同步登录需要）──
// 未显式配置时，若 .env 配了 UC_API，则自动放行其主机名
$cspExtra = Env::get('CSP_EXTRA_SCRIPT_SRC', '');
if (trim((string)$cspExtra) === '') {
    $ucApi = trim((string)Env::get('UC_API', ''));
    if ($ucApi !== '' && preg_match('#^https?://([^/]+)#i', $ucApi, $m)) {
        $cspExtra = $m[1];
    }
}
define('CSP_EXTRA_SCRIPT_SRC', $cspExtra);

// ── 安全响应头 ──
Security::setSecurityHeaders();

// ── 防路径穿越（uploads 目录禁止执行PHP） ──
$htaccess = UPLOAD_PATH . '.htaccess';
if (!file_exists($htaccess)) {
    @file_put_contents($htaccess, "Deny from all\n<FilesMatch \"\\.(jpg|jpeg|png|gif|webp)$\">\nAllow from all\n</FilesMatch>\n");
}

// ── 「记住我」持久登录恢复 ──
// 仅在浏览器携带了持久令牌 Cookie 时才加载 Auth 并访问数据库，避免无谓开销
if (empty($_SESSION['user_id']) && !empty($_COOKIE['ft_remember'])) {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/logger.php';
    require_once __DIR__ . '/includes/auth.php';
    Auth::restoreFromRememberCookie();
}
