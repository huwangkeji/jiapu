<?php
/**
 * Env - 环境变量加载器
 * 从 .env 文件读取配置，支持缓存
 */
class Env
{
    private static $loaded = false;
    private static $data = [];

    /**
     * 加载 .env 文件
     */
    public static function load($path = null)
    {
        if (self::$loaded) return;
        self::$loaded = true;

        $path = $path ?: dirname(__DIR__) . '/.env';
        if (!file_exists($path)) return;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // 去掉引号
            if ((($val[0] ?? '') === '"' && substr($val, -1) === '"') ||
                (($val[0] ?? '') === "'" && substr($val, -1) === "'")) {
                $val = substr($val, 1, -1);
            }
            self::$data[$key] = $val;
        }
    }

    /**
     * 获取环境变量，支持默认值
     */
    public static function get($key, $default = null)
    {
        return self::$data[$key] ?? $default;
    }

    /**
     * 获取整数型环境变量
     */
    public static function int($key, $default = 0)
    {
        return (int)(self::$data[$key] ?? $default);
    }

    /**
     * 检查环境变量是否存在
     */
    public static function has($key)
    {
        return isset(self::$data[$key]);
    }

    /**
     * 获取所有变量（调试用）
     */
    public static function all()
    {
        return self::$data;
    }
}
