<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * TypedExternalMusicSearchHook 为统一外部搜索声明可验证的资源类型能力。
 *
 * 该接口是 ExternalMusicSearchHook 的向后兼容扩展：尚未升级的插件继续只按 `track` 调用旧 search，
 * 不会因核心增加参数而在长驻 PHP 进程中发生签名冲突。实现必须返回固定 `track|album` 子集，并在
 * searchTyped 中再次拒绝未声明类型；核心会把请求类型写入固定结果 DTO，不能根据标题猜测专辑或单曲。
 */
interface TypedExternalMusicSearchHook extends ExternalMusicSearchHook
{
    /**
     * 返回插件真实支持的搜索类型。
     *
     * 返回值必须非空、无重复且保持插件希望展示的顺序，只允许 `track|album`。该方法不能访问网络、
     * 数据库秘密或创建租约，目录读取失败时核心会让插件退出可搜索提供方集合而不是扩大能力。
     *
     * @return non-empty-list<'track'|'album'>
     */
    public function searchTypes(): array;

    /**
     * 按明确资源类型执行搜索并签发 actor 绑定租约。
     *
     * resourceType 必须属于 searchTypes；插件不能把不支持的专辑请求降级为单曲，也不能用标题启发式
     * 改写类型。返回值继续受核心固定 DTO、响应上限和敏感字段裁剪约束。
     *
     * @return array{query:string,total:int,limited:bool,items:list<array<string,mixed>>}
     */
    public function searchTyped(string $resourceType, string $query, int $limit, string $actorId): array;
}
