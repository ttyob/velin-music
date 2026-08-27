<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\ResourcePlugin\Contract\PluginDatabaseLifecycle;
use app\infrastructure\Audit\AuditLogger;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use support\Db;
use Throwable;
use ZipArchive;

/**
 * 管理后台上传的受信 PHP 插件包及其数据库生命周期。
 *
 * 上传只接受一个不超过 16 MiB 的 ZIP，解压后最多 512 个文件、32 MiB，且必须只有一个与 manifest key
 * 一致的顶层目录。服务逐项拒绝绝对路径、反斜杠、`.`/`..`、符号链接、特殊文件和非白名单代码/页面资源，
 * 不调用 ZipArchive::extractTo，避免 Zip Slip 和链接逃逸。校验后的目录在同一文件系统原子发布，再在
 * SQLite 事务中初始化插件表；数据库失败会撤回刚发布的目录，不留下可被下次启动加载的半安装包。
 * 同 key 包只允许 manifest 版本严格递增，升级时旧包先移入同卷隔离目录，新包数据库迁移与审计失败会
 * 恢复旧包；相同版本、降级、待卸载或损坏的旧包都失败关闭，避免上传动作变成无版本覆盖。
 *
 * 首次安装通过核心固定 API 与通用 Worker 热发现，不改变 Workerman 进程拓扑，因此数据库提交后立即
 * 可用。升级仍返回 restartRequired=true：长驻 PHP 进程不能替换已加载的同名依赖类，只有新进程才能
 * 保证整包代码来自同一版本。卸载也保持两阶段重启边界，避免删除仍被旧调用栈使用的表或 PHP 文件。
 */
