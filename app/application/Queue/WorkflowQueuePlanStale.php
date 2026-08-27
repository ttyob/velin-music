<?php

declare(strict_types=1);

namespace app\application\Queue;

use RuntimeException;

/**
 * 表示队列清理预览与执行时的授权范围、对象状态或依赖关系已经发生变化。
 *
 * 清理命令必须针对管理员刚刚预览过的精确对象集合执行；一旦计划摘要不同，调用方只能重新预览，
 * 不能自动扩大到后来进入队列的记录。该异常是并发控制结果，不代表数据库或 Worker 故障。
 */
final class WorkflowQueuePlanStale extends RuntimeException
{
}
