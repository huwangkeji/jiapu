<?php
/**
 * ============================================================
 *  UCenter 2.0 应用接口（回调端点）
 * ============================================================
 *  在 UCenter 后台「应用管理」中把本应用的接口 URL 填为：
 *      http://你的域名/api/uc.php
 *
 *  UCenter 会把请求参数用 uc_authcode 加密后放在 ?code= 中传过来。
 *  本文件解密后交给 UCenterBridge::handleNotify 处理，并按要求回写
 *  1（成功）/ -1（失败）/ -2（禁用）。
 */

define('API_RETURN_SUCCEED', '1');
define('API_RETURN_FAILED',  '-1');
define('API_RETURN_FORBIDDEN', '-2');

require_once __DIR__ . '/../includes/header_pre.php';
require_once __DIR__ . '/../includes/UCenter.php';
require_once __DIR__ . '/../includes/UCenterBridge.php';

// 允许跨域写入 Cookie（IE 遗留策略，对同步登录有帮助）
header('P3P: CP="CURa ADMa DEVa PSAo PSDo OUR BUS UNI PUR INT DEM STA PRE COM NAV OTC NOI DSP COR"');
header('Content-Type: text/plain; charset=utf-8');

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    exit('Invalid Request');
}

$ucKey = (string)UCenter::config('uc_key', '');
if ($ucKey === '') {
    exit('UCenter Not Configured');
}

$decoded = UCenter::authcode($code, 'DECODE', $ucKey);
if ($decoded === '') {
    exit('Invalid Request');
}

$get = [];
parse_str($decoded, $get);
if (!isset($get['action'])) {
    exit('Invalid Request');
}

$action = strtolower((string)$get['action']);
$time   = (int)($get['time'] ?? 0);

// 时间戳校验：超过 3600 秒视为重放攻击
if ($time > 0 && abs(time() - $time) > 3600) {
    exit(API_RETURN_FAILED);
}

$db = Database::getInstance();

// ── 同步登录：Discuz 登录后，UCenter 用 <script src> 回调本地址设置本站会话 ──
if ($action === 'synlogin') {
    $uid = (int)($get['uid'] ?? 0);
    if ($uid > 0 && UCenterBridge::ensureColumn()) {
        $user = $db->fetch("SELECT * FROM users WHERE uc_uid = ? AND status = 1", [$uid]);
        if ($user) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_regenerate_id(true);
            }
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['nickname']   = $user['nickname'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['login_time'] = time();
            Logger::info('UCenter', "同步登录：{$user['username']}", $user['id']);
        }
    }
    exit(API_RETURN_SUCCEED);
}

// ── 同步退出 ──
if ($action === 'synlogout') {
    if (!empty($_SESSION['user_id'])) {
        Logger::info('UCenter', '同步退出', (int)$_SESSION['user_id']);
    }
    Auth::clearRememberCookie();
    $_SESSION = [];
    session_unset();
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'] ?? '/', $params['domain'] ?? '',
            (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
    }
    session_destroy();
    exit(API_RETURN_SUCCEED);
}

// ── 其他通知统一交给桥接层 ──
$ret = UCenterBridge::handleNotify($get);
exit($ret === null ? API_RETURN_FAILED : (string)$ret);
