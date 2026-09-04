<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Media\MediaDeletionService;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;

/**
 * 删除管理后台选中的专辑或艺人及其歌曲索引、歌词和刮削派生数据。
 *
 * 专辑删除覆盖该专辑的全部歌曲；艺人删除覆盖其歌曲署名下的歌曲，但只删除因此变为空的专辑，
 * 从而不会因为一个艺人参与合辑而误删其他艺人的歌曲。调用者必须先由 Controller 校验全局
 * `manage_library`，本服务仍按实体当前实际关联的全部活动音乐库复验 manage 范围。歌曲删除分为两条
 * 路径：本地受管文件委托给 MediaDeletionService 原子移入回收区，网络库只删除索引。歌曲级外键负责
 * 清理歌词、元数据、播放关系和普通刮削目标；本服务额外清理刮削历史、封面选择和任务子记录，保留
 * 审计历史但不删除原始相邻歌词/封面文件，也不触碰远程源文件。
 *
 * 删除不是可回滚操作。所有本地文件会在实体关系提交前逐首完成安全移动，因此批量中途发生文件系统
 * 故障时可能留下已进入回收区的前缀歌曲；服务会返回失败而不会声称整批成功。写回方案和进行中的
 * 刮削任务属于受保护并发事实，预检发现它们时失败关闭，不通过强制删除绕过其外键和 Worker 所有权。
 */
