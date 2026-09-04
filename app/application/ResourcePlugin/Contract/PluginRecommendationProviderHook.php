<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * PluginRecommendationProviderHook 定义推荐与缺失歌曲详情的只读插件边界。
 *
 * 插件必须同时声明 `recommendation_provider`。所有歌曲输入和输出均使用平台无关身份；实现不得返回
 * 平台 ID、播放/下载地址、凭据、物理路径或原始响应，也不得修改核心业务数据库、媒体文件、播放历史
 * 或歌单。核心会对结果重新执行结构校验、本地匹配与当前账号音乐库授权，插件返回本地 ID 不会被接受。
 * 单次网络或协议失败可以抛出异常，由核心隔离并对已有本地推荐降级，不得阻断播放和媒体详情读取。
 */
interface PluginRecommendationProviderHook extends PhpResourcePlugin
{
    /**
     * 返回每日歌曲身份。
     *
     * `$seeds` 是核心从当前账号已授权媒体中选出的有界偏好样本，不含用户 ID、播放时间或次数；插件可
     * 忽略它。结果最多为 `$limit` 项，方法只读且相同输入允许随上游每日内容变化。
     *
     * @param list<RecommendationSongIdentity> $seeds
     * @return list<RecommendationSongIdentity>
     */
    public function dailyRecommendations(array $seeds, int $limit): array;

    /** @return list<RecommendationSongIdentity> 与种子不同的有界歌曲身份列表。 */
    public function similarSongs(RecommendationSongIdentity $seed, int $limit): array;

    /**
     * 返回相似艺人显示身份；每项只允许 `name`，不得包含平台 ID、URL 或第三方资料对象。
     *
     * @return list<array{name:string}>
     */
    public function similarArtists(string $artistName, int $limit): array;

    /**
     * 返回推荐歌单及有界歌曲身份。`key` 必须是插件生成的不可逆摘要，仅用于一次响应内稳定区分项目。
     *
     * @return list<array{key:string,title:string,description:string,songs:list<RecommendationSongIdentity>}>
     */
    public function recommendedPlaylists(int $limit): array;

    /**
     * 查询尚未入库歌曲的安全描述详情。
     *
     * 返回 null 表示正常无匹配；非空结果只允许通用描述字段和封面/歌词存在性布尔值，不返回歌词正文、
     * 封面 URL、平台定位符或可播放资源。方法不得自动创建下载、补全或刮削任务。
     *
     * @return array<string,mixed>|null
     */
    public function missingSongDetail(RecommendationSongIdentity $identity): ?array;
}
