<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * PluginAdminActionHook 为受信插件提供固定、无任意参数的管理员动作入口。
 *
 * 该能力适合缓存刷新、外部同步、诊断、索引重建或提交插件自有后台任务，不要求插件伪装成搜索或下载器。
 * 核心在调用前实时验证 `manage_system`、CSRF、插件状态、数据库版本、动作白名单和显式确认，并在执行前后
 * 写审计。动作不得接收 URL、路径、凭据或浏览器任意 options；耗时操作应只提交插件自有耐久任务。
 */
interface PluginAdminActionHook extends PhpResourcePlugin
{
    /**
     * 返回当前版本公开的固定动作清单。
     *
     * 列表必须稳定、有界、无重复，不能根据当前请求或秘密动态改变。`confirmationRequired` 为 true 时核心
     * 要求管理员输入动作 key；它只防止误触，不替代插件内部的幂等、状态复验和失败补偿。
     *
     * @return list<array{key:string,title:string,description:string,confirmationRequired:bool}>
     */
    public function adminActions(): array;

    /**
     * 执行一个已经由核心动作清单确认的命令。
     *
     * actorId 与 requestId 均由可信请求上下文注入，插件不得接受页面覆盖。相同 requestId 的重放必须返回
     * 同一任务或同一完成结果；若动作包含网络、文件或数据库副作用，插件负责在短事务外执行 I/O，并为
     * 部分失败提供可审计状态。未知动作必须抛出异常，不能回退默认命令。
     *
     * @return array{status:'completed'|'queued',message:string,referenceId:?string}
     */
    public function runAdminAction(string $actionKey, string $actorId, string $requestId): array;
}
