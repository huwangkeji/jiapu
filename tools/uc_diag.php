<?php
/**
 * UCenter 连通性诊断脚本（仅用于排查，可安全删除）
 *
 * 用法（在站点根目录执行）：
 *   D:\phpstudy_pro\Extensions\php\php7.4.3nts\php.exe tools\uc_diag.php
 *
 * 它会：
 *   1. 读取 .env + 数据库 ucenter_settings 得到「有效配置」
 *   2. 用与 includes/UCenter.php 完全相同的 authcode 算法构造请求
 *   3. 实测 m=app&a=ls 与 m=user&a=check_username 的 HTTP 状态、耗时、原始返回体
 *   4. 做一次 authcode 本地加解密自测
 *   5. DNS 解析 UC_API 主机
 *
 * 输出即可直接判断卡在网络 / 密钥 / appid / UCenter 内部错误。
 */

// ── 1. 读 .env ──
$env = [];
$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if ((($v[0] ?? '') === '"' && substr($v, -1) === '"') ||
            (($v[0] ?? '') === "'" && substr($v, -1) === "'")) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
}
function env($k, $d = null) { global $env; return $env[$k] ?? $d; }

// ── 2. 读数据库 ucenter_settings（覆盖 .env） ──
$dbConf = [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'name' => env('DB_NAME', 'family_tree'),
    'user' => env('DB_USER', 'root'),
    'pass' => env('DB_PASS', ''),
];
$settings = [];
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConf['host'], $dbConf['port'], $dbConf['name']),
        $dbConf['user'], $dbConf['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rows = $pdo->query("SELECT setting_key, setting_value FROM ucenter_settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Throwable $e) {
    echo "⚠ 数据库读取失败（ucenter_settings 可能不存在）：" . $e->getMessage() . "\n";
}

$cfg = [
    'uc_enabled' => $settings['uc_enabled'] ?? env('UC_ENABLED', 'false'),
    'uc_api'     => $settings['uc_api']     ?? env('UC_API', ''),
    'uc_ip'      => $settings['uc_ip']      ?? env('UC_IP', ''),
    'uc_key'     => $settings['uc_key']     ?? env('UC_KEY', ''),
    'uc_appid'   => $settings['uc_appid']   ?? env('UC_APPID', '0'),
];

echo "================ 有效配置（已合并 DB 覆盖）================\n";
echo "uc_enabled : " . $cfg['uc_enabled'] . "\n";
echo "uc_api     : " . ($cfg['uc_api'] ?: '(空)') . "\n";
echo "uc_ip      : " . ($cfg['uc_ip'] ?: '(空)') . "\n";
echo "uc_key     : " . (strlen($cfg['uc_key']) ? substr($cfg['uc_key'], 0, 3) . '***' . substr($cfg['uc_key'], -3) . ' (len=' . strlen($cfg['uc_key']) . ')' : '(空)') . "\n";
echo "uc_appid   : " . $cfg['uc_appid'] . "\n";
echo "--------------------------------------------------------\n";

$enabled = strtolower((string)$cfg['uc_enabled']) === 'true'
    && !empty($cfg['uc_api']) && !empty($cfg['uc_key']) && (int)$cfg['uc_appid'] > 0;
echo "enabled()  : " . ($enabled ? "TRUE（会真正发起请求）" : "FALSE（不会发起请求）") . "\n";
if (!$enabled) {
    echo "\n结论：UCenter 未启用或配置不完整。请到后台「UCenter 互通」页填写并保存\n";
    echo "       UC_API / UC_KEY / UC_APPID，再运行本脚本。\n";
    exit(0);
}

