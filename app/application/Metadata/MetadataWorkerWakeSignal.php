<?php

declare(strict_types=1);

namespace app\application\Metadata;

/**
 * 定义派生资源 Worker 到元数据 Worker 的可丢失唤醒信号。
 *
 * SQLite 任务表始终是唯一事实，信号只用于缩短下一次领取等待；通知或消费失败不能改变任务终态，也
 * 不能让调用方跳过数据库 CAS。实现不得携带用户、歌曲、target、路径或第三方标识，多个通知允许合并。
 */
interface MetadataWorkerWakeSignal
{
    /** 尽力发布一次无身份唤醒；基础设施不可用时静默退化到周期轮询。 */
    public function notify(): void;

    /** 原子消费当前合并信号；不存在或基础设施不可用时返回 false。 */
    public function consume(): bool;
}
