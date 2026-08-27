<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalMusicSearchHook 定义第三方音乐资源搜索钩子。
 *
 * 搜索词必须在调用前完成核心固定端点的 Session、能力和 CSRF 校验：插件私有管理端点要求
 * `manage_system`，统一领域端点要求 `manage_library`。插件仍需执行长度与编码复验。返回项目只含展示
 * 事实与随机租约 ID；平台资源 ID、torrent、磁力、下载 URL、Cookie 和签名上下文必须留在服务端加密
 * 租约中。搜索失败不得留下半成品任务，也不得把原始上游异常传播到 Web API。
 */
interface ExternalMusicSearchHook extends PhpResourcePlugin
{
    /** 返回插件自己的脱敏配置和连接状态；读取可以探测网络，但不得写设置。 */
    public function searchStatus(): array;

    /** 返回当前版本的脱敏配置；秘密只允许投影为 configured 布尔值。 */
    public function searchConfiguration(): array;

    /**
     * 使用乐观版本锁更新搜索源配置。
     *
     * payload 字段白名单由插件固定；实现必须在短事务内保存设置和审计，网络探测必须位于事务外。
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $actor
     */
    public function updateSearchConfiguration(array $payload, array $actor, string $requestId): array;

    /**
     * 执行一次有界搜索并签发 actor 绑定的服务端租约。
     *
     * 核心统一端点会再次把数组转换为固定 DTO 并丢弃未知字段；插件不得依赖未知字段可到达客户端。
     *
     * @return array{query:string,total:int,limited:bool,items:list<array<string,mixed>>}
     */
    public function search(string $query, int $limit, string $actorId): array;
}
