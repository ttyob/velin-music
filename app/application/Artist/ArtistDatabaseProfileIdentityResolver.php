<?php

declare(strict_types=1);

namespace app\application\Artist;

use app\application\Search\SearchTextNormalizer;
use PDO;
use Throwable;

/**
 * 从固定只读艺人 SQLite 解析唯一 MusicBrainz 身份。
 *
 * FTS 只把候选限制到 32 个，最终仍用统一名称规范化执行完整相等比较；多个不同 MBID 同时精确命中时
 * 返回 null，避免把同名艺人资料写错。数据库缺失、损坏、繁忙或无唯一命中时返回 null，由艺人资料插件
 * 转用 MusicBrainz 外部名称搜索；它既不阻断歌曲刮削，也不是完整艺人资料功能的前置依赖。连接使用
 * query_only，绝不修改上传的辅助库。
 */
final class ArtistDatabaseProfileIdentityResolver implements ArtistProfileIdentityResolver
{
    private ?PDO $connection = null;
    private bool $unavailable = false;

    public function __construct(
        private readonly string $databasePath = '/media/cache/artist-database/musicbrainz_artists_zh.sqlite',
        private readonly SearchTextNormalizer $normalizer = new SearchTextNormalizer(),
    ) {
    }

    /** 执行有界精确查询；只有一个不同 MBID 时才返回身份。 */
    public function resolve(string $artistName): ?ArtistProfileIdentity
    {
        $artistName = trim($artistName);
        $needle = $this->normalizer->normalize($artistName);
        if ($needle === '' || mb_strlen($artistName, 'UTF-8') > 160 || $this->unavailable) return null;
        try {
            $pdo = $this->connection();
            if (!$pdo instanceof PDO) return null;
            $statement = $pdo->prepare(<<<'SQL'
WITH matched AS (
    SELECT DISTINCT mbid
    FROM artist_names_fts
    WHERE artist_names_fts MATCH :query
    LIMIT 32
)
SELECT a.mbid, a.name_zh, a.name_original, a.country,
       n.name_zh AS alias_zh, n.name_original AS alias_original
FROM matched m
JOIN artists a ON a.mbid = m.mbid
LEFT JOIN artist_names n ON n.mbid = m.mbid
LIMIT 256
SQL);
            $statement->execute(['query' => '"' . str_replace('"', '""', $artistName) . '"']);
            $matches = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                foreach (['name_zh', 'name_original', 'alias_zh', 'alias_original'] as $field) {
                    if (is_string($row[$field] ?? null)
                        && $this->normalizer->normalize($row[$field]) === $needle) {
                        $mbid = strtolower(trim((string) ($row['mbid'] ?? '')));
                        if ($this->validMbid($mbid)) $matches[$mbid] = $row;
                        break;
                    }
                }
            }
            if (count($matches) !== 1) return null;
            $mbid = (string) array_key_first($matches);
            $row = $matches[$mbid];
            return new ArtistProfileIdentity(
                $mbid,
                $this->text($row['name_zh'] ?? null, 255),
                $this->text($row['name_original'] ?? null, 255),
                $this->country($row['country'] ?? null),
            );
        } catch (Throwable) {
            $this->unavailable = true;
            return null;
        }
    }

    /** 只打开固定普通 SQLite 文件；任何异常后当前实例永久降级。 */
    private function connection(): ?PDO
    {
        if ($this->connection instanceof PDO) return $this->connection;
        $stat = @lstat($this->databasePath);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($this->databasePath)
            || @file_get_contents($this->databasePath, false, null, 0, 16) !== "SQLite format 3\0") {
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

    private function validMbid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function text(mixed $value, int $maximum): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value !== '' && mb_strlen($value, 'UTF-8') <= $maximum ? $value : null;
    }

    private function country(mixed $value): ?string
    {
        $value = is_string($value) ? strtoupper(trim($value)) : '';
        return preg_match('/^[A-Z]{2}$/D', $value) === 1 ? $value : null;
    }
}
