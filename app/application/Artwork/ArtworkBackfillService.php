<?php

declare(strict_types=1);

namespace app\application\Artwork;

use app\application\Auth\CapabilityResolver;
use app\application\Library\LibraryAccessResolver;
use stdClass;
use support\Db;

/**
 * 按小批次为当前可见且确实缺图的专辑和艺人创建自动 Provider 搜索。
 *
 * 本服务只编排持久任务，不在调用线程访问第三方或处理图片。每轮优先专辑再处理艺人，避免一次扫描
 * 把整个曲库同时压入网络队列；实际搜索、下载、规范化和选择仍由单消费者逐步完成。自动任务使用
 * 实体证据摘要派生幂等键，同一证据即使每十秒被重新发现也只创建一次；歌曲或元数据变化后才允许
 * 新一轮查询。没有活动超级管理员时保持无副作用，不能伪造不存在的审计主体。
 */
final readonly class ArtworkBackfillService
{
    public function __construct(
        private ArtworkProviderAdminService $provider = new ArtworkProviderAdminService(),
        private CapabilityResolver $capabilities = new CapabilityResolver(),
        private LibraryAccessResolver $libraries = new LibraryAccessResolver(),
    ) {}

    /**
     * 最多创建 limit 个缺图任务，返回实际新增数量。
     *
     * 候选查询只覆盖 active 音乐库中 available/ready 的歌曲关系，并排除仍由 available 库存锚定的
     * 本地扫描图与任何已有选择。陈旧图片索引若指向 missing/已删除库存，读取端必然无法复验身份，
     * 因而必须视作缺图；Provider 选择只覆盖数据库投影，不删除陈旧索引或媒体文件。查询与建任务之间
     * 若管理员上传图片，后续自动导入仍会二次检查并放弃选择，因此这里无需长事务或 SQLite 写锁。
     * 单个实体的并发冲突只跳过该实体，不能阻塞同批其他缺图对象。
     */
    public function enqueueMissing(int $limit = 8): int
    {
        $limit = max(1, min(50, $limit));
        $actor = $this->automationActor();
        if ($actor === null) return 0;

        $created = 0;
        foreach ($this->missingTargets() as $target) {
            if ($created >= $limit) break;
            try {
                if ($this->provider->createAutomaticSearch(
                    (string) $target->entity_type,
                    (string) $target->entity_id,
                    (string) $target->library_id,
                    $actor,
                    'system-artwork-backfill-' . bin2hex(random_bytes(8)),
                )) {
                    ++$created;
                }
            } catch (ArtworkAdminConflict|ArtworkAdminNotFound) {
                // 手工任务、并发扫描或刚失效的实体优先；下一轮会从最新目录事实重新判断。
            }
        }
        return $created;
    }

    /** @return list<stdClass> 专辑按标题、艺人按名称稳定排序，便于积压期间可预测推进。 */
    private function missingTargets(): array
    {
        /** @var list<stdClass> $albums */
        $albums = Db::table('media_albums as albums')
            ->join('media_songs as songs', 'songs.album_id', '=', 'albums.id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'albums.library_id')
            ->leftJoin('media_album_artworks as local_artwork', 'local_artwork.album_id', '=', 'albums.id')
            ->leftJoin('library_file_inventory as local_artwork_source', function ($join): void {
                $join->on('local_artwork_source.id', '=', 'local_artwork.source_inventory_file_id')
                    ->where('local_artwork_source.status', 'available');
            })
            ->leftJoin('media_artwork_selection_overrides as selected', 'selected.album_id', '=', 'albums.id')
            ->leftJoin('artwork_provider_search_jobs as prior_search', function ($join): void {
                $join->on('prior_search.album_id', '=', 'albums.id')
                    ->on('prior_search.library_id', '=', 'albums.library_id')
                    ->where('prior_search.auto_import', 1);
            })
            ->where('libraries.status', 'active')->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')->whereNull('local_artwork_source.id')->whereNull('selected.id')
            ->groupBy('albums.id', 'albums.library_id', 'albums.title', 'albums.updated_at')
            ->havingRaw('MAX(prior_search.created_at) IS NULL OR MAX(prior_search.created_at) < albums.updated_at'
                . ' OR MAX(prior_search.created_at) < MAX(songs.updated_at)')
            ->orderBy('albums.title')->limit(250)->get([
                Db::raw("'album' AS entity_type"), 'albums.id as entity_id', 'albums.library_id',
            ])->all();
        /** @var list<stdClass> $artists */
        $artists = Db::table('media_artists as artists')
            ->join('media_song_artists as credits', 'credits.artist_id', '=', 'artists.id')
            ->join('media_songs as songs', 'songs.id', '=', 'credits.song_id')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->leftJoin('media_artist_artworks as local_artwork', function ($join): void {
                $join->on('local_artwork.artist_id', '=', 'artists.id')
                    ->on('local_artwork.library_id', '=', 'songs.library_id');
            })
            ->leftJoin('library_file_inventory as local_artwork_source', function ($join): void {
                $join->on('local_artwork_source.id', '=', 'local_artwork.source_inventory_file_id')
                    ->where('local_artwork_source.status', 'available');
            })
            ->leftJoin('media_artwork_selection_overrides as selected', function ($join): void {
                $join->on('selected.artist_id', '=', 'artists.id')
                    ->on('selected.library_id', '=', 'songs.library_id');
            })
            ->leftJoin('artwork_provider_search_jobs as prior_search', function ($join): void {
                $join->on('prior_search.artist_id', '=', 'artists.id')
                    ->on('prior_search.library_id', '=', 'songs.library_id')
                    ->where('prior_search.auto_import', 1);
            })
            ->where('libraries.status', 'active')->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')->whereNull('local_artwork_source.id')->whereNull('selected.id')
            ->groupBy('artists.id', 'songs.library_id', 'artists.name', 'artists.updated_at')
            ->havingRaw('MAX(prior_search.created_at) IS NULL OR MAX(prior_search.created_at) < artists.updated_at'
                . ' OR MAX(prior_search.created_at) < MAX(songs.updated_at)'
                . ' OR MAX(prior_search.created_at) < MAX(albums.updated_at)')
            ->orderBy('artists.name')->limit(250)->get([
                Db::raw("'artist' AS entity_type"), 'artists.id as entity_id', 'songs.library_id',
            ])->all();
        return array_merge($albums, $artists);
    }

    /**
     * 使用真实活动超级管理员作为可审计主体，并在 Worker 执行时再次重建权限。
     *
     * 自动补图是全库维护，普通 manage 用户不应被后台擅自借用；超级管理员被停用或删除后，已排队
     * 任务会由 Worker 失败关闭。这里只生成权限快照，不绕过后续实体范围和 capability 复验。
     *
     * @return array<string,mixed>|null
     */
    private function automationActor(): ?array
    {
        /** @var stdClass|null $user */
        $user = Db::table('users')->leftJoin('user_preferences as preferences', 'preferences.user_id', '=', 'users.id')
            ->where('users.status', 'active')->where('users.is_super_admin', 1)->whereNull('users.deleted_at')
            ->orderBy('users.id')->first([
                'users.id', 'users.locale', 'preferences.locale as preference_locale',
            ]);
        if (!$user instanceof stdClass) return null;
        $userId = (string) $user->id;
        $capabilities = $this->capabilities->resolve($userId, true);
        if (!in_array('edit_metadata', $capabilities, true) || !in_array('run_scrape', $capabilities, true)) {
            return null;
        }
        return [
            'id' => $userId, 'isSuperAdmin' => true, 'capabilities' => $capabilities,
            'libraries' => $this->libraries->resolve($userId, true),
            'preferences' => ['locale' => (string) ($user->preference_locale ?? $user->locale)],
        ];
    }
}
