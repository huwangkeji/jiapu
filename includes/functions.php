<?php
/**
 * 通用函数库
 */

/**
 * HTML转义
 */
function e($string)
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * 安全输出 HTML 内容（防止 XSS）
 * 移除危险标签和事件属性，保留安全标签
 */
function safe_html($html)
{
    if (empty($html)) return '';
    
    // 允许的安全标签
    $allowedTags = '<p><br><b><i><u><strong><em><a><ul><ol><li><h1><h2><h3><h4><h5><h6>'
                 . '<img><table><thead><tbody><tr><td><th><blockquote><pre><code><hr>'
                 . '<span><div><sup><sub><del><ins>';
    
    $html = strip_tags($html, $allowedTags);
    
    // 移除危险属性（onclick, onerror 等事件处理器）
    $html = preg_replace('/\s+on\w+\s*=\s*(["\'])[^"\']*\\1/i', '', $html);
    $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html);
    
    // 移除 javascript: 协议
    $html = preg_replace('/href\s*=\s*(["\'])javascript:/i', 'href=$1#', $html);
    
    return $html;
}

/**
 * 生成CSRF Token
 */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 验证CSRF Token
 */
function csrf_verify($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 输出CSRF隐藏字段
 */
function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * 页面跳转
 */
function redirect($url)
{
    header("Location: {$url}");
    exit;
}

/**
 * 返回JSON响应
 */
function json_response($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取GET参数（带默认值）
 */
function get_param($key, $default = '')
{
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

/**
 * 获取POST参数（带默认值）
 */
function post_param($key, $default = '')
{
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

/**
 * 格式化时间
 */
function format_time($datetime, $format = 'Y-m-d H:i')
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    $t = strtotime($datetime);
    return $t ? date($format, $t) : '-';
}

/**
 * 友好时间显示
 */
function friendly_time($datetime)
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    $t = strtotime($datetime);
    $diff = time() - $t;

    if ($diff < 60)       return '刚刚';
    if ($diff < 3600)     return floor($diff / 60) . '分钟前';
    if ($diff < 86400)    return floor($diff / 3600) . '小时前';
    if ($diff < 604800)   return floor($diff / 86400) . '天前';
    if ($diff < 2592000)  return floor($diff / 604800) . '周前';
    return date('Y-m-d', $t);
}

/**
 * 截取字符串
 */
function str_cut($string, $length, $suffix = '...')
{
    if (mb_strlen($string) <= $length) {
        return $string;
    }
    return mb_substr($string, 0, $length) . $suffix;
}

/**
 * 生成随机字符串
 */
function random_str($length = 16)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $str = '';
    for ($i = 0; $i < $length; $i++) {
        $str .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $str;
}

/**
 * 文件上传处理（安全加固版）
 * 使用 Security::validateUpload 进行 MIME 类型验证
 */
function upload_file($file, $subDir = '')
{
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => '上传失败'];
    }

    // 安全验证（MIME类型 + 图片验证 + 扩展名双重检查）
    $validation = Security::validateUpload($file);
    if (!$validation['valid']) {
        return ['success' => false, 'message' => $validation['message']];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // 上传目录安全：防止目录穿越
    $subDir = Security::sanitizePath($subDir);
    $dir = UPLOAD_PATH . ($subDir ? $subDir . '/' : '');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        // 写入 .htaccess 防止目录执行
        @file_put_contents($dir . '.htaccess', "Deny from all\n<FilesMatch \"\\.(jpg|jpeg|png|gif|webp)$\">\nAllow from all\n</FilesMatch>\n");
    }

    $filename = date('Ymd_His') . '_' . random_str(8) . '.' . $ext;
    $filepath = $dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        $webPath = '/uploads/' . ($subDir ? $subDir . '/' : '') . $filename;
        return ['success' => true, 'path' => $webPath, 'filename' => $filename];
    }

    return ['success' => false, 'message' => '文件保存失败'];
}

/**
 * 保存 Base64 编码的 PNG 头像
 * @param string $base64 data:image/png;base64,... 格式的数据
 * @return string 头像Web路径
 */
