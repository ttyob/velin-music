<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\ResourcePlugin\Contract\AlbumScrapeCompletionRequest;
use app\application\ResourcePlugin\Contract\AlbumScrapeCompletionResult;
use app\application\ResourcePlugin\Contract\ExternalMetadataScrapePluginRegistry;
use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use Symfony\Component\Uid\Ulid;
use stdClass;
use support\Db;
use support\Log;
use Throwable;

/**
 * 管理按专辑唯一的元数据刮削任务，并在独立插件 Worker 中应用最终专辑结论。
 *
 * 歌曲刮削只登记专辑 ID；所有网络请求都在本服务的事务外执行，专辑字段由实体状态仓储按
 * manual > raw > scraped 规则物化。任务拥有自己的租约、重试和冷却，不会阻塞或回滚歌曲任务。
 */
final readonly class AlbumMetadataScrapeJobService
{
    private const FRESH_SECONDS = 7_776_000;
    private const FAILED_COOLDOWN_SECONDS = 2_592_000;
    private const STALE_LEASE_SECONDS = 300;
    private const MAX_ATTEMPTS = 4;

    public function __construct(
        private EntityMetadataStateRepository $states = new EntityMetadataStateRepository(),
        private ExternalMetadataScrapePluginRegistry $plugins = new PhpResourcePluginRegistry(),
        private AlbumMetadataScrapePolicy $policy = new AlbumMetadataScrapePolicy(),
        private AlbumScrapeArtworkCache $artworkCache = new AlbumScrapeArtworkCache(),
    ) {
    }

    /** 歌曲成功提交后为所属专辑幂等登记任务；本方法不访问网络，也不改变歌曲终态。 */
    public function enqueueForSong(string $songId): int
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('album_scrape_jobs')) return 0;
        $albumId = Db::table('media_songs')->where('id', $songId)->value('album_id');
        return is_string($albumId) && $albumId !== '' && $this->enqueue($albumId) ? 1 : 0;
    }

    /** 为已有可播放专辑补登记任务；重复扫描由唯一 album_id 和新鲜度判断收敛。 */
    public function enqueueExisting(int $limit = 25): int
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('album_scrape_jobs')) return 0;
        $limit = max(1, min(100, $limit));
        $fresh = gmdate('Y-m-d\TH:i:s\Z', time() - self::FRESH_SECONDS);
        $cooldown = gmdate('Y-m-d\TH:i:s\Z', time() - self::FAILED_COOLDOWN_SECONDS);
        $ids = Db::table('media_albums as albums')->whereExists(function ($songs): void {
            $songs->selectRaw('1')->from('media_songs as songs')
                ->join('library_file_inventory as inventory', 'inventory.id', '=', 'songs.inventory_file_id')
                ->whereColumn('songs.album_id', 'albums.id')->where('inventory.status', 'available')
                ->where('inventory.metadata_status', 'ready');
        })->where(function ($eligible) use ($fresh, $cooldown): void {
            $eligible->whereNotExists(function ($jobs) use ($fresh): void {
                $jobs->selectRaw('1')->from('album_scrape_jobs as jobs')
                    ->whereColumn('jobs.album_id', 'albums.id')->where('jobs.status', 'succeeded')
                    ->where('jobs.updated_at', '>=', $fresh);
            })->where(function ($state) use ($cooldown, $fresh): void {
                $state->whereNotExists(function ($jobs): void {
                    $jobs->selectRaw('1')->from('album_scrape_jobs as jobs')->whereColumn('jobs.album_id', 'albums.id');
                })->orWhereExists(function ($jobs) use ($cooldown): void {
                    $jobs->selectRaw('1')->from('album_scrape_jobs as jobs')->whereColumn('jobs.album_id', 'albums.id')
                        ->where('jobs.status', 'failed')->where('jobs.updated_at', '<=', $cooldown);
                })->orWhereExists(function ($jobs) use ($fresh): void {
                    $jobs->selectRaw('1')->from('album_scrape_jobs as jobs')->whereColumn('jobs.album_id', 'albums.id')
                        ->where('jobs.status', 'succeeded')->where('jobs.updated_at', '<', $fresh);
                });
            });
        })->orderBy('albums.id')->limit($limit)->pluck('albums.id')->map('strval')->all();
        $created = 0;
        foreach ($ids as $id) if ($this->enqueue($id)) ++$created;
        return $created;
    }

    /**
     * 根据专辑业务完整度创建或按冷却规则恢复唯一任务。
     *
     * 入参必须是现有专辑 ULID。完整度策略先排除占位专辑及资料完整实体，任务表随后只处理 queued/running
     * 去重、成功新鲜期和失败冷却；方法不访问插件或媒体文件。新建与重排均以 album_id 唯一键收敛，
     * 返回 false 表示无需查询或当前调度状态禁止重复入队，不改变既有资料。
     */
    public function enqueue(string $albumId): bool
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $albumId) !== 1
            || !Db::table('media_albums')->where('id', $albumId)->exists()
            || !Db::connection()->getSchemaBuilder()->hasTable('album_scrape_jobs')) return false;
        if (!$this->policy->shouldQuery($albumId)) return false;
        $fresh = gmdate('Y-m-d\TH:i:s\Z', time() - self::FRESH_SECONDS);
        if (Db::table('album_scrape_jobs')->where('album_id', $albumId)->where('status', 'succeeded')
            ->where('updated_at', '>=', $fresh)->exists()) return false;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        /** @var stdClass|null $existing */
        $existing = Db::table('album_scrape_jobs')->where('album_id', $albumId)->first();
        if ($existing instanceof stdClass) {
            if (in_array((string) $existing->status, ['queued', 'running'], true)) return false;
            if ((string) $existing->status === 'failed'
                && (string) $existing->updated_at > gmdate('Y-m-d\TH:i:s\Z', time() - self::FAILED_COOLDOWN_SECONDS)) return false;
            return Db::table('album_scrape_jobs')->where('id', (string) $existing->id)
                ->whereIn('status', ['failed', 'succeeded'])->update([
                    'status' => 'queued', 'attempt' => 0, 'next_attempt_at' => $now,
                    'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null, 'requested_at' => $now, 'updated_at' => $now,
                ]) === 1;
        }
        Db::table('album_scrape_jobs')->insert([
            'id' => (string) new Ulid(), 'album_id' => $albumId, 'status' => 'queued', 'attempt' => 0,
            'next_attempt_at' => $now, 'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null,
            'requested_at' => $now, 'updated_at' => $now,
        ]);
        return true;
    }

    /** 原子领取一条到期任务。 */
    public function claimNext(string $workerId): ?array
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('album_scrape_jobs')) return null;
        return Db::transaction(function () use ($workerId): ?array {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            /** @var stdClass|null $row */
            $row = Db::table('album_scrape_jobs')->where('status', 'queued')->where('next_attempt_at', '<=', $now)
                ->orderBy('requested_at')->orderBy('id')->first(['id']);
            if (!$row instanceof stdClass) return null;
            $changed = Db::table('album_scrape_jobs')->where('id', (string) $row->id)->where('status', 'queued')
                ->whereNull('worker_id')->update([
                    'status' => 'running', 'worker_id' => $workerId, 'heartbeat_at' => $now,
                    'error_code' => null, 'updated_at' => $now,
                ]);
            return $changed === 1 ? ['id' => (string) $row->id] : null;
        });
    }

    /** 执行插件最终专辑结论；网络阶段不在数据库事务中，提交阶段使用任务租约 CAS。 */
    public function execute(array $job, string $workerId): void
    {
        /** @var stdClass|null $row */
        $row = Db::table('album_scrape_jobs as jobs')->join('media_albums as albums', 'albums.id', '=', 'jobs.album_id')
            ->where('jobs.id', (string) ($job['id'] ?? ''))->where('jobs.status', 'running')->where('jobs.worker_id', $workerId)
            ->first(['jobs.id', 'jobs.album_id', 'jobs.attempt', 'albums.title', 'albums.musicbrainz_release_id', 'albums.musicbrainz_release_group_id']);
        if (!$row instanceof stdClass) return;
        try {
            $artists = Db::table('media_album_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
                ->where('links.album_id', (string) $row->album_id)->orderBy('links.position')->pluck('artists.name')->map('strval')->all();
            $identityDigest = $this->identityDigest(
                (string) $row->title,
                $artists ?: ['未知艺术家'],
                $row->musicbrainz_release_id === null ? null : (string) $row->musicbrainz_release_id,
                $row->musicbrainz_release_group_id === null ? null : (string) $row->musicbrainz_release_group_id,
            );
            $plugin = $this->plugins->metadataAlbum('metadata-scrape');
            $result = $plugin->scrapeAlbum(new AlbumScrapeCompletionRequest(
                (string) $row->title, $artists ?: ['未知艺术家'],
                $row->musicbrainz_release_id === null ? null : (string) $row->musicbrainz_release_id,
                $row->musicbrainz_release_group_id === null ? null : (string) $row->musicbrainz_release_group_id,
            ));
            if ($result->status === AlbumScrapeCompletionResult::UNMATCHED) {
                $this->fail($row, $workerId, 'ALBUM_SCRAPE_UNMATCHED', false);
                return;
            }
            if ($result->status !== AlbumScrapeCompletionResult::MATCHED || !is_array($result->metadata)) {
                $this->fail($row, $workerId, 'ALBUM_SCRAPE_PLUGIN_UNAVAILABLE', true);
                return;
            }
            Db::transaction(function () use ($row, $workerId, $result, $identityDigest, $artists): void {
                /** @var stdClass|null $current */
                $current = Db::table('media_albums')->where('id', (string) $row->album_id)
                    ->first(['title', 'musicbrainz_release_id', 'musicbrainz_release_group_id']);
                if (!$current instanceof stdClass) {
                    throw new AlbumMetadataTaskFailure('ALBUM_SCRAPE_IDENTITY_STALE', false);
                }
                $artists = Db::table('media_album_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
                    ->where('links.album_id', (string) $row->album_id)->orderBy('links.position')
                    ->pluck('artists.name')->map('strval')->all();
                $currentDigest = $this->identityDigest(
                    (string) $current->title,
                    $artists ?: ['未知艺术家'],
                    $current->musicbrainz_release_id === null ? null : (string) $current->musicbrainz_release_id,
                    $current->musicbrainz_release_group_id === null ? null : (string) $current->musicbrainz_release_group_id,
                );
                if (!hash_equals($identityDigest, $currentDigest)) {
                    throw new AlbumMetadataTaskFailure('ALBUM_SCRAPE_IDENTITY_STALE', false);
                }
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $this->states->applyScrapedCandidate('album', (string) $row->album_id, $result->metadata, $now);
                $cacheTitle = (string) (Db::table('media_albums')->where('id', (string) $row->album_id)->value('title') ?? $current->title);
                $cacheArtists = Db::table('media_album_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
                    ->where('links.album_id', (string) $row->album_id)->orderBy('links.position')->pluck('artists.name')->map('strval')->all();
                $this->artworkCache->put(
                    (string) $row->album_id, $cacheTitle, $cacheArtists ?: $artists,
                    $result->artworkUrl, $now,
                );
                $changed = Db::table('album_scrape_jobs')->where('id', (string) $row->id)->where('status', 'running')
                    ->where('worker_id', $workerId)->update([
                        'status' => 'succeeded', 'attempt' => 0, 'worker_id' => null, 'heartbeat_at' => null,
                        'error_code' => null, 'next_attempt_at' => $now, 'updated_at' => $now,
                    ]);
                if ($changed !== 1) throw new \RuntimeException('ALBUM_SCRAPE_LEASE_LOST');
            });
        } catch (AlbumMetadataTaskFailure $failure) {
            $this->fail($row, $workerId, $failure->reasonCode, $failure->retryable);
        } catch (Throwable $failure) {
            $this->fail($row, $workerId, 'ALBUM_SCRAPE_PLUGIN_FAILED', true);
        }
    }

    /** 恢复超时租约；网络请求无数据库副作用，重新执行是安全的。 */
    public function recoverStaleLeases(): int
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('album_scrape_jobs')) return 0;
        return Db::table('album_scrape_jobs')->where('status', 'running')->where('heartbeat_at', '<=',
            gmdate('Y-m-d\TH:i:s\Z', time() - self::STALE_LEASE_SECONDS))->update([
                'status' => 'queued', 'worker_id' => null, 'heartbeat_at' => null,
                'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z'), 'error_code' => 'ALBUM_SCRAPE_LEASE_RECOVERED',
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
    }

    /** @param stdClass $row */
    private function fail(stdClass $row, string $workerId, string $reason, bool $retryable): void
    {
        $attempt = (int) $row->attempt + 1;
        $terminal = !$retryable || $attempt >= self::MAX_ATTEMPTS;
        $delay = [60, 300, 1800, self::FAILED_COOLDOWN_SECONDS][min($attempt - 1, 3)];
        Db::table('album_scrape_jobs')->where('id', (string) $row->id)->where('status', 'running')->where('worker_id', $workerId)
            ->update([
                'status' => $terminal ? 'failed' : 'queued', 'attempt' => min($attempt, self::MAX_ATTEMPTS),
                'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $delay), 'worker_id' => null, 'heartbeat_at' => null,
                'error_code' => $reason, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        if ($terminal) Log::warning('Album scrape entered terminal failure.', ['reason_code' => $reason]);
    }

    /**
     * 生成专辑网络请求前后共用的身份摘要。
     *
     * 标题、艺人顺序和已有发行身份共同决定一次结果的适用范围；摘要只留在进程内，不写入任务表或插件
     * 响应。数组键固定且 JSON 编码稳定，避免并发事务因字段顺序不同产生假冲突。
     *
     * @param list<string> $artists
     */
    private function identityDigest(string $title, array $artists, ?string $releaseId, ?string $releaseGroupId): string
    {
        return hash('sha256', json_encode([
            'title' => $title,
            'artists' => array_values($artists),
            'musicbrainzReleaseId' => $releaseId,
            'musicbrainzReleaseGroupId' => $releaseGroupId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
