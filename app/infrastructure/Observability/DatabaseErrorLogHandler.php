<?php

declare(strict_types=1);

namespace app\infrastructure\Observability;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Throwable;

/**
 * 把所有 Monolog error 及以上记录旁路写入系统异常聚合表。
 *
 * handler 位于原有文件 handler 之后并保持 bubble；数据库写入失败不会抛出或再次调用 Log，避免异常
 * 处理期间递归。HTTP 请求存在时只读取方法和规范化路径，Worker 日志标为 worker。原文件日志始终保留，
 * 因此后台诊断不是唯一故障证据，也不会改变部署层日志轮转。
 */
final class DatabaseErrorLogHandler extends AbstractProcessingHandler
{
    private bool $recording = false;

    public function __construct()
    {
        parent::__construct(Logger::ERROR, true);
    }

    /** 接收 Monolog 2 数组记录；重入和任何捕获器失败均静默退出。 */
    protected function write(array $record): void
    {
        if ($this->recording) {
            return;
        }
        $this->recording = true;
        try {
            $request = null;
            try {
                $candidate = request();
                $request = $candidate instanceof \Webman\Http\Request ? $candidate : null;
            } catch (Throwable) {
            }
            (new SystemErrorRecorder())->recordLog($record, $request);
        } catch (Throwable) {
        } finally {
            $this->recording = false;
        }
    }
}
