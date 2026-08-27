<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * PluginTranscodeWorkerHook 定义下载完成后的独立媒体发布消费合同。
 *
 * 下载 Worker 只负责提交来源、轮询正文并把任务原子切换到 importing/publishing；真正的文件发布、
 * 标签处理和 FFmpeg 转码由本钩子在独立进程中执行。实现必须先用状态 CAS 领取任务，再在事务外执行
 * 文件副作用，并在成功或失败时以同一任务 ID 收口，保证多个进程并行时不会重复发布。崩溃后的租约
 * 恢复由插件自己的状态机负责，核心不会猜测插件表或路径。
 */
interface PluginTranscodeWorkerHook extends PhpResourcePlugin
{
    /**
     * 处理至多一个已经下载完成的媒体发布任务。
     *
     * 没有可领取任务返回 false；调用方会在多个独立进程中并行调用。插件应在外部 FFmpeg/文件操作前
     * 持久化 worker_id 和 heartbeat，并在成功提交后清理租约。该方法不得把任务回退到下载阶段。
     */
    public function processTranscodeNext(string $workerId): bool;
}
