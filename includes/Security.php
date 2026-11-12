<?php
/**
 * Security - 安全防护中间件
 * 
 * 防护能力：
 * - 暴力破解防护（登录限流 + IP 黑名单）
 * - XSS 防护增强（输出过滤 + CSP 头）
 * - 请求频率限制（API 接口防刷）
 * - 文件上传安全加固（MIME 类型检测 + 图片验证）
 * - Session 安全加固（指纹绑定 + HTTPS Only）
 * - 安全响应头（X-Frame-Options, X-Content-Type-Options 等）
 * - 输入净化（递归过滤 + 类型校验）
 */
class Security
{
    // ─── 暴力破解防护 ───

    /** @var int 登录失败最大次数 */
    const MAX_LOGIN_ATTEMPTS = 5;

    /** @var int 封禁时长（秒） */
    const BAN_DURATION = 1800; // 30分钟

    /** @var int 两次登录最小间隔（秒） */
    const LOGIN_THROTTLE = 2;

    /**
     * 检查IP是否被临时封禁
     */
    public static function isIpBanned()
    {
        $ip = self::getClientIp();
        if (empty($ip)) return false;

        try {
            $db = Database::getInstance();
            $row = $db->fetch(
                "SELECT * FROM ip_blacklist WHERE ip = ? AND ban_until > NOW()",
                [$ip]
            );
            return !empty($row);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 记录一次登录失败
     * @return array ['attempts' => int, 'banned' => bool]
     */
    public static function recordLoginFailure($username = '')
    {
        $ip = self::getClientIp();
        $result = ['attempts' => 0, 'banned' => false];

        try {
            $db = Database::getInstance();

            // 清理过期记录
            $db->execute("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");

            // 记录本次失败
            $db->execute(
                "INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, NOW())",
                [$ip, $username ?: '']
            );

            // 统计最近30分钟内的失败次数
            $count = $db->count(
                "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
                [$ip]
            );

            $result['attempts'] = $count;

            // 超过阈值则封禁IP
            if ($count >= self::MAX_LOGIN_ATTEMPTS) {
                $db->execute(
                    "INSERT INTO ip_blacklist (ip, reason, ban_until, created_at) VALUES (?, '登录暴力破解', DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
                     ON DUPLICATE KEY UPDATE ban_until = DATE_ADD(NOW(), INTERVAL ? SECOND)",
                    [$ip, self::BAN_DURATION, self::BAN_DURATION]
                );
                $result['banned'] = true;

                Logger::warning('安全防护', "IP {$ip} 因连续登录失败被封禁30分钟");
            }

        } catch (\Exception $e) {
            // 安全模块不能阻塞主流程
            error_log('Security::recordLoginFailure error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * 登录成功后清除失败记录
     */
    public static function clearLoginFailures()
    {
        $ip = self::getClientIp();
        try {
            $db = Database::getInstance();
            $db->execute("DELETE FROM login_attempts WHERE ip = ?", [$ip]);
        } catch (\Exception $e) {
            // 忽略
        }
    }

    /**
     * 检查登录频率限制
     * @return string|null 错误消息，null 表示通过
     */
    public static function checkLoginThrottle()
    {
        // 先检查 IP 是否被封禁
        if (self::isIpBanned()) {
            return '您的IP已被临时封禁，请30分钟后再试';
        }

        $ip = self::getClientIp();
        try {
            $db = Database::getInstance();
            $last = $db->fetch(
                "SELECT attempted_at FROM login_attempts WHERE ip = ? ORDER BY attempted_at DESC LIMIT 1",
                [$ip]
            );
            if ($last) {
                $lastTime = strtotime($last['attempted_at']);
                if (time() - $lastTime < self::LOGIN_THROTTLE) {
                    return '操作过于频繁，请稍后再试';
                }
            }
        } catch (\Exception $e) {
            // 忽略
        }

        return null;
    }

    // ─── 通用频率限制 ───

    /**
     * 通用请求频率限制（文件存储，无需额外表）
     *
     * @param string $bucket 业务标识（如 forgot_password）
     * @param int    $limit  时间窗内允许的最大次数
     * @param int    $window 时间窗（秒）
     * @return bool true 表示放行，false 表示已超限
     */
    public static function throttle($bucket, $limit = 5, $window = 600)
    {
        $bucket = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$bucket);
        if ($bucket === '') {
            return true;
        }

        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/.throttle_' . $bucket . '.json';
        $key  = self::getClientIp();
        $now  = time();

        $data = [];
        if (is_file($file)) {
            $raw = json_decode((string)@file_get_contents($file), true);
            if (is_array($raw)) {
                $data = $raw;
            }
        }

        $hits = array_values(array_filter((array)($data[$key] ?? []), function ($t) use ($now, $window) {
            return ($now - (int)$t) < $window;
        }));

        if (count($hits) >= $limit) {
            $data[$key] = $hits;
            @file_put_contents($file, json_encode($data), LOCK_EX);
            return false;
        }

        $hits[] = $now;
        $data[$key] = $hits;

        // 清理过期记录，避免文件无限增长
        foreach ($data as $k => $v) {
            $v = array_filter((array)$v, function ($t) use ($now, $window) {
                return ($now - (int)$t) < $window;
            });
            if (empty($v)) {
                unset($data[$k]);
            } else {
                $data[$k] = array_values($v);
            }
        }
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }

    // ─── 输入净化 ───

    /**
     * 递归净化输入（用于 $_GET / $_POST / $_REQUEST）
     * 去除NULL字节、控制字符、前后空白
     */
    public static function sanitizeInput(&$data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                self::sanitizeInput($data[$key]);
            }
        } elseif (is_string($data)) {
            // 移除NULL字节
            $data = str_replace("\0", '', $data);
            // 移除不可见控制字符（保留换行和制表符）
            $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data);
            // 前后去空白
            $data = trim($data);
        }
    }

    /**
     * 强类型整数校验
     * @param mixed $value
     * @param int $min
     * @param int $max
     * @return int
     */
    public static function int($value, $min = null, $max = null)
    {
        $int = (int)$value;
        if ($min !== null && $int < $min) $int = $min;
        if ($max !== null && $int > $max) $int = $max;
        return $int;
    }

    /**
     * 强类型正整数校验
     */
    public static function positiveInt($value)
    {
        return max(1, (int)$value);
    }

    /**
     * 校验并清理文件路径（防止目录穿越）
     */
    public static function sanitizePath($path)
    {
        // 移除 ../ 和绝对路径
        $path = str_replace(['../', '..\\', '\\'], '', $path);
        $path = preg_replace('#/+#', '/', $path);
        return ltrim($path, '/');
    }

    /**
     * 校验表名/列名（只允许字母数字下划线）
     */
    public static function sanitizeIdentifier($name)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("非法标识符: {$name}");
        }
        return $name;
    }

    // ─── 文件上传安全 ───

    /** @var array 允许的 MIME 类型映射 */
    const ALLOWED_MIME_TYPES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    /**
     * 验证上传文件的安全性
     * @param array $file $_FILES 中的文件项
     * @return array ['valid' => bool, 'message' => string]
     */
    public static function validateUpload(array $file)
    {
        // 基本检查
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'message' => '上传失败'];
        }

        // 文件大小
        if ($file['size'] > UPLOAD_MAX_SIZE) {
            return ['valid' => false, 'message' => '文件大小不能超过5MB'];
        }

        // 扩展名检查
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_MIME_TYPES[$ext])) {
            return ['valid' => false, 'message' => '不允许的文件类型'];
        }

        // MIME 类型检查（通过 finfo，比 $_FILES['type'] 可靠）
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES[$ext])) {
            return ['valid' => false, 'message' => '文件实际类型与扩展名不匹配'];
        }

        // 图片验证（确认是真正的图片文件）
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return ['valid' => false, 'message' => '文件不是有效的图片'];
        }

        // 检查文件名中的脚本标签（防止双扩展名攻击）
        $basename = pathinfo($file['name'], PATHINFO_BASENAME);
        if (preg_match('/\.(php|phtml|php3|php4|php5|pht|phar|asp|aspx|jsp|cgi|pl|py|rb|sh|bat|cmd|exe|dll|msi)$/i', $basename)) {
            return ['valid' => false, 'message' => '禁止上传可执行文件'];
        }

        return ['valid' => true, 'message' => ''];
    }

    // ─── 安全响应头 ───

    /**
     * 设置安全相关的 HTTP 响应头
     * 在 header.php 中调用一次
     */
    public static function setSecurityHeaders()
    {
        // 防止点击劫持
        header('X-Frame-Options: SAMEORIGIN');

        // 防止 MIME 嗅探
        header('X-Content-Type-Options: nosniff');

        // XSS 保护（旧浏览器兼容）
        header('X-XSS-Protection: 1; mode=block');

        // 引用来源策略
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // 权限策略
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // 额外放行的域名（UCenter 跨应用同步登录需要加载其他应用的脚本）
        $extra = [];
        $extraRaw = defined('CSP_EXTRA_SCRIPT_SRC') ? (string)CSP_EXTRA_SCRIPT_SRC : '';
        foreach (explode(',', $extraRaw) as $host) {
            $host = trim($host);
            if ($host === '') continue;
            // 只接受合法的主机名/IP，拒绝携带协议、路径或通配符，防止 CSP 被注入破坏
            if (preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*(:\d{1,5})?$/', $host)) {
                $extra[] = $host;
            }
        }
        $extraSrc = $extra ? ' ' . implode(' ', $extra) : '';

        // Content Security Policy（渐进式，不阻断正常功能）
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'" . $extraSrc,   // 需要unsafe-inline因为有内联JS
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:" . $extraSrc,
            "font-src 'self'",
            "connect-src 'self'" . $extraSrc,
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));

        // HSTS（仅HTTPS时启用）
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    // ─── Session 安全 ───

    /**
     * 初始化安全 Session 配置
     * 在 session_start() 之前调用
     */
    public static function initSecureSession()
    {
        // Session Cookie 安全设置
        ini_set('session.cookie_httponly', '1');     // JS 不可读取
        ini_set('session.use_strict_mode', '1');      // 拒绝未初始化的 Session ID
        ini_set('session.use_only_cookies', '1');     // 禁止 URL 传 Session ID
        ini_set('session.cookie_samesite', 'Lax');    // CSRF 防护

        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');   // HTTPS Only
        }

        // Session 指纹绑定（防止 Session Hijacking）
        session_start();

        $fingerprint = self::generateFingerprint();
        if (isset($_SESSION['_security_fp'])) {
            if ($_SESSION['_security_fp'] !== $fingerprint) {
                // 指纹不匹配 = 被劫持，销毁 Session
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['_security_fp'] = $fingerprint;
            }
        } else {
            $_SESSION['_security_fp'] = $fingerprint;
        }
    }

    /**
     * 生成浏览器指纹（IP + User-Agent）
     */
    private static function generateFingerprint()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return hash('sha256', $ip . '|' . $ua . '|' . SECURITY_SALT);
    }

    // ─── 辅助方法 ───

    /**
     * 安全获取客户端IP（防止 IP 伪造）
     */
    public static function getClientIp()
    {
        // 不信任 X-Forwarded-For（容易被伪造）
        // 只使用 REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * 生成安全的随机令牌
     */
    public static function generateToken($length = 32)
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * 安全地比较两个字符串（时序安全）
     */
    public static function secureCompare($a, $b)
    {
        if (function_exists('hash_equals')) {
            return hash_equals((string)$a, (string)$b);
        }
        // 降级方案
        return (string)$a === (string)$b;
    }

    /**
     * 检查密码强度
     * @return array ['score' => 0-4, 'message' => string]
     */
    public static function checkPasswordStrength($password)
    {
        $score = 0;
        $len = mb_strlen($password);

        if ($len >= 6)  $score++;
        if ($len >= 10) $score++;
        if (preg_match('/[A-Z]/', $password) && preg_match('/[a-z]/', $password)) $score++;
        if (preg_match('/\d/', $password)) $score++;
        if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;

        $messages = [
            0 => '非常弱',
            1 => '弱',
            2 => '一般',
            3 => '强',
            4 => '非常强',
            5 => '极强',
        ];

        return ['score' => min($score, 5), 'message' => $messages[min($score, 5)]];
    }
}
