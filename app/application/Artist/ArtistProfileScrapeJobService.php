<?php

declare(strict_types=1);

namespace app\application\Artist;

use app\infrastructure\Audit\AuditLogger;
use app\application\ResourcePlugin\Contract\ArtistProfileScrapeRequest;
use app\application\ResourcePlugin\Contract\ArtistProfileScrapeResult;
use app\application\ResourcePlugin\Contract\ExternalMetadataScrapePluginRegistry;
use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use stdClass;
use support\Db;
use support\Log;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 管理按艺人唯一的资料补全任务，并在独立 Worker 中调用艺人资料插件。
 *
 * 歌曲刮削只调用 enqueueForSong 写入轻量意图；第三方资料请求全部委托给已安装的艺人资料插件，并位于
 * SQLite 事务之外。本地辅助库只作为优先身份提示；缺失或无唯一结果时由插件执行 MusicBrainz 名称搜索。
 * 插件停用或协议不兼容不会回退核心远程实现，而是进入任务自己的重试/冷却状态。
 * 同一艺人已有 90 天内成功资料时不入队，活动任务不会被后续歌曲
 * 重置；最终失败 30 天后才允许新的歌曲刮削重新排队，防止缺失身份或上游拒绝形成请求放大。
 */
final readonly class ArtistProfileScrapeJobService
{
    private const FAILED_COOLDOWN_SECONDS = 2_592_000;
    private const STALE_LEASE_SECONDS = 300;
    private const MAX_ATTEMPTS = 4;

    public function __construct(
        private ArtistProfileIdentityResolver $identities = new ArtistDatabaseProfileIdentityResolver(),
        private ExternalMetadataScrapePluginRegistry $plugins = new PhpResourcePluginRegistry(),
        private AuditLogger $audit = new AuditLogger(),
        private ArtistProfileScrapePolicy $policy = new ArtistProfileScrapePolicy(),
    ) {
    }

    /**
     * 为一首已成功物化艺人关系的歌曲幂等入队。
     *
     * 调用方应在歌曲核心事务提交后执行；这里重新读取关系，因而封面/资料都以最终艺人实体为准。方法
     * 不访问辅助库或网络，表尚未迁移时安全跳过以兼容滚动部署。已有新鲜资料、queued/running 或仍在
     * 失败冷却期的任务保持不变；单个入队失败不能改变歌曲目标终态。
     */
    public function enqueueForSong(string $songId): int
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('artist_profiles') || !$schema->hasTable('artist_profile_scrape_jobs')) return 0;
        /** @var list<string> $artistIds */
        $artistIds = Db::table('media_song_artists')->where('song_id', $songId)->orderBy('position')
            ->pluck('artist_id')->map('strval')->unique()->values()->all();
        $created = 0;
        foreach ($artistIds as $artistId) {
            if ($this->enqueue($artistId)) ++$created;
        }
        return $created;
    }

    /**
     * 为升级前已有且至少关联一首可用歌曲的艺人有界补登记。
     *
     * 这里只扫描稳定 ID 并复用 enqueue 的新鲜度、活动任务与冷却判断，不读取辅助库、不访问网络；每次
     * Worker tick 默认检查 25 个且生产 Worker 只传 1，避免升级后快速堆积大批写入。游标不是持久进度，重启后重复扫描
     * 仍由唯一键保持幂等，已成功或冷却中的艺人不会被重置。
     */
    public function enqueueExisting(int $limit = 25): int
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('artist_profiles') || !$schema->hasTable('artist_profile_scrape_jobs')) return 0;
        $limit = max(1, min(100, $limit));
        /** @var list<string> $artistIds */
        $cooldown = gmdate('Y-m-d\TH:i:s\Z', time() - self::FAILED_COOLDOWN_SECONDS);
        $artistIds = Db::table('media_artists as artists')
            ->whereExists(function ($songs): void {
                $songs->selectRaw('1')->from('media_song_artists as links')
                    ->join('media_songs as songs', 'songs.id', '=', 'links.song_id')
                    ->join('library_file_inventory as inventory', 'inventory.id', '=', 'songs.inventory_file_id')
                    ->whereColumn('links.artist_id', 'artists.id')
                    ->where('inventory.status', 'available')->where('inventory.metadata_status', 'ready');
            })->where(function ($eligible) use ($cooldown): void {
                $eligible->whereNotExists(function ($jobs): void {
                    $jobs->selectRaw('1')->from('artist_profile_scrape_jobs as profile_jobs')
                        ->whereColumn('profile_jobs.artist_id', 'artists.id');
                })->orWhereExists(function ($jobs) use ($cooldown): void {
                    $jobs->selectRaw('1')->from('artist_profile_scrape_jobs as retryable_profile_jobs')
                        ->whereColumn('retryable_profile_jobs.artist_id', 'artists.id')
                        ->where('retryable_profile_jobs.status', 'failed')
                        ->where('retryable_profile_jobs.updated_at', '<=', $cooldown);
                });
            })->orderBy('artists.id')->limit(min(100, max($limit, $limit * 10)))->pluck('artists.id')->map('strval')->all();
        $created = 0;
        foreach ($artistIds as $artistId) {
            if ($this->enqueue($artistId)) {
                ++$created;
                if ($created >= $limit) break;
            }
        }
        return $created;
    }

    /**
     * 强制为全部当前可播放艺人重新登记资料任务，同时保留旧资料作为刷新失败时的回退。
     *
     * 调用者必须已经通过全局管理权限校验并提供审计身份。候选仅来自活动音乐库中 available/ready 的
     * 歌曲署名，孤立词表和不可播放媒体不会制造外部请求。方法按稳定艺人 ID 每 250 个提交一个短事务：
     * queued/failed 任务重置为立即执行，running 租约保持不动，缺失任务用唯一 artist_id 幂等插入。
     * 资料行不删除，因此命令中断或上游失败不会让当前简介消失；重复执行会收敛到同一组任务。批次间
     * 崩溃可能只登记前半部分，操作者可用同一命令安全重试，已登记任务不会重复创建。
     *
     * @return array{eligible:int,created:int,requeued:int,running:int}
     */
    public function forceRefreshAll(string $actorUserId, string $requestId): array
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('artist_profiles') || !$schema->hasTable('artist_profile_scrape_jobs')) {
            throw new ArtistProfileTaskFailure('ARTIST_PROFILE_SCHEMA_UNAVAILABLE', false);
        }
        $counts = ['eligible' => 0, 'created' => 0, 'requeued' => 0, 'running' => 0];
        $cursor = '';
        do {
            /** @var list<string> $artistIds */
            $artistIds = Db::table('media_artists as artists')
                ->where('artists.id', '>', $cursor)
                ->whereExists(function ($songs): void {
                    $songs->selectRaw('1')->from('media_song_artists as links')
                        ->join('media_songs as songs', 'songs.id', '=', 'links.song_id')
                        ->join('library_file_inventory as inventory', 'inventory.id', '=', 'songs.inventory_file_id')
                        ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
                        ->whereColumn('links.artist_id', 'artists.id')
                        ->where('libraries.status', 'active')
                        ->where('inventory.status', 'available')->where('inventory.metadata_status', 'ready');
                })->orderBy('artists.id')->limit(250)->pluck('artists.id')->map('strval')->all();
            if ($artistIds === []) break;
            $cursor = $artistIds[array_key_last($artistIds)];
            $batch = Db::transaction(function () use ($artistIds): array {
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $batchCounts = ['created' => 0, 'requeued' => 0, 'running' => 0];
                foreach ($artistIds as $artistId) {
                    /** @var stdClass|null $existing */
                    $existing = Db::table('artist_profile_scrape_jobs')->where('artist_id', $artistId)->first();
                    if ($existing instanceof stdClass && (string) $existing->status === 'running') {
                        ++$batchCounts['running'];
                        continue;
                    }
                    if ($existing instanceof stdClass) {
                        $changed = Db::table('artist_profile_scrape_jobs')->where('id', (string) $existing->id)
                            ->whereIn('status', ['queued', 'failed'])->update([
                                'status' => 'queued', 'attempt' => 0, 'next_attempt_at' => $now,
                                'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null,
                                'requested_at' => $now, 'updated_at' => $now,
                            ]);
                        $batchCounts['requeued'] += $changed;
                        continue;
                    }
                    $batchCounts['created'] += Db::table('artist_profile_scrape_jobs')->insertOrIgnore([
                        'id' => (string) new Ulid(), 'artist_id' => $artistId, 'status' => 'queued', 'attempt' => 0,
                        'next_attempt_at' => $now, 'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null,
                        'requested_at' => $now, 'updated_at' => $now,
                    ]);
                }
                return $batchCounts;
            });
            $counts['eligible'] += count($artistIds);
            $counts['created'] += $batch['created'];
            $counts['requeued'] += $batch['requeued'];
            $counts['running'] += $batch['running'];
        } while (count($artistIds) === 250);

        $this->audit->record($actorUserId, 'artist.profile.refresh_all', 'artist_profile', null,
            'success', $requestId, $counts);
        return $counts;
    }

    /**
     * 根据艺人资料完整度为单个稳定艺人 ID 创建或按冷却规则恢复唯一任务。
     *
     * 完整资料即使没有任务历史也直接跳过；缺失、损坏或过期资料才进入任务状态判断。queued/running
     * 保持原租约，终态失败在 30 天冷却后才可 CAS 重排。方法不访问辅助库、插件或网络，新建和重排只
     * 修改任务投影，不删除旧资料；唯一 artist_id 保证多首歌曲并发登记仍然幂等。
     */
    public function enqueue(string $artistId): bool
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $artistId) !== 1
            || !Db::table('media_artists')->where('id', $artistId)->exists()) return false;
        if (!$this->policy->shouldQuery($artistId)) return false;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        /** @var stdClass|null $existing */
        $existing = Db::table('artist_profile_scrape_jobs')->where('artist_id', $artistId)->first();
        if ($existing instanceof stdClass) {
            if (in_array((string) $existing->status, ['queued', 'running'], true)) return false;
            $cooldown = gmdate('Y-m-d\TH:i:s\Z', time() - self::FAILED_COOLDOWN_SECONDS);
            if ((string) $existing->updated_at > $cooldown) return false;
            return Db::table('artist_profile_scrape_jobs')->where('id', (string) $existing->id)
                ->where('status', 'failed')->where('updated_at', (string) $existing->updated_at)->update([
                    'status' => 'queued', 'attempt' => 0, 'next_attempt_at' => $now,
                    'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null,
                    'requested_at' => $now, 'updated_at' => $now,
                ]) === 1;
        }
        Db::table('artist_profile_scrape_jobs')->insert([
            'id' => (string) new Ulid(), 'artist_id' => $artistId, 'status' => 'queued', 'attempt' => 0,
            'next_attempt_at' => $now, 'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null,
            'requested_at' => $now, 'updated_at' => $now,
        ]);
        return true;
    }

    /** 原子领取一条到期任务；返回不透明任务 ID，避免进程日志携带艺人身份。 */
    public function claimNext(string $workerId): ?array
    {
        return Db::transaction(function () use ($workerId): ?array {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            /** @var stdClass|null $row */
            $row = Db::table('artist_profile_scrape_jobs')->where('status', 'queued')
                ->where('next_attempt_at', '<=', $now)->orderBy('requested_at')->orderBy('id')->first(['id']);
            if (!$row instanceof stdClass) return null;
            $changed = Db::table('artist_profile_scrape_jobs')->where('id', (string) $row->id)
                ->where('status', 'queued')->whereNull('worker_id')->update([
                    'status' => 'running', 'worker_id' => $workerId, 'heartbeat_at' => $now,
                    'error_code' => null, 'updated_at' => $now,
                ]);
            return $changed === 1 ? ['id' => (string) $row->id] : null;
        });
    }

    /**
     * 执行已领取任务并以 CAS 发布资料。
     *
     * 本地艺人名在请求前冻结，插件在事务外完成第三方身份确认与资料聚合；若提交时艺人已改名且原先
     * 没有 MBID，则丢弃结果并重新排队，避免旧名称身份污染新实体。成功资料整体 upsert 后才删除任务；
     * 事务失败不会留下半份资料。插件不可用按可重试故障退避，身份歧义等稳定失败直接进入 30 天冷却。
     */
    public function execute(array $job, string $workerId): void
    {
        /** @var stdClass|null $row */
        $row = Db::table('artist_profile_scrape_jobs as jobs')
            ->join('media_artists as artists', 'artists.id', '=', 'jobs.artist_id')
            ->where('jobs.id', (string) ($job['id'] ?? ''))->where('jobs.status', 'running')
            ->where('jobs.worker_id', $workerId)->first([
                'jobs.id', 'jobs.artist_id', 'jobs.attempt', 'artists.name', 'artists.musicbrainz_artist_id',
            ]);
        if (!$row instanceof stdClass) return;
        try {
            $storedMbid = is_string($row->musicbrainz_artist_id) ? strtolower(trim($row->musicbrainz_artist_id)) : '';
            $localIdentity = $this->identities->resolve((string) $row->name);
            $knownMbid = $this->validMbid($storedMbid) ? $storedMbid : ($localIdentity?->musicBrainzId);
            $plugin = $this->plugins->metadataArtistProfile('metadata-scrape');
            $result = $plugin->scrapeArtistProfile(new ArtistProfileScrapeRequest((string) $row->name, $knownMbid));
            if ($result->status === ArtistProfileScrapeResult::UNMATCHED) {
                $this->fail($row, $workerId, 'ARTIST_PROFILE_IDENTITY_AMBIGUOUS', false);
                return;
            }
            if ($result->status !== ArtistProfileScrapeResult::MATCHED || !is_array($result->profile)) {
                $this->fail($row, $workerId, 'ARTIST_PROFILE_PLUGIN_UNAVAILABLE', true);
                return;
            }
            $resolvedMbid = strtolower(trim((string) ($result->profile['musicBrainzArtistId'] ?? '')));
            if (!$this->validMbid($resolvedMbid)) {
                throw new ArtistProfileTaskFailure('ARTIST_PROFILE_RESPONSE_INVALID', false);
            }
            if ($knownMbid !== null && !hash_equals($knownMbid, $resolvedMbid)) {
                throw new ArtistProfileTaskFailure('ARTIST_PROFILE_IDENTITY_MISMATCH', false);
            }
            $identity = new ArtistProfileIdentity($resolvedMbid, null, (string) $row->name, null);
            $profile = $this->normalizeProfile($result->profile, $identity);
            Db::transaction(function () use ($identity, $profile, $row, $workerId): void {
                /** @var stdClass|null $current */
                $current = Db::table('artist_profile_scrape_jobs as jobs')
                    ->join('media_artists as artists', 'artists.id', '=', 'jobs.artist_id')
                    ->where('jobs.id', (string) $row->id)->where('jobs.status', 'running')
                    ->where('jobs.worker_id', $workerId)->first(['artists.name', 'artists.musicbrainz_artist_id']);
                if (!$current instanceof stdClass) return;
                $currentMbid = is_string($current->musicbrainz_artist_id)
                    ? strtolower(trim($current->musicbrainz_artist_id)) : '';
                if ($currentMbid !== '' && !hash_equals($identity->musicBrainzId, $currentMbid)) {
                    throw new ArtistProfileTaskFailure('ARTIST_PROFILE_IDENTITY_STALE', false);
                }
                if ($currentMbid === '' && (string) $current->name !== (string) $row->name) {
                    throw new ArtistProfileTaskFailure('ARTIST_PROFILE_IDENTITY_STALE', true);
                }
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $values = [
                    ...$profile,
                    'refreshed_at' => $now,
                    'updated_at' => $now,
                ];
                $existingProfile = Db::table('artist_profiles')
                    ->where('artist_id', (string) $row->artist_id)->exists();
                if ($existingProfile) {
                    Db::table('artist_profiles')->where('artist_id', (string) $row->artist_id)->update($values);
                } else {
                    Db::table('artist_profiles')->insert([
                        'artist_id' => (string) $row->artist_id,
                        ...$values,
                        'created_at' => $now,
                    ]);
                }
                Db::table('artist_profile_scrape_jobs')->where('id', (string) $row->id)
                    ->where('status', 'running')->where('worker_id', $workerId)->delete();
            });
        } catch (ArtistProfileTaskFailure $failure) {
            $this->fail($row, $workerId, $failure->reasonCode, $failure->retryable);
        } catch (Throwable) {
            $this->fail($row, $workerId, 'ARTIST_PROFILE_PLUGIN_FAILED', true);
        }
    }

    /** 把进程崩溃遗留租约恢复为 queued；网络请求无持久副作用，不需要补偿外部状态。 */
    public function recoverStaleLeases(): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return Db::table('artist_profile_scrape_jobs')->where('status', 'running')
            ->where('heartbeat_at', '<=', gmdate('Y-m-d\TH:i:s\Z', time() - self::STALE_LEASE_SECONDS))->update([
                'status' => 'queued', 'worker_id' => null, 'heartbeat_at' => null,
                'next_attempt_at' => $now, 'error_code' => 'ARTIST_PROFILE_LEASE_RECOVERED', 'updated_at' => $now,
            ]);
    }

    /** @return array<string,mixed> */
    private function normalizeProfile(array $profile, ArtistProfileIdentity $identity): array
    {
        if (!is_string($profile['musicBrainzArtistId'] ?? null)
            || strtolower(trim($profile['musicBrainzArtistId'])) !== $identity->musicBrainzId) {
            throw new ArtistProfileTaskFailure('ARTIST_PROFILE_RESPONSE_INVALID', false);
        }
        $tags = is_array($profile['tags'] ?? null) && array_is_list($profile['tags'])
            ? array_slice(array_values(array_filter($profile['tags'], static fn (mixed $tag): bool =>
                is_string($tag) && trim($tag) !== '' && mb_strlen($tag, 'UTF-8') <= 80)), 0, 12) : [];
        $sources = is_array($profile['sources'] ?? null) && array_is_list($profile['sources'])
            ? array_values(array_intersect(['musicbrainz', 'wikidata', 'wikipedia_zh'], $profile['sources'])) : [];
        if (!in_array('musicbrainz', $sources, true)) $sources[] = 'musicbrainz';
        return [
            'musicbrainz_artist_id' => $identity->musicBrainzId,
            'wikidata_id' => $this->matchedValue($profile, 'wikidataId', 17, '/^Q[1-9][0-9]{0,15}$/D'),
            'canonical_name' => $this->value($profile, 'canonicalName', 255),
            'artist_type' => $this->value($profile, 'artistType', 80),
            'gender' => $this->value($profile, 'gender', 80),
            'country_code' => $this->matchedValue($profile, 'countryCode', 8, '/^[A-Z]{2,8}$/D'),
            'area_name' => $this->value($profile, 'areaName', 160),
            'begin_date' => $this->matchedValue($profile, 'beginDate', 10, '/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/D'),
            'end_date' => $this->matchedValue($profile, 'endDate', 10, '/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/D'),
            'disambiguation' => $this->value($profile, 'disambiguation', 500),
            'biography' => $this->value($profile, 'biography', 12_000),
            'official_url' => $this->urlValue($profile, 'officialUrl'),
            'wikipedia_url' => $this->urlValue($profile, 'wikipediaUrl', 'zh.wikipedia.org'),
            'tags_json' => json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'sources_json' => json_encode($sources, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * 对注入网关返回值执行最后一道有界校验。
     *
     * 空值表示上游没有该字段；非空但超长或类型错误属于协议失败，不能依赖数据库 CHECK 截断或让一次
     * 非法响应形成可重试的内部错误。这里不修改正文，确保已经审计的远端值与最终投影一致。
     */
    private function value(array $profile, string $key, int $maximum): ?string
    {
        $raw = $profile[$key] ?? null;
        if ($raw === null || $raw === '') return null;
        if (!is_string($raw)) throw new ArtistProfileTaskFailure('ARTIST_PROFILE_RESPONSE_INVALID', false);
        $value = trim($raw);
        if ($value === '') return null;
        if (mb_strlen($value, 'UTF-8') > $maximum) {
            throw new ArtistProfileTaskFailure('ARTIST_PROFILE_RESPONSE_INVALID', false);
        }
        return $value;
    }

    private function matchedValue(array $profile, string $key, int $maximum, string $pattern): ?string
    {
        $value = $this->value($profile, $key, $maximum);
        if ($value !== null && preg_match($pattern, $value) !== 1) {
            throw new ArtistProfileTaskFailure('ARTIST_PROFILE_RESPONSE_INVALID', false);
        }
        return $value;
    }

    /** 只持久化无凭据、无片段且主机可按需固定的 HTTPS URL。 */
    private function urlValue(array $profile, string $key, ?string $requiredHost = null): ?string
    {
        $value = $this->value($profile, $key, 1000);
        if ($value === null) return null;
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || filter_var($parts['host'], FILTER_VALIDATE_IP) !== false
            || ($requiredHost !== null && strtolower($parts['host']) !== $requiredHost)) {
            throw new ArtistProfileTaskFailure('ARTIST_PROFILE_RESPONSE_INVALID', false);
        }
        return $value;
    }

    /** 更新仍由当前任务消费者持有的任务；日志只记录稳定原因，不记录艺人、任务或第三方正文。 */
    private function fail(stdClass $row, string $workerId, string $reason, bool $retryable): void
    {
        $reason = preg_match('/^[A-Z0-9_]{3,96}$/D', $reason) === 1 ? $reason : 'ARTIST_PROFILE_FAILED';
        $attempt = (int) $row->attempt + 1;
        $terminal = !$retryable || $attempt >= self::MAX_ATTEMPTS;
        $delays = [60, 300, 1800, self::FAILED_COOLDOWN_SECONDS];
        Db::table('artist_profile_scrape_jobs')->where('id', (string) $row->id)
            ->where('status', 'running')->where('worker_id', $workerId)->update([
                'status' => $terminal ? 'failed' : 'queued', 'attempt' => min($attempt, self::MAX_ATTEMPTS),
                'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $delays[min($attempt - 1, 3)]),
                'worker_id' => null, 'heartbeat_at' => null, 'error_code' => $reason,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        if ($terminal) Log::warning('Artist profile scrape entered cooldown.', ['reason_code' => $reason]);
    }

    private function validMbid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
