<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * PluginEventSubscriberHook 允许受信 PHP 插件异步观察核心已经提交的业务事件。
 *
 * 插件必须在 manifest 同时声明 `event_subscriber`，并从 PluginDomainEvent::names() 的版本化白名单中返回
 * 非空、无重复订阅。handle 运行在独立核心 Worker，不在原业务事务或 HTTP 请求内；正常返回即确认，抛出
 * 异常会由 Redis 投递层有界重试。插件必须按 eventId 幂等，不能依赖无限保留或严格一次投递，也不能通过
 * 本钩子直接修改核心数据库、权限事实或媒体文件。
 */
interface PluginEventSubscriberHook extends PhpResourcePlugin
{
    /** @return list<string> 当前插件明确订阅的版本化事件名 */
    public function subscribedEvents(): array;

    /**
     * 处理一条只读通知；成功必须正常返回，临时故障通过异常请求有界重试。
     *
     * 插件不得保存事件中未声明的数据，不得返回核心业务结果。达到最大失败次数后核心会跳过该插件的这条
     * 投递并保留有限期死信摘要，其他插件游标和原业务事实不受影响。
     */
    public function handle(PluginDomainEvent $event): void;
}
