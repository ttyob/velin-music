<?php

declare(strict_types=1);

namespace app\application\Artist;

use stdClass;
use support\Db;
use Throwable;

/**
 * 根据艺人资料业务投影判断是否需要调用独立艺人资料插件。
 *
 * 完整资料必须属于目标艺人、包含规范 MusicBrainz UUID、至少一个经过记录的来源，并在 90 天新鲜期
 * 内。简介、地区和百科链接是可选来源事实，不能因为上游合法缺失而形成永久重试。任务表仍只负责活动
 * 租约和失败冷却；即使没有历史任务，只要资料投影完整且新鲜也必须跳过。
 *
 * 本策略只读数据库且无副作用。损坏 JSON、未知字段或缺表均视为需要查询，由任务服务随后应用活动任务
 * 与冷却限制，避免损坏状态永久阻断修复。
 */
final readonly class ArtistProfileScrapePolicy
{
    public const FRESH_SECONDS = 7_776_000;

    /**
     * 返回艺人资料是否缺失、不完整或已经过期。
     *
     * artistId 必须指向现有艺人实体；孤立或已删除 ID 返回 false，避免制造无归属请求。资料缺失、MBID
     * 非规范 UUID、来源 JSON 损坏或为空、刷新时间缺失或超过 90 天均返回 true。方法只读且不创建任务，
     * 活动租约和失败冷却由任务服务在本判断之后执行。
     */
    public function shouldQuery(string $artistId): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_artists')
            || !Db::table('media_artists')->where('id', $artistId)->exists()) return false;
        if (!$schema->hasTable('artist_profiles')) return true;
        /** @var stdClass|null $profile */
        $profile = Db::table('artist_profiles')->where('artist_id', $artistId)
            ->first(['musicbrainz_artist_id', 'sources_json', 'refreshed_at']);
        if (!$profile instanceof stdClass) return true;
        $mbid = is_string($profile->musicbrainz_artist_id) ? strtolower(trim($profile->musicbrainz_artist_id)) : '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $mbid) !== 1) {
            return true;
        }
        try {
            $sources = json_decode((string) $profile->sources_json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return true;
        }
        if (!is_array($sources) || !array_is_list($sources) || $sources === []) return true;
        return !is_string($profile->refreshed_at)
            || $profile->refreshed_at < gmdate('Y-m-d\TH:i:s\Z', time() - self::FRESH_SECONDS);
    }
}
