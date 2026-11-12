<?php
/**
 * UCenter 2.0 客户端（Discuz 账号互通）
 *
 * 协议兼容：UCenter 1.6 / 2.0（uc_client 协议未变更）
 * 通信方式：HTTP（UC_API/index.php），无需依赖 UCenter 的本地目录
 *
 * 实现要点：
 *  - uc_authcode 加解密（与官方 uc_client 完全一致）
 *  - uc_api_input / uc_api_requestdata / uc_api_post
 *  - 用户接口：注册 / 登录 / 改名 / 删号 / 改密 / 改邮箱 / 校验用户名邮箱
 *  - 同步登录 / 同步退出脚本
 *  - 可选：直连 UCenter 数据库（用于全量导入已有用户）
 */
class UCenter
{
    const CLIENT_VERSION = '2.0.0';
    const CLIENT_RELEASE = '20170101';

    /** @var array|null 配置缓存 */
    private static $config = null;

    /** @var string 最近一次错误 */
    private static $lastError = '';

    /** @var int 最近一次 HTTP 状态码（诊断用） */
    private static $lastHttp = 0;

    /** @var string 最近一次原始响应体（诊断用，截断前 600 字节） */
    private static $lastBody = '';

    // ───────────────────────── 配置 ─────────────────────────

    /**
     * 读取配置：优先 ucenter_settings 表，缺失时回落到 .env
     */
    public static function config($key = null, $default = null)
    {
        if (self::$config === null) {
            $defaults = [
                'uc_enabled'    => Env::get('UC_ENABLED', 'false'),
                'uc_api'        => Env::get('UC_API', ''),
                'uc_ip'         => Env::get('UC_IP', ''),
                'uc_key'        => Env::get('UC_KEY', ''),
                'uc_appid'      => Env::get('UC_APPID', '0'),
                'uc_connect'    => Env::get('UC_CONNECT', 'mysql'),
                'uc_charset'    => Env::get('UC_CHARSET', 'utf-8'),
                'uc_dbhost'     => Env::get('UC_DBHOST', ''),
                'uc_dbuser'     => Env::get('UC_DBUSER', ''),
                'uc_dbpw'       => Env::get('UC_DBPW', ''),
                'uc_dbname'     => Env::get('UC_DBNAME', ''),
                'uc_dbcharset'  => Env::get('UC_DBCHARSET', 'utf8'),
                'uc_dbtablepre' => Env::get('UC_DBTABLEPRE', '`ucenter`.uc_'),
                'uc_dbconnect'  => Env::get('UC_DBCONNECT', '0'),
                // 业务开关
                'uc_sync_register' => '1',  // 本站注册 → 同步到 UCenter
                'uc_sync_login'    => '1',  // 本站登录 → 向其他应用广播同步登录
                'uc_sync_profile'  => '1',  // 本站改资料 → 同步 UCenter
                'uc_sync_password' => '1',  // 本站改密 → 同步 UCenter
                'uc_allow_uc_login' => '1', // UCenter 用户可凭 UCenter 密码登录本站
                'uc_auto_create'    => '1', // UCenter 存在但本站没有 → 自动建号
            ];

            try {
                $db = Database::getInstance();
                $rows = $db->fetchAll("SELECT setting_key, setting_value FROM ucenter_settings");
                foreach ($rows as $row) {
                    $defaults[$row['setting_key']] = $row['setting_value'];
                }
            } catch (\Exception $e) {
                // 表不存在时纯用 .env 配置
            }

            self::$config = $defaults;
        }

        if ($key === null) {
            return self::$config;
        }
        return array_key_exists($key, self::$config) ? self::$config[$key] : $default;
    }

