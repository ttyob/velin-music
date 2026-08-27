<?php

declare(strict_types=1);

namespace app\application\Recommendation;

use app\application\Media\MediaQueryService;
use app\application\Playlist\PlaylistConflict;
use app\application\Playlist\PlaylistNotFound;
use app\application\Scrape\ChineseQueryVariantNormalizer;
use app\application\Subsonic\SubsonicCredentialCipher;
use app\infrastructure\Audit\AuditLogger;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use JsonException;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 管理全局 Last.fm 凭据以及多条系统榜单的创建、配置和同步。
 *
 * API Key 只在内存解密并发送到固定官方 HTTPS 端点；浏览器、日志、审计和歌单表均不接触明文。每条
 * 系统歌单通过一对一规则选择 global/chinese/rock。远端条目会以脱敏标题、艺人和匹配状态原子替换
 * `playlist_import_entries`，其中只有规范化身份下的唯一本地授权歌曲进入 `playlist_items`；候选为零或
 * 多个时分别以 `resource_missing` 或 `multiple_candidates` 保留，因此整张榜单可以暂时没有可播放资源。
 * 鉴权、网络或协议故障不会覆盖上次
 * 完整结果，管理员自定义名称与封面也始终保留。自动调度由单独 Workerman 进程调用，本服务不创建
 * 线程或持有跨请求内存状态。
 */
