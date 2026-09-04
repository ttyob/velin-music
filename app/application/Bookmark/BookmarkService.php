<?php

declare(strict_types=1);

namespace app\application\Bookmark;

use app\application\Media\MediaQueryService;
use app\application\Media\SongDuplicateRedirectResolver;
use app\application\ResourcePlugin\Contract\PluginDomainEvent;
use app\application\ResourcePlugin\PluginEventPublisher;
use stdClass;
use support\Db;

/**
 * 管理 Web 与 Subsonic 兼容层共享、按用户隔离的歌曲书签。
 *
 * 返回歌曲资料前必须应用实时音乐库和文件权限；保存会在短事务内重新证明歌曲可见性，并以用户/歌曲唯一
 * 行和乐观版本防止覆盖。旧 Subsonic 协议没有版本前置条件，只能使用最后命令生效语义。删除允许用户在
 * 撤权后清理自己的隐藏书签，但不返回媒体资料。成功提交后只向 Redis 发布不含评论正文的有限期摘要，
 * Redis 故障不回滚个人书签；插件不得把通知当作书签事实来源。
 */
final readonly class BookmarkService
{
    /** 构建权限查询和提交后事件发布依赖；构造阶段不访问数据库或 Redis。 */
    public function __construct(
        private MediaQueryService $media = new MediaQueryService(),
        private PluginEventPublisher $events = new PluginEventPublisher(),
    )
    {
    }

    /**
     * Returns one recent-first page after authorization filtering and before public song projection.
     *
     * @param array<string, mixed> $actor Authenticated principal with global play capability.
     * @return array{bookmarks: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $actor, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $query = Db::table('user_song_bookmarks as bookmarks')
            ->where('bookmarks.user_id', (string) $actor['id']);
        $this->media->constrainToVisibleSongs($query, $actor, 'bookmarks.song_id');
        $total = (clone $query)->count();
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('bookmarks.updated_at')->orderBy('bookmarks.song_id')
            ->offset($offset)->limit($limit)
            ->get([
                'bookmarks.song_id', 'bookmarks.position_ms', 'bookmarks.comment',
                'bookmarks.version', 'bookmarks.created_at', 'bookmarks.updated_at',
            ])->all();

        return [
            'bookmarks' => $this->mapRows($actor, $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Returns the current user's authorized bookmark or null for absent, hidden, or invalid media.
     *
     * This non-enumerating shape lets the player obtain expectedVersion=0 before creating a bookmark
     * without learning whether an inaccessible song exists. It performs no writes.
     *
     * @param array<string, mixed> $actor Authenticated principal with current grants.
     * @return array<string, mixed>|null
     */
    public function one(array $actor, string $songId): ?array
    {
        $songId = (new SongDuplicateRedirectResolver())->resolve($songId);
        /** @var stdClass|null $row */
        $row = Db::table('user_song_bookmarks')->where('user_id', (string) $actor['id'])
            ->where('song_id', $songId)
            ->first(['song_id', 'position_ms', 'comment', 'version', 'created_at', 'updated_at']);
        if (!$row instanceof stdClass) {
            return null;
        }

        return $this->mapRows($actor, [$row])[0] ?? null;
    }

    /**
     * 按 Web 乐观版本或旧协议覆盖语义创建、更新一个书签。
     *
     * 授权、时长上限、版本比较和写入位于同一事务。`expectedVersion=0` 要求当前不存在，正数必须等于
     * 当前版本，null 仅供没有版本字段的 Subsonic 使用。位置可等于已知时长但不能超过；时长为零时只执行
     * 协议非负上限。事务提交后发布 `bookmark.changed.v1`，仅包含 saved、位置和新版本，不包含评论。
     *
     * @param array<string, mixed> $actor 用户 ID 拥有该行的已认证身份。
     * @return array<string, mixed> 提交后重新授权的完整书签投影。
     */
    public function save(array $actor, BookmarkInput $input): array
    {
        $userId = (string) $actor['id'];
        $songId = (new SongDuplicateRedirectResolver())->resolve($input->songId);
        Db::transaction(function () use ($actor, $input, $songId, $userId): void {
            $song = $this->media->songsByIds($actor, [$songId])[$songId] ?? null;
            if (!is_array($song)) {
                throw new BookmarkNotFound('Bookmark song was not found.');
            }
            $durationMs = max(0, (int) ($song['durationMs'] ?? 0));
            if ($durationMs > 0 && $input->positionMs > $durationMs) {
                throw new BookmarkInvalid('Bookmark position exceeds song duration.');
            }
            /** @var stdClass|null $current */
            $current = Db::table('user_song_bookmarks')->where('user_id', $userId)
                ->where('song_id', $songId)->first(['version']);
            $currentVersion = $current instanceof stdClass ? (int) $current->version : 0;
            if ($input->expectedVersion !== null && $input->expectedVersion !== $currentVersion) {
                throw new BookmarkConflict('书签已在其他页面或客户端更新，请重新加载。');
            }
            $now = gmdate('c');
            if (!$current instanceof stdClass) {
                Db::table('user_song_bookmarks')->insert([
                    'user_id' => $userId,
                    'song_id' => $songId,
                    'position_ms' => $input->positionMs,
                    'comment' => $input->comment,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return;
            }
            $updated = Db::table('user_song_bookmarks')->where('user_id', $userId)
                ->where('song_id', $songId)->where('version', $currentVersion)->update([
                    'position_ms' => $input->positionMs,
                    'comment' => $input->comment,
                    'version' => $currentVersion + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                // SQLite serializes writers today, while the future MySQL repository may observe
                // a competing version between read and update. The predicate is the final arbiter.
                throw new BookmarkConflict('书签已在其他页面或客户端更新，请重新加载。');
            }
        });

        $saved = $this->one($actor, $songId)
            ?? throw new BookmarkNotFound('Saved bookmark is no longer authorized.');
        $this->events->publish(PluginDomainEvent::BOOKMARK_CHANGED, 'song', $songId, 'user', $userId, [
            'action' => 'saved',
            'positionMs' => (int) $saved['positionMs'],
            'version' => (int) $saved['version'],
        ]);
        return $saved;
    }

    /**
     * 只删除当前认证用户自己的书签，并可执行 Web 乐观版本校验。
     *
     * expectedVersion 为 null 是 Subsonic 幂等形式，不存在也成功；Web 必须提交精确正版本，不存在或陈旧
     * 均冲突。删除故意不要求当前媒体权限，使撤权用户仍可清理个人数据。只有实际删除一行时才在事务提交
     * 后发布 deleted 摘要；幂等空删除不制造虚假事件，Redis 失败不恢复已删除书签。
     */
    public function delete(array $actor, string $songId, ?int $expectedVersion): void
    {
        $songId = (new SongDuplicateRedirectResolver())->resolve($songId);
        $userId = (string) $actor['id'];
        $deleted = Db::transaction(function () use ($expectedVersion, $songId, $userId): bool {
            /** @var stdClass|null $row */
            $row = Db::table('user_song_bookmarks')->where('user_id', $userId)
                ->where('song_id', $songId)->first(['version']);
            if (!$row instanceof stdClass) {
                if ($expectedVersion !== null) {
                    throw new BookmarkConflict('书签已被删除，请重新加载。');
                }
                return false;
            }
            $version = (int) $row->version;
            if ($expectedVersion !== null && $expectedVersion !== $version) {
                throw new BookmarkConflict('书签已在其他页面或客户端更新，请重新加载。');
            }
            $deleted = Db::table('user_song_bookmarks')->where('user_id', $userId)
                ->where('song_id', $songId)->where('version', $version)->delete();
            if ($deleted !== 1) {
                throw new BookmarkConflict('书签已在其他页面或客户端更新，请重新加载。');
            }
            return true;
        });
        if ($deleted) {
            $this->events->publish(PluginDomainEvent::BOOKMARK_CHANGED, 'song', $songId, 'user', $userId, [
                'action' => 'deleted',
            ]);
        }
    }

    /**
     * Maps rows only after fetching current authorized song projections in one bounded query.
     *
     * @param list<stdClass> $rows Rows already filtered by user ownership; list queries also apply visibility.
     * @return list<array<string, mixed>> Path-free personal bookmark projections.
     */
    private function mapRows(array $actor, array $rows): array
    {
        $songIds = array_map(static fn (stdClass $row): string => (string) $row->song_id, $rows);
        $songs = $this->media->songsByIds($actor, $songIds);
        $result = [];
        foreach ($rows as $row) {
            $songId = (string) $row->song_id;
            if (!isset($songs[$songId])) {
                continue;
            }
            $result[] = [
                'songId' => $songId,
                'positionMs' => (int) $row->position_ms,
                'comment' => $row->comment === null ? null : (string) $row->comment,
                'version' => (int) $row->version,
                'createdAt' => (string) $row->created_at,
                'updatedAt' => (string) $row->updated_at,
                'song' => $songs[$songId],
            ];
        }

        return $result;
    }
}
