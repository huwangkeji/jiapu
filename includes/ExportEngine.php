<?php
/**
 * ExportEngine — 家谱编目成书数据引擎
 * 聚合家族数据，按世次排序，生成导出用的结构化数据
 * PHP 7.4 + 纯本地化，零外部依赖
 */

class ExportEngine
{
    private $db;
    private $familyId;
    private $family;
    private $members;
    private $generations;

    /**
     * @param int $familyId 家谱ID
     */
    public function __construct($familyId)
    {
        $this->db = Database::getInstance();
        $this->familyId = (int)$familyId;
        $this->load();
    }

    /**
     * 加载家族数据
     */
    private function load()
    {
        // 家谱信息
        $this->family = $this->db->fetch(
            "SELECT f.*, u.nickname as creator_name
             FROM families f
             LEFT JOIN users u ON f.user_id = u.id
             WHERE f.id = ? AND f.status = 1",
            [$this->familyId]
        );

        if (!$this->family) {
            throw new \RuntimeException('家谱不存在');
        }

        // 所有成员（在世序内）
        $rows = $this->db->fetchAll(
            "SELECT m.*, 
                    p.name as father_name,
                    s.name as spouse_name
             FROM members m
             LEFT JOIN members p ON m.parent_id = p.id
             LEFT JOIN members s ON m.spouse_id = s.id
             WHERE m.family_id = ? AND m.status = 1
             ORDER BY m.generation ASC, m.sort_order ASC, m.id ASC",
            [$this->familyId]
        );

        $this->members = $rows;
        $this->generations = $this->groupByGeneration($rows);
    }

    /**
     * 按世次分组
     */
    private function groupByGeneration($rows)
    {
        $groups = [];
        foreach ($rows as $row) {
            $gen = (int)$row['generation'];
            $groups[$gen][] = $row;
        }
        ksort($groups);
        return $groups;
    }

    /**
     * 获取家谱基本信息
     */
    public function getFamily()
    {
        return $this->family;
    }

    /**
     * 获取所有成员
     */
    public function getMembers()
    {
        return $this->members;
    }

    /**
     * 按世次分组获取成员
     */
    public function getGenerations()
    {
        return $this->generations;
    }

    /**
     * 获取世次范围
     */
    public function getGenerationRange()
    {
        $keys = array_keys($this->generations);
        return ['min' => min($keys), 'max' => max($keys)];
    }

    /**
     * 统计信息
     */
    public function getStats()
    {
        $male = 0;
        $female = 0;
        foreach ($this->members as $m) {
            if ($m['gender'] === 'male') $male++;
            elseif ($m['gender'] === 'female') $female++;
        }
        return [
            'total'    => count($this->members),
            'male'     => $male,
            'female'   => $female,
            'generations' => count($this->generations),
        ];
    }

    /**
     * 生成书名
     */
    public function getBookTitle()
    {
        $surname = $this->family['surname'];
        $title   = $this->family['title'];
        return "{$surname}氏族谱 — {$title}";
    }

    /**
     * 生成导出用的结构化数据数组
     * @return array
     */
    public function getExportData()
    {
        return [
            'family'      => $this->family,
            'title'       => $this->getBookTitle(),
            'stats'       => $this->getStats(),
            'gen_range'   => $this->getGenerationRange(),
            'generations' => $this->generations,
            'members'     => $this->members,
        ];
    }
}
