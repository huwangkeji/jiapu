<?php
/**
 * 邮件发送类
 * 支持 SMTP 发送（含连接复用/批量发送），配置存储在 site_settings 表
 *
 * 改进点：
 * - SMTP 连接复用：批量发送时复用同一 TCP 连接，避免重复握手
 * - dot-stuffing：DATA 中行首的点号自动转义
 * - CRLF 规范化：确保邮件体使用 \r\n 换行
 * - 长行折叠：超过 998 字符的行自动折叠（RFC 5322）
 */
class Mailer
{
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $fromName;
    private $fromEmail;

    /** @var resource|null 当前复用的 SMTP 连接 */
    private $socket = null;

    /** @var int 连接超时秒数 */
    private $timeout = 15;

    /** @var bool 是否已认证 */
    private $authenticated = false;

    public function __construct()
    {
        $db = Database::getInstance();
        $this->host       = self::getSetting('smtp_host', '');
        $this->port       = (int)self::getSetting('smtp_port', '587');
        $this->username   = self::getSetting('smtp_user', '');
        $this->password   = self::getSetting('smtp_pass', '');
        $this->encryption = self::getSetting('smtp_encryption', 'tls');
        $this->fromName   = self::getSetting('smtp_from_name', SITE_NAME);
        $this->fromEmail  = self::getSetting('smtp_from_email', '');
        // fromEmail 为空时自动使用 smtp_user（大多数 SMTP 要求发件邮箱跟认证邮箱一致）
        if (empty($this->fromEmail) && !empty($this->username) && strpos($this->username, '@') !== false) {
            $this->fromEmail = $this->username;
        }
    }

    private static function getSetting($key, $default = '')
    {
        try {
            $db = Database::getInstance();
            $row = $db->fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
            return $row ? $row['setting_value'] : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * 检查是否已配置
     */
    public function isConfigured()
    {
        return !empty($this->host) && !empty($this->username) && !empty($this->password) && !empty($this->fromEmail);
    }

    /**
     * 测试 SMTP 连接（发送 EHLO + AUTH，但不实际发送邮件）
     * @return array ['success'=>bool, 'message'=>string, 'details'=>array]
     */
    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'SMTP 未配置', 'details' => []];
        }

        $details = [];
        $startTime = microtime(true);

