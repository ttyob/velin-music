<?php

declare(strict_types=1);

namespace app\application\Storage;

use RuntimeException;
use Throwable;

/**
 * 配置并报告部署方确认的容器音乐库与刮削缓存目录。
 *
 * 本服务只由专用扫描 Worker 调用，HTTP 请求不会触发。音乐库固定为 `/storage/music`，可重建缓存固定
 * 为 `/data/cache/scrape`；两者由不同职责的挂载根承载。服务以 0750 创建缺失目录，不修改已有
 * 所有者/权限，不删除内容，也不跟随软链接。每次实际发布仍须重验真实路径、权限和文件身份。
 * 每次实际发布仍须重验真实路径、包含关系、权限和文件身份。
 */
final class DefaultMediaStorageProvisioner
{
    public const LIBRARY_PATH = StorageLayout::LIBRARY_ROOT;
    public const SCRAPE_CACHE_PATH = StorageLayout::SCRAPE_CACHE_ROOT;

    /**
     * 创建缺失的默认媒体根与刮削缓存根。
     *
     * Parent creation is recursive but bounded by fixed constants. Existing symlinks or non-directory
     * nodes fail closed. 失败不改数据库配置；下次 Worker 启动重试保持幂等，且不会删除期间产生的文件。
     */
    public function provision(): void
    {
        try {
            $library = $this->ensureDirectory(self::LIBRARY_PATH, false);
            $cache = $this->ensureDirectory(self::SCRAPE_CACHE_PATH, true);
            $this->assertDistinctNonOverlapping($library, $cache);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Default media storage provisioning failed.', previous: $throwable);
        }
    }

    /**
     * Returns an administrator-safe health projection without traversing directory contents.
     *
     * Paths are fixed deployment configuration, not user-controlled data. This method performs only
     * stat-style checks and may run in an HTTP worker; it never creates or modifies a filesystem node.
     *
     * @return array{library: array<string, mixed>, cache: array<string, mixed>}
     */
    public function status(): array
    {
        return [
            'library' => $this->inspect(self::LIBRARY_PATH, false),
            'cache' => $this->inspect(self::SCRAPE_CACHE_PATH, true),
        ];
    }

    /** Creates one fixed directory and enforces the read/write preconditions for its role. */
    private function ensureDirectory(string $path, bool $requiresWrite): string
    {
        if (is_link($path)) {
            throw new RuntimeException('Default media path must not be a symbolic link.');
        }
        if (!file_exists($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Default media directory could not be created.');
        }
        $resolved = realpath($path);
        if ($resolved !== $path || !is_dir($path) || !is_readable($path)) {
            throw new RuntimeException('Default media directory is unavailable.');
        }
        if ($requiresWrite && !is_writable($path)) {
            throw new RuntimeException('Default media work directory is not writable.');
        }

        return $resolved;
    }

    /** 保持媒体根与派生缓存根不相等且互不包含，防止扫描把缓存再次识别成媒体。 */
    private function assertDistinctNonOverlapping(string $library, string $cache): void
    {
        $libraryPrefix = rtrim($library, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $cachePrefix = rtrim($cache, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($library === $cache || str_starts_with($libraryPrefix, $cachePrefix) || str_starts_with($cachePrefix, $libraryPrefix)) {
            throw new RuntimeException('Default library and scrape cache directories overlap.');
        }
    }

    /** @return array{path: string, exists: bool, readable: bool, writable: bool} */
    private function inspect(string $path, bool $requiresWrite): array
    {
        $exists = is_dir($path) && !is_link($path);

        return [
            'path' => $path,
            'exists' => $exists,
            'readable' => $exists && is_readable($path),
            'writable' => $requiresWrite && $exists && is_writable($path),
        ];
    }
}
