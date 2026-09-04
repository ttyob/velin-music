<?php

declare(strict_types=1);

namespace app\application\Scrape;

use app\application\Artist\ArtistNameIdentityNormalizer;
use app\application\Storage\StorageLayout;
use PDO;
use Throwable;

/**
 * 从固定只读艺人 SQLite 中执行有界精确名称查询。
 *
 * 查询先通过 `artist_names_fts` 把候选 MBID 限制为最多 32 个，再回查规范名和别名并在 PHP 使用与
 * 搜索一致的规范化规则做完整相等比较。FTS 命中本身不算精确命中；数据库不存在、表结构变化、锁定
 * 或单次查询失败均静默返回 false，保证辅助数据永远不能阻断扫描和刮削。
 */
final class ArtistDatabaseNameMatcher implements ArtistNameLookup
{
    private ?PDO $connection = null;
    private bool $unavailable = false;
    /** @var array<string,bool> 单个 Worker 内最多缓存 2048 个规范名称，避免同一目录艺人反复查询 FTS。 */
    private array $cache = [];

    /**
     * 创建惰性只读名称匹配器。
     *
     * 生产路径固定且不读取环境变量；路径注入仅供测试夹具。构造时不打开 SQLite，首次有效查询才创建
     * query_only 连接，因而未安装辅助库不会增加普通扫描启动成本。
     */
    public function __construct(
        private readonly string $databasePath = StorageLayout::ARTIST_DATABASE_PATH,
        private readonly ArtistNameIdentityNormalizer $normalizer = new ArtistNameIdentityNormalizer(),
    ) {
    }

    /**
     * 判断一个完整候选是否为已知规范名或别名。
     *
     * 输入最长 160 个字符，不接受空值。PDO 连接在当前 PHP Worker 内惰性复用并设置 query_only；方法
     * 最多读取 32 个 FTS MBID 对应的名称。异常后本实例永久降级，避免同一扫描批次反复访问损坏文件。
     */
    public function contains(string $name): bool
    {
        $name = trim($name);
        $needle = $this->normalizer->identityKey($name);
        if ($needle === '' || mb_strlen($name, 'UTF-8') > 160 || $this->unavailable) return false;
        if (array_key_exists($needle, $this->cache)) return $this->cache[$needle];
        try {
            $pdo = $this->connection();
            if ($pdo === null) return false;
            $statement = $pdo->prepare(<<<'SQL'
WITH matched AS (
    SELECT DISTINCT mbid
    FROM artist_names_fts
    WHERE artist_names_fts MATCH :query
    LIMIT 32
)
SELECT n.name_zh, n.name_original,
       a.name_zh AS artist_name_zh, a.name_original AS artist_name_original
FROM matched m
JOIN artists a ON a.mbid = m.mbid
LEFT JOIN artist_names n ON n.mbid = m.mbid
LIMIT 256
SQL);
            $statement->execute(['query' => '"' . str_replace('"', '""', $name) . '"']);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                foreach ($row as $candidate) {
                    if (is_string($candidate) && $this->normalizer->identityKey($candidate) === $needle) {
                        return $this->remember($needle, true);
                    }
                }
            }
        } catch (Throwable) {
            $this->unavailable = true;
        }
        return $this->remember($needle, false);
    }

    /** 只打开固定普通 SQLite 文件；连接不创建文件，且整个生命周期禁止写语句。 */
    private function connection(): ?PDO
    {
        if ($this->connection instanceof PDO) return $this->connection;
        $stat = @lstat($this->databasePath);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($this->databasePath)) {
            $this->unavailable = true;
            return null;
        }
        if (file_get_contents($this->databasePath, false, null, 0, 16) !== "SQLite format 3\0") {
            $this->unavailable = true;
            return null;
        }
        $this->connection = new PDO('sqlite:' . $this->databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 1,
        ]);
        $this->connection->exec('PRAGMA query_only = ON');
        $this->connection->exec('PRAGMA busy_timeout = 1000');
        return $this->connection;
    }

    /** 缓存有明确容量上限；淘汰最早插入项不会影响正确性，只会让后续查询重新访问只读库。 */
    private function remember(string $name, bool $matched): bool
    {
        if (count($this->cache) >= 2048) array_shift($this->cache);
        $this->cache[$name] = $matched;
        return $matched;
    }
}