final readonly class LastfmRecommendationService
{
    private const KEY = 'recommendation.lastfm';
    private const MAX_TRACKS = 100;
    private const DEFAULT_INTERVAL_SECONDS = 21_600;

    /** @var array<string,array{name:string,description:string,method:string,parameters:array<string,int|string>}> */
    private const PRESETS = [
        'global' => [
            'name' => 'Last.fm 全球热门',
            'description' => '匹配 Last.fm 全球热门榜中本地可播放的歌曲',
            'method' => 'chart.getTopTracks',
            'parameters' => ['limit' => 200],
        ],
        'chinese' => [
            'name' => 'Last.fm 华语热门',
            'description' => '匹配 Last.fm Chinese 标签热门榜中本地可播放的歌曲',
            'method' => 'tag.getTopTracks',
            'parameters' => ['tag' => 'chinese', 'limit' => 200],
        ],
        'rock' => [
            'name' => 'Last.fm 摇滚热门',
            'description' => '匹配 Last.fm Rock 标签热门榜中本地可播放的歌曲',
            'method' => 'tag.getTopTracks',
            'parameters' => ['tag' => 'rock', 'limit' => 200],
        ],
    ];

    public function __construct(
        private SubsonicCredentialCipher $cipher = new SubsonicCredentialCipher(),
        private MediaQueryService $media = new MediaQueryService(),
        private AuditLogger $audit = new AuditLogger(),
        private ?ClientInterface $http = null,
        private ChineseQueryVariantNormalizer $identityNormalizer = new ChineseQueryVariantNormalizer(),
    ) {
    }

    /** 返回脱敏全局配置；playlistCount 实时按系统规则计数，避免删除歌单后旧 JSON 漂移。 */
    public function snapshot(): array
    {
        $setting = $this->setting();
        return [
            'enabled' => (bool) $setting['enabled'],
            'configured' => is_string($setting['apiKeyCiphertext']) && $setting['apiKeyCiphertext'] !== '',
            'version' => (int) $setting['version'],
            'lastRefreshAt' => $setting['lastRefreshAt'],
            'lastErrorCode' => $setting['lastErrorCode'],
            'playlistCount' => $this->ruleTableExists()
                ? Db::table('system_playlist_sync_rules')->where('provider', 'lastfm')->count()
                : (int) $setting['playlistCount'],
        ];
    }

    /** 返回固定预置目录；方法名、标签参数和 Last.fm 响应字段不暴露给浏览器。 */
    public function presets(): array
    {
        return array_map(
            static fn (string $key, array $preset): array => ['key' => $key, 'name' => $preset['name']],
            array_keys(self::PRESETS),
            array_values(self::PRESETS),
        );
    }

    /**
     * 原子保存全局启停和 API Key。
     *
     * 省略 apiKey 保留现有密文；空字符串只允许在关闭时清除。expectedVersion 是 CAS 版本，冲突不覆盖
     * 另一管理员修改。保存不访问网络，也不自动创建或删除系统歌单。
     */
    public function update(array $command, string $actorId, string $requestId): array
    {
        $setting = $this->setting();
        if (!is_bool($command['enabled'] ?? null) || !is_int($command['expectedVersion'] ?? null)
            || $command['expectedVersion'] !== (int) $setting['version']) {
            throw new LastfmRecommendationConflict();
        }
        $apiKey = $setting['apiKeyCiphertext'];
        if (array_key_exists('apiKey', $command)) {
            if (!is_string($command['apiKey']) || strlen($command['apiKey']) > 128) {
                throw new LastfmRecommendationInvalid();
            }
            $value = trim($command['apiKey']);
            $apiKey = $value === '' ? null : $this->cipher->encrypt($value);
        }
        if ($command['enabled'] && !is_string($apiKey)) throw new LastfmRecommendationInvalid();
        $value = $setting;
        $value['enabled'] = $command['enabled'];
        $value['apiKeyCiphertext'] = $apiKey;
        $value['version'] = (int) $setting['version'] + 1;
        $changed = Db::table('system_settings')->where('setting_key', self::KEY)
            ->where('version', (int) $setting['version'])->update([
                'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'version' => $value['version'], 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        if ($changed !== 1) throw new LastfmRecommendationConflict();
        $this->audit->record($actorId, 'recommendation.lastfm.update', 'system_setting', self::KEY, 'success', $requestId,
            ['enabled' => $value['enabled'], 'configured' => is_string($apiKey)]);
        return $this->snapshot();
    }

    /**
     * 从固定预置创建一条空系统歌单和一对一同步规则。
     *
     * 每个预置全站只允许一条，唯一来源键负责最终幂等；名称可在创建时覆盖，后续同步不再修改。开启自动
     * 同步时 nextSyncAt 设为当前时间，由独立进程异步执行，HTTP 请求不等待 Last.fm。
     *
     * @return array{playlistId:string,preset:string,autoSync:bool}
     */
    public function createPreset(array $actor, array $command, string $requestId): array
    {
        $presetKey = is_string($command['preset'] ?? null) ? trim($command['preset']) : '';
        $preset = self::PRESETS[$presetKey] ?? null;
        $autoSync = $command['autoSync'] ?? true;
        $name = is_string($command['name'] ?? null) ? trim($command['name']) : '';
        if (!is_array($preset) || !is_bool($autoSync) || mb_strlen($name) > 100) {
            throw new LastfmRecommendationInvalid();
        }
        if ($name === '') $name = $preset['name'];
        $playlistId = (string) new Ulid();
        $actorId = (string) ($actor['id'] ?? '');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use ($actorId, $autoSync, $name, $now, $playlistId, $preset, $presetKey, $requestId): void {
                Db::table('playlists')->insert([
                    'id' => $playlistId, 'owner_user_id' => $actorId, 'kind' => 'manual', 'scope' => 'system',
                    'source' => 'lastfm', 'source_key' => 'lastfm.' . $presetKey, 'name' => $name,
                    'description' => $preset['description'], 'visibility' => 'server', 'song_count' => 0,
                    'duration_ms' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                Db::table('system_playlist_sync_rules')->insert([
                    'playlist_id' => $playlistId, 'provider' => 'lastfm', 'preset' => $presetKey,
                    'enabled' => $autoSync ? 1 : 0, 'interval_seconds' => self::DEFAULT_INTERVAL_SECONDS,
                    'last_attempt_at' => null, 'last_success_at' => null, 'last_error_code' => null,
                    'next_sync_at' => $autoSync ? $now : null, 'version' => 1, 'updated_by' => $actorId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record($actorId, 'system_playlist.lastfm.create', 'playlist', $playlistId, 'success',
                    $requestId, ['preset' => $presetKey, 'autoSync' => $autoSync]);
            });
        } catch (Throwable $throwable) {
            if ($this->presetExists($presetKey)) throw new LastfmRecommendationConflict(previous: $throwable);
            throw $throwable;
        }
        return ['playlistId' => $playlistId, 'preset' => $presetKey, 'autoSync' => $autoSync];
    }

    /**
     * 用规则版本锁启停一条 Last.fm 系统歌单的自动同步。
     *
     * 开启时立即设为到期，关闭时清空 nextSyncAt；已保存歌曲不变化。运行状态字段不增加规则版本，因而
     * 后台定时刷新不会制造虚假表单冲突，只有管理员配置变更才递增 version。
     */
    public function updateRule(string $playlistId, array $command, string $actorId, string $requestId): array
    {
        $enabled = $command['enabled'] ?? null;
        $expectedVersion = $command['expectedVersion'] ?? null;
        if (!is_bool($enabled) || !is_int($expectedVersion) || $expectedVersion < 1) {
            throw new LastfmRecommendationInvalid();
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)
            ->where('provider', 'lastfm')->where('version', $expectedVersion)->update([
                'enabled' => $enabled ? 1 : 0, 'next_sync_at' => $enabled ? $now : null,
                'version' => $expectedVersion + 1, 'updated_by' => $actorId, 'updated_at' => $now,
            ]);
        if ($changed !== 1) {
            if (!$this->rule($playlistId) instanceof stdClass) throw new PlaylistNotFound('System playlist rule not found.');
            throw new PlaylistConflict('同步设置已发生变化，请重新加载。');
        }
        $this->audit->record($actorId, 'system_playlist.lastfm.rule.update', 'playlist', $playlistId, 'success',
            $requestId, ['enabled' => $enabled, 'version' => $expectedVersion + 1]);
        return $this->ruleSnapshot($this->ruleRequired($playlistId));
    }

    /**
     * 兼容旧“立即刷新”入口：依次刷新全部 Last.fm 系统歌单。
     *
     * 任一歌单成功即返回匹配、缺失与远端条目总数；各歌单失败状态独立保存。全部失败时抛出最后一个
     * 稳定领域错误，旧内容仍保留。该方法不再创建隐式单歌单，管理员应从歌单模块明确选择预置。
     */
    public function refresh(array $actor, string $requestId): array
    {
        /** @var list<stdClass> $rules */
        $rules = Db::table('system_playlist_sync_rules')->where('provider', 'lastfm')->orderBy('playlist_id')->get()->all();
        if ($rules === []) throw new LastfmRecommendationUnavailable();
        $matched = 0;
        $missing = 0;
        $total = 0;
        $success = 0;
        $lastError = null;
        foreach ($rules as $rule) {
            try {
                $result = $this->refreshPlaylist($actor, (string) $rule->playlist_id, $requestId);
                $matched += $result['matchedCount'];
                $missing += $result['missingCount'];
                $total += $result['totalCount'];
                $success++;
            } catch (Throwable $throwable) {
                $lastError = $throwable;
            }
        }
        if ($success === 0 && $lastError instanceof Throwable) throw $lastError;
        return $this->snapshot() + [
            'matchedCount' => $matched,
            'missingCount' => $missing,
            'totalCount' => $total,
            'refreshedCount' => $success,
        ];
    }

    /**
     * 同步指定规则并原子替换可播放项目与资源缺失条目。
     *
     * 网络和本地匹配发生在 SQLite 事务外；提交时重新确认歌单仍为相同 preset 的 Last.fm 系统歌单。
     * 名称、说明和封面不在更新列中。有效远端榜单即使零本地匹配也算成功；网络、鉴权、空/畸形协议
     * 响应等上游失败会记录稳定错误和下一次时间，且不会删除或重排上次完整结果。
     *
     * @return array{matchedCount:int,missingCount:int,totalCount:int,rule:array<string,mixed>}
     */
    public function refreshPlaylist(array $actor, string $playlistId, string $requestId): array
    {
        $setting = $this->setting();
        if (!$setting['enabled'] || !is_string($setting['apiKeyCiphertext'])) {
            $this->recordFailure($playlistId, 'LASTFM_RECOMMENDATION_UNAVAILABLE');
            throw new LastfmRecommendationUnavailable();
        }
        $rule = $this->ruleRequired($playlistId);
        $preset = (string) $rule->preset;
        try {
            $apiKey = $this->cipher->decrypt($setting['apiKeyCiphertext']);
            $entries = $this->matchPresetTracks($actor, $apiKey, $preset);
            if ($entries === []) throw new LastfmRecommendationUnavailable();
            $counts = $this->persistPlaylist($actor, $playlistId, $preset, $entries);
            $this->recordSuccess($playlistId);
            $this->recordGlobalState(null);
            return $counts + ['rule' => $this->ruleSnapshot($this->ruleRequired($playlistId))];
        } catch (Throwable $exception) {
            $code = match (true) {
                $exception instanceof LastfmRecommendationAuthenticationFailed => 'LASTFM_API_KEY_REJECTED',
                default => 'LASTFM_REFRESH_FAILED',
            };
            $this->recordFailure($playlistId, $code);
            $this->recordGlobalState($code);
            if ($exception instanceof LastfmRecommendationAuthenticationFailed
                || $exception instanceof LastfmRecommendationUnavailable) {
                throw $exception;
            }
            throw new LastfmRecommendationUnavailable(previous: $exception);
        }
    }

    /**
     * 执行一条到期自动规则。
     *
     * 调度器固定单进程，每次 tick 最多处理一条，防止上游故障时并发放大。系统 actor 只用于应用全库
     * 媒体可见范围和满足 playlist_items 的 added_by 外键，不代表登录用户，也不经过 HTTP 权限提升；
     * 该入口只能由进程配置调用。返回 false 表示没有到期任务或全局 Last.fm 已关闭。
     */
    public function syncOneDue(string $requestId): bool
    {
        $setting = $this->setting();
        if (!$setting['enabled'] || !is_string($setting['apiKeyCiphertext'])) return false;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        /** @var stdClass|null $row */
        $row = Db::table('system_playlist_sync_rules as sync')
            ->join('playlists as playlists', 'playlists.id', '=', 'sync.playlist_id')
            ->join('users as owners', 'owners.id', '=', 'playlists.owner_user_id')
            ->where('sync.provider', 'lastfm')->where('sync.enabled', 1)
            ->where(function ($due) use ($now): void { $due->whereNull('sync.next_sync_at')->orWhere('sync.next_sync_at', '<=', $now); })
            ->where('playlists.scope', 'system')->where('owners.status', 'active')
            ->orderBy('sync.next_sync_at')->orderBy('sync.playlist_id')
            ->first(['sync.playlist_id', 'owners.id as owner_id']);
        if (!$row instanceof stdClass) return false;
        $actor = ['id' => (string) $row->owner_id, 'isSuperAdmin' => true];
        try { $this->refreshPlaylist($actor, (string) $row->playlist_id, $requestId); } catch (Throwable) {}
        return true;
    }

    /**
     * 从固定 Last.fm 榜单提取有界条目，并记录当前本地唯一身份匹配结果。
     *
     * 标题和艺人是唯一持久化的第三方显示证据，必须满足导入条目表的长度与控制字符约束；匹配只在
     * 内存中增加固定 OpenCC 简体变体，原文、平台 ID、链接和原始响应永不落库。返回空数组表示响应
     * 没有任何可安全解释的榜单条目，由调用方按上游协议失败处理并保留旧结果。最多保留前 100 个有效
     * 条目，避免异常响应放大 SQLite 写事务；多个本地候选不会按 ID 偷选。
     *
     * @return list<array{position:int,status:string,songId:?string,reasonCode:?string,sourceTitle:string,sourceArtists:list<string>}>
     */
    private function matchPresetTracks(array $actor, string $apiKey, string $presetKey): array
    {
        $preset = self::PRESETS[$presetKey] ?? null;
        if (!is_array($preset)) throw new LastfmRecommendationInvalid();
        $document = $this->lastfm($apiKey, $preset['method'], $preset['parameters']);
        $tracks = $document['tracks']['track'] ?? $document['toptracks']['track'] ?? [];
        if (!is_array($tracks)) throw new LastfmRecommendationUnavailable();
        $sourceEntries = [];
        foreach ($tracks as $track) {
            if (!is_array($track)) continue;
            $title = $this->externalDisplayText($track['name'] ?? null, 500);
            $artistValue = $track['artist'] ?? null;
            $artist = $this->externalDisplayText(
                is_array($artistValue) ? ($artistValue['name'] ?? null) : $artistValue,
                300,
            );
            if ($title === null || $artist === null) continue;
            $sourceEntries[] = ['sourceTitle' => $title, 'sourceArtists' => [$artist]];
            if (count($sourceEntries) >= self::MAX_TRACKS) break;
        }
        $identities = $this->identityNormalizer->simplify(array_map(
            static fn (array $entry): array => ['title' => $entry['sourceTitle'], 'artists' => $entry['sourceArtists']],
            $sourceEntries,
        ));
        $entries = [];
        foreach ($sourceEntries as $position => $sourceEntry) {
            $identity = is_array($identities[$position] ?? null) ? $identities[$position] : [];
            $titles = [$sourceEntry['sourceTitle']];
            if (is_string($identity['title'] ?? null)) $titles[] = $identity['title'];
            $artists = $sourceEntry['sourceArtists'];
            if (is_array($identity['artists'] ?? null)) {
                foreach ($identity['artists'] as $artist) if (is_string($artist)) $artists[] = $artist;
            }
            $candidateIds = $this->media->songIdsByPlaylistIdentity($actor, $titles, $artists);
            $candidateCount = count($candidateIds);
            $songId = $candidateCount === 1 ? $candidateIds[0] : null;
            $entries[] = [
                'position' => $position,
                'status' => $songId !== null ? 'matched' : ($candidateCount > 1 ? 'ambiguous' : 'unmatched'),
                'songId' => $songId,
                'reasonCode' => $songId !== null ? null : ($candidateCount > 1 ? 'multiple_candidates' : 'resource_missing'),
                'sourceTitle' => $sourceEntry['sourceTitle'],
                'sourceArtists' => $sourceEntry['sourceArtists'],
                'candidateCount' => min(100, $candidateCount),
            ];
        }
        return $entries;
    }

    /** 将不受信任的第三方标签约束为可用于精确匹配和后台展示的单行文本。 */
    private function externalDisplayText(mixed $value, int $maximum): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        return $value;
    }

    /**
     * 调用固定 Last.fm HTTPS API 并解析有界 JSON。
     *
     * error=10/26 表示密钥无效或暂停；其他 HTTP、协议和 JSON 故障统一视为可重试上游错误。响应正文
     * 只在内存解析，不进入日志、异常、审计或持久化。
     */
    private function lastfm(string $apiKey, string $method, array $parameters): array
    {
        $response = ($this->http ?? new Client(['http_errors' => false]))->request('GET',
            'https://ws.audioscrobbler.com/2.0/', [
                'query' => ['method' => $method, 'api_key' => $apiKey, 'format' => 'json'] + $parameters,
                'timeout' => 10, 'connect_timeout' => 3,
            ]);
        $document = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($document)) throw new LastfmRecommendationUnavailable();
        $error = isset($document['error']) ? (int) $document['error'] : 0;
        if (in_array($error, [10, 26], true)) throw new LastfmRecommendationAuthenticationFailed();
        if ($response->getStatusCode() >= 400 || $error !== 0) throw new LastfmRecommendationUnavailable();
        return $document;
    }

    /**
     * 在短事务中替换一次同步的完整远端条目和可播放子集。
     *
     * 匹配查询结束后歌曲仍可能被删除或撤权，因此提交前再次应用实时授权；失效匹配降级为
     * `resource_missing`，不会导致整张榜单回滚。`playlist_items.position` 只对可播放子集连续编号，
     * `playlist_import_entries.position` 则保持远端榜单顺序。歌单身份或版本在提交前变化时事务整体回滚。
     *
     * @param list<array{position:int,status:string,songId:?string,reasonCode:?string,sourceTitle:string,sourceArtists:list<string>}> $entries
     * @return array{matchedCount:int,missingCount:int,totalCount:int}
     */
    private function persistPlaylist(array $actor, string $playlistId, string $preset, array $entries): array
    {
        $candidateSongIds = array_values(array_unique(array_filter(array_column($entries, 'songId'), 'is_string')));
        $songs = $this->media->songsByIds($actor, $candidateSongIds);
        $songIds = [];
        foreach ($entries as &$entry) {
            $songId = $entry['songId'];
            if (!is_string($songId) || !isset($songs[$songId])) {
                $entry['status'] = 'unmatched';
                $entry['songId'] = null;
                $entry['candidateCount'] = 0;
                $entry['reasonCode'] = 'resource_missing';
                continue;
            }
            $songIds[] = $songId;
        }
        unset($entry);
        $durationMs = array_sum(array_map(static fn (string $id): int => (int) ($songs[$id]['durationMs'] ?? 0), $songIds));
        $ownerId = (string) $actor['id'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($durationMs, $entries, $now, $ownerId, $playlistId, $preset, $songIds): void {
            /** @var stdClass|null $row */
            $row = Db::table('playlists')->where('id', $playlistId)->where('scope', 'system')
                ->where('source', 'lastfm')->where('source_key', 'lastfm.' . $preset)->first(['version']);
            if (!$row instanceof stdClass) throw new PlaylistNotFound('Last.fm system playlist not found.');
            Db::table('playlist_items')->where('playlist_id', $playlistId)->delete();
            Db::table('playlist_import_entries')->where('playlist_id', $playlistId)->delete();
            $items = [];
            foreach ($songIds as $position => $songId) $items[] = [
                'playlist_id' => $playlistId, 'position' => $position, 'song_id' => $songId,
                'added_by_user_id' => $ownerId, 'added_at' => $now,
            ];
            if ($items !== []) Db::table('playlist_items')->insert($items);
            $importRows = array_map(static fn (array $entry): array => [
                'playlist_id' => $playlistId,
                'position' => $entry['position'],
                'status' => $entry['status'],
                'song_id' => $entry['songId'],
                'candidate_count' => $entry['candidateCount'],
                'reason_code' => $entry['reasonCode'],
                'source_title' => $entry['sourceTitle'],
                'source_artists_json' => json_encode($entry['sourceArtists'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'source_album' => null,
                'created_at' => $now,
            ], $entries);
            Db::table('playlist_import_entries')->insert($importRows);
            $changed = Db::table('playlists')->where('id', $playlistId)->where('version', (int) $row->version)->update([
                'song_count' => count($songIds), 'duration_ms' => $durationMs,
                'version' => (int) $row->version + 1, 'updated_at' => $now,
            ]);
            if ($changed !== 1) throw new PlaylistConflict('System playlist changed during Last.fm sync.');
        });

        return [
            'matchedCount' => count($songIds),
            'missingCount' => count($entries) - count($songIds),
            'totalCount' => count($entries),
        ];
    }

    /** 记录成功运行状态，不递增管理员配置版本。 */
    private function recordSuccess(string $playlistId): void
    {
        $rule = $this->ruleRequired($playlistId);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)->update([
            'last_attempt_at' => $now, 'last_success_at' => $now, 'last_error_code' => null,
            'next_sync_at' => gmdate('Y-m-d\TH:i:s\Z', time() + (int) $rule->interval_seconds), 'updated_at' => $now,
        ]);
    }

    /** 记录脱敏失败状态并安排下一周期；列表和规则配置字段不变化。 */
    private function recordFailure(string $playlistId, string $code): void
    {
        $rule = $this->rule($playlistId);
        if (!$rule instanceof stdClass) return;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)->update([
            'last_attempt_at' => $now, 'last_error_code' => $code,
            'next_sync_at' => (int) $rule->enabled === 1
                ? gmdate('Y-m-d\TH:i:s\Z', time() + (int) $rule->interval_seconds) : null,
            'updated_at' => $now,
        ]);
    }

    /** 更新兼容旧设置页的聚合状态，不修改密钥、启停或 CAS 版本。 */
    private function recordGlobalState(?string $errorCode): void
    {
        $setting = $this->setting();
        $setting['lastErrorCode'] = $errorCode;
        if ($errorCode === null) $setting['lastRefreshAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $setting['playlistCount'] = Db::table('system_playlist_sync_rules')->where('provider', 'lastfm')->count();
        Db::table('system_settings')->where('setting_key', self::KEY)->update([
            'value_json' => json_encode($setting, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @return array<string,mixed> */
    private function ruleSnapshot(stdClass $rule): array
    {
        return [
            'provider' => 'lastfm', 'preset' => (string) $rule->preset, 'enabled' => (int) $rule->enabled === 1,
            'intervalSeconds' => (int) $rule->interval_seconds,
            'lastAttemptAt' => $rule->last_attempt_at === null ? null : (string) $rule->last_attempt_at,
            'lastSuccessAt' => $rule->last_success_at === null ? null : (string) $rule->last_success_at,
            'lastErrorCode' => $rule->last_error_code === null ? null : (string) $rule->last_error_code,
            'nextSyncAt' => $rule->next_sync_at === null ? null : (string) $rule->next_sync_at,
            'version' => (int) $rule->version,
        ];
    }

    /** 返回一条规则或 null；查询不包含密钥和歌单项目。 */
    private function rule(string $playlistId): ?stdClass
    {
        $row = Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)
            ->where('provider', 'lastfm')->first();
        return $row instanceof stdClass ? $row : null;
    }

    /** 返回一条规则，不存在统一按系统歌单不存在处理。 */
    private function ruleRequired(string $playlistId): stdClass
    {
        return $this->rule($playlistId) ?? throw new PlaylistNotFound('System playlist rule not found.');
    }

    /** 检查固定来源键是否已被占用，用于把唯一约束转换为稳定冲突。 */
    private function presetExists(string $preset): bool
    {
        return Db::table('playlists')->where('scope', 'system')->where('source', 'lastfm')
            ->where('source_key', 'lastfm.' . $preset)->exists();
    }

    /** @return array<string,mixed> */
    private function setting(): array
    {
        $row = Db::table('system_settings')->where('setting_key', self::KEY)->first();
        if (!$row instanceof stdClass) throw new LastfmRecommendationUnavailable();
        try { $value = json_decode((string) $row->value_json, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new LastfmRecommendationUnavailable(previous: $exception); }
        if (!is_array($value)) throw new LastfmRecommendationUnavailable();
        return $value + ['enabled' => false, 'apiKeyCiphertext' => null, 'version' => (int) $row->version,
            'lastRefreshAt' => null, 'lastErrorCode' => null, 'playlistCount' => 0];
    }

    /** 测试精简 schema 和滚动升级期间安全回退旧快照计数。 */
    private function ruleTableExists(): bool
    {
        return Db::connection()->getSchemaBuilder()->hasTable('system_playlist_sync_rules');
    }
}

final class LastfmRecommendationInvalid extends \RuntimeException {}
final class LastfmRecommendationConflict extends \RuntimeException {}
final class LastfmRecommendationUnavailable extends \RuntimeException {}
final class LastfmRecommendationAuthenticationFailed extends \RuntimeException {}
