<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\application\Artist\ArtistNameIdentityNormalizer;
use app\application\Media\MediaQueryService;
use app\application\Search\SearchTextNormalizer;
use app\http\RequestContext;
use support\Db;

/**
 * 编排平台歌单文件与公开链接导入，并把条目转换为与 M3U 相同的本地授权结果。
 *
 * 文件适配器只解析离线 JSON，公开链接由活动资源插件解析；本服务按标题、艺人、可选专辑和时长
 * 在当前账号可播放目录中匹配，绝不把平台私有 ID 写入业务歌曲表。匹配结果、未匹配状态、歌单头、
 * 幂等记录和审计由 PlaylistService 在一个短事务内提交；失败时不残留歌单或部分条目。
 */
final readonly class PlatformPlaylistImportService
{
    private const MAX_DURATION_DELTA_MS = 3_000;

    /**
     * @param list<PlaylistPlatformAdapter>|null $adapters
     */
    public function __construct(
        private ?array $adapters = null,
        private MediaQueryService $media = new MediaQueryService(),
        private PlaylistService $playlists = new PlaylistService(),
        private ?PluginPlaylistIdentificationGateway $playlistIdentifier = null,
        private ArtistNameIdentityNormalizer $artistNames = new ArtistNameIdentityNormalizer(),
    ) {
    }

    /**
     * 解析并创建一个平台歌单；导入条目始终按原顺序保留。
     *
     * `$format` 只能是适配器目录中的固定键。幂等键只保存 HMAC 摘要，请求正文和平台 ID 不进入
     * 数据库；同一用户重复提交相同键和内容会返回原歌单，改换内容会返回冲突。
     *
     * @param array{name: string, description: string|null, visibility: string} $metadata
     * @return array{playlist: array<string, mixed>, report: array<string, mixed>, replayed: bool}
     * @throws PlaylistImportInvalid 格式、JSON 或平台字段不受支持。
     */
    public function import(
        array $actor,
        string $format,
        string $bytes,
        array $metadata,
        string $idempotencyKey,
        string $requestId,
    ): array {
        $this->validateIdempotencyKey($idempotencyKey);
        $document = $this->adapter($format)->parse($bytes);
        return $this->importDocument($actor, $document, $metadata, $idempotencyKey, $requestId,
            hash('sha256', json_encode([
                'format' => $document->format,
                'contentDigest' => hash('sha256', $bytes),
                'metadata' => $metadata,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }

    /**
     * 解析公开链接后导入歌单。查询完成前不写入幂等记录，链接原文也不进入数据库或审计。
     * 只有平台解析器返回的脱敏文档会进入与文件导入相同的匹配、未匹配保留和事务提交路径。
     */
    public function importLink(
        array $actor,
        string $source,
        string $link,
        array $metadata,
        string $idempotencyKey,
        string $requestId,
        string $scope = 'user',
    ): array {
        $this->validateIdempotencyKey($idempotencyKey);
        $document = ($this->playlistIdentifier ?? new PluginPlaylistIdentificationGateway())->identify($source, $link);
        $normalizedLink = trim($link);
        $requestDigest = hash('sha256', "playlist-link-v1\0" . $document->format . "\0" . $normalizedLink . "\0" . json_encode(
            ['metadata' => $metadata, 'scope' => $scope],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        return $this->importDocument($actor, $document, $metadata, $idempotencyKey, $requestId, $requestDigest, $scope);
    }

    /** 将统一文档匹配并提交，供文件和链接两种入口共享原子事务与未匹配状态。 */
    public function importDocument(
        array $actor,
        PlatformPlaylistDocument $document,
        array $metadata,
        string $idempotencyKey,
        string $requestId,
        string $requestDigest,
        string $scope = 'user',
    ): array {
        $this->validateIdempotencyKey($idempotencyKey);
        [$songIds, $entries, $report] = $this->match($actor, $document->entries);
        $keyDigest = hash_hmac(
            'sha256',
            "velin-platform-playlist-import-idempotency-v1\0" . $idempotencyKey,
            RequestContext::authenticationHashKey(),
        );
        return $this->playlists->createImported(
            $actor,
            $metadata,
            $songIds,
            $entries,
            $report,
            $keyDigest,
            $requestDigest,
            $requestId,
            $scope,
        );
    }

    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 200
            || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw new PlaylistImportInvalid('invalid_idempotency_key');
        }
    }

    /** @return list<PlaylistPlatformAdapter> */
    private function adapterList(): array
    {
        return $this->adapters ?? [new NeteasePlaylistAdapter(), new QQMusicPlaylistAdapter()];
    }

    private function adapter(string $format): PlaylistPlatformAdapter
    {
        foreach ($this->adapterList() as $adapter) {
            if ($adapter->format() === $format) {
                return $adapter;
            }
        }
        throw new PlaylistImportInvalid('unsupported_platform_format');
    }

    /**
     * 将平台条目匹配到当前账号的授权目录。
     *
     * 查询只使用已授权、活动库、可用文件和 ready 元数据；相同最佳评分的候选保持 ambiguous，绝不
     * 依赖数据库行顺序。平台标题中没有足够身份信息时记录 unsupported，单条失败不会中断整份导入。
     *
     * @param list<PlatformPlaylistEntry> $sourceEntries
     * @return array{list<string>, list<array<string, mixed>>, array<string, mixed>}
     */
    private function match(array $actor, array $sourceEntries): array
    {
        $normalizer = new SearchTextNormalizer();
        $songIds = [];
        $importEntries = [];
        $reportEntries = [];
        $counts = ['matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'unsupported' => 0];
        foreach ($sourceEntries as $position => $entry) {
            $source = [
                'sourceTitle' => $entry->title !== '' ? $entry->title : null,
                'sourceArtists' => $entry->artists,
                'sourceAlbum' => $entry->album,
            ];
            $artists = [];
            foreach ($entry->artists as $artist) $artists = [...$artists, ...$this->artistNames->lookupKeys($artist)];
            $artists = array_values(array_unique($artists));
            if ($entry->title === '' || $artists === []) {
                $counts['unsupported']++;
                $reportEntries[] = ['ordinal' => $position + 1, 'status' => 'unsupported'];
                $importEntries[] = $source + ['position' => $position, 'status' => 'unsupported', 'reasonCode' => 'missing_identity'];
                continue;
            }
            $candidates = $this->candidates($actor, $normalizer->normalize($entry->title), $artists);
            $ranked = $this->rank($candidates, $entry, $normalizer);
            if ($ranked === []) {
                $counts['unmatched']++;
                $reportEntries[] = ['ordinal' => $position + 1, 'status' => 'unmatched'];
                $importEntries[] = $source + ['position' => $position, 'status' => 'unmatched', 'reasonCode' => 'no_local_candidate'];
                continue;
            }
            $bestScore = $ranked[0]['score'];
            $best = array_values(array_filter($ranked, static fn (array $candidate): bool => $candidate['score'] === $bestScore));
            if (count($best) !== 1) {
                $counts['ambiguous']++;
                $candidateCount = min(100, count($best));
                $reportEntries[] = ['ordinal' => $position + 1, 'status' => 'ambiguous', 'candidateCount' => $candidateCount];
                $importEntries[] = $source + ['position' => $position, 'status' => 'ambiguous', 'candidateCount' => $candidateCount, 'reasonCode' => 'multiple_candidates'];
                continue;
            }
            $song = $this->media->songsByIds($actor, [(string) $best[0]['id']])[(string) $best[0]['id']] ?? null;
            if (!is_array($song)) {
                $counts['unmatched']++;
                $reportEntries[] = ['ordinal' => $position + 1, 'status' => 'unmatched'];
                $importEntries[] = $source + ['position' => $position, 'status' => 'unmatched', 'reasonCode' => 'song_unavailable'];
                continue;
            }
            $songId = (string) $best[0]['id'];
            $songIds[] = $songId;
            $counts['matched']++;
            $reportEntries[] = ['ordinal' => $position + 1, 'status' => 'matched', 'song' => $song];
            $importEntries[] = $source + ['position' => $position, 'status' => 'matched', 'songId' => $songId];
        }

        return [$songIds, $importEntries, [
            'summary' => ['total' => count($sourceEntries)] + $counts,
            'entries' => $reportEntries,
        ]];
    }

    /** @return list<array{id: string, album: ?string, duration: int}> */
    private function candidates(array $actor, string $normalizedTitle, array $artists): array
    {
        $query = Db::table('media_songs as songs')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->join('media_song_artists as song_artists', 'song_artists.song_id', '=', 'songs.id')
            ->join('media_artists as artists', 'artists.id', '=', 'song_artists.artist_id')
            ->where('songs.normalized_title', $normalizedTitle)
            ->whereIn('artists.normalized_name', $artists)
            ->where('libraries.status', 'active')
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as grants', function ($join) use ($actor): void {
                $join->on('grants.library_id', '=', 'songs.library_id')->where('grants.user_id', '=', (string) $actor['id']);
            });
        }
        /** @var list<object> $rows */
        $rows = $query->distinct()->orderBy('songs.id')->get([
            'songs.id', 'albums.normalized_title as album_normalized', 'songs.duration_ms',
        ])->all();

        return array_map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'album' => is_string($row->album_normalized ?? null) ? (string) $row->album_normalized : null,
            'duration' => (int) $row->duration_ms,
        ], $rows);
    }

    /** @param list<array{id: string, album: ?string, duration: int}> $candidates */
    private function rank(array $candidates, PlatformPlaylistEntry $entry, SearchTextNormalizer $normalizer): array
    {
        $album = $entry->album === null ? null : $normalizer->normalize($entry->album);
        $ranked = [];
        foreach ($candidates as $candidate) {
            $score = 1;
            if ($album !== null && $candidate['album'] === $album) {
                $score += 2;
            }
            if ($entry->durationMs !== null && abs($candidate['duration'] - $entry->durationMs) <= self::MAX_DURATION_DELTA_MS) {
                $score++;
            }
            $candidate['score'] = $score;
            $ranked[] = $candidate;
        }
        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: strcmp($left['id'], $right['id']));

        return $ranked;
    }
}
