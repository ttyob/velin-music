<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalMusicCompletionHook 定义“按身份补全一首歌”的插件钩子。
 *
 * 核心只传递已经由主程序固定 OpenCC 转为简体的歌名、艺人、可选专辑和已经授权的目标库；插件必须在
 * 自身边界内对返回候选的同一身份字段执行相同简体转换，再完成搜索、租约选择和下载入库，不得把搜索
 * 列表、URL、平台 ID 或 Cookie 返回给调用方。completeMusic 返回的 accepted 只代表
 * 耐久任务已经创建，只有 completionStatus 返回 succeeded 才代表媒体已经成功发布；queued/running 和
 * failed 都不能被核心误判为成功。任务状态可以包含有限的脱敏阶段日志，日志不得携带上游正文或秘密。
 */
interface ExternalMusicCompletionHook extends PhpResourcePlugin
{
    /**
     * 按歌曲身份创建一个插件内部补全任务。
     *
     * @param array<string,mixed> $request 精确包含 title、artist、album?、libraryId
     * @param array<string,mixed> $actor 当前管理员身份；插件必须再次校验目标库权限
     * @return array{taskId:string,accepted:true,status:'queued'|'running',progress:int}
     */
    public function completeMusic(array $request, array $actor, string $requestId): array;

    /**
     * 返回单个补全任务的最终一致性投影。
     *
     * status 只有 succeeded 才是成功；logs 仅允许该 taskId 关联的脱敏进度事件。
     *
     * @param array<string,mixed> $actor 当前管理员身份
     * @return array{taskId:string,status:'queued'|'running'|'succeeded'|'failed',progress:int,errorCode:?string,logs:list<array<string,mixed>>}
     */
    public function completionStatus(string $taskId, array $actor): array;
}
