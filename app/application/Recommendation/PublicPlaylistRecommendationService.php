<?php

declare(strict_types=1);

namespace app\application\Recommendation;

use app\application\Media\MediaQueryService;
use app\application\Playlist\PlaylistConflict;
use app\application\Playlist\PlaylistNotFound;
use app\application\Scrape\ChineseQueryVariantNormalizer;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 管理由歌单同步插件声明的无需登录官方榜单系统资源；当前默认插件覆盖历史 `['netease', 'qq', 'kugou']`。
 *
 * 平台请求和详情解析由受控插件及其随包 Helper 完成，本服务只接收已经收窄的显示字段和本地匹配证据。平台私有
 * ID、链接、Cookie、播放地址和原始响应不会进入数据库；每条歌单使用 Helper 计算的摘要作为稳定来源键。
 * 匹配与替换发生在网络请求外，提交时再次应用管理员的全库媒体可见范围。上游失败保留上一次完整结果，
 * 空的合法结果也只会产生资源缺失条目，不会伪造可播放歌曲。
 */
final readonly class PublicPlaylistRecommendationService
{
    private const LEGACY_PROVIDERS = [
        'netease' => '网易云音乐',
        'qq' => 'QQ 音乐',
        'kugou' => '酷狗音乐',
    ];
    private const INTERVAL_SECONDS = 21_600;
    private const MAX_ENTRIES = 100;
    private const SOURCE_PREFIX = 'public.';

    public function __construct(
        private PublicPlaylistCatalogGateway $catalogGateway = new PluginPlaylistCatalogGateway(),
        private MediaQueryService $media = new MediaQueryService(),
        private AuditLogger $audit = new AuditLogger(),
        private ChineseQueryVariantNormalizer $identityNormalizer = new ChineseQueryVariantNormalizer(),
    ) {
    }

    /** 返回由同步插件声明的来源目录，不访问第三方；登录需求和展示名称不由核心猜测。 */
    public function providers(): array
    {
        if ($this->catalogGateway instanceof PublicPlaylistProviderCatalogGateway) {
            $declared = $this->catalogGateway->providers();
            if (!array_is_list($declared) || count($declared) > 64) {
                throw new PublicPlaylistRecommendationUnavailable('PUBLIC_PLAYLIST_PROVIDER_DECLARATION_INVALID');
            }
            $result = [];
            foreach ($declared as $provider) {
                if (!is_array($provider) || array_diff(array_keys($provider), ['key', 'name', 'loginRequired']) !== []
                    || !is_string($provider['key'] ?? null) || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $provider['key']) !== 1
                    || isset($result[$provider['key']]) || !is_string($provider['name'] ?? null)
                    || trim($provider['name']) === '' || mb_strlen($provider['name']) > 100
                    || !is_bool($provider['loginRequired'] ?? null)) {
                    throw new PublicPlaylistRecommendationUnavailable('PUBLIC_PLAYLIST_PROVIDER_DECLARATION_INVALID');
                }
                $result[$provider['key']] = [
                    'key' => $provider['key'], 'name' => trim($provider['name']), 'loginRequired' => $provider['loginRequired'],
                ];
            }
            return array_values($result);
        }
        // 仅为旧的内存测试网关和历史调用方保留只读投影；生产默认网关始终来自插件声明。
        return array_map(static fn (string $key, string $name): array => ['key' => $key, 'name' => $name, 'loginRequired' => false],
            array_keys(self::LEGACY_PROVIDERS), array_values(self::LEGACY_PROVIDERS));
    }

    /**
     * 查询一个来源的脱敏官方榜单目录。
     *
     * 结果仅供管理员选择待同步歌单，不会写入业务库；网络或协议错误直接抛出，调用方应返回稳定错误码。
     * 浏览器只能看到摘要和条目数量，不能得到平台 ID、外链或原始第三方字段。
     *
     * @return array{provider:string,providerName:string,playlists:list<array{key:string,title:string,description:string,entryCount:int}>}
     */
    public function catalog(string $provider): array
    {
        $this->assertProvider($provider);
        $items = $this->catalogGateway->catalog($provider);
        return ['provider' => $provider, 'providerName' => $this->providerName($provider), 'playlists' => array_map(
            static fn (array $item): array => [
                'key' => $item['key'], 'title' => $item['title'], 'description' => $item['description'],
                'entryCount' => count($item['entries']),
            ],
            $items,
        )];
    }

    /**
     * 创建一个来源固定的系统歌单。
     *
     * 创建前重新查询目录并确认 key 属于指定 provider，避免浏览器伪造跨平台来源或写入不存在的榜单。歌单
     * 先以空内容落库，自动同步由 Worker 异步执行；失败不会删除歌单。相同 provider/key 由数据库唯一索引
     * 和事务内查询共同保证幂等冲突，名称只在创建时读取，后续同步不覆盖管理员修改。
     *
     * @return array{playlistId:string,provider:string,preset:string,autoSync:bool}
     * @throws PublicPlaylistRecommendationInvalid
     * @throws PublicPlaylistRecommendationConflict
     */
    public function createPreset(array $actor, array $command, string $requestId): array
    {
        $provider = is_string($command['provider'] ?? null) ? strtolower(trim($command['provider'])) : '';
        $key = is_string($command['key'] ?? null) ? trim($command['key']) : '';
        $name = is_string($command['name'] ?? null) ? trim($command['name']) : '';
        $autoSync = $command['autoSync'] ?? true;
        if (!is_bool($autoSync) || $key === '' || preg_match('/^[a-f0-9]{64}$/', $key) !== 1
            || mb_strlen($name) > 100) throw new PublicPlaylistRecommendationInvalid();
        $this->assertProvider($provider);
        $catalog = $this->catalogGateway->catalog($provider);
        $item = null;
        foreach ($catalog as $candidate) if ($candidate['key'] === $key) { $item = $candidate; break; }
        if (!is_array($item)) throw new PublicPlaylistRecommendationInvalid();
        $sourceKey = self::SOURCE_PREFIX . $provider . '.' . $key;
        if ($name === '') $name = $item['title'];
        $playlistId = (string) new Ulid();
        $actorId = (string) ($actor['id'] ?? '');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use ($actorId, $autoSync, $item, $name, $now, $playlistId, $provider, $requestId, $sourceKey, $key): void {
                if (Db::table('playlists')->where('scope', 'system')->where('source', $provider)->where('source_key', $sourceKey)->exists()) {
                    throw new PublicPlaylistRecommendationConflict();
                }
                Db::table('playlists')->insert([
                    'id' => $playlistId, 'owner_user_id' => $actorId, 'kind' => 'manual', 'scope' => 'system',
                    'source' => $provider, 'source_key' => $sourceKey, 'name' => $name,
                    'description' => $item['description'] !== '' ? $item['description'] : $this->providerName($provider) . '热门歌单',
                    'visibility' => 'server', 'song_count' => 0, 'duration_ms' => 0, 'version' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                Db::table('system_playlist_sync_rules')->insert([
                    'playlist_id' => $playlistId, 'provider' => $provider, 'preset' => 'hot',
                    'enabled' => $autoSync ? 1 : 0, 'interval_seconds' => self::INTERVAL_SECONDS,
                    'last_attempt_at' => null, 'last_success_at' => null, 'last_error_code' => null,
                    'next_sync_at' => $autoSync ? $now : null, 'version' => 1, 'updated_by' => $actorId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record($actorId, 'system_playlist.' . $provider . '.create', 'playlist', $playlistId,
                    'success', $requestId, ['preset' => $key, 'autoSync' => $autoSync]);
            });
        } catch (PublicPlaylistRecommendationConflict $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (Db::table('playlists')->where('scope', 'system')->where('source_key', $sourceKey)->exists()) {
                throw new PublicPlaylistRecommendationConflict(previous: $exception);
            }
            throw $exception;
        }
        return ['playlistId' => $playlistId, 'provider' => $provider, 'preset' => $key, 'autoSync' => $autoSync];
    }

    /** 手工刷新指定来源的全部系统热门歌单；单条失败不影响其他来源和旧结果。 */
    public function refresh(array $actor, string $requestId): array
    {
        $rules = Db::table('system_playlist_sync_rules')->whereIn('provider', array_keys($this->providerMap()))
            ->orderBy('playlist_id')->get(['playlist_id', 'provider'])->all();
        if ($rules === []) throw new PublicPlaylistRecommendationUnavailable();
        $success = 0; $matched = 0; $missing = 0; $total = 0; $lastError = null;
        foreach ($rules as $rule) {
            try {
                $result = $this->refreshPlaylist($actor, (string) $rule->playlist_id, (string) $rule->provider, $requestId);
                $success++; $matched += $result['matchedCount']; $missing += $result['missingCount']; $total += $result['totalCount'];
            } catch (Throwable $exception) { $lastError = $exception; }
        }
        if ($success === 0 && $lastError instanceof Throwable) throw $lastError;
        return ['matchedCount' => $matched, 'missingCount' => $missing, 'totalCount' => $total, 'refreshedCount' => $success];
    }

    /** 同步一条规则；上游失败记录稳定错误码并保留旧项目。 */
    public function refreshPlaylist(array $actor, string $playlistId, string $provider, string $requestId): array
    {
        $this->assertProvider($provider);
        $rule = $this->rule($playlistId, $provider) ?? throw new PlaylistNotFound('Public playlist rule not found.');
        try {
            $sourceKey = (string) Db::table('playlists')->where('id', $playlistId)->where('scope', 'system')->value('source_key');
            $expectedPrefix = self::SOURCE_PREFIX . $provider . '.';
            $key = str_starts_with($sourceKey, $expectedPrefix) ? substr($sourceKey, strlen($expectedPrefix)) : '';
            if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) throw new PublicPlaylistRecommendationUnavailable();
            $item = null;
            foreach ($this->catalogGateway->catalog($provider) as $candidate) if ($candidate['key'] === $key) { $item = $candidate; break; }
            if (!is_array($item) || $item['entries'] === []) throw new PublicPlaylistRecommendationUnavailable();
            if ($this->catalogGateway instanceof PluginPlaylistCatalogGateway) {
                $document = $this->catalogGateway->sync($provider, $key);
                $item['entries'] = array_map(static fn (\app\application\Playlist\PlatformPlaylistEntry $entry): array => [
                    'title' => $entry->title, 'artists' => $entry->artists, 'album' => $entry->album, 'durationMs' => $entry->durationMs,
                ], $document->entries);
            }
            $entries = $this->matchEntries($actor, $item['entries']);
            $counts = $this->persistPlaylist($actor, $playlistId, $provider, $sourceKey, $entries);
            $this->recordSuccess($playlistId, $provider, $rule);
            return $counts + ['rule' => $this->ruleSnapshot($this->rule($playlistId, $provider) ?? $rule)];
        } catch (Throwable $exception) {
            $this->recordFailure($playlistId, $provider, 'PUBLIC_' . strtoupper($provider) . '_REFRESH_FAILED', $rule);
            if ($exception instanceof PublicPlaylistRecommendationUnavailable) throw $exception;
            throw new PublicPlaylistRecommendationUnavailable(previous: $exception);
        }
    }

    /** 用规则版本锁更新公开热门歌单的自动同步开关；已保存的歌曲和版本内容不变化。 */
    public function updateRule(string $playlistId, array $command, string $actorId, string $requestId): array
    {
        $enabled = $command['enabled'] ?? null; $expectedVersion = $command['expectedVersion'] ?? null;
        if (!is_bool($enabled) || !is_int($expectedVersion) || $expectedVersion < 1) throw new PublicPlaylistRecommendationInvalid();
        $rule = Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)->whereIn('provider', array_keys($this->providerMap()))->first();
        if (!$rule instanceof stdClass) throw new PlaylistNotFound('Public playlist rule not found.');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)->where('provider', (string) $rule->provider)
            ->where('version', $expectedVersion)->update(['enabled' => $enabled ? 1 : 0, 'next_sync_at' => $enabled ? $now : null,
                'version' => $expectedVersion + 1, 'updated_by' => $actorId, 'updated_at' => $now]);
        if ($changed !== 1) throw new PlaylistConflict('同步设置已发生变化，请重新加载。');
        $this->audit->record($actorId, 'system_playlist.' . (string) $rule->provider . '.rule.update', 'playlist', $playlistId,
            'success', $requestId, ['enabled' => $enabled, 'version' => $expectedVersion + 1]);
        return $this->ruleSnapshot($this->rule($playlistId, (string) $rule->provider) ?? $rule);
    }

    /** 由单消费者 Worker 调用，每次最多处理一条到期公开来源规则。 */
    public function syncOneDue(string $requestId): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $row = Db::table('system_playlist_sync_rules as sync')->join('playlists', 'playlists.id', '=', 'sync.playlist_id')
            ->join('users as owners', 'owners.id', '=', 'playlists.owner_user_id')->whereIn('sync.provider', array_keys($this->providerMap()))
            ->where('sync.enabled', 1)->where(function ($due) use ($now): void { $due->whereNull('sync.next_sync_at')->orWhere('sync.next_sync_at', '<=', $now); })
            ->where('playlists.scope', 'system')->where('owners.status', 'active')->orderBy('sync.next_sync_at')->orderBy('sync.playlist_id')
            ->first(['sync.playlist_id', 'sync.provider', 'owners.id as owner_id']);
        if (!$row instanceof stdClass) return false;
        try { $this->refreshPlaylist(['id' => (string) $row->owner_id, 'isSuperAdmin' => true], (string) $row->playlist_id, (string) $row->provider, $requestId); }
        catch (Throwable) { }
        return true;
    }

    /** @return list<array{provider:string,preset:string,enabled:bool,intervalSeconds:int,lastAttemptAt:?string,lastSuccessAt:?string,lastErrorCode:?string,nextSyncAt:?string,version:int}> */
    public function rules(): array
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('system_playlist_sync_rules')) return [];
        return array_map(fn (stdClass $rule): array => $this->ruleSnapshot($rule), Db::table('system_playlist_sync_rules')
            ->whereIn('provider', array_keys($this->providerMap()))->orderBy('provider')->orderBy('playlist_id')->get()->all());
    }

    /**
     * 以外部身份候选匹配当前管理员可见歌曲。
     *
     * 每个条目会同时保留第三方原文和固定 OpenCC 产生的内存简体变体；标题与艺人仍只做规范化等值
     * 查询。候选为零时记录 resource_missing，候选多于一个时记录 multiple_candidates，只有唯一 ID 才
     * 允许进入播放项目。简繁转换不可用时由变体组件原样回退，不会阻断整张榜单或改写原始证据。
     *
     * @param list<array{title:string,artists:list<string>,album:?string,durationMs:?int}> $sourceEntries
     * @return list<array{position:int,status:string,songId:?string,candidateCount:int,reasonCode:?string,sourceTitle:string,sourceArtists:list<string>,sourceAlbum:?string}>
     */
    private function matchEntries(array $actor, array $sourceEntries): array
    {
        $entries = [];
        $sourceEntries = array_slice($sourceEntries, 0, self::MAX_ENTRIES);
        $identities = $this->identityNormalizer->simplify(array_map(
            static fn (array $entry): array => ['title' => $entry['title'], 'artists' => $entry['artists']],
            $sourceEntries,
        ));
        foreach ($sourceEntries as $position => $entry) {
            $identity = is_array($identities[$position] ?? null) ? $identities[$position] : [];
            $titles = [$entry['title']];
            if (is_string($identity['title'] ?? null)) $titles[] = $identity['title'];
            $artists = $entry['artists'];
            if (is_array($identity['artists'] ?? null)) {
                foreach ($identity['artists'] as $artist) if (is_string($artist)) $artists[] = $artist;
            }
            $candidateIds = $this->media->songIdsByPlaylistIdentity($actor, $titles, $artists);
            $candidateCount = count($candidateIds);
            $songId = $candidateCount === 1 ? $candidateIds[0] : null;
            $status = $songId !== null ? 'matched' : ($candidateCount > 1 ? 'ambiguous' : 'unmatched');
            $entries[] = ['position' => $position, 'status' => $status, 'songId' => $songId,
                'candidateCount' => min(100, $candidateCount),
                'reasonCode' => $songId === null ? ($candidateCount > 1 ? 'multiple_candidates' : 'resource_missing') : null,
                'sourceTitle' => $entry['title'], 'sourceArtists' => $entry['artists'], 'sourceAlbum' => $entry['album']];
        }
        return $entries;
    }

    /**
     * 在歌单版本锁事务中替换可播放项目和脱敏导入证据。
     *
     * 匹配完成后重新读取管理员可见歌曲，避免媒体删除或权限撤销在网络阶段被绕过；歌单身份、来源和版本
     * 任一变化都会整体回滚。事务失败不触碰旧项目，成功后项目位置连续而导入证据保留第三方榜单顺序。
     */
    private function persistPlaylist(array $actor, string $playlistId, string $provider, string $sourceKey, array $entries): array
    {
        $candidateIds = array_values(array_unique(array_filter(array_column($entries, 'songId'), 'is_string')));
        $songs = $this->media->songsByIds($actor, $candidateIds); $songIds = [];
        foreach ($entries as &$entry) {
            if (!is_string($entry['songId']) || !isset($songs[$entry['songId']])) {
                $entry['songId'] = null; $entry['candidateCount'] = 0; $entry['status'] = 'unmatched';
                $entry['reasonCode'] = 'resource_missing'; continue;
            }
            $songIds[] = $entry['songId'];
        }
        unset($entry);
        $durationMs = array_sum(array_map(static fn (string $id): int => (int) ($songs[$id]['durationMs'] ?? 0), $songIds));
        $ownerId = (string) $actor['id']; $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($durationMs, $entries, $now, $ownerId, $playlistId, $provider, $songIds, $sourceKey): void {
            $row = Db::table('playlists')->where('id', $playlistId)->where('scope', 'system')->where('source', $provider)
                ->where('source_key', $sourceKey)->first(['version']);
            if (!$row instanceof stdClass) throw new PlaylistNotFound('Public system playlist not found.');
            Db::table('playlist_items')->where('playlist_id', $playlistId)->delete();
            Db::table('playlist_import_entries')->where('playlist_id', $playlistId)->delete();
            $items = [];
            foreach ($songIds as $position => $songId) $items[] = ['playlist_id' => $playlistId, 'position' => $position, 'song_id' => $songId, 'added_by_user_id' => $ownerId, 'added_at' => $now];
            if ($items !== []) Db::table('playlist_items')->insert($items);
            $imports = array_map(static fn (array $entry): array => ['playlist_id' => $playlistId, 'position' => $entry['position'], 'status' => $entry['status'], 'song_id' => $entry['songId'], 'candidate_count' => $entry['candidateCount'], 'reason_code' => $entry['reasonCode'], 'source_title' => $entry['sourceTitle'], 'source_artists_json' => json_encode($entry['sourceArtists'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'source_album' => $entry['sourceAlbum'], 'created_at' => $now], $entries);
            Db::table('playlist_import_entries')->insert($imports);
            if (Db::table('playlists')->where('id', $playlistId)->where('version', (int) $row->version)->update(['song_count' => count($songIds), 'duration_ms' => $durationMs, 'version' => (int) $row->version + 1, 'updated_at' => $now]) !== 1) throw new PlaylistConflict('Public system playlist changed during sync.');
        });
        return ['matchedCount' => count($songIds), 'missingCount' => count($entries) - count($songIds), 'totalCount' => count($entries)];
    }

    private function recordSuccess(string $playlistId, string $provider, stdClass $rule): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z'); Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)->where('provider', $provider)->update([
            'last_attempt_at' => $now, 'last_success_at' => $now, 'last_error_code' => null,
            'next_sync_at' => gmdate('Y-m-d\TH:i:s\Z', time() + (int) $rule->interval_seconds), 'updated_at' => $now,
        ]);
    }

    private function recordFailure(string $playlistId, string $provider, string $code, stdClass $rule): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z'); Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)->where('provider', $provider)->update([
            'last_attempt_at' => $now, 'last_error_code' => $code,
            'next_sync_at' => (int) $rule->enabled === 1 ? gmdate('Y-m-d\TH:i:s\Z', time() + (int) $rule->interval_seconds) : null, 'updated_at' => $now,
        ]);
    }

    private function rule(string $playlistId, string $provider): ?stdClass
    {
        $row = Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)->where('provider', $provider)->first();
        return $row instanceof stdClass ? $row : null;
    }

    /** @return array<string,mixed> */
    private function ruleSnapshot(stdClass $rule): array
    {
        return ['provider' => (string) $rule->provider, 'preset' => (string) $rule->preset, 'enabled' => (int) $rule->enabled === 1,
            'intervalSeconds' => (int) $rule->interval_seconds, 'lastAttemptAt' => $rule->last_attempt_at === null ? null : (string) $rule->last_attempt_at,
            'lastSuccessAt' => $rule->last_success_at === null ? null : (string) $rule->last_success_at, 'lastErrorCode' => $rule->last_error_code === null ? null : (string) $rule->last_error_code,
            'nextSyncAt' => $rule->next_sync_at === null ? null : (string) $rule->next_sync_at, 'version' => (int) $rule->version];
    }

    private function assertProvider(string $provider): void
    {
        if (!isset($this->providerMap()[$provider])) throw new PublicPlaylistRecommendationInvalid();
    }

    /** @return array<string,string> */
    private function providerMap(): array
    {
        $providers = $this->providers();
        $map = [];
        foreach ($providers as $provider) {
            if (!is_array($provider) || !is_string($provider['key'] ?? null) || !is_string($provider['name'] ?? null)
                || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $provider['key']) !== 1) continue;
            $map[$provider['key']] = $provider['name'];
        }
        return $map;
    }

    private function providerName(string $provider): string
    {
        return $this->providerMap()[$provider] ?? $provider;
    }
}

final class PublicPlaylistRecommendationInvalid extends \RuntimeException {}
final class PublicPlaylistRecommendationConflict extends \RuntimeException {}
final class PublicPlaylistRecommendationUnavailable extends \RuntimeException {}