final readonly class PhpResourcePluginPackageService
{
    private const MAX_ARCHIVE_BYTES = 16 * 1024 * 1024;
    private const MAX_EXTRACTED_BYTES = 32 * 1024 * 1024;
    private const MAX_FILES = 512;

    public function __construct(
        private ?string $root = null,
        private AuditLogger $audit = new AuditLogger(),
        private ?PhpResourcePluginWorkspaceService $workspaces = null,
    ) {
    }

    /** @return array{pluginKey:string,databaseVersion:int,status:string,restartRequired:bool} */
    public function install(string $archivePath, string $uploadName, string $actorId, string $requestId): array
    {
        if (pathinfo($uploadName, PATHINFO_EXTENSION) !== 'zip' || !is_file($archivePath)
            || is_link($archivePath) || ($size = filesize($archivePath)) === false
            || $size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_PACKAGE_INVALID');
        }
        $root = $this->ensureRoot();
        $staging = $root . '/.install-' . bin2hex(random_bytes(12));
        if (!mkdir($staging, 0700)) throw new PhpResourcePluginPackageUnavailable();
        $published = null;
        $upgradeRoot = null;
        $previous = null;
        $previousDeactivated = false;
        $backupPath = null;
        try {
            $key = $this->extract($archivePath, $staging);
            $this->assertNamespaceAvailable($root, $key);
            $target = $root . '/' . $key;
            $registry = new PhpResourcePluginRegistry($staging);
            $plugin = $registry->getPackage($key);
            $newVersion = $this->packageVersion($staging . '/' . $key, $plugin->descriptor());
            $upgrade = file_exists($target) || is_link($target);
            if ($upgrade) {
                $oldVersion = $this->installedPackageVersion($target, $key);
                if (version_compare($newVersion, $oldVersion, '<=')) {
                    throw new PhpResourcePluginPackageConflict('PHP_PLUGIN_VERSION_NOT_NEWER');
                }
                $upgradeRoot = $root . '/.upgrade-' . bin2hex(random_bytes(8));
                if (!mkdir($upgradeRoot, 0700) || !rename($target, $upgradeRoot . '/' . $key)) {
                    @rmdir($upgradeRoot);
                    throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_PACKAGE_UPGRADE_STAGE_FAILED');
                }
                $previous = $upgradeRoot . '/' . $key;
                $this->deactivatePreviousPackage($previous);
                $previousDeactivated = true;
            }
            $marker = $staging . '/' . $key . '/.velin-installed';
            if (file_put_contents($marker, '') === false || !chmod($marker, 0440)) {
                throw new PhpResourcePluginPackageUnavailable();
            }
            if ($upgrade) {
                $restartMarker = $staging . '/' . $key . '/.velin-restart-required';
                if (file_put_contents($restartMarker, '') === false || !chmod($restartMarker, 0440)) {
                    throw new PhpResourcePluginPackageUnavailable();
                }
            }
            if (!rename($staging . '/' . $key, $target)) throw new PhpResourcePluginPackageUnavailable();
            $published = $target;
            $result = $this->withPackageAutoloader($root, $key,
                function () use ($plugin, $key, $actorId, $requestId): array {
                    return Db::transaction(function () use ($plugin, $key, $actorId, $requestId): array {
                        if (!$plugin instanceof PluginDatabaseLifecycle) throw new PhpResourcePluginInvalid();
                        // 复用暂存目录中已完整校验的实例，避免相同发布路径命中替换前的 manifest 缓存。
                        $result = (new PhpResourcePluginLifecycleService())->installValidated($key, $plugin);
                        $this->audit->record($actorId, 'php_resource_plugin.install', 'php_resource_plugin', $key,
                            'success', $requestId, ['databaseVersion' => $result['databaseVersion']]);
                        return $result;
                    });
                });
            if (is_string($upgradeRoot) && is_dir($previous ?? '')) {
                $backupPath = $root . '/.backup-' . $key . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
                if (!rename((string) $previous, $backupPath)) {
                    // 新包和数据库已经提交，备份移动失败不影响运行，但保留隔离目录供运维清理。
                    $backupPath = null;
                }
                @rmdir($upgradeRoot);
                $this->trimBackups($root, $key, 3);
            }
            return $result + ['restartRequired' => $upgrade, 'backupKept' => $backupPath !== null];
        } catch (Throwable $throwable) {
            if (is_string($published) && is_dir($published)) {
                $this->deleteTree($published);
            }
            if (is_string($previous) && is_dir($previous) && !file_exists($root . '/' . basename($previous))) {
                if ($previousDeactivated) $this->reactivatePreviousPackage($previous);
                @rename($previous, $root . '/' . basename($previous));
            }
            if (is_string($upgradeRoot)) @rmdir($upgradeRoot);
            $this->deleteTree($staging);
            throw $throwable;
        } finally {
            $this->deleteTree($staging);
        }
    }

    /**
     * 返回某插件保留的升级备份清单。
     *
     * 备份目录由本服务生成且不参与插件加载；只返回版本文件中的版本和时间，读取失败的目录直接
     * 忽略。该接口不改变数据库或活动插件，也不会执行备份中的 PHP。
     *
     * @return array{pluginKey:string,backups:list<array{id:string,version:string,createdAt:string}>}
     */
    public function backups(string $key): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $key) !== 1) throw new PhpResourcePluginInvalid();
        $root = $this->ensureRoot();
        $items = [];
        foreach (glob($root . '/.backup-' . $key . '-*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_link($directory) || !is_file($directory . '/config/resource_plugin.php')) continue;
            $manifest = require $directory . '/config/resource_plugin.php';
            if (!is_array($manifest) || !is_string($manifest['version'] ?? null)) continue;
            $items[] = ['id' => basename($directory), 'version' => $manifest['version'],
                'createdAt' => gmdate('c', (int) (filemtime($directory) ?: time()))];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));
        return ['pluginKey' => $key, 'backups' => $items];
    }

    /** 删除指定插件的旧备份，不触碰活动包、数据库和媒体。 */
    public function purgeBackups(string $key, int $keep = 3): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $key) !== 1) throw new PhpResourcePluginInvalid();
        $root = $this->ensureRoot();
        $removed = $this->trimBackups($root, $key, max(0, min(10, $keep)), true);
        return ['pluginKey' => $key, 'removed' => $removed];
    }

    /**
     * 新 Webman 主进程启动前激活已经完成数据库安装的包。
     *
     * 只移除服务自身创建的普通标记，不加载插件类、不访问网络或修改数据库。当前旧进程在标记存在期间
     * 由注册表失败关闭；新进程清除后才会扫描配置、路由和 Worker，实现明确的进程代际切换。
     *
     * @return list<string>
     */
    public function activatePendingInstalls(): array
    {
        $root = $this->ensureRoot();
        $activated = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $key = basename($directory);
            $marker = $directory . '/.velin-restart-required';
            if (is_link($directory) || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $key) !== 1
                || !is_file($directory . '/.velin-installed') || is_link($directory . '/.velin-installed')
                || !is_file($marker) || is_link($marker)) continue;
            if (!unlink($marker)) throw new PhpResourcePluginPackageUnavailable();
            $activated[] = $key;
        }
        sort($activated);
        return $activated;
    }

    /**
     * 在线登记卸载，但不在仍有 Worker 的进程中删除表或 PHP 文件。
     *
     * 活动标记原子改名后，注册表立即拒绝新钩子。待删除标记由容器入口在下一次 Webman 启动前消费；
     * 重复请求幂等返回 pending，错误确认词不会改变文件或数据库。
     *
     * @return array{pluginKey:string,status:string,dataDeleted:false,packageDeleted:false,restartRequired:true}
     */
    public function requestUninstall(string $key, string $confirmation, string $actorId, string $requestId): array
    {
        if (!hash_equals($key, $confirmation)) throw new \InvalidArgumentException('插件卸载确认词必须与插件 key 完全一致。');
        $root = $this->ensureRoot();
        $directory = $root . '/' . $key;
        $registry = new PhpResourcePluginRegistry($root);
        $registry->getPackage($key);
        $marker = $directory . '/.velin-installed';
        $pending = $directory . '/.velin-remove-pending';
        if (is_file($marker) && !is_link($marker)) {
            if (!rename($marker, $pending)) throw new PhpResourcePluginPackageUnavailable();
            try {
                $this->audit->record($actorId, 'php_resource_plugin.uninstall.request', 'php_resource_plugin', $key,
                    'success', $requestId, ['restartRequired' => true]);
            } catch (Throwable $throwable) {
                @rename($pending, $marker);
                throw $throwable;
            }
        } elseif (!is_file($pending) || is_link($pending)) {
            throw new PhpResourcePluginPackageConflict();
        }
        return ['pluginKey' => $key, 'status' => 'uninstall_pending', 'dataDeleted' => false,
            'packageDeleted' => false, 'restartRequired' => true];
    }

    /**
     * 在 Webman/插件 Worker 尚未启动时完成待处理卸载。
     *
     * 包先原子移入隐藏隔离根，数据库和审计再在事务中删除；失败会把完整包恢复到活动根并保留待处理
     * 标记，下一次启动可安全重试。数据库成功后只删除隔离目录，不再有代码能被框架扫描。
     *
     * @return array{pluginKey:string,status:string,dataDeleted:bool,packageDeleted:bool,restartRequired:false}
     */
    public function finalizePendingUninstall(string $key, string $confirmation, string $requestId): array
    {
        if (!hash_equals($key, $confirmation)) throw new \InvalidArgumentException('插件卸载确认词必须与插件 key 完全一致。');
        $root = $this->ensureRoot();
        $directory = $root . '/' . $key;
        $pending = $directory . '/.velin-remove-pending';
        if (!is_file($pending) || is_link($pending)) throw new PhpResourcePluginPackageConflict();
        $quarantineRoot = $root . '/.remove-' . bin2hex(random_bytes(8));
        if (!mkdir($quarantineRoot, 0700) || !rename($directory, $quarantineRoot . '/' . $key)) {
            @rmdir($quarantineRoot);
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_PACKAGE_REMOVE_FAILED');
        }
        $registry = new PhpResourcePluginRegistry($quarantineRoot);
        try {
            // 启动期没有插件 Worker；先清理可重建工作区，未知所有权会在数据库 DDL 前中止并恢复插件包。
            ($this->workspaces ?? new PhpResourcePluginWorkspaceService())->removePluginDirectories($key);
            $result = $this->withPackageAutoloader($quarantineRoot, $key,
                function () use ($registry, $key, $confirmation, $requestId): array {
                    return Db::transaction(function () use ($registry, $key, $confirmation, $requestId): array {
                        $result = (new PhpResourcePluginLifecycleService($registry))->uninstall($key, $confirmation);
                        $this->audit->record(null, 'php_resource_plugin.uninstall.finalize', 'php_resource_plugin', $key,
                            'success', $requestId, ['dataDeleted' => true]);
                        return $result;
                    });
                });
        } catch (Throwable $throwable) {
            @rename($quarantineRoot . '/' . $key, $directory);
            @rmdir($quarantineRoot);
            throw $throwable;
        }
        $this->deleteTree($quarantineRoot);
        if (file_exists($quarantineRoot) || is_link($quarantineRoot)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_PACKAGE_DELETE_FAILED');
        }
        return $result + ['packageDeleted' => true, 'restartRequired' => false];
    }

    /**
     * 清理上一次已经提交数据库卸载、但进程在删除隔离包时中断的残留。
     *
     * 只接受本服务固定 `.remove-<hex>` 目录、内部唯一安全 key 子目录和待删除标记，并要求通用迁移账本
     * 已不存在该 key；任一条件不满足即失败关闭，不猜测目录归属。清理不执行插件 PHP，也不修改业务表。
     *
     * @return list<string>
     */
    public function purgeCompletedQuarantines(): array
    {
        $root = $this->ensureRoot();
        $purged = [];
        foreach (glob($root . '/.remove-*', GLOB_ONLYDIR) ?: [] as $quarantine) {
            if (is_link($quarantine) || preg_match('/^\.remove-[a-f0-9]{16}$/D', basename($quarantine)) !== 1) {
                throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_QUARANTINE_INVALID');
            }
            $children = array_values(array_filter(scandir($quarantine) ?: [],
                static fn (string $name): bool => $name !== '.' && $name !== '..'));
            if (count($children) !== 1 || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $children[0]) !== 1) {
                throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_QUARANTINE_INVALID');
            }
            $key = $children[0];
            $package = $quarantine . '/' . $key;
            if (!is_dir($package) || is_link($package) || !is_file($package . '/.velin-remove-pending')
                || is_link($package . '/.velin-remove-pending')
                || Db::table('php_resource_plugin_migrations')->where('plugin_key', $key)->exists()) {
                throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_QUARANTINE_NOT_COMMITTED');
            }
            $this->deleteTree($quarantine);
            if (file_exists($quarantine) || is_link($quarantine)) {
                throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_PACKAGE_DELETE_FAILED');
            }
            $purged[] = $key;
        }
        sort($purged);
        return $purged;
    }

    private function ensureRoot(): string
    {
        $root = rtrim($this->root ?? base_path('plugin'), DIRECTORY_SEPARATOR);
        if ($root === '' || $root[0] !== DIRECTORY_SEPARATOR || is_link($root)) {
            throw new PhpResourcePluginPackageUnavailable();
        }
        if (!is_dir($root) && !mkdir($root, 0750, true)) throw new PhpResourcePluginPackageUnavailable();
        $real = realpath($root);
        if (!is_string($real) || !is_writable($real)) throw new PhpResourcePluginPackageUnavailable();
        return $real;
    }

    /**
     * 让已移入升级隔离区的旧包立即失去 Webman 活动身份。
     *
     * Webman 会枚举插件根下包括点目录在内的所有一级目录；若旧包继续携带 `.velin-installed`，带私有
     * 路由的插件会与新包重复注册并使整个 backend 无法启动。标记只在旧目录已原子离开活动 key 后改名，
     * 不删除旧包或业务数据；后续数据库迁移失败时由 reactivatePreviousPackage 精确恢复同一标记。
     */
    private function deactivatePreviousPackage(string $directory): void
    {
        $active = $directory . '/.velin-installed';
        $backup = $directory . '/.velin-backup';
        if (!is_file($active) || is_link($active) || file_exists($backup) || is_link($backup)
            || !rename($active, $backup)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_BACKUP_DEACTIVATE_FAILED');
        }
    }

    /** 数据库或新包发布失败时恢复旧包活动身份；失败关闭并保留隔离目录，不能启动身份不明的旧包。 */
    private function reactivatePreviousPackage(string $directory): void
    {
        $active = $directory . '/.velin-installed';
        $backup = $directory . '/.velin-backup';
        if (!is_file($backup) || is_link($backup) || file_exists($active) || is_link($active)
            || !rename($backup, $active)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_BACKUP_REACTIVATE_FAILED');
        }
    }

    /** 保留最新 keep 份备份；目录名和 key 已由调用方验证，删除失败即停止并抛出。 */
    private function trimBackups(string $root, string $key, int $keep, bool $returnNames = false): array
    {
        $directories = glob($root . '/.backup-' . $key . '-*', GLOB_ONLYDIR) ?: [];
        usort($directories, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $removed = [];
        foreach (array_slice($directories, $keep) as $directory) {
            if (is_link($directory) || !str_starts_with(basename($directory), '.backup-' . $key . '-')) {
                throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_BACKUP_INVALID');
            }
            $removed[] = basename($directory);
            $this->deleteTree($directory);
        }
        return $returnNames ? $removed : [];
    }

    /**
     * 读取已安装包的可信版本，决定上传是否为严格升级。
     *
     * 旧目录必须带有核心创建的安装标记，允许尚待首次重启，但禁止待卸载、链接和非法 manifest。这里
     * 只读取 manifest 标量，不实例化旧插件类，避免长驻进程已经加载旧类时与新实现发生类名冲突；旧包
     * 在首次安装时已经过完整注册表校验。任何异常状态都按冲突处理，管理员应先恢复或完成卸载。
     */
    private function installedPackageVersion(string $directory, string $key): string
    {
        if (is_link($directory) || !is_dir($directory)
            || !is_file($directory . '/.velin-installed') || is_link($directory . '/.velin-installed')
            || is_file($directory . '/.velin-remove-pending')) {
            throw new PhpResourcePluginPackageConflict('PHP_PLUGIN_UPGRADE_STATE_INVALID');
        }
        $manifestPath = $directory . '/config/resource_plugin.php';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new PhpResourcePluginPackageConflict('PHP_PLUGIN_UPGRADE_MANIFEST_INVALID');
        }
        $manifest = require $manifestPath;
        if (!is_array($manifest) || ($manifest['key'] ?? null) !== $key) {
            throw new PhpResourcePluginPackageConflict('PHP_PLUGIN_UPGRADE_MANIFEST_INVALID');
        }
        return $this->packageVersion($directory, $manifest);
    }

    /** 校验用于升级排序的 SemVer；版本只参与包替换，不替代插件自己的数据库版本。 */
    private function packageVersion(string $directory, array $descriptor): string
    {
        $version = $descriptor['version'] ?? null;
        $real = realpath($directory);
        if (!is_string($version)
            || preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version) !== 1
            || !is_string($real) || $real !== $directory) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_VERSION_INVALID');
        }
        return $version;
    }

    private function extract(string $archivePath, string $staging): string
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true || $zip->numFiles < 1
            || $zip->numFiles > self::MAX_FILES) throw new PhpResourcePluginInvalid('PHP_PLUGIN_ARCHIVE_INVALID');
        $total = 0;
        $top = null;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat) || !is_string($stat['name'] ?? null)) throw new PhpResourcePluginInvalid();
                $name = $stat['name'];
                $directory = str_ends_with($name, '/');
                $path = rtrim($name, '/');
                $parts = explode('/', $path);
                if ($path === '' || str_starts_with($name, '/') || str_contains($name, '\\')
                    || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
                    throw new PhpResourcePluginInvalid('PHP_PLUGIN_ARCHIVE_PATH_INVALID');
                }
                if ($top === null) $top = $parts[0];
                if ($top !== $parts[0] || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $top) !== 1) {
                    throw new PhpResourcePluginInvalid('PHP_PLUGIN_ARCHIVE_LAYOUT_INVALID');
                }
                $executable = !$directory && count($parts) === 3 && $parts[1] === 'bin'
                    && preg_match('/^[a-z0-9][a-z0-9_-]{1,63}\.bin$/D', $parts[2]) === 1;
                if (!$directory && !$executable && !$this->allowedFile(basename($path))) {
                    throw new PhpResourcePluginInvalid('PHP_PLUGIN_ARCHIVE_FILE_INVALID');
                }
                $zip->getExternalAttributesIndex($index, $opsys, $attributes);
                $type = ((int) $attributes >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000 && !($directory && $type === 0040000)) {
                    throw new PhpResourcePluginInvalid('PHP_PLUGIN_ARCHIVE_LINK_INVALID');
                }
                $length = (int) ($stat['size'] ?? -1);
                if ($length < 0 || ($total += $length) > self::MAX_EXTRACTED_BYTES) {
                    throw new PhpResourcePluginInvalid('PHP_PLUGIN_ARCHIVE_TOO_LARGE');
                }
                $destination = $staging . '/' . $path;
                if ($directory) {
                    if (!is_dir($destination) && !mkdir($destination, 0750, true)) throw new PhpResourcePluginPackageUnavailable();
                    continue;
                }
                $parent = dirname($destination);
                if (!is_dir($parent) && !mkdir($parent, 0750, true)) throw new PhpResourcePluginPackageUnavailable();
                $input = $zip->getStream($name);
                $output = @fopen($destination, 'xb');
                if (!is_resource($input) || !is_resource($output)) throw new PhpResourcePluginPackageUnavailable();
                $copied = stream_copy_to_stream($input, $output, self::MAX_EXTRACTED_BYTES + 1);
                fclose($input);
                if (!fflush($output) || (function_exists('fsync') && !fsync($output))) $copied = false;
                fclose($output);
                // 仅顶层 `bin/*.bin` 获得执行位；其他代码与资源继续只读。二进制仍受单顶层目录、普通文件、
                // 文件数和总大小约束，不能利用 ZIP mode 自行声明任意脚本可执行。0550 不授予 other 权限，
                // 安装失败会由外层删除整个 staging，不留下部分可执行文件。
                if ($copied !== $length || !chmod($destination, $executable ? 0550 : 0440)) {
                    throw new PhpResourcePluginPackageUnavailable();
                }
            }
        } finally {
            $zip->close();
        }
        if (!is_string($top) || !is_file($staging . '/' . $top . '/config/resource_plugin.php')) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_MANIFEST_MISSING');
        }
        return $top;
    }

    private function allowedFile(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '.')) return false;
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($extension, [
            'php', 'json', 'md', 'txt', 'html', 'css', 'js', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'woff2',
        ], true) || preg_match('/^licen[cs]e$/i', $name) === 1;
    }

    /**
     * 拒绝两个目录 key 映射到同一 PHP 顶级 namespace。
     *
     * `-` 到 `_` 的映射允许 URL 友好的插件 key 使用合法 PHP 标识符，但 `lx-music` 与 `lx_music` 会发生
     * 冲突。检查只读取安装根一级普通目录，忽略当前 key 自身以允许升级；冲突在新包发布、manifest
     * 实例化和数据库迁移前失败，因此不会加载错误类或留下数据副作用。
     */
    private function assertNamespaceAvailable(string $root, string $key): void
    {
        $namespaceKey = str_replace('-', '_', $key);
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_link($directory)) continue;
            $candidate = basename($directory);
            if ($candidate === $key || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $candidate) !== 1) continue;
            if (str_replace('-', '_', $candidate) === $namespaceKey) {
                throw new PhpResourcePluginPackageConflict('PHP_PLUGIN_NAMESPACE_CONFLICT');
            }
        }
    }

    /**
     * 在一次安装/卸载事务范围内加载目标包依赖，结束后立即注销，避免长驻进程累积自动加载闭包。
     *
     * 类名只允许目标插件命名空间，真实文件必须留在精确包根且不是链接；回调异常原样传播，finally
     * 始终注销。已由 PHP 加载的类本身不能卸载，因此调用方仍必须遵守进程代际切换协议。
     */
    private function withPackageAutoloader(string $root, string $key, callable $operation): mixed
    {
        $packageRoot = realpath($root . '/' . $key);
        if (!is_string($packageRoot) || !str_starts_with($packageRoot, $root . '/')) {
            throw new PhpResourcePluginPackageUnavailable();
        }
        $namespaceKey = str_replace('-', '_', $key);
        $loader = static function (string $class) use ($namespaceKey, $packageRoot): void {
            $prefix = 'plugin\\' . $namespaceKey . '\\';
            if (!str_starts_with($class, $prefix)
                || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D',
                    substr($class, strlen($prefix))) !== 1) return;
            $path = $packageRoot . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            $real = realpath($path);
            if (is_string($real) && !is_link($path) && is_file($real)
                && str_starts_with($real, $packageRoot . '/')) require_once $real;
        };
        spl_autoload_register($loader, true, true);
        try {
            return $operation();
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /** 只删除本服务生成或已原子移入隔离区的精确目录；链接本身删除但绝不跟随。 */
    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isLink() || $item->isFile() ? @unlink($item->getPathname()) : @rmdir($item->getPathname());
        }
        @rmdir($path);
    }
}
