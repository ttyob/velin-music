<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use Illuminate\Database\QueryException;
use stdClass;
use support\Db;

/**
 * 为资源插件分配“音乐库 + 插件”专属的最终媒体目录。
 *
 * 该服务是插件进入本地音乐库的唯一目录边界：调用方只能提交已授权的库 ID 和安装协议中的插件 key，
 * 不能提交物理路径、相对路径或目录名。目录位于库根下的固定 `Velin Plugins/<plugin-key>`，其中的
 * 音频属于该库的最终媒体，会被普通扫描发现；partial、收据和其他中间文件仍必须留在插件 media/runtime，
 * 不能借此服务把临时文件写进音乐库。远程库、停用库、符号链接根和不可写根全部失败关闭。
 *
 * 目录创建是幂等的。核心先复验数据库中的 active/local 状态，再复验真实根和父目录，最后逐级创建并
 * 写入所有权 marker；任意步骤失败都不返回路径。已有无 marker 目录不会被接管，避免把用户同名目录
 * 当成插件目录。该服务不移动既有媒体、不删除文件，也不修改数据库；旧版本已经发布的媒体继续保持原路径。
 */
final readonly class PluginLibraryDirectoryService
{
    private const ROOT_DIRECTORY = 'Velin Plugins';
    private const MARKER = '.velin-plugin-library-owner';
    private const OWNER_PREFIX = 'velin-plugin-library-v1:';

    /**
     * 返回指定本地库的插件专属最终媒体目录。
     *
     * @throws PhpResourcePluginWorkspaceUnavailable 库不存在、非本地、停用、根目录异常、路径重叠或无法创建
     */
    public function resolve(string $libraryId, string $pluginKey): string
    {
        $this->assertPluginKey($pluginKey);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $libraryId) !== 1) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_INVALID');
        }

        try {
            /** @var stdClass|null $library */
            $library = Db::table('music_libraries')->where('id', $libraryId)
                ->where('status', 'active')->where('source_type', 'local')
                ->first(['resolved_root_path']);
        } catch (QueryException) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_UNAVAILABLE');
        }
        if (!$library instanceof stdClass || !is_string($library->resolved_root_path)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_UNAVAILABLE');
        }

        $rootPath = (string) $library->resolved_root_path;
        $root = realpath($rootPath);
        if (!is_string($root) || $root !== rtrim($rootPath, DIRECTORY_SEPARATOR)
            || !is_dir($root) || is_link($root) || !is_writable($root)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_UNAVAILABLE');
        }

        $collection = $root . DIRECTORY_SEPARATOR . self::ROOT_DIRECTORY;
        $collection = $this->ensureDirectory($collection, self::OWNER_PREFIX . "collection\n");
        return $this->ensureDirectory(
            $collection . DIRECTORY_SEPARATOR . $pluginKey,
            self::OWNER_PREFIX . $libraryId . ':' . $pluginKey . "\n",
        );
    }

    /** 创建或复验一个核心拥有的普通目录，拒绝链接和无 marker 接管。 */
    private function ensureDirectory(string $path, string $owner): string
    {
        if (file_exists($path) && is_link($path)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_INVALID');
        }
        $created = false;
        if (!file_exists($path)) {
            if (!@mkdir($path, 0750) || !is_dir($path)) {
                throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_UNAVAILABLE');
            }
            $created = true;
        }
        $real = realpath($path);
        if (!is_string($real) || $real !== $path || !is_dir($real) || is_link($path) || !is_writable($real)) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_INVALID');
        }

        $marker = $real . DIRECTORY_SEPARATOR . self::MARKER;
        if ($created) {
            $handle = @fopen($marker, 'x+b');
            if ($handle === false) throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_UNAVAILABLE');
            try {
                if (fwrite($handle, $owner) !== strlen($owner) || !fflush($handle)
                    || (function_exists('fsync') && !fsync($handle))) {
                    throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_UNAVAILABLE');
                }
            } finally {
                fclose($handle);
            }
            @chmod($marker, 0600);
        }
        if (!is_file($marker) || is_link($marker) || @file_get_contents($marker) !== $owner) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_LIBRARY_DIRECTORY_INVALID');
        }
        return $real;
    }

    /** 插件 key 同时进入目录名和 marker，必须与安装协议使用同一安全字符集。 */
    private function assertPluginKey(string $pluginKey): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $pluginKey) !== 1) {
            throw new PhpResourcePluginWorkspaceUnavailable('PHP_PLUGIN_WORKSPACE_KEY_INVALID');
        }
    }
}
