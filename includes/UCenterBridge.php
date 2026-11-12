<?php
/**
 * UCenterBridge - UCenter 与本站账号体系的桥接层
 *
 * 职责：
 *  - 注册 / 登录 / 改密 / 改资料 / 删号 / 改名 与 UCenter 双向同步
 *  - 允许 UCenter 已有用户直接登录本站（自动建号）
 *  - 处理 UCenter 推送过来的通知（api/uc.php）
 *  - 全量导入 UCenter 用户
 */
class UCenterBridge
{
    /** @var bool|null users.uc_uid 列是否就绪 */
    private static $columnReady = null;

    // ───────────────── 基础工具 ─────────────────

    public static function enabled()
    {
        return class_exists('UCenter') && UCenter::enabled();
    }

    public static function opt($key, $default = '0')
    {
        if (!class_exists('UCenter')) {
            return $default;
        }
        $v = UCenter::config($key, $default);
        return $v === null || $v === '' ? $default : $v;
    }

    private static function on($key)
    {
        return strtolower((string)self::opt($key, '0')) === '1'
            || strtolower((string)self::opt($key, '0')) === 'true';
    }

    /**
     * 确保 users 表存在 uc_uid 列（每个请求只检测一次）
     */
    public static function ensureColumn()
    {
        if (self::$columnReady !== null) {
            return self::$columnReady;
        }
        try {
            $db = Database::getInstance();
            $row = $db->fetch("SHOW COLUMNS FROM users LIKE 'uc_uid'");
            if (!$row) {
                $db->execute("ALTER TABLE users ADD COLUMN `uc_uid` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'UCenter UID' AFTER `id`");
                try {
                    $db->execute("ALTER TABLE users ADD INDEX `idx_uc_uid` (`uc_uid`)");
                } catch (\Exception $e) {
                    // 索引已存在时忽略
                }
            }
            self::$columnReady = true;
        } catch (\Exception $e) {
            error_log('UCenterBridge::ensureColumn 失败: ' . $e->getMessage());
            self::$columnReady = false;
        }
        return self::$columnReady;
    }

    private static function db()
    {
        return Database::getInstance();
    }