// ── 3. authcode（与 includes/UCenter.php 完全一致） ──
function authcode($string, $operation = 'DECODE', $key = '', $expiry = 0)
{
    $ckey_length = 4;
    $key = md5($key ? $key : '');
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
        $tmp = $box[$i]; $box[$i] = $box[$j]; $box[$j] = $tmp;
    }
    for ($a = $j = $i = 0; $i < $string_length; $i++) {
        $a = ($a + 1) % 256;
        $j = ($j + $box[$a]) % 256;
        $tmp = $box[$a]; $box[$a] = $box[$j]; $box[$j] = $tmp;
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

// ── 4. 构造请求 + 发送（与 UCenter.php 一致） ──
function apiInput($data, $module, $action, $ucKey, $ucAppid)
{
    $ua = 'UCenterClient/2.0.0'; // 与 CURLOPT_USERAGENT 保持一致
    $payload = $data . '&agent=' . md5($ua) . '&time=' . time()
        . '&m=' . $module . '&a=' . $action . '&appid=' . $ucAppid;
    return urlencode(authcode($payload, 'ENCODE', $ucKey));
}
function apiRequestData($module, $action, $arg, $ucKey, $ucAppid)
{
    $input = apiInput($arg, $module, $action, $ucKey, $ucAppid);
    return "m={$module}&a={$action}&inajax=2&release=20170101&input={$input}&appid=" . urlencode((string)$ucAppid);
}
function doProbe($module, $action, $arg, $cfg)
{
    $post = apiRequestData($module, $action, $arg, $cfg['uc_key'], $cfg['uc_appid']);
    $url = rtrim((string)$cfg['uc_api'], '/') . '/index.php';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'UCenterClient/2.0.0',
        CURLOPT_HTTPHEADER => ['Expect:'],
    ]);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return [
        'url' => $url,
        'http_code' => $info['http_code'] ?? 0,
        'time' => round(($info['total_time'] ?? 0) * 1000),
        'err' => $err,
        'body' => $body === false ? '' : $body,
    ];
}

// ── 5. DNS 解析 ──
$host = parse_url($cfg['uc_api'], PHP_URL_HOST);
echo "\n[DNS] UC_API 主机 = " . ($host ?: '(无法解析)') . "\n";
if ($host) {
    $ip = gethostbyname($host);
    echo "      解析结果 = " . ($ip === $host ? "解析失败（域名不存在/无法解析）" : $ip) . "\n";
}

// ── 6. authcode 自测 ──
echo "\n[authcode 自测]\n";
$sample = 'username=diag_test&password=123456&isuid=0&agent=' . md5('x') . '&time=' . time();
$enc = authcode($sample, 'ENCODE', $cfg['uc_key']);
$dec = authcode($enc, 'DECODE', $cfg['uc_key']);
echo "  编码后长度 = " . strlen($enc) . "\n";
echo "  解码还原   = " . ($dec === $sample ? "OK ✅" : "失败 ❌") . "\n";

// ── 7. 实跑 app.ls / check_username ──
echo "\n[实测 A] m=app&a=ls\n";
$r1 = doProbe('app', 'ls', '', $cfg);
echo "  HTTP " . $r1['http_code'] . "  耗时 " . $r1['time'] . "ms  curl错误: " . ($r1['err'] ?: '无') . "\n";
echo "  原始返回体(前800字节):\n" . substr(preg_replace('/[[:^print:]]/', '.', $r1['body']), 0, 800) . "\n";

echo "\n[实测 B] m=user&a=check_username (随机名 uc_diag_XXXX)\n";
$probeName = 'uc_diag_' . mt_rand(1000, 9999);
$r2 = doProbe('user', 'check_username', 'username=' . urlencode($probeName), $cfg);
echo "  HTTP " . $r2['http_code'] . "  耗时 " . $r2['time'] . "ms  curl错误: " . ($r2['err'] ?: '无') . "\n";
echo "  原始返回体(前800字节):\n" . substr(preg_replace('/[[:^print:]]/', '.', $r2['body']), 0, 800) . "\n";

