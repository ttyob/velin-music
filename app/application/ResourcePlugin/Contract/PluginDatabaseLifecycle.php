<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * PluginDatabaseLifecycle 定义 PHP 插件数据库结构与初始数据的安装、升级和卸载合同。
 *
 * databaseVersion 通常必须单调递增；声明 PluginDatabaseBaselineCollapse 的发布基线允许一次性收敛已验证的历史账本。核心在同一数据库事务中调用 install 并更新插件迁移账本，因此 DDL、
 * 初始化数据和版本记录要么一起提交，要么一起回滚。uninstall 是显式破坏性操作，调用前必须停止对应
 * 插件 Worker 并使用精确确认词；实现按外键逆序删除插件数据和表，不得删除音乐库、用户、媒体或核心
 * 审计表。外部系统中的任务不属于数据库事务，插件必须在文档中说明残留和人工清理条件。
 */
interface PluginDatabaseLifecycle extends PhpResourcePlugin
{
    /** 返回当前插件数据库合同版本；大于零且只随迁移增加。 */
    public function databaseVersion(): int;

    /**
     * 幂等安装或升级插件表、索引和初始设置。
     *
     * fromVersion 首次安装为 0，升级时为账本中的已安装版本；实现只能顺序应用大于该版本的步骤，不能
     * 通过猜测表结构判断业务版本。方法运行于核心开启的短 DDL 事务，不允许访问网络、启动 Worker 或
     * 执行媒体文件操作，异常会使 DDL、初始化数据和账本整体回滚。
     */
    public function installDatabase(int $fromVersion): void;

    /**
     * 删除插件拥有的全部数据库表和设置数据。
     *
     * 方法运行于核心事务；失败必须抛出并整体回滚。成功后插件不得假设历史任务或凭据仍可恢复。
     */
    public function uninstallDatabase(): void;
}
