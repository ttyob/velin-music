<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * PluginDatabaseBaselineCollapse 声明插件可以把已发布的历史数据库账本收敛到新的基线版本。
 *
 * 该能力只允许明确实现此合同的插件使用，不能由核心对所有插件放宽“数据库版本高于代码”保护。
 * 实现必须保证 installDatabase 对声明支持的旧版本执行最终 schema 的幂等校验，不删除业务数据、凭据
 * 或任务，并在同一生命周期事务中由核心把账本写成当前 databaseVersion。未知或未来版本必须返回 false，
 * 让核心继续失败关闭，避免降级代码误读新结构。
 */
interface PluginDatabaseBaselineCollapse extends PluginDatabaseLifecycle
{
    /** 返回给定历史账本是否属于本插件已验证的最终基线兼容范围。 */
    public function supportsLegacyDatabaseVersion(int $fromVersion): bool;
}
