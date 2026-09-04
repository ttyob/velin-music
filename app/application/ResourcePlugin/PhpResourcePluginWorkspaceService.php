<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\Storage\StorageLayout;
use stdClass;
use support\Db;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 为受信 PHP 资源插件分配由 Velin 核心管理的隔离工作目录。
 *
 * 普通运行数据固定进入 `runtime/resource-plugins/{plugin-key}`，可包含音频正文的大文件工作区固定进入
 * `/storage/downloads/{plugin-key}`。插件只能通过本服务取得路径，不能把中间文件写进音乐库或自行选择宿主
 * 路径。集合根和插件根都带有核心创建的精确所有权标记；已有但无标记的目录不会被自动接管，符号链接、
 * 路径逃逸和不可写目录均失败关闭。方法按请求幂等，不执行递归删除，也不修改插件数据库。
 */
final readonly class PhpResourcePluginWorkspaceService
{
    private const MARKER = '.velin-owner';
    private const PERSISTENT_MEDIA_DIRECTORY = 'downloads';
    private const COLLECTION_OWNER_PREFIX = 'velin-resource-plugin-collection-v1:';
    private const PLUGIN_OWNER_PREFIX = 'velin-resource-plugin-workspace-v1:';

    /**
     * 测试可注入两个绝对集合根和本地音乐库根快照；生产默认从 Webman runtime、环境配置和数据库读取。
     *
     * 注入的音乐库根只用于确定性合同测试。生产查询只读取 `source_type=local` 的真实根，不把 WebDAV
     * 等协议路径当作本地目录。构造过程没有文件副作用，目录只在显式分配时创建。
     *
     * @param list<string>|null $localLibraryRoots
     */
    public function __construct(
        private ?string $runtimeRoot = null,
        private ?string $mediaRoot = null,
        private ?array $localLibraryRoots = null,
    ) {
    }

    /**
     * 返回插件的普通运行目录。
     *
     * 该目录适合日志之外的短期控制文件、解析结果和小型中间数据，不应用来规避媒体工作区的大文件存储
     * 约束。重复调用返回同一真实路径；任何所有权或文件类型异常都不会自动修复或接管。
     */
    public function runtimeDirectory(string $pluginKey): string
    {
        $root = $this->runtimeRoot ?? runtime_path('resource-plugins');
        return $this->allocate($root, $pluginKey, 'runtime');
    }

    /**
     * 返回插件专属媒体临时库。
     *
     * 默认集合根固定为 `/storage/downloads`，不接受环境变量改成任意路径。分配
     * 前会把集合真实路径与全部本地音乐库真实根做双向包含检查；任一方是另一方自身或子目录时拒绝，
     * 从结构上保证扫描器不会发现 partial、转码副本和任务收据。该方法不保证与最终音乐库位于同设备，
     * 需要原子发布的插件必须在发布前比较 `st_dev` 并在跨设备时失败关闭。
     */
    public function mediaDirectory(string $pluginKey): string
    {
        $root = $this->mediaRoot ?? StorageLayout::DOWNLOAD_ROOT;
        $this->assertMediaRootDoesNotOverlapLibraries($root);
        return $this->allocate($root, $pluginKey, 'media');
    }

    /**
     * 在插件最终卸载前删除其两类核心所有工作目录。
     *
     * 该方法只能由没有插件 Worker 运行的启动期卸载流程调用。删除范围精确限制在合法 key 子目录，并
     * 同时复验集合 marker、插件 marker、真实路径和媒体根与音乐库不重叠；链接只 unlink，不跟随目标。
     * 某类目录从未分配时幂等跳过。任一未知所有权、文件系统失败或路径异常会中止卸载，调用方应恢复
     * 隔离中的插件包；已经删除的临时数据可重建，不参与数据库回滚。媒体插件根顶层 `downloads` 是协议
     * 保留的外部交换目录，存在时保留该目录、插件根及其 marker，只清理 `runtime` 等临时内容；最终
     * 最终媒体和保留目录中的做种数据永远不在本方法删除范围内。
     */
    public function removePluginDirectories(string $pluginKey): void
    {
        $this->assertPluginKey($pluginKey);
        $this->removeOwnedPluginDirectory(
            $this->runtimeRoot ?? runtime_path('resource-plugins'),
            $pluginKey,
            'runtime',
        );
        $mediaRoot = $this->mediaRoot ?? StorageLayout::DOWNLOAD_ROOT;
        $this->assertMediaRootDoesNotOverlapLibraries($mediaRoot);
        $this->removeOwnedPluginDirectory($mediaRoot, $pluginKey, 'media', true);
    }

    /**
     * 在固定集合根下幂等建立一个插件目录并复验两级所有权。
     *
     * 集合根的父目录必须已经存在且是非链接普通目录，避免递归 mkdir 穿过部署者未审查的路径组件。只有
     * 本次调用成功 mkdir 的目录才会写入新标记；若目录在调用前已存在却没有正确标记，立即失败而不是
     * 猜测其来源。并发创建由独占标记和最终精确内容复验收敛，失败最多留下本次新建的空目录与标记，
     * 不删除其他进程可能已经开始使用的工作区。
     */
    private function allocate(string $root, string $pluginKey, string $kind): string
    {
        $this->assertPluginKey($pluginKey);
        $collection = $this->ensureOwnedDirectory(
            $root,
            self::COLLECTION_OWNER_PREFIX . $kind . "\n",
            'PHP_PLUGIN_WORKSPACE_ROOT_INVALID',
        );
        $directory = $this->ensureOwnedDirectory(
            $collection . DIRECTORY_SEPARATOR . $pluginKey,
            self::PLUGIN_OWNER_PREFIX . $kind . ':' . $pluginKey . "\n",
            'PHP_PLUGIN_WORKSPACE_DIRECTORY_INVALID',
        );
        if (!str_starts_with($directory, $collection . DIRECTORY_SEPARATOR)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_PATH_ESCAPE');
        }
        return $directory;
    }

    /**
     * 删除一个已分配插件目录，集合根本身保留供其他插件使用。
     *
     * 根或插件目录不存在时不创建任何目录。只要插件子目录存在，就要求两级 marker 内容与分配协议完全
     * 一致；目录树中遇到链接仅删除链接节点。删除采用子节点优先，任一步失败立即抛错并保留未删除部分，
     * 后续启动可在相同所有权校验下重试。
     */
    private function removeOwnedPluginDirectory(
        string $root,
        string $pluginKey,
        string $kind,
        bool $preservePersistentMedia = false,
    ): void
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if (!file_exists($root) && !is_link($root)) return;
        $collection = realpath($root);
        if (!is_string($collection) || $collection !== $root || !is_dir($collection) || is_link($root)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_ROOT_INVALID');
        }
        $directory = $collection . DIRECTORY_SEPARATOR . $pluginKey;
        if (!file_exists($directory) && !is_link($directory)) return;
        if (@file_get_contents($collection . DIRECTORY_SEPARATOR . self::MARKER)
                !== self::COLLECTION_OWNER_PREFIX . $kind . "\n"
            || is_link($collection . DIRECTORY_SEPARATOR . self::MARKER)
            || !is_dir($directory) || is_link($directory) || realpath($directory) !== $directory
            || @file_get_contents($directory . DIRECTORY_SEPARATOR . self::MARKER)
                !== self::PLUGIN_OWNER_PREFIX . $kind . ':' . $pluginKey . "\n"
            || is_link($directory . DIRECTORY_SEPARATOR . self::MARKER)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_DIRECTORY_INVALID');
        }
        $persistent = $directory . DIRECTORY_SEPARATOR . self::PERSISTENT_MEDIA_DIRECTORY;
        $preserve = $preservePersistentMedia && (file_exists($persistent) || is_link($persistent));
        if ($preserve && (!is_dir($persistent) || is_link($persistent) || realpath($persistent) !== $persistent)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_DIRECTORY_INVALID');
        }

        $entries = @scandir($directory);
        if (!is_array($entries)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_CLEANUP_FAILED');
        }
        foreach (array_diff($entries, ['.', '..']) as $name) {
            if ($name === self::MARKER || ($preserve && $name === self::PERSISTENT_MEDIA_DIRECTORY)) continue;
            $this->removeTree($directory . DIRECTORY_SEPARATOR . $name);
        }
        if (!$preserve) {
            if (!@unlink($directory . DIRECTORY_SEPARATOR . self::MARKER) || !@rmdir($directory)) {
                throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_CLEANUP_FAILED');
            }
        }
    }

    /**
     * 删除核心拥有插件根下的一个精确子树，链接只删除链接节点。
     *
     * 调用前已经完成两级 marker、realpath 和持久目录校验，因此该方法绝不接收浏览器路径，也不会进入
     * 顶层 `downloads`。删除采用子节点优先；任一步失败立即保留现场并抛错，让启动期卸载恢复插件包后
     * 重试，而不把部分清理误报为完整卸载。
     */
    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_CLEANUP_FAILED');
            }
            return;
        }
        if (!is_dir($path)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_CLEANUP_FAILED');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $removed = $entry->isLink() || $entry->isFile()
                ? @unlink($entry->getPathname())
                : ($entry->isDir() && @rmdir($entry->getPathname()));
            if (!$removed) {
                throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_CLEANUP_FAILED');
            }
        }
        if (!@rmdir($path)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_CLEANUP_FAILED');
        }
    }

    /**
     * 建立或复验单个核心所有目录。
     *
     * 路径必须是绝对路径，直接父目录必须存在、可写且不是链接。目录与 marker 均拒绝链接；marker 使用
     * `x+b` 独占创建并限制为 0600。已有目录缺标记、标记内容不符或 realpath 与请求路径不精确相等时
     * 失败关闭。这里不递归创建父层，也不追随插件提供的路径。
     */
    private function ensureOwnedDirectory(string $path, string $owner, string $errorCode): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) {
            throw new PhpResourcePluginWorkspaceUnavailable($errorCode);
        }
        $parent = dirname($path);
        $parentReal = realpath($parent);
        if (!is_string($parentReal) || $parentReal !== $parent || !is_dir($parentReal)
            || is_link($parent) || !is_writable($parentReal)) {
            throw new PhpResourcePluginWorkspaceUnavailable($errorCode);
        }
        $created = false;
        if (!file_exists($path) && !is_link($path)) {
            $created = @mkdir($path, 0700);
            if (!$created && !is_dir($path)) throw new PhpResourcePluginWorkspaceUnavailable($errorCode);
        }
        $real = realpath($path);
        if (!is_string($real) || $real !== $path || !is_dir($real) || is_link($path) || !is_writable($real)) {
            throw new PhpResourcePluginWorkspaceUnavailable($errorCode);
        }
        $marker = $real . DIRECTORY_SEPARATOR . self::MARKER;
        if ($created) {
            $handle = @fopen($marker, 'x+b');
            if ($handle === false) throw new PhpResourcePluginWorkspaceUnavailable($errorCode);
            try {
                if (fwrite($handle, $owner) !== strlen($owner) || !fflush($handle)
                    || (function_exists('fsync') && !fsync($handle))) {
                    throw new PhpResourcePluginWorkspaceUnavailable($errorCode);
                }
            } finally {
                fclose($handle);
            }
            @chmod($marker, 0600);
        }
        if (!is_file($marker) || is_link($marker) || @file_get_contents($marker) !== $owner) {
            throw new PhpResourcePluginWorkspaceUnavailable($errorCode);
        }
        return $real;
    }

    /** 插件 key 同时用于目录名和所有权标记，必须保持与安装协议完全一致且不允许路径分隔符。 */
    private function assertPluginKey(string $pluginKey): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $pluginKey) !== 1) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_KEY_INVALID');
        }
    }

    /**
     * 拒绝媒体工作区与任一本地音乐库相同或互为上下级。
     *
     * 对尚未建立的集合根，以其真实父目录拼接 basename 得到确定性候选；父目录含链接、路径非绝对或
     * basename 非单段时直接失败。数据库表在核心迁移完成后必须存在，生产缺表同样失败关闭；测试可注入
     * 根列表。无效或不存在的本地库根仍按保存的绝对路径做词法比较，已有目录则使用 realpath 消除别名。
     */
    private function assertMediaRootDoesNotOverlapLibraries(string $root): void
    {
        $candidate = $this->prospectiveRealPath($root);
        foreach ($this->libraryRoots() as $libraryRoot) {
            if ($libraryRoot === '' || $libraryRoot[0] !== DIRECTORY_SEPARATOR) continue;
            $library = realpath($libraryRoot);
            $library = is_string($library) ? rtrim($library, DIRECTORY_SEPARATOR) : rtrim($libraryRoot, DIRECTORY_SEPARATOR);
            if ($library === '' || $candidate === $library
                || str_starts_with($candidate, $library . DIRECTORY_SEPARATOR)
                || str_starts_with($library, $candidate . DIRECTORY_SEPARATOR)) {
                throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_MEDIA_WORKSPACE_LIBRARY_OVERLAP');
            }
        }
    }

    /** 把已存在目录或“真实父目录 + 单段名称”转换为可比较的绝对候选，拒绝更深层的隐式创建。 */
    private function prospectiveRealPath(string $path): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || basename($path) !== substr($path, strrpos($path, '/') + 1)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_ROOT_INVALID');
        }
        if (file_exists($path) || is_link($path)) {
            $real = realpath($path);
            if (!is_string($real) || $real !== $path || !is_dir($real) || is_link($path)) {
                throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_ROOT_INVALID');
            }
            return rtrim($real, DIRECTORY_SEPARATOR);
        }
        $parent = dirname($path);
        $parentReal = realpath($parent);
        if (!is_string($parentReal) || $parentReal !== $parent || is_link($parent)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_ROOT_INVALID');
        }
        return $parentReal . DIRECTORY_SEPARATOR . basename($path);
    }

    /** @return list<string> */
    private function libraryRoots(): array
    {
        if (is_array($this->localLibraryRoots)) return $this->localLibraryRoots;
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('music_libraries')) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_MEDIA_WORKSPACE_LIBRARY_STATE_UNAVAILABLE');
        }
        return array_values(array_map(
            static fn (stdClass $row): string => (string) $row->resolved_root_path,
            Db::table('music_libraries')->where('source_type', 'local')->get(['resolved_root_path'])->all(),
        ));
    }
}
