<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use RuntimeException;

/**
 * 表示没有可用插件，或选定插件未能安全完成搜索、租约消费或任务投影。
 *
 * 该异常映射为稳定 503 且不公开前序异常正文。下载插件可能已经在抛错前耐久创建任务，因此调用方只能
 * 使用同一 leaseId 幂等重试；不能假定失败意味着没有副作用，也不能自动签发新租约制造重复任务。
 */
final class ExternalMusicUnavailable extends RuntimeException
{
}
