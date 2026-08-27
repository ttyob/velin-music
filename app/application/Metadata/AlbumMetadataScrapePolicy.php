<?php

declare(strict_types=1);

namespace app\application\Metadata;

use stdClass;
use support\Db;

/**
 * 根据专辑业务事实判断是否需要调用独立专辑插件。
 *
 * 任务状态只表示排队、租约、重试和冷却，不能证明专辑字段完整。本策略先拒绝扫描器为无专辑标签歌曲
 * 创建的“单曲/未知专辑/Unknown Album”占位实体以及未知专辑艺人，再检查发行日期、碟数和 MusicBrainz 发行
 * 身份是否已经存在。任一详情字段缺失时允许入队，字段完整时即使从未创建过任务也跳过查询。
 *
 * 本类只读目录投影，不初始化字段状态、不访问插件或文件。滚动部署表或字段不完整时返回 true，让旧
 * Worker 保持原行为；对象不存在或缺少可查询身份时返回 false，避免使用占位名称扩大第三方请求。
 */
final readonly class AlbumMetadataScrapePolicy
{
    private const PLACEHOLDER_TITLES = ['单曲', '未知专辑', 'Unknown Album'];
    private const PLACEHOLDER_ARTISTS = ['未知艺术家', 'Unknown Artist'];

    /**
     * 返回专辑是否具有可查询身份且仍缺少独立专辑详情。
     *
     * albumId 必须是核心目录中的稳定实体 ID；不存在、占位标题或只有未知专辑艺人的实体返回 false。
     * 对真实专辑，发行日期、正整数碟数和至少一个 MusicBrainz 发行身份任一缺失即返回 true。方法只读
     * 当前业务投影，不读取任务历史、不访问网络；调用方负责再应用唯一活动任务和失败冷却。
     */
    public function shouldQuery(string $albumId): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_albums')) return false;
        $columns = ['id', 'title'];
        foreach (['identity_source_title', 'release_date', 'disc_total',
            'musicbrainz_release_id', 'musicbrainz_release_group_id'] as $column) {
            if ($schema->hasColumn('media_albums', $column)) $columns[] = $column;
        }
        /** @var stdClass|null $album */
        $album = Db::table('media_albums')->where('id', $albumId)->first($columns);
        if (!$album instanceof stdClass) return false;
        $identityTitle = property_exists($album, 'identity_source_title')
            && is_string($album->identity_source_title) && trim($album->identity_source_title) !== ''
            ? trim($album->identity_source_title) : trim((string) $album->title);
        if (in_array($identityTitle, self::PLACEHOLDER_TITLES, true)) return false;

        if ($schema->hasTable('media_album_artists') && $schema->hasTable('media_artists')) {
            /** @var list<string> $artists */
            $artists = Db::table('media_album_artists as links')
                ->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
                ->where('links.album_id', $albumId)->orderBy('links.position')
                ->pluck('artists.name')->map('strval')->all();
            if ($artists === [] || count(array_diff($artists, self::PLACEHOLDER_ARTISTS)) === 0) return false;
        }

        if (!property_exists($album, 'release_date') || !property_exists($album, 'disc_total')
            || !property_exists($album, 'musicbrainz_release_id')
            || !property_exists($album, 'musicbrainz_release_group_id')) {
            return true;
        }
        $hasReleaseDate = is_string($album->release_date) && trim($album->release_date) !== '';
        $hasDiscTotal = (is_int($album->disc_total) || is_numeric($album->disc_total))
            && (int) $album->disc_total > 0;
        $hasExternalIdentity = (is_string($album->musicbrainz_release_id) && trim($album->musicbrainz_release_id) !== '')
            || (is_string($album->musicbrainz_release_group_id) && trim($album->musicbrainz_release_group_id) !== '');
        return !$hasReleaseDate || !$hasDiscTotal || !$hasExternalIdentity;
    }
}
