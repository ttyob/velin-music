<?php

declare(strict_types=1);

namespace app\application\Scan;

use RuntimeException;

/**
 * 表示扫描看到空目录，但数据库仍保存可用媒体，因而不能确认本次发现集可信。
 *
 * 该异常专门阻止挂载消失、重复 Worker 使用不同路径命名空间等故障把整库库存标记为缺失。
 * 抛出后扫描任务失败，已提交的单文件观察允许保留，但缺失校准、媒体计数和播放可见性不得改变。
 * 管理员应先检查挂载身份和 Worker 部署，再重新扫描；异常本身不执行文件或数据库补偿。
 */
final class SuspiciousEmptyScan extends RuntimeException
{
}
