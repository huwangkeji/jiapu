<?php
/**
 * Flash - 闪存消息管理
 * 统一的 flash message 存取，支持 info/success/warning/error 四种类型
 */
class Flash
{
    const INFO    = 'info';
    const SUCCESS = 'success';
    const WARNING = 'warning';
    const ERROR   = 'error';

    /**
     * 设置一条闪存消息
     */
    public static function set($message, $type = self::INFO)
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }

    /**
     * 设置成功消息
     */
    public static function success($message) { self::set($message, self::SUCCESS); }

    /**
     * 设置错误消息
     */
    public static function error($message) { self::set($message, self::ERROR); }

    /**
     * 设置警告消息
     */
    public static function warning($message) { self::set($message, self::WARNING); }

    /**
     * 检查是否有待显示的消息
     */
    public static function has()
    {
        return isset($_SESSION['flash_message']);
    }

    /**
     * 获取并清除消息（header.php调用，渲染后自动清除）
     */
    public static function get()
    {
        if (!self::has()) return null;
        $result = [
            'message' => $_SESSION['flash_message'],
            'type'    => $_SESSION['flash_type'] ?? self::INFO,
        ];
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return $result;
    }
}