        try {
            $errno = 0;
            $errstr = '';
            $hostStr = $this->host;
            if ($this->encryption === 'ssl') {
                $hostStr = 'ssl://' . $this->host;
            }

            $socket = @stream_socket_client(
                "{$hostStr}:{$this->port}",
                $errno,
                $errstr,
                10, // 连接超时10秒
                STREAM_CLIENT_CONNECT
            );

            if (!$socket) {
                $msg = "无法连接到 {$hostStr}:{$this->port}";
                if ($errno) {
                    $msg .= " (错误码 {$errno}: {$errstr})";
                } else {
                    $msg .= " — 请检查：1) 主机地址是否正确 2) 端口号是否正确 3) 服务器防火墙是否放行该端口";
                }
                return ['success' => false, 'message' => $msg, 'details' => $details];
            }

            stream_set_timeout($socket, 10);
            $details['connect_time'] = round((microtime(true) - $startTime) * 1000) . 'ms';

            // 读欢迎消息
            $welcome = $this->readLineForTest($socket);
            $details['welcome'] = trim($welcome);
            if (substr($welcome, 0, 3) !== '220') {
                fclose($socket);
                return ['success' => false, 'message' => "SMTP 未就绪: " . trim($welcome), 'details' => $details];
            }

            // EHLO
            fwrite($socket, "EHLO smtp-test\r\n");
            $ehloResp = $this->readAllForTest($socket);
            $details['ehlo'] = substr(trim($ehloResp), 0, 200);

            // TLS 升级
            if ($this->encryption === 'tls') {
                fwrite($socket, "STARTTLS\r\n");
                $tlsResp = $this->readLineForTest($socket);
                $details['starttls'] = trim($tlsResp);
                if (substr($tlsResp, 0, 3) === '220') {
                    $cryptoResult = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    if ($cryptoResult === false) {
                        fclose($socket);
                        return ['success' => false, 'message' => "TLS 握手失败 — 可能原因：服务器不支持 TLS 或证书无效，尝试改用 SSL 加密方式", 'details' => $details];
                    }
                    $details['tls'] = 'TLS 握手成功';
                    fwrite($socket, "EHLO smtp-test\r\n");
                    $this->readAllForTest($socket);
                } else {
                    fclose($socket);
                    return ['success' => false, 'message' => "STARTTLS 被拒绝: " . trim($tlsResp), 'details' => $details];
                }
            }

            // AUTH LOGIN
            fwrite($socket, "AUTH LOGIN\r\n");
            $authResp = $this->readLineForTest($socket);
            if (substr($authResp, 0, 3) !== '334') {
                fclose($socket);
                return ['success' => false, 'message' => "AUTH LOGIN 被拒绝: " . trim($authResp), 'details' => $details];
            }

            fwrite($socket, base64_encode($this->username) . "\r\n");
            $userResp = $this->readLineForTest($socket);
            fwrite($socket, base64_encode($this->password) . "\r\n");
            $passResp = $this->readLineForTest($socket);

            if (substr($passResp, 0, 3) !== '235') {
                fclose($socket);
                $details['auth_response'] = trim($passResp);
                $msg = "SMTP 认证失败: " . trim($passResp);
                if (strpos($passResp, '535') !== false) {
                    $msg .= " — 用户名或密码错误";
                    if (strpos($this->username, 'qq.com') !== false || strpos($this->host, 'qq.com') !== false) {
                        $msg .= "（QQ邮箱请使用授权码，不是QQ密码！在QQ邮箱→设置→账户→POP3/SMTP→生成授权码）";
                    }
                }
                return ['success' => false, 'message' => $msg, 'details' => $details];
            }

            fwrite($socket, "QUIT\r\n");
            fclose($socket);

            $details['auth'] = '认证成功';
            $details['total_time'] = round((microtime(true) - $startTime) * 1000) . 'ms';

            return [
                'success' => true,
                'message' => "SMTP 连接测试成功！主机 {$this->host}:{$this->port}，加密方式 {$this->encryption}",
                'details' => $details
            ];

        } catch (\Exception $e) {
            if (isset($socket) && is_resource($socket)) {
                fclose($socket);
            }
            return ['success' => false, 'message' => $e->getMessage(), 'details' => $details];
        }
    }

    /**
     * 测试连接时读取一行
     */
    private function readLineForTest($socket)
    {
        $line = fgets($socket, 512);
        return $line !== false ? $line : '';
    }

    /**
     * 测试连接时读取多行响应
     */
    private function readAllForTest($socket)
    {
        $response = '';
        while (($line = fgets($socket, 512)) !== false) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $response;
    }

    /**
     * 发送单封邮件（自动管理连接生命周期）
     */
    public function send($to, $subject, $body, $attachments = [])
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'SMTP 未配置'];
        }

        try {
            // 确保连接已建立
            $this->ensureConnected();

            $result = $this->sendData($to, $subject, $body);

            if (!$result['success']) {
                // SMTP 失败尝试 mail() 降级
                return $this->mailSendFallback($to, $subject, $body);
            }
            return $result;
        } catch (\Exception $e) {
            $this->disconnect();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 批量发送邮件（复用同一个 SMTP 连接）
     * @param array $recipients 每个元素: ['email'=>string, 'subject'=>string, 'body'=>string]
     * @return array ['results'=>array, 'sent'=>int, 'failed'=>int]
     */
    public function sendBatch($recipients)
    {
        if (empty($recipients)) {
            return ['results' => [], 'sent' => 0, 'failed' => 0];
        }

        if (!$this->isConfigured()) {
            $results = [];
            foreach ($recipients as $r) {
                $results[] = ['success' => false, 'message' => 'SMTP 未配置', 'email' => $r['email']];
            }
            return ['results' => $results, 'sent' => 0, 'failed' => count($recipients)];
        }

        $results = [];
        $sent = 0;
        $failed = 0;

        try {
            $this->ensureConnected();

            foreach ($recipients as $r) {
                try {
                    $result = $this->sendData($r['email'], $r['subject'], $r['body']);
                    $result['email'] = $r['email'];
                    if ($result['success']) {
                        $sent++;
                    } else {
                        $failed++;
                    }
                    $results[] = $result;
                } catch (\Exception $e) {
                    $failed++;
                    $results[] = ['success' => false, 'message' => $e->getMessage(), 'email' => $r['email']];
                }
            }
        } catch (\Exception $e) {
            // 连接级别错误
            foreach ($recipients as $i => $r) {
                if (!isset($results[$i])) {
                    $failed++;
                    $results[] = ['success' => false, 'message' => $e->getMessage(), 'email' => $r['email']];
                }
            }
        } finally {
            $this->disconnect();
        }

        return ['results' => $results, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * 确保 SMTP 已连接并认证
     */
    private function ensureConnected()
    {
        if ($this->socket && is_resource($this->socket)) {
            // 探测连接是否存活
            $meta = stream_get_meta_data($this->socket);
            if ($meta['eof']) {
                $this->disconnect();
            } else {
                return; // 连接仍有效
            }
        }

        $errno = 0;
        $errstr = '';

        $hostStr = $this->host;
        if ($this->encryption === 'ssl') {
            $hostStr = 'ssl://' . $this->host;
        }

        $this->socket = @stream_socket_client(
            "{$hostStr}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!$this->socket) {
            $msg = "SMTP 连接失败: 无法连接到 {$hostStr}:{$this->port}";
            if ($errno) {
                // 常见错误码解读
                $errTips = '';
                switch ($errno) {
                    case 110: $errTips = '（连接超时 — 请检查主机地址和端口是否正确，防火墙是否放行）'; break;
                    case 111: $errTips = '（连接被拒绝 — 端口可能未开放或被防火墙拦截）'; break;
                    case 113: $errTips = '（无法路由到目标主机 — 请检查 SMTP 主机地址）'; break;
                }
                $msg .= " (错误码 {$errno}: {$errstr}){$errTips}";
            } else {
                $msg .= " — 建议：1) 检查主机地址和端口号 2) 云虚拟主机需联系主机商开放外发端口 3) 尝试切换 TLS/SSL 加密方式";
            }
            throw new \RuntimeException($msg);
        }

        stream_set_timeout($this->socket, $this->timeout);
        $this->smtpRead(); // 读欢迎消息

        $this->smtpCommand('EHLO smtp-client');

        // TLS 升级
        if ($this->encryption === 'tls') {
            $this->smtpCommand('STARTTLS');
            stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->smtpCommand('EHLO smtp-client');
        }

        // 认证
        $this->smtpCommand('AUTH LOGIN');
        $this->smtpCommand(base64_encode($this->username));
        $this->smtpCommand(base64_encode($this->password));

        $this->authenticated = true;
    }

    /**
     * 通过已连接的 socket 发送一封邮件 DATA
     */
    private function sendData($to, $subject, $body)
    {
        if (!$this->socket || !$this->authenticated) {
            throw new \RuntimeException('SMTP 未连接');
        }

        if (empty($this->fromEmail)) {
            throw new \RuntimeException('发件邮箱未配置，请在网站设置中填写 smtp_from_email');
        }

        $boundary = "boundary_" . bin2hex(random_bytes(12));

        // MAIL FROM
        $resp = $this->smtpCommand("MAIL FROM:<{$this->fromEmail}>");
        if (substr($resp, 0, 3) !== '250') {
            throw new \RuntimeException("MAIL FROM 被拒绝: " . trim($resp));
        }

        // RCPT TO
        $resp = $this->smtpCommand("RCPT TO:<{$to}>");
        if (substr($resp, 0, 3) !== '250') {
            throw new \RuntimeException("RCPT TO 被拒绝 ({$to}): " . trim($resp));
        }

        // DATA 命令
        $resp = $this->smtpCommand('DATA');
        if (substr($resp, 0, 3) !== '354') {
            throw new \RuntimeException("DATA 命令失败: " . trim($resp));
        }

        // 构建符合 RFC 的邮件头和正文
        $subjectEncoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $fromEncoded = "=?UTF-8?B?" . base64_encode($this->fromName) . "?=";

        // 邮件头
        $data = "From: {$fromEncoded} <{$this->fromEmail}>\r\n";
        $data .= "To: <{$to}>\r\n";
        $data .= "Subject: {$subjectEncoded}\r\n";
        $data .= "MIME-Version: 1.0\r\n";
        $data .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $data .= "Date: " . date('r') . "\r\n";
        $data .= "X-Mailer: FamilyTree-Mailer\r\n";
        $data .= "\r\n";

        // 纯文本部分
        $plainText = $this->htmlToText($body);
        $data .= "--{$boundary}\r\n";
        $data .= "Content-Type: text/plain; charset=utf-8\r\n";
        $data .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $data .= chunk_split(base64_encode($plainText)) . "\r\n";

        // HTML 部分（使用 base64 编码，避免内容中的特殊字符破坏协议）
        $htmlBody = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"></head><body>" . $body . "</body></html>";
        $data .= "--{$boundary}\r\n";
        $data .= "Content-Type: text/html; charset=utf-8\r\n";
        $data .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $data .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        $data .= "--{$boundary}--\r\n";

        // dot-stuffing: 行首的点号需要转义为双点号
        $data = $this->dotStuff($data);

        // 发送 DATA 并以 CRLF.CRLF 结束
        $response = $this->smtpCommand($data . "\r\n.");

        if (substr($response, 0, 3) !== '250') {
            throw new \RuntimeException("发送失败: " . trim($response));
        }

        // RSET 为下一封邮件准备（不关闭连接）
        $this->smtpCommand('RSET');

        return ['success' => true, 'message' => '发送成功'];
    }

    /**
     * 断开 SMTP 连接
     */
    public function disconnect()
    {
        if ($this->socket && is_resource($this->socket)) {
            try {
                @fwrite($this->socket, "QUIT\r\n");
                @fclose($this->socket);
            } catch (\Exception $e) {
                // 忽略断开时的错误
            }
        }
        $this->socket = null;
        $this->authenticated = false;
    }

    /**
     * 读取 SMTP 响应
     */
    private function smtpRead()
    {
        if (!$this->socket || !is_resource($this->socket)) {
            throw new \RuntimeException('SMTP 读取失败: 连接已断开');
        }
        $response = '';
        while (($line = @fgets($this->socket, 512)) !== false) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        if ($response === '') {
            throw new \RuntimeException('SMTP 无响应: 连接可能被服务器关闭');
        }
        return $response;
    }

    /**
     * 发送 SMTP 命令并读取响应
     */
    private function smtpCommand($command)
    {
        if (!$this->socket || !is_resource($this->socket)) {
            throw new \RuntimeException('SMTP 连接已断开');
        }
        $written = @fwrite($this->socket, $command . "\r\n");
        if ($written === false) {
            throw new \RuntimeException('SMTP 写入失败: 连接已断开');
        }
        return $this->smtpRead();
    }

    /**
     * dot-stuffing: 将正文中行首的 . 转义为 ..
     * RFC 5321 §4.5.2: 透明度要求
     */
    private function dotStuff($data)
    {
        // 将 \r\n.\r\n 替换为 \r\n..\r\n
        // 同时处理单独的 \n. 开头的行
        return preg_replace('/^\./m', '..', $data);
    }

    /**
     * HTML 转纯文本（用于纯文本备选部分）
     * 比 strip_tags 更智能：保留基本结构
     */
    private function htmlToText($html)
    {
        $text = $html;
        // 移除 script 和 style 内容
        $text = preg_replace('#<script[^>]*>.*?</script>#is', '', $text);
        $text = preg_replace('#<style[^>]*>.*?</style>#is', '', $text);
        // 换行标签转为换行
        $text = str_replace(['</p>', '</div>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '<br>', '<br/>', '<br />'], "\n", $text);
        // 移除剩余标签
        $text = strip_tags($text);
        // 解码 HTML 实体
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        // 清理多余空白
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * 降级方案：使用 PHP mail() 函数
     */
    private function mailSendFallback($to, $subject, $body)
    {
        $toStr = is_array($to) ? implode(',', $to) : $to;
        $subjectEncoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $fromEncoded = "=?UTF-8?B?" . base64_encode($this->fromName) . "?=";
        $boundary = "boundary_" . bin2hex(random_bytes(12));

        $hdrs  = "MIME-Version: 1.0\r\n";
        $hdrs .= "From: {$fromEncoded} <{$this->fromEmail}>\r\n";
        $hdrs .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $hdrs .= "X-Mailer: FamilyTree-Mailer\r\n";

        $plainText = $this->htmlToText($body);
        $msg  = "--{$boundary}\r\n";
        $msg .= "Content-Type: text/plain; charset=utf-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($plainText));
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/html; charset=utf-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode("<!DOCTYPE html><html><head><meta charset=\"utf-8\"></head><body>{$body}</body></html>"));
        $msg .= "--{$boundary}--";

        if (mail($toStr, $subjectEncoded, $msg, $hdrs)) {
            return ['success' => true, 'message' => '发送成功（mail方式）'];
        }
        return ['success' => false, 'message' => 'mail() 函数发送失败'];
    }

    /**
     * 析构函数：确保连接关闭
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