    /**
     * 保存配置到 ucenter_settings 表
     */
    public static function saveConfig(array $pairs)
    {
        $db = Database::getInstance();
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `ucenter_settings` (
                `setting_key` VARCHAR(64) NOT NULL,
                `setting_value` TEXT,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='UCenter 互通配置'"
        );

        foreach ($pairs as $k => $v) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $k)) {
                continue;
            }
            $exists = $db->fetch("SELECT setting_key FROM ucenter_settings WHERE setting_key = ?", [$k]);
            if ($exists) {
                $db->execute("UPDATE ucenter_settings SET setting_value = ? WHERE setting_key = ?", [(string)$v, $k]);
            } else {
                $db->execute(
                    "INSERT INTO ucenter_settings (setting_key, setting_value) VALUES (?, ?)",
                    [$k, (string)$v]
                );
            }
        }
        self::$config = null; // 清缓存
        return true;
    }

    /** 是否已启用且配置完整 */
    public static function enabled()
    {
        $c = self::config();
        return strtolower((string)$c['uc_enabled']) === 'true'
            && !empty($c['uc_api'])
            && !empty($c['uc_key'])
            && (int)$c['uc_appid'] > 0;
    }

    public static function getLastError()
    {
        return self::$lastError;
    }

    /**
     * 最近一次通信诊断（HTTP 状态码 + 原始响应体 + 错误）
     * 用于让「测试连通性」把真实失败原因显示出来，而不是笼统报「未返回有效数据」。
     */
    public static function getLastDiagnostics()
    {
        return [
            'http'  => self::$lastHttp,
            'body'  => self::$lastBody,
            'error' => self::$lastError,
        ];
    }

    private static function setError($msg)
    {
        self::$lastError = $msg;
        error_log('[UCenter] ' . $msg);
        return $msg;
    }

    // ───────────────────── 核心：authcode 加解密 ─────────────────────

    /**
     * 与官方 uc_client 完全一致的加解密算法
     * @param string $string 原文/密文
     * @param string $operation ENCODE|DECODE
     * @param string $key 通信密钥
     * @param int $expiry 有效期（秒）
     */
    public static function authcode($string, $operation = 'DECODE', $key = '', $expiry = 0)
    {
        $ckey_length = 4;
        $key = md5($key ? $key : self::config('uc_key', ''));
        $keya = md5(substr($key, 0, 16));
        $keyb = md5(substr($key, 16, 16));
        $keyc = $ckey_length
            ? ($operation === 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length))
            : '';

        $cryptkey = $keya . md5($keya . $keyc);
        $key_length = strlen($cryptkey);

        $string = $operation === 'DECODE'
            ? base64_decode(substr($string, $ckey_length))
            : sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string . $keyb), 0, 16) . $string;

        $string_length = strlen($string);

        $result = '';
        $box = range(0, 255);
        $rndkey = [];
        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $key_length]);
        }
        for ($j = $i = 0; $i < 256; $i++) {
            $j = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        for ($a = $j = $i = 0; $i < $string_length; $i++) {
            $a = ($a + 1) % 256;
            $j = ($j + $box[$a]) % 256;
            $tmp = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }

        if ($operation === 'DECODE') {
            if ((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0)
                && substr($result, 10, 16) == substr(md5(substr($result, 26) . $keyb), 0, 16)) {
                return substr($result, 26);
            }
            return '';
        }

        return $keyc . str_replace('=', '', base64_encode($result));
    }

    // ───────────────────── 核心：HTTP 通信 ─────────────────────

    /** 统一的出站 User-Agent（必须与 agent 哈希一致，否则 UCenter 报 agent changed） */
    private static function clientUa()
    {
        return 'UCenterClient/' . self::CLIENT_VERSION;
    }

    private static function apiInput($data, $module = '', $action = '')
    {
        $ua = self::clientUa();
        $appid = (string)self::config('uc_appid', 0);
        // UCenter 2.0 的 secureoperation 校验要求密文中同样携带 m/a/appid
        // 且与明文参数一致，否则返回 "Access denied for operation changed"。
        // 多出的字段对 UCenter 1.6 无影响，保持新老版本双向兼容。
        $payload = $data
            . '&agent=' . md5($ua)
            . '&time=' . time()
            . '&m=' . $module
            . '&a=' . $action
            . '&appid=' . $appid;
        return urlencode(self::authcode($payload, 'ENCODE', self::config('uc_key', '')));
    }

    private static function apiRequestData($module, $action, $arg = '', $extra = '')
    {
        $input = self::apiInput($arg, $module, $action);
        return "m={$module}&a={$action}&inajax=2&release=" . self::CLIENT_RELEASE
             . "&input={$input}&appid=" . urlencode((string)self::config('uc_appid', 0)) . $extra;
    }

    /**
     * 向 UCenter 发起请求
     * @return string 响应体（失败返回空串）
     */
    public static function apiPost($module, $action, array $arg = [])
    {
        if (!self::enabled()) {
            self::setError('UCenter 未启用或配置不完整');
            return '';
        }

        $s = $sep = '';
        foreach ($arg as $k => $v) {
            $k = urlencode((string)$k);
            if (is_array($v)) {
                $s2 = $sep2 = '';
                foreach ($v as $k2 => $v2) {
                    $s2 .= $sep2 . $k . '%5B' . urlencode((string)$k2) . '%5D=' . urlencode((string)$v2);
                    $sep2 = '&';
                }
                $s .= $sep . $s2;
            } else {
                $s .= $sep . $k . '=' . urlencode((string)$v);
            }
            $sep = '&';
        }

        $postdata = self::apiRequestData($module, $action, $s);
        $url = rtrim((string)self::config('uc_api', ''), '/') . '/index.php';

        $resp = self::httpRequest($url, $postdata, (string)self::config('uc_ip', ''));

        if ($resp === false || $resp === '') {
            self::setError("请求 UCenter 失败：{$url}");
            return '';
        }
        return $resp;
    }

    /**
     * HTTP 请求（cURL 优先，降级 fsockopen）
     */
    private static function httpRequest($url, $post = '', $ip = '')
    {
        $timeout = 15;

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => $post !== '',
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => self::clientUa(),
                CURLOPT_HTTPHEADER     => ['Expect:'],
            ]);
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                curl_setopt($ch, CURLOPT_RESOLVE, [self::resolveEntry($url, $ip)]);
            }
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body !== false) {
                self::$lastHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                self::$lastBody = $body;
                return $body;
            }
            self::$lastHttp = 0;
            self::$lastBody = '';
            self::setError('cURL 错误：' . $err);
            return false;
        }

        // fsockopen 降级
        $matches = parse_url($url);
        if (empty($matches['host'])) {
            return false;
        }
        $host = $matches['host'];
        $path = ($matches['path'] ?? '/') . (isset($matches['query']) ? '?' . $matches['query'] : '');
        $port = !empty($matches['port']) ? (int)$matches['port'] : (($matches['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $scheme = $matches['scheme'] ?? 'http';
        if ($scheme === 'https') {
            $host = 'ssl://' . $host;
        }

        $fp = @fsockopen(($ip ?: $host), $port, $errno, $errstr, $timeout);
        if (!$fp) {
            self::setError("fsockopen 失败：{$errstr} ({$errno})");
            return false;
        }

        $out = ($post !== '' ? 'POST ' : 'GET ') . $path . " HTTP/1.0\r\n";
        $out .= "Host: " . ($matches['host']) . "\r\n";
        $out .= "User-Agent: " . self::clientUa() . "\r\n";
        if ($post !== '') {
            $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $out .= "Content-Length: " . strlen($post) . "\r\n";
        }
        $out .= "Connection: Close\r\n\r\n" . $post;

        fwrite($fp, $out);
        $body = '';
        while (!feof($fp)) {
            $body .= fgets($fp, 4096);
        }
        fclose($fp);

        // 去掉响应头
        $pos = strpos($body, "\r\n\r\n");
        if ($pos !== false) {
            $body = substr($body, $pos + 4);
        }
        // 记录 HTTP 状态码（fsockopen 路径）
        $statusCode = 0;
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#i', $body, $m)) {
            $statusCode = (int)$m[1];
        }
        self::$lastHttp = $statusCode;
        self::$lastBody = $body;
        return $body;
    }

    /**
     * 构造 CURLOPT_RESOLVE 条目
     */
    private static function resolveEntry($url, $ip)
    {
        $m = parse_url($url);
        $host = $m['host'] ?? '';
        $port = !empty($m['port']) ? (int)$m['port'] : (($m['scheme'] ?? 'http') === 'https' ? 443 : 80);
        return $host . ':' . $port . ':' . $ip;
    }

    /**
     * 响应解码（容错多版本格式）
     *  - UCenter 2.0：结构化数据为 XML（<root><item id=..>…）
     *  - UCenter 1.6：PHP serialize（兼容 addslashes 输出）
     */
    public static function unserialize($data)
    {
        $data = (string)$data;
        if ($data === '') {
            return null;
        }
        $trimmed = ltrim($data);
        if (strpos($trimmed, '<?xml') === 0 || strpos($trimmed, '<root>') === 0) {
            return self::xmlToArray($data);
        }
        $r = @unserialize($data);
        if ($r !== false || $data === serialize(false)) {
            return $r;
        }
        $r = @unserialize(stripslashes($data));
        return $r === false ? null : $r;
    }

    /**
     * UCenter 2.0 的 XML 响应 → PHP 数组
     * 格式形如：<root><item id="1"><item id="appid"><![CDATA[3]]></item>…</item></root>
     */
    private static function xmlToArray($xml)
    {
        if (!function_exists('simplexml_load_string')) {
            return null;
        }
        // UCenter 2.0 固定声明 encoding="ISO-8859-1"，但实际字节是 UC_CHARSET 编码。
        // 按声明解析会破坏中文，这里把声明校正为真实编码再交给 SimpleXML。
        $charset = strtolower((string)self::config('uc_charset', 'utf-8')) ?: 'utf-8';
        if ($charset !== 'utf-8' && preg_match('/encoding=["\']ISO-8859-1["\']/i', $xml)
            && function_exists('mb_convert_encoding')) {
            $xml = mb_convert_encoding($xml, 'UTF-8', strtoupper($charset));
            $charset = 'utf-8';
        }
        $xml = preg_replace('/(<\?xml[^>]*encoding=["\'])[-\w]+(["\'])/i', '${1}' . $charset . '${2}', $xml, 1);

        $oldErrors = libxml_use_internal_errors(true);
        $root = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($oldErrors);
        if ($root === false) {
            return null;
        }
        return self::xmlNodeToArray($root);
    }

    private static function xmlNodeToArray($node)
    {
        $result = [];
        $hasChildren = false;
        foreach ($node->children() as $child) {
            $hasChildren = true;
            $key = (string)$child['id'];
            if ($key === '') {
                $key = $child->getName();
            }
            if ($child->count() > 0) {
                $result[$key] = self::xmlNodeToArray($child);
            } else {
                $result[$key] = (string)$child;
            }
        }
        return $hasChildren ? $result : (string)$node;
    }

    // ───────────────────── 用户接口 ─────────────────────

    /**
     * 注册 UCenter 用户
     * @return int >0 为 uid；负数见 errorText()
     */
    public static function userRegister($username, $password, $email, $regip = '')
    {
        $r = self::apiPost('user', 'register', [
            'username'   => $username,
            'password'   => $password,
            'email'      => $email,
            'questionid' => '',
            'answer'     => '',
            'regip'      => $regip ?: ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        return is_numeric($r) ? (int)$r : (int)trim((string)$r);
    }

    /**
     * UCenter 登录校验
     * @return array [uid, username, password, email, merge]
     */
    public static function userLogin($username, $password, $isuid = 0)
    {
        $r = self::apiPost('user', 'login', [
            'username'   => $username,
            'password'   => $password,
            'isuid'      => (int)$isuid,
            'checkques'  => 0,
            'questionid' => '',
            'answer'     => '',
        ]);
        $data = self::unserialize($r);
        return is_array($data) ? $data : ['0', '', '', '', ''];
    }

    /** 修改 UCenter 用户资料/密码/邮箱 */
    public static function userEdit($username, $oldpw, $newpw, $email, $ignoreoldpw = 0)
    {
        $r = self::apiPost('user', 'edit', [
            'username'     => $username,
            'oldpw'        => $oldpw,
            'newpw'        => $newpw,
            'email'        => $email,
            'ignoreoldpw'  => (int)$ignoreoldpw,
            'questionid'   => '',
            'answer'       => '',
        ]);
        return is_numeric(trim((string)$r)) ? (int)$r : 0;
    }

    /** 删除 UCenter 用户 */
    public static function userDelete($uid)
    {
        return (int)self::apiPost('user', 'delete', ['uid' => (int)$uid]);
    }

    /** UCenter 用户改名 */
    public static function userRename($uid, $oldusername, $newusername)
    {
        return (int)self::apiPost('user', 'renameuser', [
            'uid' => (int)$uid, 'oldusername' => $oldusername, 'newusername' => $newusername,
        ]);
    }

    /** 校验用户名：1 可用 */
    public static function userCheckName($username)
    {
        return (int)self::apiPost('user', 'check_username', ['username' => $username]);
    }

    /** 校验邮箱：1 可用 */
    public static function userCheckEmail($email)
    {
        return (int)self::apiPost('user', 'check_email', ['email' => $email]);
    }

    /** 同步登录脚本（HTML/JS，直接输出到页面） */
    public static function userSynLogin($uid)
    {
        return (string)self::apiPost('user', 'synlogin', ['uid' => (int)$uid]);
    }

    /** 同步退出脚本 */
    public static function userSynLogout()
    {
        return (string)self::apiPost('user', 'synlogout', []);
    }

    /** 取 UCenter 应用列表 */
    public static function appLs()
    {
        $r = self::apiPost('app', 'ls', []);
        return self::unserialize($r) ?: [];
    }

    /**
     * 测试连通性
     * @return array ['success'=>bool,'message'=>string,'detail'=>mixed]
     */
    public static function testConnection()
    {
        if (!self::enabled()) {
            return ['success' => false, 'message' => 'UCenter 未启用或配置不完整（需 uc_api / uc_key / uc_appid）'];
        }
        $start = microtime(true);
        $apps = self::appLs();
        $cost = round((microtime(true) - $start) * 1000);

        if (is_array($apps) && !empty($apps)) {
            return [
                'success' => true,
                'message' => "通信正常（{$cost}ms），共获取到 " . count($apps) . " 个应用",
                'detail'  => $apps,
            ];
        }

        // app.ls 无数据时，退而用 check_username 探测
        $probe = self::userCheckName('uc_probe_' . mt_rand(1000, 9999));
        if ($probe !== 0) {
            return ['success' => true, 'message' => "通信正常（{$cost}ms）", 'detail' => ['probe' => $probe]];
        }

        // ── 构造可操作的失败原因，避免笼统的「未返回有效数据」 ──
        $diag = self::getLastDiagnostics();
        $reason = self::getLastError();

        if (!empty($diag['error'])) {
            $reason = $diag['error'];
        } elseif (!empty($diag['body'])) {
            $snippet = (strlen($diag['body']) > 300)
                ? substr($diag['body'], 0, 300) . '…'
                : $diag['body'];
            $snippet = preg_replace('/[[:^print:]]/', '.', $snippet);
            $reason = "UCenter 返回了非预期内容（HTTP {$diag['http']}）：{$snippet}";
        } elseif ($diag['http'] >= 400) {
            $reason = "UC_API 返回 HTTP {$diag['http']}，接口地址不正确（应指向 uc_server 目录且不含 index.php）";
        } elseif ($diag['http'] > 0 && trim((string)$diag['body']) === '') {
            $reason = "请求已到达但返回空：极可能是 UC_KEY 与 UCenter「应用管理→本应用→通信密钥」不一致，或 UC_APPID 与 UCenter 应用 ID 不符";
        }

        // 额外提示：UC_API 指向了本机/本站，几乎必然不是 UCenter 服务器
        $ucHost  = parse_url((string)self::config('uc_api', ''), PHP_URL_HOST);
        $siteHost = defined('SITE_URL') ? parse_url((string)SITE_URL, PHP_URL_HOST) : '';
        $localHosts = ['127.0.0.1', 'localhost', '::1', gethostbyname(gethostname() ?: 'localhost')];
        if ($ucHost !== '' && (in_array($ucHost, $localHosts, true) || ($siteHost !== '' && $ucHost === $siteHost))) {
            $reason .= '（注意：UC_API 指向了本机/本站，通常不是 UCenter 服务器，请填写 Discuz/UCenter 的 uc_server 完整地址）';
        }

        return [
            'success' => false,
            'message' => '通信失败：' . ($reason ?: 'UCenter 未返回有效数据，请检查 UC_API / UC_KEY / UC_APPID'),
            'detail'  => $apps,
        ];
    }

    /**
     * 错误码转中文
     */
    public static function errorText($code, $type = 'register')
    {
        $map = [
            'register' => [
                -1 => '用户名不合法',
                -2 => '包含不允许注册的词语',
                -3 => '用户名已存在',
                -4 => '邮箱格式有误',
                -5 => '邮箱不允许注册',
                -6 => '邮箱已被注册',
            ],
            'login' => [
                -1 => '用户不存在或已被删除',
                -2 => '密码错误',
                -3 => '安全提问错误',
            ],
            'edit' => [
                0  => '未做任何修改',
                -1 => '原密码错误',
                -4 => '邮箱格式有误',
                -5 => '邮箱不允许注册',
                -6 => '邮箱已被注册',
                -7 => '没有做任何修改',
                -8 => '该用户受保护，无法修改',
            ],
        ];
        $table = $map[$type] ?? [];
        return $table[(int)$code] ?? ('未知错误(' . $code . ')');
    }

    // ───────────────── 可选：直连 UCenter 数据库 ─────────────────

    /** @var PDO|null */
    private static $ucPdo = null;

    /**
     * 获取 UCenter 数据库直连（用于全量导入用户）
     */
    public static function ucDb()
    {
        if (self::$ucPdo instanceof PDO) {
            return self::$ucPdo;
        }
        $c = self::config();
        if (empty($c['uc_dbhost']) || empty($c['uc_dbname'])) {
            return null;
        }
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $c['uc_dbhost'], $c['uc_dbname'], $c['uc_dbcharset'] ?: 'utf8'
            );
            self::$ucPdo = new PDO($dsn, $c['uc_dbuser'], $c['uc_dbpw'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            return self::$ucPdo;
        } catch (\PDOException $e) {
            self::setError('UCenter 数据库连接失败：' . $e->getMessage());
            return null;
        }
    }

    /**
     * 读取 UCenter 用户表（uc_members）
     */
    public static function ucMembers($limit = 1000, $offset = 0)
    {
        $pdo = self::ucDb();
        if (!$pdo) {
            return [];
        }
        $pre = (string)self::config('uc_dbtablepre', 'uc_');
        // 表前缀可能带库名：`ucenter`.uc_
        $table = $pre . 'members';
        $sql = "SELECT uid, username, password, email, regip, regdate, salt FROM {$table} ORDER BY uid ASC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        try {
            return $pdo->query($sql)->fetchAll();
        } catch (\PDOException $e) {
            self::setError('读取 uc_members 失败：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 校验 UCenter 的密码（UCenter 使用 md5(md5(pwd).salt)）
     */
    public static function checkUcPassword($password, $ucPasswordHash, $salt)
    {
        if ($salt === '' || $salt === null) {
            return md5($password) === $ucPasswordHash;
        }
        return md5(md5($password) . $salt) === $ucPasswordHash;
    }
}
