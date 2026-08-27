<?php

declare(strict_types=1);

namespace app\application\Scan;

use support\Db;

/**
 * 清理扫描后已经失去歌曲归属的专辑实体。
 *
 * 该服务只处理数据库实体，不访问、移动或删除音乐文件。调用方必须已经完成一次无错误的完整扫描
 * 对账，并在 SQLite 写入闸门持有的短事务内调用；这样文件缺失校准、歌曲归属变化和专辑删除处于同一
 * 提交边界。专辑只有在不存在任何 media_songs 行时才符合候选条件，不能使用派生 song_count 判断，
 * 因为 missing 或 metadata_pending 歌曲仍然可能保留关联。收藏、手工元数据/封面、实体操作历史和
 * 活动任务会阻止删除；可重建的扫描来源字段和普通专辑关系随 media_albums 的外键级联清理。
 *
 * 删除具有幂等性：重复执行只返回本次实际删除的数量。每个候选在删除前都会再次检查歌曲和保护性
 * 引用，遇到并发写入或外键保护时跳过/失败并由外层扫描事务统一回滚，不会把失败误报为扫描成功。
 */
final class AlbumOrphanCleanupService
{
    /**
     * 删除指定音乐库中可以安全回收的空专辑。
     *
     * 前置条件：调用方已完成可信扫描，且当前处于短写事务和 SQLite 写入闸门内。libraryId 只用于
     * 限定删除范围；本方法不执行权限判断，也不改变歌曲、库存或媒体文件。没有歌曲但仍有收藏、手工
     * 编辑、人工封面、实体合并/拆分历史或 queued/running 任务的专辑会被保留，等待明确的业务操作。
     * 终态任务没有活动执行者，可以随专辑的级联关系一并清理；审计日志不引用专辑外键，仍由扫描审计
     * 记录本次实际清理数量。
     *
     * @return int 本次实际删除的专辑数量
     */
    public function cleanup(string $libraryId): int
    {
        /** @var list<string> $candidateIds */
        $candidateIds = Db::table('media_albums as albums')
            ->where('albums.library_id', $libraryId)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('media_songs as songs')
                    ->whereColumn('songs.album_id', 'albums.id');
            })
            ->pluck('albums.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $deleted = 0;
        foreach ($candidateIds as $albumId) {
            if (!$this->isDeletable($albumId, $libraryId)) {
                continue;
            }

            $deleted += Db::table('media_albums')
                ->where('id', $albumId)
                ->where('library_id', $libraryId)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('media_songs as songs')
                        ->whereColumn('songs.album_id', 'media_albums.id');
                })
                ->delete();
        }

        return $deleted;
    }

    /**
     * 在最终删除前复验不可逆的业务保护条件。
     *
     * 该检查必须和删除位于同一个短事务；它不依赖专辑上的派生计数，也不以“当前页面看不到”为删除
     * 依据。人工字段、人工候选和实体操作历史即使暂时没有歌曲也可能被管理员用于恢复，因此保留；
     * 活动 Worker 仍可能持有任务 ID，删除前必须让任务自然结束。所有查询只返回布尔结果，不读取或
     * 记录用户、路径、图片字节和第三方响应。
     */
    private function isDeletable(string $albumId, string $libraryId): bool
    {
        if (Db::table('media_songs')->where('album_id', $albumId)->exists()) {
            return false;
        }
        if (Db::table('user_album_preferences')->where('album_id', $albumId)->exists()) {
            return false;
        }
        if (Db::table('media_album_metadata_field_states')->where('album_id', $albumId)
            ->where(static function ($query): void {
                $query->whereNotNull('manual_value_json')->orWhere('is_locked', 1);
            })->exists()) {
            return false;
        }
        if (Db::table('media_manual_artwork_candidates')->where('album_id', $albumId)->exists()
            || Db::table('media_artwork_selection_overrides')->where('album_id', $albumId)->exists()
            || Db::table('media_album_artworks')->where('album_id', $albumId)
                ->where('source_kind', 'manual')->exists()) {
            return false;
        }
        if (Db::table('metadata_entity_operations')->where('entity_type', 'album')
            ->where(static function ($query) use ($albumId): void {
                $query->where('source_entity_id', $albumId)
                    ->orWhere('target_entity_id', $albumId)
                    ->orWhere('created_entity_id', $albumId);
            })->exists()) {
            return false;
        }
        if (Db::table('metadata_entity_redirects')->where('entity_type', 'album')
            ->where(static function ($query) use ($albumId): void {
                $query->where('source_entity_id', $albumId)->orWhere('target_entity_id', $albumId);
            })->exists()) {
            return false;
        }

        return !$this->hasActiveTaskReference($albumId, $libraryId);
    }

    /**
     * 判断专辑是否仍被会执行或继续投递的任务引用。
     *
     * 终态任务不会再访问专辑，随实体级联删除不会产生运行时竞争；queued/running 任务必须保留其
     * 稳定目标，避免 Worker 在领取或收口时得到悬空实体。不同任务表由独立迁移创建，查询保持在这里
     * 集中，未来替换数据库时只需迁移这个可替换的存储边界。
     */
    private function hasActiveTaskReference(string $albumId, string $libraryId): bool
    {
        if (Db::table('album_scrape_jobs')->where('album_id', $albumId)
            ->whereIn('status', ['queued', 'running'])->exists()) {
            return true;
        }

        if (Db::table('artwork_provider_search_jobs')->where('album_id', $albumId)
            ->whereIn('status', ['queued', 'running'])->exists()) {
            return true;
        }

        return Db::table('artwork_provider_import_jobs')
            ->where('album_id', $albumId)
            ->where('library_id', $libraryId)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
