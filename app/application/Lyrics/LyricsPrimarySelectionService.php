<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use stdClass;
use support\Db;

/**
 * 管理歌曲级主歌词选择，不修改任何来源歌词的固有优先级或正文。
 *
 * Controller 先要求 `edit_metadata`，本服务在事务前和事务内都应用歌曲所属库的实时 read/manage 范围。
 * 设置同时校验歌词 ID/版本和选择版本，避免候选变化或两个管理员互相覆盖；清除只删除选择行。所有
 * 写入与无正文审计同事务提交，不访问文件、Provider、任务或 Outbox。
 */
final class LyricsPrimarySelectionService
{
    public function __construct(
        private readonly LyricsAdminQueryService $query = new LyricsAdminQueryService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 把一个仍属于歌曲且版本未变的歌词设为主版本。
     *
     * 首次选择要求 `expectedSelectionVersion=null`；已有选择要求当前正版本。成功递增选择版本并返回最新
     * 单曲投影。歌词失效、跨歌曲和失权统一按 404；歌词或选择并发变化返回 409，事务完整回滚。
     *
     * @param array<string,mixed> $actor 已通过全局能力校验的身份快照。
     * @return array<string,mixed>
     */
    public function set(string $songId, string $lyricId, int $expectedLyricVersion, ?int $expectedSelectionVersion, array $actor, string $requestId): array
    {
        $this->requireUlid($songId, '歌曲标识无效。');
        $this->requireUlid($lyricId, '歌词标识无效。');
        if ($expectedLyricVersion < 1 || ($expectedSelectionVersion !== null && $expectedSelectionVersion < 1)) {
            throw new LyricsWritebackInvalid('主歌词版本无效。');
        }
        $this->findScopedSong($songId, $actor);

        try {
            Db::transaction(function () use ($actor, $expectedLyricVersion, $expectedSelectionVersion, $lyricId, $requestId, $songId): void {
                $song = $this->findScopedSong($songId, $actor);
                $lyric = Db::table('media_lyrics')->where('id', $lyricId)->where('song_id', $songId)
                    ->where('version', $expectedLyricVersion)->first(['id']);
                if (!$lyric instanceof stdClass) {
                    throw new LyricsWritebackNotFound('歌词不存在或不属于该歌曲。');
                }
                /** @var stdClass|null $current */
                $current = Db::table('media_lyrics_primary_selections')->where('song_id', $songId)
                    ->first(['version']);
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $nextVersion = 1;
                if ($current instanceof stdClass) {
                    if ($expectedSelectionVersion === null || (int) $current->version !== $expectedSelectionVersion) {
                        throw new LyricsWritebackConflict('主歌词选择已变化，请刷新后重试。');
                    }
                    $nextVersion = $expectedSelectionVersion + 1;
                    $changed = Db::table('media_lyrics_primary_selections')->where('song_id', $songId)
                        ->where('version', $expectedSelectionVersion)->update([
                            'lyric_id' => $lyricId, 'version' => $nextVersion,
                            'updated_by' => (string) $actor['id'], 'updated_at' => $now,
                        ]);
                    if ($changed !== 1) {
                        throw new LyricsWritebackConflict('主歌词选择已变化，请刷新后重试。');
                    }
                } else {
                    if ($expectedSelectionVersion !== null) {
                        throw new LyricsWritebackConflict('主歌词选择已清除，请刷新后重试。');
                    }
                    Db::table('media_lyrics_primary_selections')->insert([
                        'song_id' => $songId, 'lyric_id' => $lyricId, 'version' => 1,
                        'updated_by' => (string) $actor['id'], 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $this->audit->record((string) $actor['id'], 'lyrics.primary.set', 'song', $songId, 'success', $requestId, [
                    'libraryId' => (string) $song->library_id, 'lyricId' => $lyricId,
                    'lyricVersion' => $expectedLyricVersion, 'selectionVersion' => $nextVersion,
                ]);
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new LyricsWritebackConflict('主歌词选择已变化，请刷新后重试。', previous: $exception);
            }
            throw $exception;
        }

        return $this->query->song($songId, $actor);
    }

    /**
     * 按选择版本清除主歌词并恢复固有优先级链。
     *
     * 旧版本、已清除和撤权都不会产生审计或部分提交；成功不删除歌词行或任何媒体文件。
     *
     * @param array<string,mixed> $actor 已通过全局能力校验的身份快照。
     * @return array<string,mixed>
     */
    public function clear(string $songId, int $expectedSelectionVersion, array $actor, string $requestId): array
    {
        $this->requireUlid($songId, '歌曲标识无效。');
        if ($expectedSelectionVersion < 1) {
            throw new LyricsWritebackInvalid('主歌词选择版本无效。');
        }
        $this->findScopedSong($songId, $actor);
        Db::transaction(function () use ($actor, $expectedSelectionVersion, $requestId, $songId): void {
            $song = $this->findScopedSong($songId, $actor);
            $deleted = Db::table('media_lyrics_primary_selections')->where('song_id', $songId)
                ->where('version', $expectedSelectionVersion)->delete();
            if ($deleted !== 1) {
                throw new LyricsWritebackConflict('主歌词选择已变化或已清除，请刷新后重试。');
            }
            $this->audit->record((string) $actor['id'], 'lyrics.primary.clear', 'song', $songId, 'success', $requestId, [
                'libraryId' => (string) $song->library_id, 'selectionVersion' => $expectedSelectionVersion,
            ]);
        });

        return $this->query->song($songId, $actor);
    }

    /** 返回当前可管理歌曲；无权限、停用或媒体不可用统一按不存在处理。 */
    private function findScopedSong(string $songId, array $actor): stdClass
    {
        $query = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('songs.id', $songId)->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')->where('libraries.status', 'active');
        $this->scope($query, $actor, 'songs.library_id');
        $row = $query->first(['songs.id', 'songs.library_id']);
        if (!$row instanceof stdClass) {
            throw new LyricsWritebackNotFound('歌曲不存在或不可管理。');
        }
        return $row;
    }

    /** 应用请求身份的 read/manage 库范围；空 grant 使用不可能集合失败关闭。 */
    private function scope(Builder $query, array $actor, string $column): void
    {
        if (($actor['isSuperAdmin'] ?? false) === true) return;
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && is_string($library['id'] ?? null)
                && in_array($library['accessLevel'] ?? null, ['read', 'manage'], true)) $ids[] = $library['id'];
        }
        $query->whereIn($column, array_values(array_unique($ids)) ?: ['']);
    }

    /** 仅接受规范 ULID；对象存在性仍由授权查询判断。 */
    private function requireUlid(string $value, string $message): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) throw new LyricsWritebackInvalid($message);
    }
}
