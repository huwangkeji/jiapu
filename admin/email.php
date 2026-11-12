<?php
/**
 * 后台 - 邮件群发
 *
 * 发送模式：
 * - 测试发送：单封同步发送（action=send）
 * - 群发模式：前端批量收集 → 按批次AJAX提交 → 后端复用SMTP连接批量发送
 */
require_once __DIR__ . '/../includes/header_pre.php';
require_once __DIR__ . '/../includes/Mailer.php';
Auth::requireAdmin();

$db = Database::getInstance();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 邮件操作可能耗时较长，防止PHP超时
    set_time_limit(120);

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '非法请求']);
        exit;
    }

    $action = post_param('action', '');
    $subject = post_param('subject', '');
    $body = $_POST['body'] ?? '';
    $target = post_param('target', 'all');
    $testEmail = post_param('test_email', '');

    // ========== AJAX: 获取收件人列表 ==========
    if ($action === 'get_users') {
        header('Content-Type: application/json; charset=utf-8');
        $where = "WHERE status = 1";
        if ($target === 'admin') $where .= " AND role = 'admin'";
        elseif ($target === 'user') $where .= " AND role = 'user'";
        $users = $db->fetchAll("SELECT id, email, nickname, username FROM users {$where} ORDER BY id ASC");
        echo json_encode(['success' => true, 'users' => $users, 'total' => count($users)]);
        exit;
    }

    // ========== AJAX: 批量发送一批邮件（核心优化） ==========
    if ($action === 'send_batch') {
        header('Content-Type: application/json; charset=utf-8');
        set_time_limit(120); // 批量发送可能需要更长时间

        // 接收前端传来的本批用户ID列表
        $uids = $_POST['uids'] ?? [];
        if (!is_array($uids) || empty($uids)) {
            echo json_encode(['success' => false, 'message' => '无收件人']);
            exit;
        }

        // 查询这批用户的邮箱信息
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $users = $db->fetchAll(
            "SELECT id, username, email, nickname FROM users WHERE id IN ({$placeholders}) AND status = 1",
            $uids
        );

        if (empty($users)) {
            echo json_encode(['success' => false, 'message' => '没有有效收件人']);
            exit;
        }

        // 构建每封邮件的收件人+替换变量后的主题/正文
        $recipients = [];
        foreach ($users as $user) {
            $name = $user['nickname'] ?: $user['username'];
            $recipients[] = [
                'email'   => $user['email'],
                'subject' => str_replace(
                    ['{site_name}', '{site_url}', '{username}', '{email}'],
                    [SITE_NAME, SITE_URL, $name, $user['email']],
                    $subject
                ),
                'body'    => str_replace(
                    ['{username}', '{email}', '{site_name}', '{site_url}'],
                    [$name, $user['email'], SITE_NAME, SITE_URL],
                    $body
                ),
            ];
        }

        // 使用 Mailer 批量发送（复用同一 SMTP 连接）
        $mailer = new Mailer();
        if (!$mailer->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'SMTP 未配置']);
            exit;
        }

        $result = $mailer->sendBatch($recipients);

        // 返回每封的详细结果
        echo json_encode([
            'success'     => $result['failed'] === 0,
            'sent'        => $result['sent'],
            'failed'      => $result['failed'],
            'total'       => count($recipients),
            'details'     => $result['results'],
        ]);
        exit;
    }

    // ========== AJAX: 测试 SMTP 连接（不发送邮件）==========
    if ($action === 'test_smtp') {
        header('Content-Type: application/json; charset=utf-8');

        $mailer = new Mailer();
        if (!$mailer->isConfigured()) {
            echo json_encode(['success' => false, 'message' => 'SMTP 未配置，请先在网站设置中填写 SMTP 信息']);
            exit;
        }

        $result = $mailer->testConnection();
        echo json_encode($result);
        exit;
    }

    // ========== AJAX: 测试发送（单封，返回JSON） ==========
    if ($action === 'send' || (!empty($testEmail))) {
        header('Content-Type: application/json; charset=utf-8');

        if (empty($subject) || empty($body)) {
            echo json_encode(['success' => false, 'message' => '主题和内容不能为空']);
            exit;
        }
        if (empty($testEmail)) {
            echo json_encode(['success' => false, 'message' => '请填写测试邮箱地址']);
            exit;
        }

        try {
            $mailer = new Mailer();
            if (!$mailer->isConfigured()) {
                echo json_encode(['success' => false, 'message' => 'SMTP 未配置，请先在网站设置中填写 SMTP 信息']);
                exit;
            }

            $subj = str_replace(
                ['{site_name}', '{site_url}', '{username}', '{email}'],
                [SITE_NAME, SITE_URL, '测试用户', $testEmail],
                $subject
            );
            $bod = str_replace(
                ['{username}', '{email}', '{site_name}', '{site_url}'],
                ['测试用户', $testEmail, SITE_NAME, SITE_URL],
                $body
            );

            $result = $mailer->send($testEmail, $subj, $bod);

            if ($result['success']) {
                echo json_encode(['success' => true, 'message' => "测试邮件已发送到 {$testEmail}", 'email' => $testEmail]);
            } else {
                echo json_encode(['success' => false, 'message' => $result['message'], 'email' => $testEmail]);
            }
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => '发送异常: ' . $e->getMessage(), 'email' => $testEmail ?? '']);
        }
        exit;
    }
}

