<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\Storage\StorageLayout;
use FilesystemIterator;
use Throwable;

/**
 * 为首次初始化提供固定媒体挂载内的只读目录浏览。
 *
 * 浏览根固定为容器内 `/storage`，浏览器只提交根内相对目录，不能请求 `/data`、宿主绝对路径或 URL。
 * 服务每次访问都重新解析真实路径，并逐段拒绝符号链接，避免安装期间目录被替换后越过媒体挂载边界。
 * 返回值只包含逻辑路径、条目类型、大小和修改时间，不创建目录、不读取文件正文，也不修改任何业务数据。
 */
final readonly class SetupLibraryDirectoryBrowserService
{
    private const MAX_PATH_BYTES = 768;
    private const MAX_PAGE_SIZE = 200;

    public function __construct(private string $storageRoot = StorageLayout::STORAGE_ROOT)
    {
    }

    /**
     * 返回媒体挂载中一个目录的一层有界投影。
     *
     * 前置条件：Controller 已确认当前主体是尚未完成音乐库设置的超级管理员。relativePath 必须是本接口
     * 先前返回的正斜杠相对路径；点段、控制字符、链接目录和越界解析全部失败关闭。分页只裁剪一次目录
     * 枚举结果，不承诺跨请求快照；目录并发变化时调用方应刷新。本方法没有文件系统写入和回滚动作。
     *
     * @return array<string,mixed> 不含宿主路径的目录、条目及分页投影
     */
    public function browse(string $relativePath, int $limit, int $offset): array
    {
        $this->assertRelativePath($relativePath);
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE || $offset < 0 || $offset > 1_000_000) {
            throw new LibraryDirectoryBrowseFailed('SETUP_DIRECTORY_REQUEST_INVALID', '目录分页参数无效。');
        }

        $root = realpath($this->storageRoot);
        if (!is_string($root) || !is_dir($root) || !is_readable($root) || is_link($this->storageRoot)) {
            throw new LibraryDirectoryBrowseFailed('SETUP_STORAGE_UNAVAILABLE', '媒体存储当前不可读取。', 503);
        }
        $directory = $root;
        if ($relativePath !== '') {
            foreach (explode('/', $relativePath) as $segment) {
                $directory .= DIRECTORY_SEPARATOR . $segment;
                if (is_link($directory)) {
                    throw new LibraryDirectoryBrowseFailed('SETUP_DIRECTORY_LINK_FORBIDDEN', '不能进入链接目录。');
                }
            }
        }
        $resolved = realpath($directory);
        if (!is_string($resolved) || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))
            || !is_dir($resolved) || !is_readable($resolved)) {
            throw new LibraryDirectoryBrowseFailed('SETUP_DIRECTORY_NOT_FOUND', '目录不存在或当前不可读取。', 404);
        }

        try {
            $iterator = new FilesystemIterator($resolved, FilesystemIterator::SKIP_DOTS);
        } catch (Throwable) {
            throw new LibraryDirectoryBrowseFailed('SETUP_DIRECTORY_UNAVAILABLE', '目录当前不可读取。', 503);
        }

        $entries = [];
        foreach ($iterator as $entry) {
            try {
                $name = $entry->getFilename();
                if (!$this->validOutputName($name)) continue;
                $path = $relativePath === '' ? $name : $relativePath . '/' . $name;
                $this->assertRelativePath($path);
                $isLink = $entry->isLink();
                $isDirectory = !$isLink && $entry->isDir();
                if (!$isLink && !$isDirectory && !$entry->isFile()) continue;
                $stat = @lstat($entry->getPathname());
                if (!is_array($stat)) continue;
                $entries[] = [
                    'name' => $name,
                    'path' => $path,
                    'kind' => $isLink ? 'link' : ($isDirectory ? 'directory' : 'file'),
                    'size' => $isDirectory || $isLink ? null : max(0, (int) ($stat['size'] ?? 0)),
                    'modifiedAt' => (int) ($stat['mtime'] ?? 0) > 0 ? gmdate('c', (int) $stat['mtime']) : null,
                ];
            } catch (Throwable) {
                // 枚举后的条目可能被并发替换；跳过不稳定条目，不能返回混合文件身份。
            }
        }
        usort($entries, static function (array $left, array $right): int {
            $rank = ['directory' => 0, 'link' => 1, 'file' => 2];
            $kind = ($rank[$left['kind']] ?? 9) <=> ($rank[$right['kind']] ?? 9);
            return $kind !== 0 ? $kind : strnatcasecmp((string) $left['name'], (string) $right['name']);
        });
        $total = count($entries);
        if ($offset > 0 && $offset >= $total) {
            $offset = max(0, intdiv(max(0, $total - 1), $limit) * $limit);
        }

        return [
            'directory' => ['path' => $relativePath, 'parentPath' => $this->parentPath($relativePath)],
            'entries' => array_slice($entries, $offset, $limit),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** 相对路径只允许 UTF-8 普通段，防止目录穿越、日志控制字符和平台分隔符歧义。 */
    private function assertRelativePath(string $path): void
    {
        if (strlen($path) > self::MAX_PATH_BYTES || !mb_check_encoding($path, 'UTF-8')
            || str_contains($path, '\\') || str_starts_with($path, '/') || str_ends_with($path, '/')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new LibraryDirectoryBrowseFailed('SETUP_DIRECTORY_PATH_INVALID', '目录位置无效。');
        }
        if ($path === '') return;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new LibraryDirectoryBrowseFailed('SETUP_DIRECTORY_PATH_INVALID', '目录位置无效。');
            }
        }
    }

    /** 输出名称必须能安全组成后续相对浏览请求；异常文件名不会进入客户端状态。 */
    private function validOutputName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && mb_check_encoding($name, 'UTF-8')
            && !str_contains($name, '/') && !str_contains($name, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1;
    }

    /** 根目录没有父级；子目录父级始终仍是同一媒体挂载内的相对路径。 */
    private function parentPath(string $path): ?string
    {
        if ($path === '') return null;
        $separator = strrpos($path, '/');
        return $separator === false ? '' : substr($path, 0, $separator);
    }
}
