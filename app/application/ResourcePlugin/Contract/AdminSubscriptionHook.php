<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * AdminSubscriptionHook 定义资源插件的后台自动订阅管理边界。
 *
 * 订阅规则、上游筛选、去重和下载任务关联全部属于插件；核心只负责实时后台 Session、manage_system、
 * CSRF、活动包和数据库版本校验。插件不得把 URL、磁力、torrent、InfoHash、Cookie 或物理路径放入
 * 订阅投影，Worker 的自动轮询仍必须通过插件自己的耐久状态机提交下载。
 */
interface AdminSubscriptionHook extends PhpResourcePlugin
{
    /** 返回当前管理员可查看的订阅列表和固定表单选项。 */
    public function subscriptions(array $actor): array;

    /** 创建一条管理员拥有的订阅规则；方法只写规则，不在 HTTP 请求内访问 Jackett 或 qBittorrent。 */
    public function createSubscription(array $payload, array $actor, string $requestId): array;

    /** 使用 expectedVersion 原子更新订阅规则；活动任务继续使用创建时固化的任务快照。 */
    public function updateSubscription(string $subscriptionId, array $payload, array $actor, string $requestId): array;

    /** 删除订阅规则及其发现账本，不删除已经提交的 qBittorrent 任务和已入库媒体。 */
    public function deleteSubscription(string $subscriptionId, array $actor, string $requestId): array;

    /** 请求一次有界立即检查；只登记待执行标记，不在 HTTP 请求内执行外部网络或下载副作用。 */
    public function runSubscription(string $subscriptionId, array $actor, string $requestId): array;

}