// ── 7.5 全链路实测：注册 → 登录 → 删号 ──
function parseUcResp($body) {
    $t = ltrim((string)$body);
    if ($t !== '' && (strpos($t, '<?xml') === 0 || strpos($t, '<root>') === 0)) {
        $x = preg_replace('/(<\?xml[^>]*encoding=["\'])[-\w]+(["\'])/i', '${1}UTF-8${2}', $t, 1);
        $root = @simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($root === false) return null;
        $out = [];
        $walk = function ($node) use (&$walk, &$out) {
            foreach ($node->children() as $c) {
                $k = (string)$c['id'];
                $out[$k] = $c->count() > 0 ? $walk($c) : (string)$c;
            }
            return $out;
        };
        return $walk($root);
    }
    return $body;
}

echo "\n[实测 C] 全链路：注册 → 登录 → 删号（测试账号，跑完即删）\n";
$testUser = 'uc_diag_' . mt_rand(10000, 99999);
$testPass = 'Diag' . mt_rand(100000, 999999) . '!';

$r = doProbe('user', 'register', http_build_query([
    'username' => $testUser, 'password' => $testPass,
    'email' => $testUser . '@diag.local', 'regip' => '127.0.0.1',
]), $cfg);
$uid = trim($r['body']);
echo "  注册 {$testUser} → uid=" . $uid . "\n";

if ((int)$uid > 0) {
    $r = doProbe('user', 'login', http_build_query([
        'username' => $testUser, 'password' => $testPass, 'isuid' => 0, 'checkques' => 0,
    ]), $cfg);
    $info = parseUcResp($r['body']);
    echo "  登录校验 → " . ((int)($info[0] ?? 0) === (int)$uid
            ? "✅ 密码验证通过（uid=" . $info[0] . "，email=" . $info[3] . "）"
            : "❌ 返回: " . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 200)) . "\n";

    $r = doProbe('user', 'delete', http_build_query(['uid' => $uid]), $cfg);
    echo "  删号清理 → 返回 " . trim($r['body']) . "（1=成功）\n";
} else {
    echo "  ⚠ 注册未成功（可能用户名/邮箱规则限制），跳过登录实测。\n";
}

// ── 8. 结论 ──
echo "\n================ 结论速判 ================\n";
if ($r1['err']) {
    echo "• 网络层失败（curl 错误）：" . $r1['err'] . "\n";
    echo "  → 检查 UC_API 是否可达、端口/协议(https?)是否正确、服务器防火墙。\n";
} elseif ($r1['http_code'] == 0) {
    echo "• 无法建立连接（HTTP 0）：UC_API 地址/端口/域名有误，或目标服务器未启动。\n";
} elseif ($r1['http_code'] >= 400) {
    echo "• UCenter 返回 HTTP {$r1['http_code']}：UC_API 路径不对（应指向 uc_server 目录）。\n";
} elseif (strpos($r1['body'], '<root>') !== false || strpos($r1['body'], '<?xml') !== false) {
    echo "• ✅ 通信正常！UCenter 2.0 返回了 XML 应用列表。\n";
    // 解析并展示应用名
    $apps = parseUcResp($r1['body']);
    if (is_array($apps)) {
        foreach ($apps as $app) {
            if (!is_array($app)) continue;
            echo "   - appid={$app['appid']}  name={$app['name']}  url={$app['url']}  synlogin={$app['synlogin']}\n";
        }
    }
    echo "• check_username 探测返回: " . trim($r2['body']) . "（1=可用，说明用户接口也通）\n";
    echo "• 建议：到后台「UCenter 互通」点「测试连通性」做最终确认。\n";
} elseif (trim($r1['body']) === '') {
    echo "• 请求成功但返回空：几乎肯定是 UC_KEY 不一致（UCenter 无法解密 input）。\n";
    echo "  → 请把本站 UC_KEY 改为 UCenter 后台「应用管理→本应用→通信密钥」里的完全一致的值。\n";
} else {
    echo "• 返回非空但无法识别（返回体见上）。原文如下：\n  " . substr($r1['body'], 0, 200) . "\n";
}
echo "==========================================\n";
