<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;

/**
 * 管理 PHP 资源插件的版本化启用状态。
 *
 * 开关只控制核心后续发现与调用，不执行插件代码、不取消已进入调用栈的操作，也不删除任何插件数据或
 * 文件。历史包没有状态行时固定视为 enabled=true、version=1；首次写入和后续更新都使用 expectedVersion
 * CAS，并与核心审计处于同一 SQLite 短事务。重新启用前必须从注册表投影复验包、manifest、数据库版本、
 * 待重启和待卸载状态；复验失败不修改旧停用事实。注册表在状态数据库不可读时失败关闭，因此一次数据库
 * 故障不会让已停用插件意外恢复运行。
 */
final readonly class PhpResourcePluginStateService
{
    public function __construct(
        private PhpResourcePluginRegistry $registry = new PhpResourcePluginRegistry(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 以乐观版本锁启用或停用一个已安装包。
     *
     * expectedVersion 来自插件目录投影。相同目标状态是幂等读取，不增加版本或审计；状态变化原子写入
     * version+1 和审计。停用允许作用于 manifest 已损坏的现存包，使管理员仍能明确阻断它；启用则要求
     * valid、databaseInstalled、packageInstalled 全部为真。并发更新、待卸载、待重启和缺少核心迁移均
     * 失败关闭，不触碰插件任务或媒体。
     *
     * @return array{pluginKey:string,enabled:bool,stateVersion:int,status:'enabled'|'disabled'}
     */
    public function update(
        string $key,
        bool $enabled,
        int $expectedVersion,
        string $actorId,
        string $requestId,
    ): array {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $key) !== 1 || $expectedVersion < 1) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_STATE_REQUEST_INVALID');
        }
        $this->assertStateTable();
        $plugin = null;
        foreach ($this->registry->list() as $item) {
            if (($item['key'] ?? null) === $key) {
                $plugin = $item;
                break;
            }
        }
        if (!is_array($plugin)) throw new PhpResourcePluginNotFound();
        if (($plugin['packageInstalled'] ?? false) !== true
            || ($plugin['pendingRemoval'] ?? false) === true
            || ($plugin['restartRequired'] ?? false) === true) {
            throw new PhpResourcePluginPackageConflict('PHP_PLUGIN_STATE_PACKAGE_INACTIVE');
        }
        if ($enabled && (($plugin['valid'] ?? false) !== true
            || ($plugin['databaseInstalled'] ?? false) !== true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_STATE_ENABLE_UNAVAILABLE');
        }

        return Db::transaction(function () use ($key, $enabled, $expectedVersion, $actorId, $requestId): array {
            /** @var stdClass|null $row */
            $row = Db::table('php_resource_plugin_states')->where('plugin_key', $key)->first();
            $currentEnabled = $row instanceof stdClass ? (int) $row->enabled === 1 : true;
            $currentVersion = $row instanceof stdClass ? (int) $row->version : 1;
            if ($currentVersion !== $expectedVersion) throw new PhpResourcePluginOperationConflict();
            if ($currentEnabled === $enabled) {
                return $this->result($key, $enabled, $currentVersion);
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $currentVersion + 1;
            if ($row instanceof stdClass) {
                $changed = Db::table('php_resource_plugin_states')->where('plugin_key', $key)
                    ->where('version', $currentVersion)->update([
                        'enabled' => $enabled ? 1 : 0,
                        'version' => $nextVersion,
                        'updated_at' => $now,
                    ]);
            } else {
                $changed = Db::table('php_resource_plugin_states')->insertOrIgnore([
                    'plugin_key' => $key,
                    'enabled' => $enabled ? 1 : 0,
                    'version' => $nextVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if ($changed !== 1) throw new PhpResourcePluginOperationConflict();
            $this->audit->record($actorId, 'php_resource_plugin.state.update', 'php_resource_plugin', $key,
                'success', $requestId, ['enabled' => $enabled, 'stateVersion' => $nextVersion]);
            return $this->result($key, $enabled, $nextVersion);
        });
    }

    /** @return array{pluginKey:string,enabled:bool,stateVersion:int,status:'enabled'|'disabled'} */
    private function result(string $key, bool $enabled, int $version): array
    {
        return ['pluginKey' => $key, 'enabled' => $enabled, 'stateVersion' => $version,
            'status' => $enabled ? 'enabled' : 'disabled'];
    }

    private function assertStateTable(): void
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('php_resource_plugin_states')) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_CORE_MIGRATION_REQUIRED');
        }
    }
}
