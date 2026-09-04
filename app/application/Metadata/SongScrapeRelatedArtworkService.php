<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Artwork\ArtworkAdminConflict;
use app\application\Artwork\ArtworkAdminNotFound;
use app\application\Artwork\ArtworkCurrentStateService;
use app\application\Artwork\ArtworkProviderAdminService;
use app\application\Artwork\ArtworkProviderEvidenceService;
use app\application\Auth\CapabilityResolver;
use app\application\Library\LibraryAccessResolver;
use stdClass;
use support\Db;
use Throwable;

/**
 * 把歌曲、专辑和艺人图片作为逐曲刮削的内部资源阶段编排。
 *
 * 图片搜索/导入继续复用原有 Durable Provider Worker，但每个子任务都保存 scrape_target_id；逐曲
 * Worker 只有在关联搜索、选定导入及歌曲封面发布全部终结后才结束当前歌曲。自动模式为缺图实体选择
 * 第一张可靠候选，手动模式只创建候选并等待一次确认提交 opaque candidate ID。单张图片失败会进入
 * 资源明细，不回滚已提交元数据或歌词，也不会允许同批次越过当前歌曲处理下一首。
 */
final readonly class SongScrapeRelatedArtworkService
{
    public function __construct(
        private ArtworkProviderAdminService $artwork = new ArtworkProviderAdminService(),
        private ArtworkProviderEvidenceService $evidence = new ArtworkProviderEvidenceService(),
        private ArtworkCurrentStateService $currentArtwork = new ArtworkCurrentStateService(),
        private CapabilityResolver $capabilities = new CapabilityResolver(),
        private LibraryAccessResolver $libraries = new LibraryAccessResolver(),
        private MetadataScrapePolicyService $scrapePolicy = new MetadataScrapePolicyService(),
    ) {}

    /**
     * 幂等创建当前歌曲的图片查询。
     *
     * 手动模式包含歌曲、专辑和艺人，供同一候选弹窗展示；自动模式的歌曲封面直接复用元数据渠道结果，
     * 因此这里只查询共享专辑/艺人图片。实体已有图片、并发任务或关系变化时保持原值并继续其他实体。
     * 本方法只写任务事实，不访问图片网络；实际查询由 ArtworkProviderWorker 执行。
     */
    public function enqueueQueries(stdClass $target, bool $automatic, bool $includeSong): int
    {
        if (!$this->supportsLinks()) return 0;
        $actor = $this->liveActor((string) $target->requested_by);
        if ($actor === []) return 0;
        $created = 0;
        foreach ($this->entities($target, $includeSong) as $entity) {
            $requestId = 'song-scrape-artwork:' . (string) $target->id . ':' . $entity['key'];
            try {
                // 自动刮削只处理缺失资源；手工搜索仍允许管理员查看新的候选，但确认导入阶段会再次
                // 复验并拒绝覆盖已有扫描图、手工选择图或历史有效 Provider 图。
                if ($automatic && $this->currentArtwork->exists(
                    $entity['type'], $entity['id'], (string) $target->library_id,
                ) && !$this->providerMayReplace($entity['type'])) continue;
                if ($automatic && $this->reusableEmptySearch($target, $entity, $actor) instanceof stdClass) {
                    continue;
                }
                $wasCreated = $automatic
                    ? $this->artwork->createAutomaticSearch(
                        $entity['type'], $entity['id'], (string) $target->library_id, $actor, $requestId,
                        (string) $target->id,
                    )
                    : $this->createManualSearch($target, $entity, $actor, $requestId);
                if ($wasCreated) ++$created;
            } catch (ArtworkAdminConflict|ArtworkAdminNotFound) {
                // 同实体已有独立任务或当前关系/图片发生变化时，以已经存在的业务事实为准。该资源会在
                // 父目标资源摘要中表现为 preserved/unavailable，而不是让整首歌失败。
            }
        }
        return $created;
    }

    /**
     * 复验一次确认中的图片候选确实属于该逐曲目标和实体。
     *
     * 候选必须来自已成功的关联搜索；实体键、候选 ID 和搜索归属全部由联合查询绑定。方法不相信浏览器
     * 提交的 provider、URL 或版本，也不创建导入任务。任一候选过期时整个确认事务失败并继续等待。
     *
     * @param array<string,string> $selections 实体键到 opaque 候选 ID。
     */
    public function validateSelections(string $targetId, array $selections): void
    {
        foreach ($selections as $entityKey => $candidateId) {
            [$type, $entityId] = explode(':', $entityKey, 2);
            $exists = Db::table('artwork_provider_candidates as candidates')
                ->join('artwork_provider_search_jobs as searches', 'searches.id', '=', 'candidates.search_job_id')
                ->where('searches.scrape_target_id', $targetId)->where('searches.status', 'succeeded')
                ->where('searches.' . $type . '_id', $entityId)->where('candidates.id', $candidateId)->exists();
            if (!$exists) throw new MediaMetadataConflict('所选图片候选已经变化。');
        }
    }

    /**
     * 为手动确认的专辑图和艺人图创建精确导入任务。
     *
     * 歌曲封面仍由同一 Metadata Worker 按已选平台结果下载、规范化和发布，避免同一首歌重复导入两份；
     * 专辑与艺人候选则复用图片 Worker。确定幂等键绑定父目标和候选，崩溃重试不会创建第二个导入。
     */
    public function enqueueSelections(stdClass $target, array $selections, bool $strict = false): int
    {
        if (!$this->supportsLinks() || $selections === []) return 0;
        $actor = $this->liveActor((string) $target->requested_by);
        if ($actor === []) return 0;
        $created = 0;
        foreach ($selections as $entityKey => $candidateId) {
            [$type, $entityId] = explode(':', $entityKey, 2);
            if ($type === 'song') continue;
            // 确认阶段也不能让第三方候选替换已有专辑/艺人封面；需要替换时走独立图片管理工作流。
            if ($this->currentArtwork->exists($type, $entityId, (string) $target->library_id)) continue;
            /** @var stdClass|null $search */
            $search = Db::table('artwork_provider_search_jobs as searches')
                ->join('artwork_provider_candidates as candidates', 'candidates.search_job_id', '=', 'searches.id')
                ->where('searches.scrape_target_id', (string) $target->id)->where('searches.status', 'succeeded')
                ->where('searches.' . $type . '_id', $entityId)->where('candidates.id', $candidateId)
                ->first(['searches.id', 'searches.version']);
            if (!$search instanceof stdClass) continue;
            try {
                $this->artwork->createImport(
                    $type, $entityId, (string) $target->library_id, (string) $search->id, $candidateId,
                    (int) $search->version, 'song-scrape-import-' . (string) $target->id . '-' . $candidateId,
                    $actor, 'song-scrape-artwork-import:' . (string) $target->id . ':' . $entityKey,
                    (string) $target->id,
                );
                ++$created;
            } catch (ArtworkAdminConflict|ArtworkAdminNotFound $exception) {
                if ($strict) throw new MediaMetadataConflict('所选图片候选无法进入统一刮削任务。', previous: $exception);
                // 并发人工选择优先。父流程仍会读取已关联子任务或当前图片事实后收口，不覆盖现有选择。
            }
        }
        return $created;
    }

    /**
     * 投影逐曲任务自己的图片候选，不暴露远端 asset ID、摘要或字节。
     *
     * actor 已由逐曲任务服务确认是原发起人且仍有全部库 manage；图片服务会再执行实体级权限和证据复验。
     * 某个实体关系已经变化时只省略该查询，历史子任务仍保留供审计，不影响其他资源展示。
     *
     * @return list<array<string,mixed>>
     */
    public function project(string $targetId, array $actor): array
    {
        if (!$this->supportsLinks()) return [];
        /** @var list<stdClass> $rows */
        $rows = Db::table('artwork_provider_search_jobs')->where('scrape_target_id', $targetId)
            ->orderByRaw("CASE WHEN song_id IS NOT NULL THEN 0 WHEN album_id IS NOT NULL THEN 1 ELSE 2 END")
            ->orderBy('created_at')->get()->all();
        $result = [];
        foreach ($rows as $row) {
            $type = $this->type($row);
            $entityId = $this->entityId($row);
            try {
                $search = $this->artwork->search(
                    $type, $entityId, (string) $row->library_id, (string) $row->id, $actor,
                );
            } catch (Throwable) {
                continue;
            }
            $result[] = [
                'key' => $type . ':' . $entityId,
                'type' => $type,
                'entityId' => $entityId,
                'name' => $this->entityName($type, $entityId),
                'job' => $search['job'],
                'candidates' => $search['candidates'],
            ];
        }
        return $result;
    }

    /**
     * 返回父目标资源阶段的稳定汇总。
     *
     * queued/running 子任务使 active=true；failed/cancelled 只记录资源错误并视为终态。候选为空的成功
     * 搜索是正常 unavailable。返回值不含内部错误正文、远端身份或路径，可直接写入有界任务 JSON。
     *
     * @return array{active:bool,items:list<array<string,mixed>>}
     */
    public function status(string $targetId): array
    {
        if (!$this->supportsLinks()) return ['active' => false, 'items' => []];
        /** @var list<stdClass> $searches */
        $searches = Db::table('artwork_provider_search_jobs')->where('scrape_target_id', $targetId)
            ->orderBy('created_at')->get()->all();
        /** @var list<stdClass> $imports */
        $imports = Db::table('artwork_provider_import_jobs')->where('scrape_target_id', $targetId)
            ->orderBy('created_at')->get()->all();
        $items = [];
        $active = false;
        foreach (array_merge($searches, $imports) as $row) {
            $status = (string) $row->status;
            $active = $active || in_array($status, ['queued', 'running'], true);
            $items[] = [
                'kind' => property_exists($row, 'search_job_id') ? 'import' : 'search',
                'entityType' => $this->type($row),
                'entityId' => $this->entityId($row),
                'status' => $status,
                'errorCode' => $row->error_code === null ? null : (string) $row->error_code,
            ];
        }
        /** @var stdClass|null $target */
        $target = Db::table('metadata_sync_scrape_targets as targets')
            ->join('metadata_sync_scrape_jobs as jobs', 'jobs.id', '=', 'targets.job_id')
            ->where('targets.id', $targetId)->first(['targets.*', 'jobs.requested_by', 'jobs.confirmation_required']);
        if ($target instanceof stdClass && (int) ($target->confirmation_required ?? 1) === 0) {
            $actor = $this->liveActor((string) $target->requested_by);
            foreach ($this->entities($target, false) as $entity) {
                $alreadyLinked = count(array_filter($searches, fn (stdClass $search): bool =>
                    $this->type($search) === $entity['type'] && $this->entityId($search) === $entity['id'])) > 0;
                if ($alreadyLinked) continue;
                if ($this->currentArtwork->exists(
                    $entity['type'], $entity['id'], (string) $target->library_id,
                )) {
                    $items[] = [
                        'kind' => 'search', 'entityType' => $entity['type'], 'entityId' => $entity['id'],
                        'status' => 'preserved', 'errorCode' => null,
                    ];
                    continue;
                }
                $reused = $actor === [] ? null : $this->reusableEmptySearch($target, $entity, $actor);
                if ($reused instanceof stdClass) {
                    $items[] = [
                        'kind' => 'search', 'entityType' => $entity['type'], 'entityId' => $entity['id'],
                        'status' => 'succeeded', 'errorCode' => null,
                    ];
                    continue;
                }
                /** @var stdClass|null $blocking */
                $blocking = Db::table('artwork_provider_search_jobs')
                    ->where($entity['type'] . '_id', $entity['id'])
                    ->where('library_id', (string) $target->library_id)
                    ->whereIn('status', ['queued', 'running'])->first(['status']);
                if (!$blocking instanceof stdClass) continue;
                $active = true;
                $items[] = [
                    'kind' => 'search', 'entityType' => $entity['type'], 'entityId' => $entity['id'],
                    'status' => (string) $blocking->status, 'errorCode' => null,
                ];
            }
        }
        return ['active' => $active, 'items' => $items];
    }

    /** @return list<array{key:string,type:string,id:string}> */
    private function entities(stdClass $target, bool $includeSong): array
    {
        $entities = $includeSong ? [[
            'key' => 'song:' . (string) $target->song_id, 'type' => 'song', 'id' => (string) $target->song_id,
        ]] : [];
        /** @var stdClass|null $song */
        $song = Db::table('media_songs')->where('id', (string) $target->song_id)
            ->where('library_id', (string) $target->library_id)->first(['album_id']);
        if (!$song instanceof stdClass) return $entities;
        $entities[] = ['key' => 'album:' . (string) $song->album_id, 'type' => 'album', 'id' => (string) $song->album_id];
        /** @var list<string> $artists */
        $artists = Db::table('media_song_artists')->where('song_id', (string) $target->song_id)
            ->orderBy('position')->pluck('artist_id')->map('strval')->unique()->values()->all();
        foreach ($artists as $artistId) {
            $entities[] = ['key' => 'artist:' . $artistId, 'type' => 'artist', 'id' => $artistId];
        }
        return $entities;
    }

    /** @param array{key:string,type:string,id:string} $entity */
    private function createManualSearch(stdClass $target, array $entity, array $actor, string $requestId): bool
    {
        $before = Db::table('artwork_provider_search_jobs')->where('scrape_target_id', (string) $target->id)
            ->where($entity['type'] . '_id', $entity['id'])->count();
        $this->artwork->createSearch(
            $entity['type'], $entity['id'], (string) $target->library_id,
            'song-scrape-search-' . (string) $target->id . '-' . $entity['key'], $actor, $requestId,
            (string) $target->id,
        );
        return Db::table('artwork_provider_search_jobs')->where('scrape_target_id', (string) $target->id)
            ->where($entity['type'] . '_id', $entity['id'])->count() > $before;
    }

    /** 专辑图仅在管理员明确选择三方优先时允许越过扫描图；艺人图仍保持只补空缺。 */
    private function providerMayReplace(string $type): bool
    {
        return $type === 'album' && $this->scrapePolicy->providerOverridesMetadata('albumArtwork');
    }

    /**
     * 查找同一自动批次内可复用的前序空图片搜索。
     *
     * 复用范围故意不跨父 job：空结果不是永久负缓存，新批次仍可发现平台后来补充的图片。前序目标必须
     * 已成功收口，搜索也必须由同一账号、同一库以 auto_import 创建并以零候选成功完成；失败、取消、
     * 有候选或仍活动的任务一律不能复用。最后通过实时 EvidenceService 重算实体、代表歌曲、描述字段、
     * locale 与库范围摘要，证据变化时返回 null 并由调用方正常新建查询。查询只读取脱敏任务事实，不
     * 修改旧任务归属；status 会为当前 target 合成 succeeded 终态，使逐曲屏障可观察且可可靠收口。
     *
     * @param array{key:string,type:string,id:string} $entity
     * @param array<string,mixed> $actor
     */
    private function reusableEmptySearch(stdClass $target, array $entity, array $actor): ?stdClass
    {
        if (!property_exists($target, 'job_id') || !property_exists($target, 'position')) return null;
        try {
            $facts = $this->evidence->scoped(
                $entity['type'], $entity['id'], (string) $target->library_id, $actor,
            );
        } catch (ArtworkAdminConflict|ArtworkAdminNotFound) {
            return null;
        }
        /** @var stdClass|null $row */
        $row = Db::table('artwork_provider_search_jobs as searches')
            ->join('metadata_sync_scrape_targets as prior_targets',
                'prior_targets.id', '=', 'searches.scrape_target_id')
            ->where('prior_targets.job_id', (string) $target->job_id)
            ->where('prior_targets.position', '<', (int) $target->position)
            ->where('prior_targets.status', 'succeeded')
            ->where('searches.' . $entity['type'] . '_id', $entity['id'])
            ->where('searches.library_id', (string) $target->library_id)
            ->where('searches.requested_by', (string) $target->requested_by)
            ->where('searches.auto_import', 1)
            ->where('searches.status', 'succeeded')
            ->where('searches.candidate_count', 0)
            ->whereNull('searches.error_code')
            ->where('searches.evidence_sha256', $facts['evidenceSha256'])
            ->orderByDesc('prior_targets.position')
            ->first(['searches.id']);
        return $row instanceof stdClass ? $row : null;
    }

    /** @return array<string,mixed> 重建供两个 Worker 独立复验的实时账号与全部库授权。 */
    private function liveActor(string $userId): array
    {
        /** @var stdClass|null $user */
        $user = Db::table('users')->where('id', $userId)->where('status', 'active')
            ->whereNull('deleted_at')->first(['id', 'is_super_admin']);
        if (!$user instanceof stdClass) return [];
        $super = (int) $user->is_super_admin === 1;
        $capabilities = $this->capabilities->resolve($userId, $super);
        if (!in_array('edit_metadata', $capabilities, true) || !in_array('run_scrape', $capabilities, true)) return [];
        return ['id' => $userId, 'isSuperAdmin' => $super, 'capabilities' => $capabilities,
            'libraries' => $this->libraries->resolve($userId, $super)];
    }

    /** 新迁移未完成时保持旧流水线可运行，但不声称已经具备统一逐曲语义。 */
    private function supportsLinks(): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        return $schema->hasColumn('artwork_provider_search_jobs', 'scrape_target_id')
            && $schema->hasColumn('artwork_provider_import_jobs', 'scrape_target_id');
    }

    private function type(stdClass $row): string
    {
        if (property_exists($row, 'song_id') && $row->song_id !== null) return 'song';
        return $row->album_id === null ? 'artist' : 'album';
    }

    private function entityId(stdClass $row): string
    {
        return (string) ($row->song_id ?? $row->album_id ?? $row->artist_id);
    }

    private function entityName(string $type, string $entityId): string
    {
        return match ($type) {
            'song' => (string) (Db::table('media_songs')->where('id', $entityId)->value('title') ?? ''),
            'album' => (string) (Db::table('media_albums')->where('id', $entityId)->value('title') ?? ''),
            default => (string) (Db::table('media_artists')->where('id', $entityId)->value('name') ?? ''),
        };
    }
}
