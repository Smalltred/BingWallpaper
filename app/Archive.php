<?php

declare(strict_types=1);

namespace BingWallpaper;

/**
 * bingimages 历史壁纸归档的数据访问。
 *
 * 数据集是另一个项目（bingimages）用 merge 脚本独立产出的 SQLite 库，
 * 这里**只读**引用它 —— 通过 PRAGMA query_only 从连接层禁止任何写操作，
 * 免得哪天一个手误往别人的数据集里写东西。
 *
 * 为什么用 PDO SQLite 而不是读 daily/*.json：
 *   3851 个 JSON 文件要支持「关键词搜索 + 年份筛选 + 分页」就得全量扫描 + 每次读盘，
 *   SQLite 一条 SQL 就搞定，而且它本来就是数据集的主存储格式。
 */
final class Archive
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly string $dbPath)
    {
    }

    /** 数据集是否就绪（文件存在且可读） */
    public function isAvailable(): bool
    {
        return is_file($this->dbPath) && is_readable($this->dbPath);
    }

    public function dbPath(): string
    {
        return $this->dbPath;
    }

    /**
     * 归档统计：总量 / 日期范围 / 每年条数。
     *
     * @return array{total:int, min_date:string, max_date:string, by_year:list<array{year:int,count:int}>}
     */
    public function stats(): array
    {
        $pdo = $this->pdo();

        $total = (int) $pdo->query('SELECT COUNT(*) FROM bing_wallpapers')->fetchColumn();
        $range = $pdo->query('SELECT MIN(date) mn, MAX(date) mx FROM bing_wallpapers')->fetch() ?: [];

        $byYear = [];
        $rows = $pdo->query('SELECT year, COUNT(*) c FROM bing_wallpapers GROUP BY year ORDER BY year')->fetchAll();
        foreach ($rows as $r) {
            $byYear[] = ['year' => (int) $r['year'], 'count' => (int) $r['c']];
        }

        return [
            'total' => $total,
            'min_date' => (string) ($range['mn'] ?? ''),
            'max_date' => (string) ($range['mx'] ?? ''),
            'by_year' => $byYear,
        ];
    }

    /**
     * 分页查询。
     *
     * @param string   $keyword 关键词，空串表示不过滤；按标题/版权/日期多字段匹配
     * @param int|null $year    年份过滤，null 表示不过滤
     *
     * @return array{items:list<array<string,mixed>>, total:int, page:int, size:int, has_more:bool}
     */
    public function page(int $page, int $size, string $keyword = '', ?int $year = null): array
    {
        $pdo = $this->pdo();

        [$where, $params] = $this->buildFilter($keyword, $year);
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bing_wallpapers{$whereSql}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = ($page - 1) * $size;

        // LIMIT/OFFSET 用绑定参数；PDO SQLite 在仿真预处理关闭时也能正确处理整数绑定
        $stmt = $pdo->prepare(
            "SELECT * FROM bing_wallpapers{$whereSql} ORDER BY date DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $size, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->rowToItem($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'has_more' => $offset + count($items) < $total,
        ];
    }

    /**
     * @return array{0:list<string>, 1:array<string,mixed>}
     */
    private function buildFilter(string $keyword, ?int $year): array
    {
        $where = [];
        $params = [];

        if ($keyword !== '') {
            $where[] = '(title LIKE :kw ESCAPE \'\\\' OR title_en LIKE :kw ESCAPE \'\\\''
                . ' OR copyright LIKE :kw ESCAPE \'\\\' OR copyright_en LIKE :kw ESCAPE \'\\\''
                . ' OR date LIKE :kw_prefix ESCAPE \'\\\')';
            $params[':kw'] = self::likePattern($keyword);
            // 日期用前缀匹配：搜 2024-10 能命中整个 10 月
            $params[':kw_prefix'] = self::likePattern($keyword, false);
        }

        if ($year !== null) {
            $where[] = 'year = :year';
            $params[':year'] = $year;
        }

        return [$where, $params];
    }

    /**
     * 构造 LIKE 模式串。
     *
     * 必须转义 % 和 _：用户搜 "50%" 时那个 % 若不加转义会被当成通配符，
     * 结果是「匹配到几乎所有记录」而不是报错 —— 这种静默错误很难查。
     *
     * @param bool $bothSides true 为 %kw%，false 为 kw%（前缀匹配）
     */
    private static function likePattern(string $keyword, bool $bothSides = true): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);

        return $bothSides ? "%{$escaped}%" : "{$escaped}%";
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function rowToItem(array $row): array
    {
        $url = (string) ($row['url'] ?? '');
        $sources = array_values(array_filter(
            array_map('trim', explode(',', (string) ($row['sources'] ?? ''))),
            static fn (string $s): bool => $s !== ''
        ));

        return [
            'date' => (string) ($row['date'] ?? ''),
            'date_compact' => (string) ($row['date_compact'] ?? ''),
            'year' => (int) ($row['year'] ?? 0),
            'month' => (int) ($row['month'] ?? 0),
            'day' => (int) ($row['day'] ?? 0),
            'weekday' => (string) ($row['weekday'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'title_en' => (string) ($row['title_en'] ?? ''),
            'copyright' => (string) ($row['copyright'] ?? ''),
            'copyright_en' => (string) ($row['copyright_en'] ?? ''),
            'copyrightlink' => (string) ($row['copyrightlink'] ?? ''),
            'url' => $url,
            // 只有部分历史图有 4K 版本，服务端算好一次性给前端，避免两边各写一套判断
            'url_4k' => self::upgradeableTo4k($url),
            'region' => (string) ($row['region'] ?? ''),
            'sources' => $sources,
        ];
    }

    /**
     * 历史图能否升到 4K。
     *
     * 实测结论（2016-2026 全量取样验证）：
     *   - cn.bing.com 的 /th?id=...&w=&h= 形式 → 改参数即得真 4K（实测 266 KB → 967 KB）
     *   - cdn.bimg.cc / bing.com 的老图 → 改写文件名（_UHD.jpg / _3840x2160.jpg）都是 404，
     *     Bing 侧就没有这些图的 4K 版本
     *
     * 所以不可升的返回 null，而不是给一个必定 404 的地址 —— 前端据此显示「原图(1080p)」。
     */
    public static function upgradeableTo4k(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || strtolower($host) !== 'cn.bing.com') {
            return null;
        }

        if (preg_match('/[?&]w=\d+/', $url) !== 1 || preg_match('/[?&]h=\d+/', $url) !== 1) {
            return null;
        }

        $out = preg_replace('/([?&])w=\d+/', '$1w=3840', $url, 1);
        $out = preg_replace('/([?&])h=\d+/', '$1h=2160', (string) $out, 1);

        return is_string($out) ? $out : null;
    }

    /**
     * 惰性建立只读连接。
     *
     * @throws \RuntimeException 数据集不可读或无法打开
     */
    private function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        if (!$this->isAvailable()) {
            throw new \RuntimeException('数据集不可读: ' . $this->dbPath);
        }

        try {
            $pdo = new \PDO('sqlite:' . $this->dbPath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('打开数据集失败: ' . $e->getMessage(), 0, $e);
        }

        // 从连接层禁止写：这个库属于另一个项目，本服务只应读它
        $pdo->exec('PRAGMA query_only = 1');

        return $this->pdo = $pdo;
    }
}