    /** 取本地用户的 UCenter UID */
    public static function getUcUid($userId)
    {
        if (!self::ensureColumn()) return 0;
        try {
            $row = self::db()->fetch("SELECT uc_uid FROM users WHERE id = ?", [$userId]);
            return (int)($row['uc_uid'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /** 绑定本地用户与 UCenter UID */
    public static function bindUcUid($userId, $ucUid)
    {
        if (!self::ensureColumn()) return false;
        try {
            self::db()->execute("UPDATE users SET uc_uid = ? WHERE id = ?", [(int)$ucUid, (int)$userId]);
            return true;
        } catch (\Exception $e) {
            error_log('UCenterBridge::bindUcUid 失败: ' . $e->getMessage());
            return false;
        }
    }

    private static function log($level, $message)
    {
        try {
            Logger::write($level, 'UCenter', 'UCenter互通', $message, null);
        } catch (\Exception $e) {
            error_log('[UCenter] ' . $message);
        }
    }

    // ───────────────── 注册同步 ─────────────────

    /**
     * 本站注册成功后调用：把账号写入 UCenter
     * @return array ['success'=>bool, 'uc_uid'=>int, 'message'=>string]
     */
    public static function afterRegister($userId, $username, $password, $email)
    {
        if (!self::enabled() || !self::on('uc_sync_register')) {
            return ['success' => false, 'uc_uid' => 0, 'message' => '未启用'];
        }

        $ucUid = UCenter::userRegister($username, $password, $email);

        if ($ucUid > 0) {
            self::bindUcUid($userId, $ucUid);
            self::log('info', "注册同步成功：{$username} → UCenter UID {$ucUid}");
            return ['success' => true, 'uc_uid' => $ucUid, 'message' => '已同步到 UCenter'];
        }

        $msg = UCenter::errorText($ucUid, 'register');
        // -3 用户名已存在 / -6 邮箱已被注册：尝试反查并绑定，不阻断本站注册
        if ($ucUid === -3 || $ucUid === -6) {
            $info = UCenter::userLogin($username, $password);
            $foundUid = (int)($info[0] ?? 0);
            if ($foundUid > 0) {
                self::bindUcUid($userId, $foundUid);
                self::log('info', "注册时发现 UCenter 已存在同名账号，直接绑定：{$username} → UID {$foundUid}");
                return ['success' => true, 'uc_uid' => $foundUid, 'message' => '已绑定已存在的 UCenter 账号'];
            }
        }

        self::log('warning', "注册同步失败：{$username} - {$msg}");
        return ['success' => false, 'uc_uid' => 0, 'message' => $msg];
    }

    // ───────────────── 登录同步 ─────────────────

    /**
     * 本站登录成功后调用：生成同步登录脚本（写入 session，由 header 输出）
     */
    public static function afterLogin($userId, $username)
    {
        if (!self::enabled() || !self::on('uc_sync_login')) {
            return '';
        }
        $ucUid = self::getUcUid($userId);
        if ($ucUid <= 0) {
            // 本地用户还没绑定过 UCenter，尝试补登记一次
            if (self::on('uc_sync_register')) {
                $info = UCenter::userLogin($username, '');
                $ucUid = (int)($info[0] ?? 0);
                if ($ucUid > 0) {
                    self::bindUcUid($userId, $ucUid);
                }
            }
            if ($ucUid <= 0) {
                return '';
            }
        }

        $script = UCenter::userSynLogin($ucUid);
        if ($script) {
            $_SESSION['uc_synlogin_script'] = $script;
        }
        return $script;
    }

    /**
     * UCenter 用户校验（用于「UCenter 用户直接登录本站」）
     * @return array ['success'=>bool,'data'=>array,'message'=>string]
     */
    public static function verifyUcUser($username, $password)
    {
        if (!self::enabled()) {
            return ['success' => false, 'data' => [], 'message' => 'UCenter 未启用'];
        }
        $info = UCenter::userLogin($username, $password);
        $uid = (int)($info[0] ?? 0);
        if ($uid > 0) {
            return ['success' => true, 'data' => $info, 'message' => 'ok'];
        }
        return ['success' => false, 'data' => $info, 'message' => UCenter::errorText($uid, 'login')];
    }

    /**
     * 是否允许 UCenter 已有用户登录本站
     */
    public static function allowUcLogin()
    {
        return self::enabled() && self::on('uc_allow_uc_login');
    }

    public static function allowAutoCreate()
    {
        return self::enabled() && self::on('uc_auto_create');
    }

    /**
     * UCenter 校验通过后，在本地建号（或更新）并返回本地 user 记录
     */
    public static function provisionLocalUser(array $ucInfo, $password)
    {
        $ucUid     = (int)($ucInfo[0] ?? 0);
        $username  = (string)($ucInfo[1] ?? '');
        $email     = (string)($ucInfo[3] ?? '');
        if ($ucUid <= 0 || $username === '') {
            return null;
        }

        $db = self::db();

        // 已绑定过？
        if (self::ensureColumn()) {
            $user = $db->fetch("SELECT * FROM users WHERE uc_uid = ?", [$ucUid]);
            if ($user) {
                return $user;
            }
        }

        $user = $db->fetch("SELECT * FROM users WHERE username = ?", [$username]);
        if ($user) {
            if (self::ensureColumn() && (int)$user['uc_uid'] === 0) {
                self::bindUcUid($user['id'], $ucUid);
                $user['uc_uid'] = $ucUid;
            }
            return $user;
        }

        if (!self::allowAutoCreate()) {
            return null;
        }

        // 邮箱冲突时生成一个占位邮箱（UCenter 邮箱可能已被本地占用）
        $safeEmail = $email;
        if ($safeEmail === '') {
            $safeEmail = 'uc' . $ucUid . '@ucenter.local';
        }
        $exists = $db->fetch("SELECT id FROM users WHERE email = ?", [$safeEmail]);
        if ($exists) {
            $safeEmail = 'uc' . $ucUid . '.' . substr(md5($username . $ucUid), 0, 8) . '@ucenter.local';
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';

        try {
            $db->execute(
                "INSERT INTO users (uc_uid, username, email, password, nickname, role, status, register_ip, register_ua, created_at)
                 VALUES (?, ?, ?, ?, ?, 'user', 1, ?, ?, NOW())",
                [$ucUid, $username, $safeEmail, $hash, $username, $ip, $ua]
            );
        } catch (\Exception $e) {
            try {
                $db->execute(
                    "INSERT INTO users (username, email, password, nickname, role, status, created_at)
                     VALUES (?, ?, ?, ?, 'user', 1, NOW())",
                    [$username, $safeEmail, $hash, $username]
                );
                $newId = (int)$db->lastInsertId();
                if ($newId > 0) {
                    self::bindUcUid($newId, $ucUid);
                }
            } catch (\Exception $e2) {
                self::log('error', "自动建号失败：{$username} - " . $e2->getMessage());
                return null;
            }
        }

        $newId = $newId ?? (int)$db->lastInsertId();
        self::log('info', "UCenter 用户自动建号：{$username}（UC UID {$ucUid}）");
        return $db->fetch("SELECT * FROM users WHERE id = ?", [$newId]);
    }

    // ───────────────── 密码 / 资料 / 删号 / 改名 ─────────────────

    /**
     * 本站改密后同步 UCenter
     */
    public static function syncPassword($userId, $newPassword, $oldPassword = '')
    {
        if (!self::enabled() || !self::on('uc_sync_password')) {
            return false;
        }
        $ucUid = self::getUcUid($userId);
        if ($ucUid <= 0) {
            return false;
        }
        $user = self::db()->fetch("SELECT username, email FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return false;
        }
        $ret = UCenter::userEdit($user['username'], $oldPassword, $newPassword, $user['email'], 1);
        $ok = ($ret === 1);
        self::log($ok ? 'info' : 'warning', "改密同步" . ($ok ? '成功' : '失败(' . $ret . ')') . "：{$user['username']}");
        return $ok;
    }

    /**
     * 本站改资料后同步 UCenter（邮箱 / 昵称）
     */
    public static function syncProfile($userId, array $data)
    {
        if (!self::enabled() || !self::on('uc_sync_profile')) {
            return false;
        }
        $ucUid = self::getUcUid($userId);
        if ($ucUid <= 0) {
            return false;
        }
        $user = self::db()->fetch("SELECT username, email FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return false;
        }
        $email = isset($data['email']) && $data['email'] !== '' ? $data['email'] : $user['email'];
        if ($email === $user['email']) {
            return true; // 无变化，不打扰 UCenter
        }
        $ret = UCenter::userEdit($user['username'], '', '', $email, 1);
        $ok = in_array($ret, [1, 0, -7], true);
        self::log($ok ? 'info' : 'warning', "资料同步" . ($ok ? '成功' : '失败(' . $ret . ')') . "：{$user['username']}");
        return $ok;
    }

    /**
     * 删除本地用户时同步删除 UCenter 账号
     */
    public static function syncDelete($userId)
    {
        if (!self::enabled()) {
            return false;
        }
        $ucUid = self::getUcUid($userId);
        if ($ucUid <= 0) {
            return false;
        }
        $ret = UCenter::userDelete($ucUid);
        self::log('info', "删号同步：本地 {$userId} → UC {$ucUid}，返回 {$ret}");
        return $ret == 1;
    }

    /**
     * 本地改名同步 UCenter
     */
    public static function syncRename($userId, $oldUsername, $newUsername)
    {
        if (!self::enabled()) {
            return false;
        }
        $ucUid = self::getUcUid($userId);
        if ($ucUid <= 0) {
            return false;
        }
        $ret = UCenter::userRename($ucUid, $oldUsername, $newUsername);
        self::log('info', "改名同步：{$oldUsername} → {$newUsername}，返回 {$ret}");
        return $ret == 1;
    }

    // ───────────────── UCenter → 本站（通知处理） ─────────────────

    /**
     * 处理 UCenter 推送（api/uc.php 调用）
     * @param array $get 解密后的参数
     * @return string API_RETURN_SUCCEED / FAILED / FORBIDDEN
     */
    public static function handleNotify(array $get)
    {
        $action = strtolower((string)($get['action'] ?? ''));

        switch ($action) {
            case 'test':
                return '1';

            case 'deleteuser':
                $uids = $get['ids'] ?? ($get['uids'] ?? '');
                $ids  = is_array($uids) ? $uids : explode(',', (string)$uids);
                $ids  = array_filter(array_map('intval', $ids));
                if (empty($ids)) {
                    return '1';
                }
                if (self::ensureColumn()) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    self::db()->execute("UPDATE users SET status = 0 WHERE uc_uid IN ({$in})", array_values($ids));
                    self::log('info', 'UCenter 通知删号，已禁用本地用户 UC UID: ' . implode(',', $ids));
                }
                return '1';

            case 'renameuser':
                $uid   = (int)($get['uid'] ?? 0);
                $newName = (string)($get['newusername'] ?? '');
                if ($uid > 0 && $newName !== '' && self::ensureColumn()) {
                    $user = self::db()->fetch("SELECT id FROM users WHERE uc_uid = ?", [$uid]);
                    if ($user) {
                        self::db()->execute("UPDATE users SET username = ? WHERE id = ?", [$newName, $user['id']]);
                        self::log('info', "UCenter 通知改名：UID {$uid} → {$newName}");
                    }
                }
                return '1';

            case 'updatepw':
                // UCenter 侧改密 → 本地强制失效持久令牌，下次登录走 UCenter 校验
                $username = (string)($get['username'] ?? '');
                if ($username !== '' && self::ensureColumn()) {
                    $user = self::db()->fetch("SELECT id FROM users WHERE username = ?", [$username]);
                    if ($user) {
                        try {
                            self::db()->execute("DELETE FROM user_tokens WHERE user_id = ?", [$user['id']]);
                        } catch (\Exception $e) {
                            // 表不存在时忽略
                        }
                        self::log('info', "UCenter 通知改密：{$username}");
                    }
                }
                return '1';

            case 'updateapps':
            case 'updateclient':
            case 'updatehosts':
            case 'updatebadwords':
            case 'updatecredit':
            case 'updatecreditsettings':
            case 'getcreditsettings':
            case 'getcredit':
            case 'gettag':
                return '1';

            case 'synlogin':
            case 'synlogout':
                // 这两个由 UCenter 直接调用各应用的 uc.php，通常只返回成功
                return '1';

            default:
                self::log('warning', '收到未知的 UCenter 通知：' . $action);
                return '-1';
        }
    }

    // ───────────────── 全量导入 ─────────────────

    /**
     * 从 UCenter 导入用户到本站
     * @return array ['total'=>int,'created'=>int,'bound'=>int,'skipped'=>int,'errors'=>array]
     */
    public static function importFromUc($limit = 500)
    {
        $result = ['total' => 0, 'created' => 0, 'bound' => 0, 'skipped' => 0, 'errors' => []];
        if (!self::enabled()) {
            $result['errors'][] = 'UCenter 未启用或配置不完整';
            return $result;
        }
        $members = UCenter::ucMembers((int)$limit, 0);
        if (empty($members)) {
            $err = UCenter::getLastError();
            $result['errors'][] = $err ?: '未读取到 UCenter 用户（请确认已配置 UCenter 数据库直连信息）';
            return $result;
        }
        if (!self::ensureColumn()) {
            $result['errors'][] = 'users 表无法写入 uc_uid 字段';
            return $result;
        }

        $db = self::db();
        $result['total'] = count($members);

        foreach ($members as $m) {
            $ucUid    = (int)$m['uid'];
            $username = (string)$m['username'];
            $email    = (string)$m['email'];
            if ($ucUid <= 0 || $username === '') {
                $result['skipped']++;
                continue;
            }

            try {
                $byUc = $db->fetch("SELECT id FROM users WHERE uc_uid = ?", [$ucUid]);
                if ($byUc) {
                    $result['skipped']++;
                    continue;
                }

                $byName = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
                if ($byName) {
                    self::bindUcUid($byName['id'], $ucUid);
                    $result['bound']++;
                    continue;
                }

                if ($email === '') {
                    $email = 'uc' . $ucUid . '@ucenter.local';
                }
                if ($db->fetch("SELECT id FROM users WHERE email = ?", [$email])) {
                    $email = 'uc' . $ucUid . '.' . substr(md5($username . $ucUid), 0, 8) . '@ucenter.local';
                }

                // UCenter 密码无法逆向，生成随机密码，用户需通过 UCenter 或找回密码登录
                $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 10]);
                $db->execute(
                    "INSERT INTO users (uc_uid, username, email, password, nickname, role, status, created_at)
                     VALUES (?, ?, ?, ?, ?, 'user', 1, NOW())",
                    [$ucUid, $username, $email, $hash, $username]
                );
                $result['created']++;
            } catch (\Exception $e) {
                $result['errors'][] = $username . '：' . $e->getMessage();
            }
        }

        self::log('info', sprintf(
            '全量导入 UCenter 用户：读取 %d，新建 %d，绑定 %d，跳过 %d',
            $result['total'], $result['created'], $result['bound'], $result['skipped']
        ));

        return $result;
    }
}
