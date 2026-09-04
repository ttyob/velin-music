<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use JsonException;

/**
 * 在容器首次见到某个镜像默认插件时执行一次受控安装。
 *
 * 默认包只从镜像只读目录 `/opt/velin/initial-plugins` 和固定 JSON 清单读取，不能由环境变量、数据库或
 * HTTP 参数替换。真正的解压、manifest/能力校验、原子发布、插件数据库事务和审计仍委托通用包服务，
 * 因而默认插件不形成第二套安装协议。每个 key 成功安装或确认已有合法安装后，在持久插件根的隐藏状态
 * 目录写入一次性标记；管理员后来卸载该插件时标记保留，后续重启不会违背卸载意图自动重装。清单新增
 * key 会独立初始化，修改既有 ZIP 不会绕过后台显式升级规则。
 *
 * 并发边界：初始化使用同一持久根内的原子目录锁，同一数据卷不能由两个 backend 同时初始化。进程异常
 * 可能留下锁目录，此时启动失败关闭并要求运维确认没有其他初始化进程后清理精确锁，不猜测锁是否过期。
 * 包安装成功但标记写入失败时，下次启动只确认现有包并补写标记，不会重复执行插件数据库迁移。
 */
final readonly class InitialPluginPackageService
{
    private const DEFAULT_PACKAGE_ROOT = '/opt/velin/initial-plugins';
    private const STATE_DIRECTORY = '.initial-plugins';

    public function __construct(
        private ?string $packageRoot = null,
        private ?string $pluginRoot = null,
        private ?PhpResourcePluginPackageService $packages = null,
    ) {
    }

    /**
     * 初始化清单中尚未处理的默认插件。
     *
     * 返回值只包含稳定 key 与动作，不暴露镜像、宿主或暂存物理路径。任一包无效、持久根被链接替换、
     * 活动目录状态不明或安装事务失败都会中止启动；此前已经完成并写标记的插件保持提交，重复执行会跳过。
     *
     * @return array{installed:list<string>,preserved:list<string>,skipped:list<string>}
     */
    public function initialize(): array
    {
        $packageRoot = $this->resolvePackageRoot();
        $pluginRoot = $this->resolvePluginRoot();
        $entries = $this->manifestEntries($packageRoot);
        $stateRoot = $this->stateRoot($pluginRoot);
        $lock = $stateRoot . '/.lock';
        if (!mkdir($lock, 0700)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIALIZATION_LOCKED');
        }

        $result = ['installed' => [], 'preserved' => [], 'skipped' => []];
        try {
            foreach ($entries as $entry) {
                $key = $entry['key'];
                $marker = $stateRoot . '/' . $key;
                if ($this->isCompletedMarker($marker)) {
                    $result['skipped'][] = $key;
                    continue;
                }
                if (file_exists($marker) || is_link($marker)) {
                    throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIALIZATION_MARKER_INVALID');
                }

                $target = $pluginRoot . '/' . $key;
                if (file_exists($target) || is_link($target)) {
                    $this->assertRecognizedExistingPackage($target);
                    $result['preserved'][] = $key;
                } else {
                    $packages = $this->packages ?? new PhpResourcePluginPackageService($pluginRoot);
                    $packages->install(
                        $entry['path'],
                        $entry['archive'],
                        null,
                        'startup-initial-plugin-' . $key . '-' . bin2hex(random_bytes(8)),
                    );
                    $result['installed'][] = $key;
                }
                $this->publishCompletedMarker($marker, $key);
            }
        } finally {
            @rmdir($lock);
        }

        return $result;
    }

    /** 解析镜像只读包根；链接、相对路径、缺失目录和逃逸 realpath 全部拒绝。 */
    private function resolvePackageRoot(): string
    {
        $root = rtrim($this->packageRoot ?? self::DEFAULT_PACKAGE_ROOT, DIRECTORY_SEPARATOR);
        $real = realpath($root);
        if ($root === '' || $root[0] !== DIRECTORY_SEPARATOR || is_link($root)
            || !is_string($real) || $real !== $root || !is_dir($real) || !is_readable($real)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_INITIAL_PACKAGE_ROOT_INVALID');
        }
        return $real;
    }

    /** 解析持久插件根；默认 `/app/plugin` 允许是镜像创建的兼容链接，但最终真实目录不得再是链接。 */
    private function resolvePluginRoot(): string
    {
        $candidate = $this->pluginRoot ?? base_path('plugin');
        $resolved = realpath($candidate) ?: $candidate;
        $root = rtrim($resolved, DIRECTORY_SEPARATOR);
        if ($root === '' || $root[0] !== DIRECTORY_SEPARATOR || is_link($root)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_STATE_ROOT_INVALID');
        }
        if (!is_dir($root) && !mkdir($root, 0750, true)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_STATE_ROOT_UNAVAILABLE');
        }
        $real = realpath($root);
        if (!is_string($real) || $real !== $root || !is_writable($real)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_STATE_ROOT_UNAVAILABLE');
        }
        return $real;
    }

    /**
     * 读取并严格校验版本化清单。
     *
     * archive 只能是与 key 同前缀的 SemVer ZIP 普通文件，realpath 必须仍位于包根。清单拒绝未知字段、
     * 重复 key 和空列表，防止构建产物拼写错误被静默忽略或借文件名越出镜像受信目录。
     *
     * @return list<array{key:string,archive:string,path:string}>
     */
    private function manifestEntries(string $root): array
    {
        $manifestPath = $root . '/manifest.json';
        if (!is_file($manifestPath) || is_link($manifestPath) || ($bytes = file_get_contents($manifestPath)) === false
            || strlen($bytes) > 65_536) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_INITIAL_MANIFEST_INVALID');
        }
        try {
            $manifest = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_INITIAL_MANIFEST_INVALID', 0, $exception);
        }
        if (!is_array($manifest) || array_is_list($manifest)
            || array_diff(array_keys($manifest), ['schemaVersion', 'plugins']) !== []
            || count($manifest) !== 2 || ($manifest['schemaVersion'] ?? null) !== 1
            || !is_array($manifest['plugins'] ?? null) || !array_is_list($manifest['plugins'])
            || $manifest['plugins'] === [] || count($manifest['plugins']) > 32) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_INITIAL_MANIFEST_INVALID');
        }

        $entries = [];
        $seen = [];
        foreach ($manifest['plugins'] as $plugin) {
            if (!is_array($plugin) || array_is_list($plugin)
                || array_diff(array_keys($plugin), ['key', 'archive']) !== [] || count($plugin) !== 2
                || !is_string($plugin['key'] ?? null) || !is_string($plugin['archive'] ?? null)) {
                throw new PhpResourcePluginInvalid('PHP_PLUGIN_INITIAL_MANIFEST_INVALID');
            }
            $key = $plugin['key'];
            $archive = $plugin['archive'];
            $versionPattern = '(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)'
                . '(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?';
            if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $key) !== 1
                || preg_match('/^' . preg_quote($key, '/') . '-' . $versionPattern . '\.zip$/D', $archive) !== 1
                || isset($seen[$key])) {
                throw new PhpResourcePluginInvalid('PHP_PLUGIN_INITIAL_MANIFEST_INVALID');
            }
            $path = $root . '/' . $archive;
            $real = realpath($path);
            if (!is_string($real) || !is_file($real) || is_link($path)
                || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                throw new PhpResourcePluginInvalid('PHP_PLUGIN_INITIAL_ARCHIVE_INVALID');
            }
            $seen[$key] = true;
            $entries[] = ['key' => $key, 'archive' => $archive, 'path' => $real];
        }
        return $entries;
    }

    /** 创建隐藏状态根；它不匹配注册表的普通插件目录枚举，也不能是宿主链接。 */
    private function stateRoot(string $pluginRoot): string
    {
        $stateRoot = $pluginRoot . '/' . self::STATE_DIRECTORY;
        if ((file_exists($stateRoot) || is_link($stateRoot))
            && (!is_dir($stateRoot) || is_link($stateRoot))) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_STATE_INVALID');
        }
        if (!is_dir($stateRoot) && !mkdir($stateRoot, 0700)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_STATE_UNAVAILABLE');
        }
        return $stateRoot;
    }

    /** 已处理标记必须是不可链接的普通文件；内容固定为 key，避免空文件被误认为完整提交。 */
    private function isCompletedMarker(string $marker): bool
    {
        if (!is_file($marker) || is_link($marker)) return false;
        return hash_equals(basename($marker) . "\n", (string) file_get_contents($marker));
    }

    /**
     * 已有目标只允许处于通用安装器产生的活动或待卸载状态。
     *
     * 初始化不执行现有包 PHP，也不覆盖、升级或修复它；这既保留管理员配置，也避免在 Worker 启动前把
     * 一个状态不明目录提升为可信插件。待卸载包会先登记已初始化，随后由既有启动命令完成删除。
     */
    private function assertRecognizedExistingPackage(string $target): void
    {
        if (!is_dir($target) || is_link($target)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_TARGET_INVALID');
        }
        $installed = $target . '/.velin-installed';
        $pending = $target . '/.velin-remove-pending';
        foreach ([$installed, $pending] as $marker) {
            if ((file_exists($marker) || is_link($marker)) && (!is_file($marker) || is_link($marker))) {
                throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_TARGET_INVALID');
            }
        }
        if (is_file($installed) === is_file($pending)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_TARGET_INVALID');
        }
    }

    /** 同目录临时文件写完并 fsync 后原子发布；失败不删除已安装插件，下次启动可补偿标记。 */
    private function publishCompletedMarker(string $marker, string $key): void
    {
        $temporary = dirname($marker) . '/.marker-' . bin2hex(random_bytes(8));
        $stream = @fopen($temporary, 'xb');
        if (!is_resource($stream)) {
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_MARKER_WRITE_FAILED');
        }
        $written = fwrite($stream, $key . "\n") === strlen($key) + 1
            && fflush($stream) && (!function_exists('fsync') || fsync($stream));
        fclose($stream);
        if (!$written || !chmod($temporary, 0440) || !rename($temporary, $marker)) {
            @unlink($temporary);
            throw new PhpResourcePluginPackageUnavailable('PHP_PLUGIN_INITIAL_MARKER_WRITE_FAILED');
        }
    }
}