function saveBase64Avatar($base64)
{
    // 去掉 data URI 前缀
    $data = substr($base64, strpos($base64, ',') + 1);
    $data = base64_decode($data);
    if (!$data) return '';

    $dir = UPLOAD_PATH . 'avatar/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = date('Ymd_His') . '_crop_' . random_str(8) . '.png';
    $filepath = $dir . $filename;

    if (file_put_contents($filepath, $data)) {
        return '/uploads/avatar/' . $filename;
    }
    return '';
}

/**
 * 分页HTML生成
 */
function pagination_html($currentPage, $totalPages, $baseUrl = '')
{
    if ($totalPages <= 1) return '';

    // BUGFIX: 自动判断分隔符。此前强制使用 "&page="，
    // 当 $baseUrl 不含 "?" 时会拼出 /my.php&page=2 这类非法 URL。
    $sep = (strpos($baseUrl, '?') === false) ? '?' : '&';

    $html = '<nav class="pagination-nav">';
    $html .= '<ul class="pagination">';

    // 上一页
    if ($currentPage > 1) {
        $html .= '<li><a href="' . e($baseUrl . $sep . 'page=' . ($currentPage - 1)) . '">&laquo; 上一页</a></li>';
    } else {
        $html .= '<li class="disabled"><span>&laquo; 上一页</span></li>';
    }

    // 页码
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $html .= '<li><a href="' . e($baseUrl . $sep . 'page=1') . '">1</a></li>';
        if ($start > 2) $html .= '<li class="disabled"><span>...</span></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        if ($i == $currentPage) {
            $html .= '<li class="active"><span>' . $i . '</span></li>';
        } else {
            $html .= '<li><a href="' . e($baseUrl . $sep . 'page=' . $i) . '">' . $i . '</a></li>';
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<li class="disabled"><span>...</span></li>';
        $html .= '<li><a href="' . e($baseUrl . $sep . 'page=' . $totalPages) . '">' . $totalPages . '</a></li>';
    }

    // 下一页
    if ($currentPage < $totalPages) {
        $html .= '<li><a href="' . e($baseUrl . $sep . 'page=' . ($currentPage + 1)) . '">下一页 &raquo;</a></li>';
    } else {
        $html .= '<li class="disabled"><span>下一页 &raquo;</span></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

/**
 * 获取站点设置（带请求级缓存）
 * 首次调用全表加载到静态变量，后续调用直接读内存
 */
function get_site_setting($key, $default = '')
{
    // BUGFIX: 原先使用函数内的 static 变量，clear_site_setting_cache() 无法真正清除，
    // 导致后台保存设置后同一请求内仍读到旧值。改用全局数组承载缓存。
    if (!isset($GLOBALS['_site_settings_cache']) || !is_array($GLOBALS['_site_settings_cache'])) {
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll("SELECT setting_key, setting_value FROM site_settings");
            $cache = [];
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
            $GLOBALS['_site_settings_cache'] = $cache;
        } catch (Exception $e) {
            $GLOBALS['_site_settings_cache'] = [];
        }
    }
    return $GLOBALS['_site_settings_cache'][$key] ?? $default;
}

/**
 * 清除设置缓存（保存设置后调用）
 */
function clear_site_setting_cache()
{
    unset($GLOBALS['_site_settings_cache']);
}

/**
 * 设置站点配置
 */
function set_site_setting($key, $value)
{
    $db = Database::getInstance();
    $exists = $db->fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
    if ($exists) {
        $ok = $db->execute("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
    } else {
        $ok = $db->execute("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
    }
    // 写库后立即失效缓存，保证同请求内再次读取拿到新值
    unset($GLOBALS['_site_settings_cache'][$key]);
    clear_site_setting_cache();
    return $ok;
}

/**
 * 性别显示
 */
function gender_text($gender)
{
    $map = ['male' => '男', 'female' => '女', 'unknown' => '未知'];
    return $map[$gender] ?? '未知';
}

/**
 * 状态显示
 */
function status_text($status)
{
    return $status ? '正常' : '禁用';
}
