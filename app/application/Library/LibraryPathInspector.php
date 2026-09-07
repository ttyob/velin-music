<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\Storage\StorageLayout;

/**
 * Resolves and compares library roots without enumerating their contents.
 *
 * No file is created, modified, opened, or deleted. realpath collapses `..` components and root
 * symlinks before identity checks, preventing two aliases from indexing the same subtree.
 * Child symlinks remain governed by each library's explicit symlink policy during future scans.
 */
final class LibraryPathInspector
{
    public const MEDIA_ROOT = StorageLayout::LIBRARY_ROOT;

    /**
     * 创建音乐库路径检查器。
     *
     * 目录不再绑定某个固定容器前缀；Docker 只允许访问实际挂载进容器的目录，原生 FPK 可直接访问宿主
     * 文件系统。可选参数仅为旧测试保留，不参与安全判断。检查器不创建目录，根缺失、为链接或不可访问
     * 时由解析方法失败关闭。
     */
    public function __construct(private readonly string $allowedRoot = self::MEDIA_ROOT)
    {
    }

    /**
     * Returns a canonical existing readable directory path.
     *
     * The filesystem root is forbidden because it would expose application files and unrelated
     * mounts. Failure messages remain operationally useful but are returned only to authorized
     * library managers, never public or ordinary-user endpoints.
     *
     * @throws LibraryPathInvalid For relative, missing, unreadable, or root paths.
     */
    public function resolve(string $path): string
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new LibraryPathInvalid('音乐库根目录必须使用绝对路径。');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            throw new LibraryPathInvalid('音乐库根目录不存在或不是目录。');
        }
        if ($resolved === DIRECTORY_SEPARATOR) {
            throw new LibraryPathInvalid('不能把文件系统根目录登记为音乐库。');
        }
        if (!is_readable($resolved)) {
            throw new LibraryPathInvalid('当前服务账户无法读取该目录。');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * 解析服务进程可见的已存在目录，并按资源策略决定是否要求写权限。
     *
     * `managed_cache` 音乐库只需可读，派生文件写入独立缓存；`adjacent` 必须可写，因为发布歌词时会
     * 在音频父目录创建临时文件。本方法不创建目录，失败无文件系统副作用；调用方在提交配置的写事务
     * 内必须再次解析，以防挂载或软链接竞态。
     *
     * @throws LibraryPathInvalid 当目录不存在、越界、不可读或缺少所需写权限时。
     */
    public function resolveMediaDirectory(string $path, string $role, bool $requiresWrite): string
    {
        $resolved = $this->resolve($path);
        if ($requiresWrite && !is_writable($resolved)) {
            throw new LibraryPathInvalid('当前服务账户无法写入' . $role . '。');
        }

        return $resolved;
    }

    /**
     * Returns true when two canonical paths are equal or one contains the other.
     *
     * Callers must pass realpath-normalized absolute paths. The separator suffix prevents sibling
     * names such as `/music` and `/music-old` from being treated as containment.
     */
    public function overlaps(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        $leftPrefix = rtrim($left, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $rightPrefix = rtrim($right, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($leftPrefix, $rightPrefix) || str_starts_with($rightPrefix, $leftPrefix);
    }
}
