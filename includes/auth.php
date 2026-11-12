<?php
/**
 * 用户认证模块
 * 处理注册、登录、会话管理
 */

require_once __DIR__ . '/db.php';
if (is_file(__DIR__ . '/UCenter.php')) {
    require_once __DIR__ . '/UCenter.php';
}
if (is_file(__DIR__ . '/UCenterBridge.php')) {
    require_once __DIR__ . '/UCenterBridge.php';
}

class Auth
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 用户注册
     */
    public function register($username, $email, $password, $nickname = '')
    {
        // 参数验证
        if (empty($username) || empty($email) || empty($password)) {
            return ['success' => false, 'message' => '请填写完整信息'];
        }
        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
            return ['success' => false, 'message' => '用户名长度需在3-50个字符之间'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => '邮箱格式不正确'];
        }
        if (mb_strlen($password) < 6) {
            return ['success' => false, 'message' => '密码长度不能少于6位'];
        }

        // 密码强度检查
        $strength = Security::checkPasswordStrength($password);
        if ($strength['score'] < 2) {
            return ['success' => false, 'message' => '密码强度过弱，请包含大小写字母、数字或特殊字符'];
        }

        // 检查用户名是否已存在
        $exists = $this->db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if ($exists) {
            return ['success' => false, 'message' => '用户名已存在'];
        }

        // 检查邮箱是否已存在
        $exists = $this->db->fetch("SELECT id FROM users WHERE email = ?", [$email]);
        if ($exists) {
            return ['success' => false, 'message' => '邮箱已被注册'];
        }

        // 密码加密
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $nickname = $nickname ?: $username;

        // 插入用户
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        try {
            $sql = "INSERT INTO users (username, email, password, nickname, role, status, register_ip, register_ua, created_at)
                    VALUES (?, ?, ?, ?, 'user', 1, ?, ?, NOW())";
            $result = $this->db->execute($sql, [$username, $email, $hash, $nickname, $ip, $ua]);
        } catch (\Exception $e) {
            // 降级：如果扩展列不存在
            $sql = "INSERT INTO users (username, email, password, nickname, role, status, created_at)
                    VALUES (?, ?, ?, ?, 'user', 1, NOW())";
            $result = $this->db->execute($sql, [$username, $email, $hash, $nickname]);
        }

        if ($result) {
            $userId = $this->db->lastInsertId();
            Logger::info('用户注册', "新用户注册: {$username}", $userId);

            // UCenter 互通：把新账号同步到 UCenter
            if (class_exists('UCenterBridge')) {
                try {
                    $uc = UCenterBridge::afterRegister($userId, $username, $password, $email);
                    if (!$uc['success'] && !empty($uc['message']) && $uc['message'] !== '未启用') {
                        // 同步失败不阻断本站注册，仅记录
                        Logger::warning('UCenter', "注册同步未成功: {$username} - {$uc['message']}", $userId);
                    }
                } catch (\Exception $e) {
                    error_log('UCenter 注册同步异常: ' . $e->getMessage());
                }
            }

            return ['success' => true, 'message' => '注册成功', 'user_id' => $userId];
        }

        return ['success' => false, 'message' => '注册失败，请稍后重试'];
    }

    /**
     * 用户登录
     */
    public function login($username, $password, $remember = false)
    {
        if (empty($username) || empty($password)) {
            return ['success' => false, 'message' => '请输入用户名和密码'];
        }

        // 安全检查：IP 是否被封禁
        if (Security::isIpBanned()) {
            return ['success' => false, 'message' => '您的IP已被临时封禁，请30分钟后再试'];
        }

        // 安全检查：登录频率限制
        $throttleMsg = Security::checkLoginThrottle();
        if ($throttleMsg) {
            return ['success' => false, 'message' => $throttleMsg];
        }

        // 用户名长度安全限制
        if (mb_strlen($username) > 100) {
            return ['success' => false, 'message' => '用户名格式不正确'];
        }

        // 查找用户（支持用户名或邮箱登录）
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 1",
            [$username, $username]
        );

        if (!$user) {
            // ── UCenter 互通：本地无此账号时，尝试用 UCenter 校验并自动建号 ──
            if (class_exists('UCenterBridge') && UCenterBridge::allowUcLogin()) {
                try {
                    $uc = UCenterBridge::verifyUcUser($username, $password);
                    if ($uc['success']) {
                        $user = UCenterBridge::provisionLocalUser($uc['data'], $password);
                    }
                } catch (\Exception $e) {
                    error_log('UCenter 登录校验异常: ' . $e->getMessage());
                }
            }

            if (!$user) {
                Security::recordLoginFailure($username);
                return ['success' => false, 'message' => '用户不存在或已被禁用'];
            }
        }

        // 验证密码
        if (!password_verify($password, $user['password'])) {
            // ── UCenter 互通：本地密码不匹配时，回退到 UCenter 校验（老用户 / 在 Discuz 改过密码）──
            $ucPassed = false;
            if (class_exists('UCenterBridge') && UCenterBridge::allowUcLogin()) {
                try {
                    $uc = UCenterBridge::verifyUcUser($user['username'], $password);
                    if ($uc['success']) {
                        $ucPassed = true;
                        // 用 UCenter 的密码刷新本地哈希，下次直接本地校验
                        $this->db->execute(
                            "UPDATE users SET password = ? WHERE id = ?",
                            [password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), $user['id']]
                        );
                        Logger::info('UCenter', "UCenter 校验通过并同步本地密码: {$user['username']}", $user['id']);
                    }
                } catch (\Exception $e) {
                    error_log('UCenter 登录回退校验异常: ' . $e->getMessage());
                }
            }

            if (!$ucPassed) {
                $failResult = Security::recordLoginFailure($username);
                Logger::warning('登录失败', "密码错误: {$username}");

                $remaining = Security::MAX_LOGIN_ATTEMPTS - $failResult['attempts'];
                if ($remaining > 0) {
                    return ['success' => false, 'message' => "密码错误，还剩 {$remaining} 次机会"];
                } else {
                    return ['success' => false, 'message' => '密码错误次数过多，IP已被封禁30分钟'];
                }
            }
        }

        // 登录成功，清除失败记录
        Security::clearLoginFailures();

        // 防止会话固定攻击（Session Fixation）
        if (PHP_VERSION_ID >= 70100 && session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }

        // 设置会话
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nickname'] = $user['nickname'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['login_time'] = time();

        // 更新最后登录时间和IP
        $ip = Security::getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        try {
            $this->db->execute(
                "UPDATE users SET last_login = NOW(), last_login_ip = ?, last_login_ua = ? WHERE id = ?",
                [$ip, $ua, $user['id']]
            );
        } catch (\Exception $e) {
            // 降级：如果扩展列不存在，只更新 last_login
            $this->db->execute("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
        }

        // 「记住我」：下发长期令牌（30天）
        if ($remember) {
            self::issueRememberToken($user['id']);
        }

        // 刷新登录时间（避免长生命周期下被判定过期）
        $_SESSION['login_time'] = time();

        // UCenter 互通：生成同步登录脚本（由 header.php 输出到页面）
        if (class_exists('UCenterBridge')) {
            try {
                UCenterBridge::afterLogin($user['id'], $user['username']);
            } catch (\Exception $e) {
                error_log('UCenter 同步登录异常: ' . $e->getMessage());
            }
        }

        Logger::info('用户登录', "用户登录: {$username}", $user['id']);

        return ['success' => true, 'message' => '登录成功'];
    }

    // ───────────────────────── 记住我（持久登录） ─────────────────────────

    /** 记住我 Cookie 名称 */
    const REMEMBER_COOKIE = 'ft_remember';

    /** 记住我有效期（秒）：30天 */
    const REMEMBER_TTL = 2592000;

    /**
     * 下发「记住我」令牌
     * 采用 selector:validator 双段结构，数据库只存 validator 的哈希
     */
    public static function issueRememberToken($userId)
    {
        try {
            $db = Database::getInstance();
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

            // 清理该用户的旧令牌（最多保留5条，防止无限增长）
            $db->execute("DELETE FROM user_tokens WHERE expires_at < NOW()");
            $db->execute(
                "DELETE FROM user_tokens WHERE user_id = ? AND id NOT IN (
                    SELECT id FROM (SELECT id FROM user_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 4) t
                 )",
                [$userId, $userId]
            );

            $selector  = bin2hex(random_bytes(6));           // 12 hex chars
            $validator = bin2hex(random_bytes(32));          // 64 hex chars
            $db->execute(
                "INSERT INTO user_tokens (user_id, selector, validator_hash, expires_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))",
                [$userId, $selector, hash('sha256', $validator), self::REMEMBER_TTL]
            );

            $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            setcookie(
                self::REMEMBER_COOKIE,
                $selector . ':' . $validator,
                time() + self::REMEMBER_TTL,
                '/',
                '',
                $secure,
                true   // HttpOnly
            );
            return true;
        } catch (\Exception $e) {
            error_log('Auth::issueRememberToken error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 通过「记住我」Cookie 恢复会话
     * 只在未登录且 Cookie 存在时调用（避免无谓的 DB 连接）
     */
    public static function restoreFromRememberCookie()
    {
        if (self::isLoggedIn()) {
            return false;
        }
        $raw = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (empty($raw) || strpos($raw, ':') === false) {
            return false;
        }

        list($selector, $validator) = explode(':', $raw, 2);
        if (!preg_match('/^[0-9a-f]{12}$/', $selector) || !preg_match('/^[0-9a-f]{64}$/', $validator)) {
            self::clearRememberCookie();
            return false;
        }

        try {
            $db = Database::getInstance();
            $token = $db->fetch(
                "SELECT * FROM user_tokens WHERE selector = ? AND expires_at > NOW()",
                [$selector]
            );
            if (!$token || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
                self::clearRememberCookie();
                return false;
            }

            $user = $db->fetch("SELECT * FROM users WHERE id = ? AND status = 1", [$token['user_id']]);
            if (!$user) {
                $db->execute("DELETE FROM user_tokens WHERE id = ?", [$token['id']]);
                self::clearRememberCookie();
                return false;
            }

            // 令牌一次性轮换（防重放）
            $db->execute("DELETE FROM user_tokens WHERE id = ?", [$token['id']]);
            self::issueRememberToken($user['id']);

            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_regenerate_id(true);
            }
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['nickname']  = $user['nickname'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();

            Logger::info('用户登录', "记住我自动登录: {$user['username']}", $user['id']);
            return true;
        } catch (\Exception $e) {
            error_log('Auth::restoreFromRememberCookie error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 清除「记住我」Cookie 与服务端令牌
     */
    public static function clearRememberCookie()
    {
        $raw = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($raw && strpos($raw, ':') !== false) {
            $selector = explode(':', $raw, 2)[0];
            try {
                Database::getInstance()->execute("DELETE FROM user_tokens WHERE selector = ?", [$selector]);
            } catch (\Exception $e) {
                // 忽略
            }
        }
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        setcookie(self::REMEMBER_COOKIE, '', time() - 3600, '/', '', $secure, true);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    /**
     * 生成一次性退出令牌（供 GET 方式安全退出使用）
     */
    public static function getLogoutToken()
    {
        if (empty($_SESSION['logout_token'])) {
            $_SESSION['logout_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['logout_token'];
    }

    /**
     * 校验退出令牌
     */
    public static function verifyLogoutToken($token)
    {
        return !empty($_SESSION['logout_token'])
            && is_string($token)
            && hash_equals($_SESSION['logout_token'], $token);
    }

    /**
     * 退出登录（安全销毁会话 + 清除持久令牌 + 防会话固定）
     */
    public function logout()
    {
        if (isset($_SESSION['username'])) {
            Logger::info('用户登出', "用户登出: {$_SESSION['username']}", $_SESSION['user_id'] ?? null);
        }

        // 清除「记住我」
        self::clearRememberCookie();

        $_SESSION = [];
        session_unset();

        // 彻底删除会话 Cookie（session_destroy 不会删除客户端 Cookie）
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }

        session_destroy();

        // 重新生成 SID，避免退出后沿用旧 ID
        if (PHP_VERSION_ID >= 70100) {
            @session_regenerate_id(true);
        }
    }

    /**
     * 检查是否已登录
     */
    public static function isLoggedIn()
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        // 检查会话是否过期
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            return false;
        }
        return true;
    }

    /**
     * 检查是否管理员
     */
    public static function isAdmin()
    {
        return self::isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin';
    }

    /**
     * 要求登录（未登录则跳转）
     */
    public static function requireLogin()
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . SITE_URL . '/login.php');
            exit;
        }
    }

    /**
     * 要求管理员权限
     */
    public static function requireAdmin()
    {
        if (!self::isLoggedIn()) {
            // 未登录 → 跳转登录页
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header('Location: ' . SITE_URL . '/login.php');
            exit;
        }
        if ($_SESSION['user_role'] !== 'admin') {
            // 已登录但非管理员 → 提示无权限
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>无权限</title>
            <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f5f5f5;}
            .box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.1);}
            h1{color:#e74c3c;font-size:48px;margin:0 0 10px;}p{color:#666;font-size:16px;}
            a{color:#8B4513;text-decoration:none;font-size:14px;}</style></head>
            <body><div class="box"><h1>⛔</h1><p>您没有管理员权限</p>
            <a href="' . SITE_URL . '/">返回首页</a></div></body></html>';
            exit;
        }
    }

    /**
     * 获取当前用户ID
     */
    public static function getUserId()
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * 获取当前用户名
     */
    public static function getUsername()
    {
        return $_SESSION['username'] ?? '';
    }

    /**
     * 获取当前用户昵称
     */
    public static function getNickname()
    {
        return $_SESSION['nickname'] ?? '';
    }

    /**
     * 修改密码
     */
    public function changePassword($userId, $oldPassword, $newPassword)
    {
        $user = $this->db->fetch("SELECT password FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        if (!password_verify($oldPassword, $user['password'])) {
            return ['success' => false, 'message' => '原密码错误'];
        }

        if (mb_strlen($newPassword) < 6) {
            return ['success' => false, 'message' => '新密码长度不能少于6位'];
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
        $result = $this->db->execute("UPDATE users SET password = ? WHERE id = ?", [$hash, $userId]);

        if ($result) {
            Logger::info('修改密码', "用户修改密码", $userId);
            return ['success' => true, 'message' => '密码修改成功'];
        }
        return ['success' => false, 'message' => '密码修改失败'];
    }
}