final readonly class MetadataEntityDeletionService
{
    private const TYPES = ['artist', 'album'];

    /** 这些引用保存不可变写回证据，删除歌曲前必须阻止而不是静默破坏。 */
    private const PROTECTED_SONG_REFS = [
        'metadata_batch_targets',
        'audio_tag_writeback_batch_targets',
        'audio_tag_writeback_jobs',
        'audio_tag_writeback_plans',
        'lyrics_audio_tag_writeback_batch_targets',
        'lyrics_audio_tag_writeback_jobs',
        'lyrics_audio_tag_writeback_plans',
        'lyrics_writeback_batch_targets',
        'lyrics_writeback_jobs',
        'lyrics_writeback_plans',
    ];

    public function __construct(
        private MediaDeletionService $mediaDeletion = new MediaDeletionService(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 删除一个受管理的专辑或艺人。
     *
     * `confirmation` 必须是服务根据类型固定的确认词，防止普通表单把元数据维护命令误当作删除命令。
     * 删除前会锁定并读取实体的当前歌曲集合、库范围和本地文件身份；本地文件的最终身份检查仍由
     * MediaDeletionService 在每次移动前重复执行。成功后返回删除统计，不返回路径、文件身份或歌词正文。
     * 相同实体在请求重试时已不存在，会按 404 处理，调用方应刷新列表而不是自动重放。
     *
     * @param array<string,mixed> $actor 已通过全局 `manage_library` 能力校验的操作者。
     * @return array{type:string,entityId:string,deletedSongCount:int,deletedAlbumCount:int,deletedScrapeTargetCount:int}
     * @throws MetadataEntityInvalid 类型、ID或确认词无效。
     * @throws MetadataEntityNotFound 实体不存在或其任一关联库不在 manage 范围。
     * @throws MetadataEntityConflict 存在并发刮削、写回方案、文件身份变化或数据库关系冲突。
     */
    public function delete(array $actor, string $type, string $entityId, string $confirmation, string $requestId): array
    {
        $this->validateInput($type, $entityId, $confirmation, $requestId);
        $entity = $this->entity($type, $entityId);
        $libraryIds = $this->entityLibraryIds($type, $entityId);
        $managedIds = $this->managedLibraryIds($actor);
        $activeLibraryCount = $libraryIds === [] ? 0 : Db::table('music_libraries')->whereIn('id', $libraryIds)
            ->where('status', 'active')->count();
        if ($libraryIds === [] || $activeLibraryCount !== count($libraryIds) || array_diff($libraryIds, $managedIds) !== []) {
            throw new MetadataEntityNotFound('元数据实体不存在。');
        }

        /** @var list<stdClass> $songs */
        $songs = Db::table('media_songs as songs')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->whereIn('songs.id', $this->sourceSongIds($type, $entityId))
            ->get(['songs.id', 'songs.library_id', 'libraries.source_type'])->all();
        $songIds = array_values(array_unique(array_map(static fn (stdClass $song): string => (string) $song->id, $songs)));
        $albumIds = $type === 'album'
            ? [$entityId]
            : $this->ids(Db::table('media_album_artists')->where('artist_id', $entityId), 'album_id');

        $targetIds = $this->scrapeTargetIds($songIds);
        $searchJobIds = $this->artworkSearchJobIds($type, [$entityId], $albumIds, $songIds);
        $this->assertNoProtectedReferences($songIds, $targetIds);
        $this->assertNoRunningScrapes($targetIds);
        $this->assertNoRunningArtworkJobs($searchJobIds);
        $this->assertNoRunningEntityScrapes($type, $entityId, $albumIds, $songIds);
        foreach ($songs as $song) {
            if ((string) $song->source_type === 'local') {
                $this->mediaDeletion->assertDeletable($actor, (string) $song->id);
            }
        }

        // 这些子记录对歌曲库存有 RESTRICT 外键，必须在删除本地歌曲及其 inventory 前先解除。
        $deletedTargets = 0;
        Db::transaction(function () use (
            $searchJobIds,
            $songIds,
            $albumIds,
            $entityId,
            $type,
            $targetIds,
            &$deletedTargets,
        ): void {
            $this->deleteArtworkJobs($searchJobIds);
            $this->deleteArtworkSelections($songIds, $albumIds, [$entityId], $type);
            $deletedTargets = $this->deleteScrapeTargets($targetIds);
            $this->deleteSongDerivedRows($songIds);
        });

        $deletedSongs = 0;
        foreach ($songs as $song) {
            if ((string) $song->source_type === 'local') {
                $this->mediaDeletion->delete(
                    $actor,
                    (string) $song->id,
                    $requestId . ':song:' . (string) $song->id,
                );
                ++$deletedSongs;
            }
        }

        $deletedAlbums = 0;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use (
            $actor,
            $type,
            $entity,
            $entityId,
            $songIds,
            $albumIds,
            $searchJobIds,
            $targetIds,
            $now,
            $requestId,
            &$deletedSongs,
            &$deletedAlbums,
            &$deletedTargets,
        ): void {
            $remoteQuery = Db::table('media_songs')->whereIn('id', $songIds);
            $remoteCount = $remoteQuery->delete();
            $deletedSongs += $remoteCount;

            if ($type === 'artist') {
                Db::table('media_album_artists')->where('artist_id', $entityId)->delete();
                foreach ($albumIds as $albumId) {
                    if (!Db::table('media_songs')->where('album_id', $albumId)->exists()) {
                        $deletedAlbums += Db::table('media_albums')->where('id', $albumId)->delete();
                    }
                }
                $deleted = Db::table('media_artists')->where('id', $entityId)->delete();
            } else {
                $deleted = Db::table('media_albums')->where('id', $entityId)->delete();
                $deletedAlbums += $deleted;
            }
            if ($deleted !== 1) throw new MetadataEntityConflict('实体状态已经变化。');

            $this->audit->record(
                (string) $actor['id'],
                'metadata.entity.delete',
                $type,
                $entityId,
                'success',
                $requestId,
                [
                    'deletedSongs' => $deletedSongs,
                    'deletedAlbums' => $deletedAlbums,
                    'deletedScrapeTargets' => $deletedTargets,
                    'entityName' => (string) ($type === 'artist' ? $entity->name : $entity->title),
                ],
            );
        });

        return [
            'type' => $type,
            'entityId' => $entityId,
            'deletedSongCount' => $deletedSongs,
            'deletedAlbumCount' => $deletedAlbums,
            'deletedScrapeTargetCount' => $deletedTargets,
        ];
    }

    /** @throws MetadataEntityInvalid */
    private function validateInput(string $type, string $entityId, string $confirmation, string $requestId): void
    {
        if (!in_array($type, self::TYPES, true) || !Ulid::isValid($entityId)
            || $requestId === '' || $confirmation !== 'DELETE ' . strtoupper($type)) {
            throw new MetadataEntityInvalid('删除命令无效。');
        }
    }

    /** @throws MetadataEntityNotFound */
    private function entity(string $type, string $id): stdClass
    {
        $row = Db::table($type === 'artist' ? 'media_artists' : 'media_albums')->where('id', $id)->first();
        if (!$row instanceof stdClass) throw new MetadataEntityNotFound('元数据实体不存在。');
        return $row;
    }

    /** 艺人只删除其歌曲署名集合；专辑删除该专辑的全部歌曲。 */
    private function sourceSongIds(string $type, string $entityId): array
    {
        $query = $type === 'artist'
            ? Db::table('media_song_artists')->where('artist_id', $entityId)
            : Db::table('media_songs')->where('album_id', $entityId);
        return $this->ids($query, $type === 'artist' ? 'song_id' : 'id');
    }

    /** 艺人同时可能只存在于专辑署名关系中，这些空关系也必须纳入最终实体清理。 */
    private function entityLibraryIds(string $type, string $id): array
    {
        if ($type === 'album') {
            return array_values(array_filter([
                Db::table('media_albums')->where('id', $id)->value('library_id'),
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''));
        }
        $songs = Db::table('media_song_artists as credits')->join('media_songs as songs', 'songs.id', '=', 'credits.song_id')
            ->where('credits.artist_id', $id)->pluck('songs.library_id')->all();
        $albums = Db::table('media_album_artists as credits')->join('media_albums as albums', 'albums.id', '=', 'credits.album_id')
            ->where('credits.artist_id', $id)->pluck('albums.library_id')->all();
        return array_values(array_unique(array_map('strval', array_merge($songs, $albums))));
    }

    /** @return list<string> */
    private function managedLibraryIds(array $actor): array
    {
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage' && is_string($library['id'] ?? null)) {
                $ids[] = $library['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /** 读取歌曲对应的刮削 target，歌曲删除后 target 的外键会被置空，所以必须提前保存 ID。 */
    private function scrapeTargetIds(array $songIds): array
    {
        if ($songIds === [] || !Db::connection()->getSchemaBuilder()->hasTable('metadata_sync_scrape_targets')) return [];
        return $this->ids(Db::table('metadata_sync_scrape_targets')->whereIn('song_id', $songIds), 'id');
    }

    /** 预读取封面搜索及导入任务，避免歌曲外键级联后无法清理被 RESTRICT 的搜索任务。 */
    private function artworkSearchJobIds(string $type, array $entityIds, array $albumIds, array $songIds): array
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('artwork_provider_search_jobs')) return [];
        $query = Db::table('artwork_provider_search_jobs');
        $query->where(function (Builder $where) use ($type, $entityIds, $albumIds, $songIds): void {
            if ($songIds !== []) $where->whereIn('song_id', $songIds)->orWhereIn('evidence_song_id', $songIds);
            if ($type === 'album' && $albumIds !== []) $where->orWhereIn('album_id', $albumIds);
            if ($type === 'artist' && $entityIds !== []) $where->orWhereIn('artist_id', $entityIds);
        });
        return $this->ids($query, 'id');
    }

    /** 受保护引用不允许被实体删除静默破坏；SQLite/MySQL 均在应用层先给出稳定冲突。 */
    private function assertNoProtectedReferences(array $songIds, array $targetIds): void
    {
        if ($songIds === []) return;
        $schema = Db::connection()->getSchemaBuilder();
        foreach (self::PROTECTED_SONG_REFS as $table) {
            if ($schema->hasTable($table) && Db::table($table)->whereIn('song_id', $songIds)->exists()) {
                throw new MetadataEntityConflict('歌曲存在未完成的写回方案，请先完成或取消任务。');
            }
        }
        if ($targetIds !== [] && $schema->hasTable('metadata_sync_scrape_targets')
            && Db::table('metadata_sync_scrape_targets')->whereIn('id', $targetIds)->where(function (Builder $query): void {
                $query->whereIn('status', ['pending', 'running']);
                if (Db::connection()->getSchemaBuilder()->hasColumn('metadata_sync_scrape_targets', 'awaiting_confirmation')) {
                    $query->orWhere('awaiting_confirmation', 1);
                }
            })->exists()) {
            throw new MetadataEntityConflict('歌曲存在进行中的刮削任务，请等待任务结束后再删除。');
        }
    }

    /** 从刮削目标读取当前任务状态；运行中的 Worker 不能和删除同时拥有同一业务事实。 */
    private function assertNoRunningScrapes(array $targetIds): void
    {
        if ($targetIds === []) return;
        $schema = Db::connection()->getSchemaBuilder();
        if ($schema->hasTable('metadata_sync_scrape_targets') && $schema->hasColumn('metadata_sync_scrape_targets', 'worker_id')
            && Db::table('metadata_sync_scrape_targets')->whereIn('id', $targetIds)->whereNotNull('worker_id')->exists()) {
            throw new MetadataEntityConflict('刮削任务正在执行，请稍后再删除。');
        }
    }

    /** 封面搜索/导入也由独立 Worker 持有；运行中任务必须先结束，防止并发写回已删除实体。 */
    private function assertNoRunningArtworkJobs(array $searchJobIds): void
    {
        if ($searchJobIds === []) return;
        $schema = Db::connection()->getSchemaBuilder();
        if ($schema->hasTable('artwork_provider_search_jobs')
            && Db::table('artwork_provider_search_jobs')->whereIn('id', $searchJobIds)
                ->whereIn('status', ['queued', 'running'])->exists()) {
            throw new MetadataEntityConflict('封面搜索任务正在执行，请稍后再删除。');
        }
        if ($schema->hasTable('artwork_provider_import_jobs')
            && Db::table('artwork_provider_import_jobs')->whereIn('search_job_id', $searchJobIds)
                ->whereIn('status', ['queued', 'running'])->exists()) {
            throw new MetadataEntityConflict('封面导入任务正在执行，请稍后再删除。');
        }
    }

    /** 拦截专辑、艺人资料和实体刮削 Worker 的活动租约，避免旧任务在删除后继续写实体。 */
    private function assertNoRunningEntityScrapes(string $type, string $entityId, array $albumIds, array $songIds): void
    {
        $schema = Db::connection()->getSchemaBuilder();
        if ($type === 'album' && $schema->hasTable('album_scrape_jobs')
            && Db::table('album_scrape_jobs')->whereIn('album_id', $albumIds)
                ->whereIn('status', ['queued', 'running'])->exists()) {
            throw new MetadataEntityConflict('专辑刮削任务正在执行，请稍后再删除。');
        }
        if ($type === 'artist' && $schema->hasTable('artist_profile_scrape_jobs')
            && Db::table('artist_profile_scrape_jobs')->where('artist_id', $entityId)
                ->whereIn('status', ['queued', 'running'])->exists()) {
            throw new MetadataEntityConflict('艺人资料刮削任务正在执行，请稍后再删除。');
        }
        if ($songIds !== [] && $schema->hasTable('metadata_entity_scrape_intents')
            && Db::table('metadata_entity_scrape_intents')->whereIn('song_id', $songIds)
                ->whereIn('status', ['queued', 'running'])->exists()) {
            throw new MetadataEntityConflict('实体刮削任务正在执行，请稍后再删除。');
        }
    }

    /** 清理封面任务的受限子表后再删除搜索任务；只使用预读取的内部 ID，不接受浏览器传入。 */
    private function deleteArtworkJobs(array $searchJobIds): void
    {
        if ($searchJobIds === []) return;
        $schema = Db::connection()->getSchemaBuilder();
        if ($schema->hasTable('artwork_provider_import_jobs')) {
            Db::table('artwork_provider_import_jobs')->whereIn('search_job_id', $searchJobIds)->delete();
        }
        if ($schema->hasTable('artwork_provider_candidates')) {
            Db::table('artwork_provider_candidates')->whereIn('search_job_id', $searchJobIds)->delete();
        }
        if ($schema->hasTable('artwork_provider_search_jobs')) {
            Db::table('artwork_provider_search_jobs')->whereIn('id', $searchJobIds)->delete();
        }
    }

    /** 先去掉候选引用，才能让歌曲/实体级封面候选按外键级联删除，避免留下错误的手工封面投影。 */
    private function deleteArtworkSelections(array $songIds, array $albumIds, array $entityIds, string $type): void
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_artwork_selection_overrides')) return;
        foreach ([
            'song_id' => $songIds,
            'album_id' => $albumIds,
            $type . '_id' => $entityIds,
        ] as $column => $ids) {
            if ($ids !== [] && $schema->hasColumn('media_artwork_selection_overrides', $column)) {
                Db::table('media_artwork_selection_overrides')->whereIn($column, $ids)->delete();
            }
        }
    }

    /**
     * 显式删除歌曲级可重建派生数据，保持旧迁移或外键暂未启用时也不会留下歌词孤儿记录。
     *
     * 生产 schema 已为多数表声明 `ON DELETE CASCADE`，这里仍按固定白名单先删子表，原因是 SQLite 兼容
     * 升级、历史测试库和外键临时关闭时不能把“级联”当成业务合同。该白名单不包含原始音频、相邻文件或
     * 用户播放历史的外部文件；它只移除可由歌曲重新扫描/刮削建立的数据库投影。写回方案在更早的预检
     * 阶段已被拒绝，因此不会通过这一步绕过不可变证据保护。
     */
    private function deleteSongDerivedRows(array $songIds): void
    {
        if ($songIds === []) return;
        $schema = Db::connection()->getSchemaBuilder();
        foreach ([
            'media_lyrics_primary_selections',
            'media_lyrics_parse_diagnostics',
            'media_lyrics',
            'media_metadata_field_states',
            'media_song_artists',
            'media_song_genres',
            'media_tag_snapshots',
            'media_manual_artwork_candidates',
            'library_scan_file_results',
        ] as $table) {
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'song_id')) {
                Db::table($table)->whereIn('song_id', $songIds)->delete();
            }
        }
    }

    /** 删除 target、渠道响应和发布账本，并重新计算仍含其他歌曲的父刮削任务。 */
    private function deleteScrapeTargets(array $targetIds): int
    {
        if ($targetIds === []) return 0;
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('metadata_sync_scrape_targets')) return 0;
        $jobIds = $this->ids(Db::table('metadata_sync_scrape_targets')->whereIn('id', $targetIds), 'job_id');
        if ($schema->hasTable('metadata_sync_scrape_channel_results')) {
            Db::table('metadata_sync_scrape_channel_results')->whereIn('target_id', $targetIds)->delete();
        }
        if ($schema->hasTable('scrape_asset_publications')) {
            Db::table('scrape_asset_publications')->whereIn('scrape_target_id', $targetIds)->delete();
        }
        $deleted = Db::table('metadata_sync_scrape_targets')->whereIn('id', $targetIds)->delete();
        foreach ($jobIds as $jobId) {
            $remaining = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)->count();
            if ($remaining === 0) {
                if ($schema->hasTable('metadata_sync_scrape_jobs')) {
                    Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->delete();
                }
                continue;
            }
            if (!$schema->hasTable('metadata_sync_scrape_jobs')) continue;
            $aggregate = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)->selectRaw(
                'COUNT(*) as target_count, SUM(CASE WHEN status IN (\'succeeded\', \'unmatched\', \'failed\') THEN 1 ELSE 0 END) as processed_count, '
                . 'SUM(CASE WHEN status = \'succeeded\' THEN 1 ELSE 0 END) as succeeded_count, '
                . 'SUM(CASE WHEN status = \'unmatched\' THEN 1 ELSE 0 END) as unmatched_count, '
                . 'SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failed_count',
            )->first();
            $targetCount = (int) $aggregate->target_count;
            $processed = (int) $aggregate->processed_count;
            $terminal = $processed === $targetCount;
            Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->update([
                'status' => $terminal ? ((int) $aggregate->succeeded_count === $targetCount ? 'succeeded' : 'partial') : 'running',
                'target_count' => $targetCount,
                'processed_count' => $processed,
                'succeeded_count' => (int) $aggregate->succeeded_count,
                'unmatched_count' => (int) $aggregate->unmatched_count,
                'failed_count' => (int) $aggregate->failed_count,
                'finished_at' => $terminal ? gmdate('Y-m-d\TH:i:s\Z') : null,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }
        return $deleted;
    }

    /** @return list<string> */
    private function ids(Builder $query, string $column): array
    {
        return array_values(array_unique(array_map('strval', $query->pluck($column)->all())));
    }
}
