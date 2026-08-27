<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use stdClass;
use support\Db;

/**
 * 清理一首歌曲当前登记的全部歌词版本，但不修改音频标签或用户相邻文件。
 *
 * Controller 必须先校验 `edit_metadata`，本服务仍按歌曲所属音乐库实时应用 read/manage grant。调用方
 * 提交弹窗快照中的全部歌词 ID 与版本；事务内会把该快照和当前集合完整比较，任何新增、删除或版本变化
 * 都以冲突失败，防止管理员在旧弹窗上误删并发产生的歌词。主版本选择、解析诊断和歌词索引在同一短事务
 * 删除并写入脱敏审计；已有不可变写回方案通过外键阻止删除，不能破坏任务证据。
 *
 * `managed_cache` 正文是可重建派生文件，索引删除后成为待回收的无引用缓存，不能在请求内删除：Provider
 * 发布采用“先文件、后索引”，同步删除会和另一任务并发登记相同内容寻址文件产生竞态。`adjacent` 正文
 * 可能是用户原始 sidecar，因此始终只解除索引，后续扫描可以重新发现。该命令不删除音频内嵌歌词，也
 * 不回滚此前单独确认的 sidecar 或音频标签写回；物理缓存回收必须由独立受控治理流程实现。
 */
final readonly class LyricsCleanupService
{
    private const MAX_VERSIONS = 100;

    public function __construct(
        private LyricsAdminQueryService $query = new LyricsAdminQueryService(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 按完整版本快照删除该歌曲的全部歌词管理事实。
     *
     * `expectedLyrics` 必须是非空列表，每项只含规范 ULID `id` 和正整数 `version`，且不得重复。成功返回
     * 已重新授权的空歌词详情；若集合并发变化或歌词仍被写回方案引用则抛出冲突，数据库和文件均不改变。
     * 缓存文件不在请求内同步删除，返回成功只承诺歌词已不可读取；无引用缓存留待独立受控治理流程回收。
     *
     * @param list<array{id:string,version:int}> $expectedLyrics 弹窗当前显示的完整歌词版本快照。
     * @param array<string,mixed> $actor 已通过全局能力校验的当前身份快照。
     * @return array<string,mixed> 清理后的单曲歌词投影。
     */
    public function clearAll(string $songId, array $expectedLyrics, array $actor, string $requestId): array
    {
        $this->requireUlid($songId, '歌曲标识无效。');
        $expected = $this->normalizeExpectedLyrics($expectedLyrics);
        $this->findScopedSong($songId, $actor);
        $managedCacheCount = 0;

        try {
            Db::transaction(function () use ($actor, $expected, &$managedCacheCount, $requestId, $songId): void {
                $song = $this->findScopedSong($songId, $actor);
                /** @var list<stdClass> $rows */
                $rows = Db::table('media_lyrics')->where('song_id', $songId)->orderBy('id')
                    ->get(['id', 'version', 'storage_kind'])->all();
                $current = array_map(static fn (stdClass $row): array => [
                    'id' => (string) $row->id,
                    'version' => (int) $row->version,
                ], $rows);
                if ($current !== $expected) {
                    throw new LyricsWritebackConflict('歌词版本已经变化，请刷新后重新确认清理。');
                }
                foreach ($rows as $row) {
                    if ((string) $row->storage_kind !== 'managed_cache') continue;
                    ++$managedCacheCount;
                }
                Db::table('media_lyrics_primary_selections')->where('song_id', $songId)->delete();
                Db::table('media_lyrics_parse_diagnostics')->where('song_id', $songId)->delete();
                $deleted = Db::table('media_lyrics')->where('song_id', $songId)->delete();
                if ($deleted !== count($expected)) {
                    throw new LyricsWritebackConflict('歌词版本已经变化，请刷新后重新确认清理。');
                }
                $this->audit->record(
                    (string) $actor['id'],
                    'lyrics.all.clear',
                    'song',
                    $songId,
                    'success',
                    $requestId,
                    [
                        'libraryId' => (string) $song->library_id,
                        'versionCount' => count($expected),
                        'managedCacheCount' => $managedCacheCount,
                    ],
                );
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'foreign key')) {
                throw new LyricsWritebackConflict('歌词仍被写回方案引用，暂时无法清理。', previous: $exception);
            }
            throw $exception;
        }

        return $this->query->song($songId, $actor);
    }

    /** @param list<mixed> $expectedLyrics @return list<array{id:string,version:int}> */
    private function normalizeExpectedLyrics(array $expectedLyrics): array
    {
        if (!array_is_list($expectedLyrics) || $expectedLyrics === [] || count($expectedLyrics) > self::MAX_VERSIONS) {
            throw new LyricsWritebackInvalid('待清理歌词版本无效。');
        }
        $normalized = [];
        $seen = [];
        foreach ($expectedLyrics as $lyric) {
            if (!is_array($lyric) || array_is_list($lyric)) {
                throw new LyricsWritebackInvalid('待清理歌词版本无效。');
            }
            $keys = array_keys($lyric);
            sort($keys);
            if ($keys !== ['id', 'version'] || !is_string($lyric['id']) || !is_int($lyric['version'])
                || $lyric['version'] < 1) {
                throw new LyricsWritebackInvalid('待清理歌词版本无效。');
            }
            $this->requireUlid($lyric['id'], '歌词标识无效。');
            if (isset($seen[$lyric['id']])) throw new LyricsWritebackInvalid('待清理歌词版本重复。');
            $seen[$lyric['id']] = true;
            $normalized[] = ['id' => $lyric['id'], 'version' => $lyric['version']];
        }
        usort($normalized, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        return $normalized;
    }

    /** 返回实时授权的歌曲和库事实；失权、停用或媒体不可用统一按不存在处理。 */
    private function findScopedSong(string $songId, array $actor): stdClass
    {
        $query = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('songs.id', $songId)->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')->where('libraries.status', 'active');
        $this->scope($query, $actor, 'songs.library_id');
        $row = $query->first(['songs.id', 'songs.library_id']);
        if (!$row instanceof stdClass) throw new LyricsWritebackNotFound('歌曲不存在或不可管理。');

        return $row;
    }

    /** 应用请求身份的 read/manage 库范围；空授权使用不可能集合失败关闭。 */
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

    /** ULID 形状校验不代替授权查询，只阻止畸形标识进入数据库条件。 */
    private function requireUlid(string $value, string $message): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) throw new LyricsWritebackInvalid($message);
    }
}
