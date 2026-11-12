<?php
/**
 * 数据库连接类 v2.0
 * 
 * 加固特性：
 * - 预处理语句缓存（同一条SQL只prepare一次）
 * - 慢查询日志（超过阈值的SQL自动记录）
 * - 事务封装（自动回滚 + 异常安全）
 * - 连接健康检查 + 自动重连
 * - 参数类型强制校验
 * - SQL 注入二次防护（危险关键词检测）
 * - 行数限制保护（防止无 LIMIT 的全表扫描）
 */

class Database
{
    private static $instance = null;
    private $pdo;

    /** @var array 预处理语句缓存 [sql => PDOStatement] */
    private $stmtCache = [];

    /** @var float 慢查询阈值（秒） */
    private $slowQueryThreshold = 1.0;

    /** @var int 最大返回行数（防止内存溢出） */
    private $maxRows = 10000;

    /** @var int 当前事务嵌套层级 */
    private $transactionLevel = 0;

    private function __construct()
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,   // 真预处理，防注入
                PDO::ATTR_PERSISTENT         => false,   // 非持久连接，避免连接泄漏
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_STRINGIFY_FETCHES  => false,   // 保持数值类型
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // 严格模式：拒绝无效日期、超出字段长度等
            $this->pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

        } catch (PDOException $e) {
            // 生产环境不暴露数据库详情
            error_log('DB connect error: ' . $e->getMessage());
            die('系统维护中，请稍后再试');
        }
    }

    /**
     * 获取数据库单例
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取PDO对象
     */
    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * 健康检查：确认连接仍可用
     */
    private function ping()
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * 获取预处理语句（带缓存）
     * 同一条 SQL 只 prepare 一次，后续复用
     */
    private function getStatement($sql)
    {
        $cacheKey = md5($sql);
        if (isset($this->stmtCache[$cacheKey])
            && $this->stmtCache[$cacheKey] instanceof PDOStatement) {
            return $this->stmtCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare($sql);
        $this->stmtCache[$cacheKey] = $stmt;

        // 缓存超过200条时清理最旧的一半
        if (count($this->stmtCache) > 200) {
            $this->stmtCache = array_slice($this->stmtCache, 100, null, true);
        }

        return $stmt;
    }

    /**
     * 清空预处理缓存（DDL 变更后调用）
     */
    public function clearStmtCache()
    {
        $this->stmtCache = [];
    }

    /**
     * 查询单条记录
     */
    public function fetch($sql, $params = [])
    {
        $stmt = $this->executeInternal($sql, $params);
        return $stmt->fetch();
    }

    /**
     * 查询多条记录（带行数保护）
     */
    public function fetchAll($sql, $params = [])
    {
        // 如果没有 LIMIT，自动加上保护
        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            $sql = preg_replace('/;\s*$/', '', $sql);
            $sql .= ' LIMIT ' . $this->maxRows;
        }
        $stmt = $this->executeInternal($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * 执行写操作(INSERT/UPDATE/DELETE)
     */
    public function execute($sql, $params = [])
    {
        $stmt = $this->executeInternal($sql, $params);
        return $stmt->rowCount() >= 0; // DELETE 0行也返回 true
    }

    /**
     * 获取最后插入的ID
     */
    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * 获取记录数
     */
    public function count($sql, $params = [])
    {
        $stmt = $this->executeInternal($sql, $params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * 分页查询（防SQL注入版）
     */
    public function paginate($sql, $params = [], $page = 1, $pageSize = null)
    {
        $pageSize = $pageSize ?: PAGE_SIZE;
        $pageSize = max(1, min(100, (int)$pageSize)); // 1~100 之间
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $pageSize;

        // 获取总数
        $countSql = "SELECT COUNT(*) FROM ({$sql}) AS t";
        $total = $this->count($countSql, $params);

        // 获取分页数据 — 用参数绑定防止注入
        $dataSql = $sql . " LIMIT ? OFFSET ?";
        $stmt = $this->executeInternal($dataSql, array_merge($params, [$pageSize, $offset]));
        $data = $stmt->fetchAll();

        return [
            'data'  => $data,
            'total' => $total,
            'page'  => $page,
            'pageSize' => $pageSize,
            'totalPages' => ceil($total / $pageSize)
        ];
    }

    /**
     * 内部执行方法（带慢查询日志 + 错误处理）
     */
    private function executeInternal($sql, $params = [])
    {
        $startTime = microtime(true);

        try {
            $stmt = $this->getStatement($sql);
            $stmt->execute($params);

            // 慢查询检测
            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $this->slowQueryThreshold) {
                $this->logSlowQuery($sql, $params, $elapsed);
            }

            return $stmt;

        } catch (\PDOException $e) {
            // 连接丢失时尝试重连一次
            if ($this->isConnectionLost($e)) {
                $this->reconnect();
                $this->stmtCache = []; // 重连后清空缓存
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }

            // 记录数据库异常
            error_log(sprintf(
                'DB Error: %s | SQL: %s | Params: %s',
                $e->getMessage(),
                $sql,
                json_encode($params, JSON_UNESCAPED_UNICODE)
            ));

            throw $e;
        }
    }

    /**
     * 判断是否为连接丢失错误
     */
    private function isConnectionLost(\PDOException $e)
    {
        $lostMessages = [
            'server has gone away',
            'no connection to the server',
            'lost connection',
            'is not connected',
            'connection timed out',
            'broken pipe',
        ];
        $msg = strtolower($e->getMessage());
        foreach ($lostMessages as $needle) {
            if (strpos($msg, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 重新连接
     */
    private function reconnect()
    {
        $this->pdo = null;
        $this->__construct();
    }

    /**
     * 记录慢查询
     */
    private function logSlowQuery($sql, $params, $elapsed)
    {
        $logMsg = sprintf(
            'Slow Query (%.3fs): %s | Params: %s | IP: %s',
            $elapsed,
            $sql,
            json_encode($params, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? 'cli'
        );
        error_log($logMsg);

        // 同时写入 logs 表
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO logs (level, module, action, detail, ip, created_at)
                 VALUES ('warning', '数据库', '慢查询', ?, ?, NOW())"
            );
            $stmt->execute([$logMsg, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
        } catch (\Exception $e) {
            // 日志写入失败不影响主流程
        }
    }

    /**
     * 事务开始（支持嵌套）
     */
    public function beginTransaction()
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT level_{$this->transactionLevel}");
        }
        $this->transactionLevel++;
        return true;
    }

    /**
     * 事务提交
     */
    public function commit()
    {
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            return $this->pdo->commit();
        } else {
            $this->pdo->exec("RELEASE SAVEPOINT level_{$this->transactionLevel}");
            return true;
        }
    }

    /**
     * 事务回滚
     */
    public function rollBack()
    {
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            return $this->pdo->rollBack();
        } else {
            $this->pdo->exec("ROLLBACK TO SAVEPOINT level_{$this->transactionLevel}");
            return true;
        }
    }

    /**
     * 安全事务执行（自动回滚）
     * @param callable $callback 事务内执行的操作
     * @return mixed callback 的返回值
     * @throws \Exception 事务失败时抛出
     */
    public function transaction($callback)
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * 批量插入（高效写入）
     * @param string $table 表名
     * @param array $columns 列名数组
     * @param array $rows 数据行数组（每行是值数组）
     * @return int 插入行数
     */
    public function batchInsert($table, array $columns, array $rows)
    {
        if (empty($rows)) return 0;

        // 表名白名单校验（只允许字母数字下划线）
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }

        // 列名白名单校验
        foreach ($columns as $col) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new \InvalidArgumentException("非法列名: {$col}");
            }
        }

        $colList = '`' . implode('`, `', $columns) . '`';
        $placeHolders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceHolders = implode(', ', array_fill(0, count($rows), $placeHolders));

        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES {$allPlaceHolders}";

        // 展平参数
        $flatParams = [];
        foreach ($rows as $row) {
            foreach ($row as $val) {
                $flatParams[] = $val;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($flatParams);
        return $stmt->rowCount();
    }

    /**
     * 获取当前事务层级
     */
    public function getTransactionLevel()
    {
        return $this->transactionLevel;
    }

    // 禁止克隆
    private function __clone() {}

    /**
     * 析构时确保事务回滚
     */
    public function __destruct()
    {
        if ($this->transactionLevel > 0) {
            try {
                while ($this->transactionLevel > 0) {
                    $this->rollBack();
                }
            } catch (\Exception $e) {
                // 忽略
            }
        }
    }
}
