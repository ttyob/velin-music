<?php

declare(strict_types=1);

namespace app\application\Storage;

use RuntimeException;
use Throwable;

/**
 * 配置并报告部署方确认的容器媒体与刮削缓存目录。
 *
 * 本服务只由专用扫描 Worker 调用，HTTP 请求不会触发。新缓存根来自部署环境但必须规范化为 `/media`
 * 子目录；默认固定为 `/media/cache/scrape`。服务以 0750 创建缺失目录，不修改已有所有者/权限，不删除
 * 内容，也不跟随软链接。新安装只创建 library/cache；升级环境既不探测也不删除遗留 work 目录。
 * 每次实际发布仍须重验真实路径、包含关系、权限和文件身份。
 */
final class DefaultMediaStorageProvisioner
{
    public const LIBRARY_PATH = '/media/library';
    public const SCRAPE_CACHE_PATH = '/media/cache/scrape';

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
            $cache = $this->ensureDirectory($this->scrapeCachePath(), true);
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
            'cache' => $this->inspect($this->scrapeCachePath(), true),
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

    /**
     * 返回部署配置的缓存根，并在创建前按字符串边界拒绝任意路径。
     *
     * 目录可能尚不存在，因此不能先依赖 realpath。只允许规范的 `/media` 后代且拒绝 `.`、`..`、NUL、
     * 根目录和尾部分隔符别名；Docker Compose 固定传入默认值，裸机部署可以选择另一个 `/media` 子目录。
     */
    private function scrapeCachePath(): string
    {
        $path = rtrim((string) (getenv('VELIN_SCRAPE_CACHE_PATH') ?: self::SCRAPE_CACHE_PATH), DIRECTORY_SEPARATOR);
        if ($path === '' || str_contains($path, "\0") || !str_starts_with($path, '/media/')
            || preg_match('#(?:^|/)(?:\.|\.\.)(?:/|$)#', $path) === 1) {
            throw new RuntimeException('Scrape cache path must be a normalized /media descendant.');
        }

        return $path;
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
