<?php
/**
 * 日志记录模块
 * 支持多种日志级别和分类
 */

class Logger
{
    const LEVEL_DEBUG   = 'debug';
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    /**
     * 写入日志
     */
    public static function write($level, $module, $action, $detail = '', $userId = null)
    {
        try {
            $db = Database::getInstance();
            $ip = self::getClientIp();
            $sql = "INSERT INTO logs (user_id, level, module, action, detail, ip, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $db->execute($sql, [$userId, $level, $module, $action, $detail, $ip]);
        } catch (Exception $e) {
            // 日志写入失败不影响主流程
            error_log("Logger error: " . $e->getMessage());
        }
    }

    /**
     * 调试日志
     */
    public static function debug($module, $action, $detail = '', $userId = null)
    {
        self::write(self::LEVEL_DEBUG, $module, $action, $detail, $userId);
    }

    /**
     * 信息日志
     */
    public static function info($module, $action, $detail = '', $userId = null)
    {
        self::write(self::LEVEL_INFO, $module, $action, $detail, $userId);
    }

    /**
     * 警告日志
     */
    public static function warning($module, $action, $detail = '', $userId = null)
    {
        self::write(self::LEVEL_WARNING, $module, $action, $detail, $userId);
    }

    /**
     * 错误日志
     */
    public static function error($module, $action, $detail = '', $userId = null)
    {
        self::write(self::LEVEL_ERROR, $module, $action, $detail, $userId);
    }

    /**
     * 获取客户端IP（安全版：不信任可伪造的 X-Forwarded-For）
     */
    private static function getClientIp()
    {
        return Security::getClientIp();
    }

    /**
     * 获取日志统计
     */
    public static function getStats($days = 7)
    {
        $db = Database::getInstance();
        $stats = [];

        // 按级别统计
        $levelStats = $db->fetchAll(
            "SELECT level, COUNT(*) as cnt FROM logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY level",
            [$days]
        );
        foreach ($levelStats as $row) {
            $stats['level'][$row['level']] = (int)$row['cnt'];
        }

        // 按模块统计
        $moduleStats = $db->fetchAll(
            "SELECT module, COUNT(*) as cnt FROM logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY module ORDER BY cnt DESC LIMIT 10",
            [$days]
        );
        $stats['module'] = $moduleStats;

        // 今日日志数
        $todayCount = $db->count("SELECT COUNT(*) FROM logs WHERE DATE(created_at) = CURDATE()");
        $stats['today'] = $todayCount;

        // 总日志数
        $totalCount = $db->count("SELECT COUNT(*) FROM logs");
        $stats['total'] = $totalCount;

        return $stats;
    }

    /**
     * 清理过期日志
     */
    public static function clean($days = 90)
    {
        $db = Database::getInstance();
        return $db->execute("DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
    }
}