// 统计数据
$allCount   = $db->fetch("SELECT COUNT(*) as cnt FROM users WHERE status = 1")['cnt'];
$userCount  = $db->fetch("SELECT COUNT(*) as cnt FROM users WHERE status = 1 AND role = 'user'")['cnt'];
$adminCount = $db->fetch("SELECT COUNT(*) as cnt FROM users WHERE status = 1 AND role = 'admin'")['cnt'];

$pageTitle = '邮件群发';
$isAdminPage = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-main">
        <div class="admin-header">
            <h1>📧 邮件群发</h1>
        </div>

        <?php if (($db = Database::getInstance()) && !(new Mailer())->isConfigured()): ?>
        <div class="alert alert-warning" style="margin-bottom:16px;">
            ⚠️ SMTP 尚未配置，请先在 <a href="<?php echo SITE_URL; ?>/admin/settings.php">网站设置</a> 中填写邮箱SMTP信息
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <!-- 统计卡片 -->
        <div class="admin-card" style="margin-bottom:16px;">
            <div class="admin-card-body">
                <div class="stats-row" style="margin-bottom:0;">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $allCount; ?></div>
                        <div class="stat-label">全部会员</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $userCount; ?></div>
                        <div class="stat-label">普通用户</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $adminCount; ?></div>
                        <div class="stat-label">管理员</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 发送表单 -->
        <div class="admin-card">
            <div class="admin-card-header"><h3>群发邮件</h3></div>
            <div class="admin-card-body">
                <form id="emailForm" onsubmit="return false;">
                    <?php echo csrf_field(); ?>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>发送目标</label>
                            <select name="target" id="targetSelect">
                                <option value="all">全部会员 (<?php echo $allCount; ?>)</option>
                                <option value="user">普通用户 (<?php echo $userCount; ?>)</option>
                                <option value="admin">管理员 (<?php echo $adminCount; ?>)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>测试发送（单封测试）</label>
                            <input type="email" name="test_email" id="testEmailInput"
                                   placeholder="填入邮箱仅发送测试邮件">
                            <p class="help-text">填写后点"发送测试"，不填则使用群发功能</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>邮件主题</label>
                        <input type="text" name="subject" id="subjectInput" required
                               placeholder="如 族谱传承——重要通知"
                               value="<?php echo e(post_param('subject')); ?>">
                        <p class="help-text">可用变量：{username} {email} {site_name} {site_url}</p>
                    </div>

                    <div class="form-group">
                        <label>邮件内容 (HTML)</label>
                        <textarea name="body" id="bodyInput" rows="12" required
                                  style="font-family:monospace;"
                                  placeholder="支持HTML标签，变量：{username} {email} {site_name} {site_url}"><?php echo e(post_param('body')); ?></textarea>
                    </div>

                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button type="button" class="btn btn-primary" id="sendBtn" onclick="startBatchSend()">
                            📮 一键群发
                        </button>
                        <button type="button" class="btn btn-outline" id="testBtn" onclick="sendTest()">
                            📧 发送测试
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" id="smtpTestBtn" onclick="testSmtp()">
                            🔌 测试SMTP连接
                        </button>
                        <button type="button" class="btn btn-sm btn-warning" id="stopBtn"
                                onclick="stopSend()" style="display:none;">⏹ 停止发送</button>
                        <span id="timerDisplay" style="font-size:13px;color:#888;display:none;"></span>
                    </div>

                    <!-- 进度区域 -->
                    <div id="progressArea" style="display:none;margin-top:18px;">
                        <!-- 总进度条 -->
                        <div style="background:#e9ecef;border-radius:8px;height:26px;overflow:hidden;margin-bottom:8px;position:relative;">
                            <div id="progressBar" style="height:100%;background:linear-gradient(135deg,#27ae60,#2ecc71);width:0%;transition:width 0.35s ease;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:bold;min-width:40px;padding:0 10px;"></div>
                        </div>
                        <div id="progressText" style="font-size:14px;color:#333;font-weight:500;">准备中...</div>

                        <!-- 统计摘要 -->
                        <div id="statsSummary" style="display:flex;gap:20px;margin-top:10px;font-size:13px;">
                            <span style="color:#27ae60;">✅ 成功: <strong id="sentCount">0</strong></span>
                            <span style="color:#e74c3c;">❌ 失败: <strong id="failCount">0</strong></span>
                            <span style="color:#666;">📊 进度: <strong id="progressPct">0</strong>%</span>
                        </div>

                        <!-- 详细日志 -->
                        <div id="resultLog" style="max-height:220px;overflow-y:auto;margin-top:12px;font-size:12.5px;background:#f8f9fa;padding:12px;border-radius:6px;border:1px solid #dee2e6;font-family:Consolas,'Courier New',monospace;line-height:1.7;"></div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    // ========== 配置常量 ==========
    var BATCH_SIZE = 5;          // 每批发送数量（复用一次SMTP连接）
    var BATCH_DELAY_MS = 300;    // 批次间间隔毫秒

    // ========== 状态变量 ==========
    var isSending = false;
    var stopFlag  = false;
    var allUsers  = [];
    var totalUsers = 0;
    var totalSent  = 0;
    var totalFail  = 0;
    var startTime  = 0;
    var timerInterval = null;

    // ========== DOM 引用 ==========
    var $sendBtn      = document.getElementById('sendBtn');
    var $testBtn      = document.getElementById('testBtn');
    var $stopBtn      = document.getElementById('stopBtn');
    var $progressArea = document.getElementById('progressArea');
    var $progressBar  = document.getElementById('progressBar');
    var $progressText = document.getElementById('progressText');
    var $resultLog    = document.getElementById('resultLog');
    var $sentCountEl  = document.getElementById('sentCount');
    var $failCountEl  = document.getElementById('failCount');
    var $progressPct  = document.getElementById('progressPct');
    var $timerDisplay = document.getElementById('timerDisplay');

    /**
     * 开始批量群发（主入口）
     */
    window.startBatchSend = function() {
        var testEmail = document.getElementById('testEmailInput').value.trim();
        if (testEmail) { sendTest(); return; }

        var subject = document.getElementById('subjectInput').value.trim();
        var body    = document.getElementById('bodyInput').value.trim();
        if (!subject) { shakeElement('#subjectInput'); alert('请输入邮件主题'); return; }
        if (!body)    { shakeElement('#bodyInput');    alert('请输入邮件内容'); return; }

        if (isSending) return;
        isSending  = true;
        stopFlag   = false;
        totalSent  = 0;
        totalFail  = 0;
        allUsers   = [];

        // UI 切换到发送状态
        setSendingUI(true);
        $resultLog.innerHTML = '';
        startTime = Date.now();
        startTimer();

        // 第一步：获取收件人列表
        logMsg('📡 正在获取收件人列表...', 'info');

        fetchPost({ action: 'get_users', target: document.getElementById('targetSelect').value })
            .then(function(res){ return res.json(); })
            .then(function(data){
                if (!data.success || !data.users || data.users.length === 0) {
                    logMsg('⚠️ 没有符合条件的收件人', 'warn');
                    finishSend(true);
                    return;
                }
                allUsers   = data.users;
                totalUsers = data.users.length;
                logMsg('✅ 共找到 ' + totalUsers + ' 位收件人，开始分批发送（每批 ' + BATCH_SIZE + ' 封）', 'success');
                updateProgress();
                sendNextBatch();
            })
            .catch(function(err){
                logMsg('❌ 获取收件人失败: ' + err.message, 'error');
                finishSend(true);
            });
    };

    /**
     * 发送下一批邮件
     */
    function sendNextBatch() {
        if (stopFlag) {
            logMsg('⏹ 用户停止发送', 'warn');
            finishSend(false);
            return;
        }

        // 计算当前批次
        var processed = totalSent + totalFail;
        if (processed >= totalUsers) {
            finishSend(false);
            return;
        }

        var batchUsers = allUsers.slice(processed, processed + BATCH_SIZE);
        var batchUids  = batchUsers.map(function(u){ return u.id; });

        logMsg('📤 发送第 ' + Math.floor(processed / BATCH_SIZE + 1) + ' 批 (' + batchUsers.length + ' 封)...', 'info');

        fetchPost({
            action: 'send_batch',
            uids: batchUids,
            subject: document.getElementById('subjectInput').value,
            body: document.getElementById('bodyInput').value
        })
        .then(function(res){ return res.json(); })
        .then(function(data){
            // 直接使用服务端返回的准确统计
            if (data.details) {
                for (var i = 0; i < data.details.length; i++) {
                    var d = data.details[i];
                    if (d.success) {
                        logMsg('✅ ' + d.email, 'success');
                    } else {
                        logMsg('❌ ' + (d.email||'?') + ': ' + (d.message||'未知错误'), 'error');
                    }
                }
            }

            if (typeof data.sent !== 'undefined')  { totalSent = data.sent; }
            if (typeof data.failed !== 'undefined') { totalFail = data.failed; }

            if (!data.success && !data.details) {
                logMsg('❌ 整批失败: ' + (data.message||'未知错误'), 'error');
            }

            updateProgress();

            // 继续下一批（带间隔，避免服务器压力过大）
            if (!stopFlag) {
                setTimeout(sendNextBatch, BATCH_DELAY_MS);
            } else {
                finishSend(false);
            }
        })
        .catch(function(err){
            totalFail += batchUsers.length;
            logMsg('❌ 网络错误（整批）: ' + err.message, 'error');
            updateProgress();
            if (!stopFlag) {
                setTimeout(sendNextBatch, BATCH_DELAY_MS);
            }
        });
    }

    /**
     * 更新进度显示
     */
    function updateProgress() {
        var done = totalSent + totalFail;
        var pct = totalUsers > 0 ? Math.round((done / totalUsers) * 100) : 0;

        $progressBar.style.width = pct + '%';
        $progressBar.textContent = pct + '%';

        $progressText.textContent = done + ' / ' + totalUsers + ' 封';
        $sentCountEl.textContent = totalSent;
        $failCountEl.textContent = totalFail;
        $progressPct.textContent = pct;

        $resultLog.scrollTop = $resultLog.scrollHeight;
    }

    /**
     * 完成（正常完成或中止）
     */
    function finishSend(stopped) {
        stopTimer();
        setSendingUI(false);

        var elapsed = ((Date.now() - startTime) / 1000).toFixed(1);

        if (stopped || stopFlag) {
            $progressText.textContent = '已停止 — 成功 ' + totalSent + ' / 失败 ' + totalFail;
            logMsg('', 'spacer');
            logMsg('⏹ 发送已停止。共处理 ' + (totalSent + totalFail) + ' 封（成功 ' + totalSent + '，失败 ' + totalFail + '），耗时 ' + elapsed + ' 秒', 'warn');
        } else {
            $progressText.textContent = '🎉 全部完成！成功 ' + totalSent + ' / 失败 ' + totalFail;
            $progressBar.style.background = totalFail === 0
                ? 'linear-gradient(135deg,#27ae60,#2ecc71)'
                : 'linear-gradient(135deg,#f39c12,#e67e22)';
            logMsg('', 'spacer');
            logMsg('🎉 群发完成！共发送 ' + totalUsers + ' 封（成功 ' + totalSent + '，失败 ' + totalFail + '），耗时 ' + elapsed + ' 秒', 'success');
        }

        isSending = false;
    }

    /**
     * 停止发送
     */
    window.stopSend = function() {
        stopFlag = true;
        $stopBtn.disabled = true;
        $stopBtn.textContent = '正在停止...';
        logMsg('⏳ 正在等待当前批次完成后停止...', 'warn');
    };

    /**
     * 测试 SMTP 连接（不发送邮件，仅验证连通性和认证）
     */
    window.testSmtp = function() {
        var $btn = document.getElementById('smtpTestBtn');
        $btn.disabled = true;
        $btn.textContent = '⏳ 测试中...';

        logMsg('🔌 正在测试 SMTP 连接...', 'info');

        fetchPost({ action: 'test_smtp' })
            .then(function(res){ return res.json(); })
            .then(function(data){
                if (data.success) {
                    logMsg('✅ ' + data.message, 'success');
                    if (data.details) {
                        for (var key in data.details) {
                            if (data.details.hasOwnProperty(key)) {
                                logMsg('   📋 ' + key + ': ' + data.details[key], 'info');
                            }
                        }
                    }
                } else {
                    logMsg('❌ ' + data.message, 'error');
                }

                $btn.disabled = false;
                $btn.textContent = '🔌 测试SMTP连接';
            })
            .catch(function(err){
                logMsg('❌ 连接测试失败: ' + err.message, 'error');
                $btn.disabled = false;
                $btn.textContent = '🔌 测试SMTP连接';
            });

        // 显示日志区域
        if ($progressArea.style.display === 'none') {
            $progressArea.style.display = '';
        }
    };

    /**
     * 发送测试邮件（单封，不刷新页面）
     */
    window.sendTest = function() {
        var testEmail = document.getElementById('testEmailInput').value.trim();
        if (!testEmail) {
            shakeElement('#testEmailInput');
            alert('请填写测试邮箱地址');
            return;
        }

        $testBtn.disabled = true;
        $testBtn.textContent = '⏳ 发送中...';
        logMsg('🧪 正在发送测试邮件到 ' + testEmail + ' ...', 'info');

        fetchPost({
            action: 'send',
            target: document.getElementById('targetSelect').value,
            subject: document.getElementById('subjectInput').value,
            body: document.getElementById('bodyInput').value,
            test_email: testEmail
        })
        .then(function(res){ return res.json(); })
        .then(function(data){
            // 服务端返回 JSON
            if (data.success) {
                logMsg('✅ ' + (data.message || '测试邮件已发送'), 'success');
            } else {
                logMsg('❌ ' + (data.message || '发送失败') + (data.email ? ' (' + data.email + ')' : ''), 'error');
            }

            $testBtn.disabled = false;
            $testBtn.textContent = '📧 发送测试';
        })
        .catch(function(err){
            logMsg('❌ 发送失败: ' + err.message, 'error');
            $testBtn.disabled = false;
            $testBtn.textContent = '📧 发送测试';
        });

        // 显示日志区域（如果还没显示的话）
        if ($progressArea.style.display === 'none') {
            $progressArea.style.display = '';
        }
    };

    // ========== 工具函数 ==========

    /**
     * POST 请求（自动附带 CSRF token）
     */
    function fetchPost(extraData) {
        var fd = new FormData();
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        for (var key in extraData) {
            if (extraData.hasOwnProperty(key)) {
                if (key === 'uids' && Array.isArray(extraData[key])) {
                    for (var i = 0; i < extraData[key].length; i++) {
                        fd.append('uids[]', extraData[key][i]);
                    }
                } else {
                    fd.append(key, extraData[key]);
                }
            }
        }

        return fetch(window.location.href, { method: 'POST', body: fd });
    }

    /**
     * 写入一行日志
     */
    function logMsg(msg, type) {
        var line = document.createElement('div');
        var time = new Date().toLocaleTimeString('zh-CN', { hour12:false });

        line.innerHTML = '<span style="color:#999;">[' + time + '] </span>' + msg;

        switch(type) {
            case 'success': break; // 绿色由内容决定
            case 'error':   break;
            case 'warn':    line.style.color = '#e67e22'; break;
            case 'info':    line.style.color = '#666'; break;
            case 'spacer':
                line.style.height = '4px';
                line.style.borderTop = '1px dashed #ddd';
                line.style.margin = '4px 0';
                line.innerHTML = '';
                break;
        }

        $resultLog.appendChild(line);
        $resultLog.scrollTop = $resultLog.scrollHeight;
    }

    /**
     * 设置发送状态 UI
     */
    function setSendingUI(sending) {
        $sendBtn.disabled = sending;
        $sendBtn.textContent = sending ? '⏳ 发送中...' : '📮 一键群发';
        $stopBtn.style.display = sending ? '' : 'none';
        $stopBtn.disabled = false;
        $stopBtn.textContent = '⏹ 停止发送';
        $progressArea.style.display = sending ? '' : 'none';
        $timerDisplay.style.display = sending ? '' : 'none';

        if (!sending) {
            $progressBar.style.width = '0%';
            $progressBar.textContent = '';
        }
    }

    /**
     * 计时器
     */
    function startTimer() {
        timerInterval = setInterval(function() {
            var sec = Math.floor((Date.now() - startTime) / 1000);
            var m = Math.floor(sec / 60);
            var s = sec % 60;
            $timerDisplay.textContent = '⏱ 已用时 ' + (m > 0 ? m + '分' : '') + s + '秒';
        }, 1000);
    }

    function stopTimer() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        $timerDisplay.style.display = 'none';
    }

    /**
     * 输入框抖动效果
     */
    function shakeElement(selector) {
        var el = document.querySelector(selector);
        if (!el) return;
        el.style.animation = 'none';
        el.offsetHeight; // 触发 reflow
        el.style.animation = 'shake 0.4s ease';
        setTimeout(function(){ el.style.animation = ''; }, 400);
    }
})();
</script>

<style>
/* 抖动动画 */
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    20%  { transform: translateX(-5px); }
    40%  { transform: translateX(5px); }
    60%  { transform: translateX(-3px); }
    80%  { transform: translateX(3px); }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
