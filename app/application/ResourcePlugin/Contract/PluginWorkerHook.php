<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * PluginWorkerHook 定义 PHP 插件耐久后台任务的单步消费合同。
 *
 * 每次调用最多领取一个属于该插件的任务，网络和文件副作用必须发生在数据库短事务之外。实现需提供
 * 崩溃恢复、幂等外部标识和有界重试；核心通用 Worker 每轮动态发现活动插件，首次安装无需重启，开始
 * 卸载后下一轮停止调用。已有任务保持可审计状态，不允许核心用其他插件猜测恢复。
 */
interface PluginWorkerHook extends PhpResourcePlugin
{
    /** 处理至多一个任务；没有可领取任务时返回 false。 */
    public function processNext(string $workerId): bool;
}
