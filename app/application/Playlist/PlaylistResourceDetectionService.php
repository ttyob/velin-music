<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\application\Media\MediaQueryService;
use app\application\Scrape\ChineseQueryVariantNormalizer;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Throwable;

/**
 * 在不访问第三方来源的前提下，把歌单导入证据重新关联到当前媒体库。
 *
 * 检测只读取 `playlist_import_entries` 已保存的标题、艺人和专辑证据，永远不重新请求 Last.fm、网易云、
 * QQ 音乐或酷狗。候选查询从 MediaQueryService 的实时授权范围开始；繁简转换只在内存中产生额外等值
 * 候选，原始证据不被覆盖。唯一候选才写入 song_id，多候选继续保持 ambiguous，避免同名歌曲误关联。
 *
 * 检测结果在短事务中使用歌单版本锁提交。事务会重新读取全部导入条目，按原始 position 重建连续的
 * playlist_items 顺序；媒体删除、授权撤销或并发修改会让对应条目回到 resource_missing，失败则整体回滚。
 * 该服务不访问文件路径、不创建下载任务，也不改变来源规则的同步时间和开关。
 */
final readonly class PlaylistResourceDetectionService
{
    private const MAX_ENTRIES = 100;

    public function __construct(
        private MediaQueryService $media = new MediaQueryService(),
        private ChineseQueryVariantNormalizer $identityNormalizer = new ChineseQueryVariantNormalizer(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 检测系统歌单中当前尚未关联的资源，并创建唯一歌曲关联。
     *
     * 入口要求调用方已经通过 `manage_system`，这里仍验证 system scope 和版本，避免把用户歌单或过期
     * 页面请求当成后台检测。返回统计反映提交事务后的完整导入条目状态；没有本地唯一候选不是异常，
     * 只会保留缺失/歧义状态。所有数据库写入集中在一个短事务，冲突时不留下部分关联。
     *
     * @param array<string,mixed> $actor 已认证管理员及实时媒体授权。
     * @return array{detectedCount:int,matchedCount:int,missingCount:int,totalCount:int,playlistVersion:int}
     * @throws PlaylistNotFound 歌单不存在、不是系统歌单或导入证据表不可用。
     * @throws PlaylistConflict 歌单在检测期间被其他写操作修改。
     */
    public function detect(array $actor, string $playlistId, string $requestId): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $playlistId) !== 1
            || !Db::connection()->getSchemaBuilder()->hasTable('playlist_import_entries')) {
            throw new PlaylistNotFound('System playlist not found.');
        }
        /** @var stdClass|null $playlist */
        $playlist = Db::table('playlists')->where('id', $playlistId)->where('scope', 'system')
            ->first(['version', 'owner_user_id']);
        if (!$playlist instanceof stdClass) throw new PlaylistNotFound('System playlist not found.');

        $rows = Db::table('playlist_import_entries')->where('playlist_id', $playlistId)
            ->whereNull('song_id')->whereIn('status', ['unmatched', 'ambiguous'])
            ->orderBy('position')->limit(self::MAX_ENTRIES)->get([
                'position', 'source_title', 'source_artists_json', 'source_album',
            ])->all();
        $sourceEntries = [];
        $positions = [];
        foreach ($rows as $row) {
            $title = is_string($row->source_title ?? null) ? trim((string) $row->source_title) : '';
            $artists = $this->artists($row->source_artists_json ?? null);
            if ($title === '' || $artists === []) continue;
            $positions[] = (int) $row->position;
            $sourceEntries[] = ['title' => $title, 'artists' => $artists];
        }

        $identities = $this->identityNormalizer->simplify($sourceEntries);
        $proposals = [];
        foreach ($sourceEntries as $index => $source) {
            $identity = is_array($identities[$index] ?? null) ? $identities[$index] : [];
            $titles = [$source['title']];
            if (is_string($identity['title'] ?? null)) $titles[] = $identity['title'];
            $artists = $source['artists'];
            if (is_array($identity['artists'] ?? null)) {
                foreach ($identity['artists'] as $artist) if (is_string($artist)) $artists[] = $artist;
            }
            $candidateIds = $this->media->songIdsByPlaylistIdentity($actor, $titles, $artists);
            $candidateCount = count($candidateIds);
            $proposals[$positions[$index]] = [
                'songId' => $candidateCount === 1 ? $candidateIds[0] : null,
                'candidateCount' => min(100, $candidateCount),
                'status' => $candidateCount === 1 ? 'matched' : ($candidateCount > 1 ? 'ambiguous' : 'unmatched'),
                'reasonCode' => $candidateCount === 1 ? null : ($candidateCount > 1 ? 'multiple_candidates' : 'resource_missing'),
            ];
        }

        $candidateIds = array_values(array_unique(array_filter(
            array_column($proposals, 'songId'),
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
        $authorizedCandidates = $this->media->songsByIds($actor, $candidateIds);
        foreach ($proposals as &$proposal) {
            // 多候选本身是有效检测结果，不应因没有唯一 songId 被改写成 resource_missing；只有已经提出唯一
            // 关联但实时授权复验失败时才回退。这样繁简转换成功但目录存在重复文件时，后台能显示真实歧义，
            // 而不会把“需要选择”伪装成“媒体库没有资源”。
            if (is_string($proposal['songId']) && !isset($authorizedCandidates[$proposal['songId']])) {
                $proposal = ['songId' => null, 'candidateCount' => 0, 'status' => 'unmatched', 'reasonCode' => 'resource_missing'];
            }
        }
        unset($proposal);

        $result = Db::transaction(function () use ($actor, $playlist, $playlistId, $proposals): array {
            /** @var stdClass|null $current */
            $current = Db::table('playlists')->where('id', $playlistId)->where('scope', 'system')
                ->first(['version', 'owner_user_id']);
            if (!$current instanceof stdClass || (int) $current->version !== (int) $playlist->version) {
                throw new PlaylistConflict('System playlist changed during resource detection.');
            }

            foreach ($proposals as $position => $proposal) {
                Db::table('playlist_import_entries')->where('playlist_id', $playlistId)->where('position', $position)
                    ->whereNull('song_id')->update([
                        'status' => $proposal['status'], 'song_id' => $proposal['songId'],
                        'candidate_count' => $proposal['candidateCount'], 'reason_code' => $proposal['reasonCode'],
                    ]);
            }

            $entries = Db::table('playlist_import_entries')->where('playlist_id', $playlistId)
                ->orderBy('position')->get(['position', 'song_id', 'status', 'candidate_count', 'reason_code'])->all();
            $allSongIds = array_values(array_unique(array_filter(array_map(
                static fn (stdClass $entry): ?string => is_string($entry->song_id ?? null) && $entry->song_id !== '' ? (string) $entry->song_id : null,
                $entries,
            ))));
            $songs = $this->media->songsByIds($actor, $allSongIds);
            $items = [];
            $durationMs = 0;
            foreach ($entries as $entry) {
                $songId = is_string($entry->song_id ?? null) ? (string) $entry->song_id : '';
                if ($songId === '' || !isset($songs[$songId])) continue;
                $items[] = [
                    'playlist_id' => $playlistId, 'position' => count($items), 'song_id' => $songId,
                    'added_by_user_id' => (string) $current->owner_user_id,
                    'added_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ];
                $durationMs += (int) ($songs[$songId]['durationMs'] ?? 0);
            }
            Db::table('playlist_items')->where('playlist_id', $playlistId)->delete();
            if ($items !== []) Db::table('playlist_items')->insert($items);
            $nextVersion = (int) $current->version + 1;
            if (Db::table('playlists')->where('id', $playlistId)->where('version', (int) $current->version)->update([
                'song_count' => count($items), 'duration_ms' => $durationMs,
                'version' => $nextVersion, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]) !== 1) throw new PlaylistConflict('System playlist changed during resource detection.');

            $matchedCount = count($items);
            $totalCount = count($entries);
            return [
                'detectedCount' => count(array_filter($proposals, static fn (array $proposal): bool => $proposal['songId'] !== null)),
                'matchedCount' => $matchedCount, 'missingCount' => $totalCount - $matchedCount,
                'totalCount' => $totalCount, 'playlistVersion' => $nextVersion,
            ];
        });
        $this->audit->record((string) ($actor['id'] ?? ''), 'system_playlist.resource.detect', 'playlist', $playlistId,
            'success', $requestId, ['detectedCount' => $result['detectedCount'], 'matchedCount' => $result['matchedCount']]);
        return $result;
    }

    /** @return list<string> 只接受导入时保存的字符串数组，损坏证据按无艺人处理。 */
    private function artists(mixed $value): array
    {
        if (!is_string($value) || $value === '') return [];
        try { $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR); } catch (Throwable) { return []; }
        return is_array($decoded) && array_is_list($decoded)
            ? array_values(array_filter($decoded, static fn (mixed $artist): bool => is_string($artist) && trim($artist) !== '')) : [];
    }
}
