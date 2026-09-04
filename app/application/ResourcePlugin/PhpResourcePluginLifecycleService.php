<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\ResourcePlugin\Contract\PluginDatabaseLifecycle;
use app\application\ResourcePlugin\Contract\PluginDatabaseBaselineCollapse;
use InvalidArgumentException;
use stdClass;
use support\Db;

/**
 * 编排 PHP 资源插件的数据库安装、升级与破坏性卸载。
 *
 * 核心只保存通用迁移账本，不理解某个插件的业务表。安装按插件 key 加载受信实现，在 SQLite 事务内执行
 * 插件迁移并原子 upsert 版本；相同版本重复执行为幂等成功，数据库版本高于代码时失败关闭，避免旧代码
 * 降级消费新 schema。卸载要求确认词精确等于 key，先由插件删除自有数据/表，再删除账本；删除插件目录
 * 之前必须执行本服务，否则核心无法安全加载已不存在的卸载逻辑。
 */
final readonly class PhpResourcePluginLifecycleService
{
    public function __construct(private PhpResourcePluginRegistry $registry = new PhpResourcePluginRegistry())
    {
    }

    /** @return array{pluginKey:string,databaseVersion:int,status:string} */
    public function install(string $key): array
    {
        return $this->installValidated($key, $this->lifecycle($key));
    }

    /**
     * 使用包管理器已从唯一暂存目录完整校验的插件实例执行数据库安装或升级。
     *
     * 升级会把新目录原子发布到旧路径；PHP 的 realpath/OPcache 可能仍把同一字符串路径映射到替换前的
     * manifest，因此发布后再次加载会形成“新实现配旧 manifest”的假冲突。调用方只能传入注册表已经
     * 复验 manifest、实现类和全部能力接口的实例，本方法仍核对 key 与数据库能力，随后沿用同一短事务
     * 和账本规则。异常会回滚插件 DDL 与账本，包管理器再恢复旧目录；本方法不访问网络或文件系统。
     *
     * @return array{pluginKey:string,databaseVersion:int,status:string}
     */
    public function installValidated(string $key, PluginDatabaseLifecycle $plugin): array
    {
        $descriptor = $plugin->descriptor();
        if (($descriptor['key'] ?? null) !== $key
            || !in_array('database_lifecycle', $descriptor['capabilities'] ?? [], true)) {
            throw new PhpResourcePluginInvalid();
        }
        $target = $plugin->databaseVersion();
        if ($target < 1) throw new PhpResourcePluginInvalid();
        $this->assertLedger();
        /** @var stdClass|null $installed */
        $installed = Db::table('php_resource_plugin_migrations')->where('plugin_key', $key)->first();
        if ($installed instanceof stdClass && (int) $installed->database_version > $target) {
            $legacyVersion = (int) $installed->database_version;
            if (!$plugin instanceof PluginDatabaseBaselineCollapse
                || !$plugin->supportsLegacyDatabaseVersion($legacyVersion)) {
                throw new PhpResourcePluginInvalid('PHP_PLUGIN_DATABASE_NEWER_THAN_CODE');
            }
        }
        if ($installed instanceof stdClass && (int) $installed->database_version === $target) {
            return ['pluginKey' => $key, 'databaseVersion' => $target, 'status' => 'current'];
        }
        $fromVersion = $installed instanceof stdClass ? (int) $installed->database_version : 0;
        $installedAt = $installed instanceof stdClass ? (string) $installed->installed_at : null;
        Db::transaction(function () use ($plugin, $key, $target, $fromVersion, $installedAt): void {
            $plugin->installDatabase($fromVersion);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::table('php_resource_plugin_migrations')->updateOrInsert(['plugin_key' => $key], [
                'database_version' => $target, 'installed_at' => $installedAt ?? $now, 'updated_at' => $now,
            ]);
        });
        return ['pluginKey' => $key, 'databaseVersion' => $target, 'status' => 'installed'];
    }

    /**
     * 破坏性卸载一个插件的全部数据库状态。
     *
     * confirmation 必须精确等于插件 key，禁止通配符或大小写折叠。调用方还应先停止 Webman，避免旧
     * Worker 在 DDL 事务提交后继续持有已删除任务。数据库删除可回滚，但外部 qBittorrent 中已提交的
     * torrent 不在本事务内，插件卸载说明必须要求部署者自行确认或清理。
     *
     * @return array{pluginKey:string,status:string,dataDeleted:bool}
     */
    public function uninstall(string $key, string $confirmation): array
    {
        if (!hash_equals($key, $confirmation)) throw new InvalidArgumentException('插件卸载确认词必须与插件 key 完全一致。');
        $plugin = $this->lifecycle($key);
        $this->assertLedger();
        Db::transaction(function () use ($plugin, $key): void {
            $plugin->uninstallDatabase();
            Db::table('php_resource_plugin_migrations')->where('plugin_key', $key)->delete();
            // 启用状态属于核心生命周期事实；插件数据卸载成功后同时删除，重装同 key 才能恢复默认启用。
            if (Db::connection()->getSchemaBuilder()->hasTable('php_resource_plugin_states')) {
                Db::table('php_resource_plugin_states')->where('plugin_key', $key)->delete();
            }
        });
        return ['pluginKey' => $key, 'status' => 'uninstalled', 'dataDeleted' => true];
    }

    /** @return list<array{pluginKey:string,databaseVersion:int,installedAt:string,updatedAt:string}> */
    public function installed(): array
    {
        $this->assertLedger();
        return Db::table('php_resource_plugin_migrations')->orderBy('plugin_key')->get()->map(
            static fn (stdClass $row): array => ['pluginKey' => (string) $row->plugin_key,
                'databaseVersion' => (int) $row->database_version, 'installedAt' => (string) $row->installed_at,
                'updatedAt' => (string) $row->updated_at],
        )->all();
    }

    private function lifecycle(string $key): PluginDatabaseLifecycle
    {
        $plugin = $this->registry->getPackage($key);
        if (!$plugin instanceof PluginDatabaseLifecycle
            || !in_array('database_lifecycle', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid();
        }
        return $plugin;
    }

    private function assertLedger(): void
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('php_resource_plugin_migrations')) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_CORE_MIGRATION_REQUIRED');
        }
    }
}
