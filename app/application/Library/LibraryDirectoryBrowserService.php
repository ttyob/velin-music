<?php

declare(strict_types=1);

namespace app\application\Library;

use FilesystemIterator;
use stdClass;
use support\Db;
use Throwable;

/**
 * 为具有音乐库 manage 范围的管理员提供统一只读目录浏览。
 *
 * 浏览请求只接受根内正斜杠相对目录，不接受物理路径、URL、远端对象 ID 或凭据。本地目录每次都从
 * 已登记真实根重新解析，拒绝根漂移、链接目录和越界；目录中的链接只作为不可进入条目展示。网络库
 * 复用现有只读客户端，响应仅投影名称、根内相对位置、类型、大小与修改时间。所有网络 I/O 和目录枚举
 * 均在 SQLite 事务外执行，方法不创建、修改、移动或删除任何文件，也不改变扫描与库存事实。
 */
final readonly class LibraryDirectoryBrowserService
{
    private const MAX_PATH_BYTES = 768;
    private const MAX_PAGE_SIZE = 200;

    public function __construct(private RemoteLibraryClientFactory $remoteClients = new RemoteLibraryClientFactory())
    {
    }

    /**
     * 返回一个目录的有界分页投影。
     *
     * 前置条件：actor 已通过全局 manage_library 校验，并且对目标库拥有 manage 授权或为超级管理员。
     * 未授权库与不存在库统一按 not found 收口，防止枚举库 ID。相对目录必须由本服务生成的 path 逐级
     * 回传；任何点段、空段、反斜杠、控制字符、链接跳转或目录竞态均失败关闭。分页只裁剪响应，不改变
     * 同次目录读取的自然排序；调用期间目录变化可能影响下一页，因此界面提供显式刷新而不承诺快照锁。
     *
     * @param array<string,mixed> $actor 当前授权主体
     * @return array<string,mixed> 不含物理路径和远端定位信息的目录投影
     */
    public function browse(string $libraryId, string $relativePath, int $limit, int $offset, array $actor): array
    {
        $this->assertRelativePath($relativePath);
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE || $offset < 0 || $offset > 1_000_000) {
            throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_REQUEST_INVALID', '目录分页参数无效。');
        }
        $library = $this->managedLibrary($libraryId, $actor);
        $entries = (string) $library->source_type === 'local'
            ? $this->localEntries((string) $library->resolved_root_path, $relativePath)
            : $this->remoteEntries((string) $library->id, $relativePath);
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
            'library' => ['id' => (string) $library->id, 'name' => (string) $library->name,
                'sourceType' => (string) $library->source_type],
            'directory' => ['path' => $relativePath, 'parentPath' => $this->parentPath($relativePath)],
            'entries' => array_slice($entries, $offset, $limit),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** 读取当前管理范围；授权不足与未知 ID 使用相同异常，不能向调用方泄露库是否存在。 */
    private function managedLibrary(string $libraryId, array $actor): stdClass
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $libraryId) !== 1) throw new LibraryNotFound('音乐库不存在。');
        $query = Db::table('music_libraries as libraries')->where('libraries.id', $libraryId);
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as actor_grant', function ($join) use ($actor): void {
                $join->on('actor_grant.library_id', '=', 'libraries.id')
                    ->where('actor_grant.user_id', '=', (string) $actor['id'])
                    ->where('actor_grant.access_level', '=', 'manage');
            });
        }
        /** @var stdClass|null $library */
        $library = $query->first(['libraries.id', 'libraries.name', 'libraries.source_type',
            'libraries.resolved_root_path']);
        if (!$library instanceof stdClass) throw new LibraryNotFound('音乐库不存在。');
        if (!in_array((string) $library->source_type, ['local', 'webdav', 'onedrive', 'google_drive'], true)) {
            throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_SOURCE_UNSUPPORTED', '该音乐库来源不支持目录浏览。');
        }
        return $library;
    }

    /**
     * 枚举本地真实目录的一层普通条目。
     *
     * 根及请求目录必须保持为登记时的无链接真实路径；中间任一段变为链接时立即拒绝。枚举过程中单个
     * 条目消失会跳过该条目，不会把已变化的 stat 当作可信事实；目录整体不可读则返回稳定 503。
     *
     * @return list<array<string,mixed>>
     */
    private function localEntries(string $configuredRoot, string $relativePath): array
    {
        $configuredRoot = rtrim($configuredRoot, DIRECTORY_SEPARATOR);
        $root = realpath($configuredRoot);
        if (!is_string($root) || $root !== $configuredRoot || is_link($configuredRoot) || !is_dir($root)
            || !is_readable($root)) {
            throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_UNAVAILABLE', '音乐库目录当前不可读取。', 503);
        }
        $directory = $root;
        if ($relativePath !== '') {
            foreach (explode('/', $relativePath) as $segment) {
                $directory .= DIRECTORY_SEPARATOR . $segment;
                if (is_link($directory)) {
                    throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_LINK_FORBIDDEN', '不能进入音乐库中的链接目录。');
                }
            }
        }
        $resolved = realpath($directory);
        if (!is_string($resolved) || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))
            || !is_dir($resolved) || !is_readable($resolved)) {
            throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_NOT_FOUND', '目录不存在或当前不可读取。', 404);
        }
        try {
            $iterator = new FilesystemIterator($resolved, FilesystemIterator::SKIP_DOTS);
        } catch (Throwable) {
            throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_UNAVAILABLE', '音乐库目录当前不可读取。', 503);
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
                $entries[] = $this->entry($name, $path, $isLink ? 'link' : ($isDirectory ? 'directory' : 'file'),
                    $isDirectory || $isLink ? null : max(0, (int) ($stat['size'] ?? 0)),
                    max(0, (int) ($stat['mtime'] ?? 0)));
            } catch (Throwable) {
                // 条目可能在枚举后被并发替换；跳过它，不能返回混合身份事实。
            }
        }
        return $entries;
    }

    /** @return list<array<string,mixed>> */
    private function remoteEntries(string $libraryId, string $relativePath): array
    {
        $objects = $this->remoteClients->forLibrary($libraryId)->listDirectory($relativePath);
        $entries = [];
        foreach ($objects as $object) {
            if ($object->relativePath === $relativePath) continue;
            $parent = dirname($object->relativePath);
            if ($parent === '.') $parent = '';
            if ($parent !== $relativePath) {
                throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_RESPONSE_INVALID', '网络音乐库目录响应无效。', 503);
            }
            $this->assertRelativePath($object->relativePath);
            $name = basename($object->relativePath);
            if (!$this->validOutputName($name)) {
                throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_RESPONSE_INVALID', '网络音乐库目录响应无效。', 503);
            }
            $entries[] = $this->entry($name, $object->relativePath, $object->directory ? 'directory' : 'file',
                $object->directory ? null : max(0, $object->size), max(0, $object->modifiedAt));
        }
        return $entries;
    }

    /** @return array{name:string,path:string,kind:string,size:?int,modifiedAt:?string} */
    private function entry(string $name, string $path, string $kind, ?int $size, int $modifiedAt): array
    {
        return ['name' => $name, 'path' => $path, 'kind' => $kind, 'size' => $size,
            'modifiedAt' => $modifiedAt > 0 ? gmdate('c', $modifiedAt) : null];
    }

    private function assertRelativePath(string $path): void
    {
        if (strlen($path) > self::MAX_PATH_BYTES || !mb_check_encoding($path, 'UTF-8')
            || str_contains($path, '\\') || str_starts_with($path, '/') || str_ends_with($path, '/')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_PATH_INVALID', '目录位置无效。');
        }
        if ($path === '') return;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_PATH_INVALID', '目录位置无效。');
            }
        }
    }

    private function validOutputName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && mb_check_encoding($name, 'UTF-8')
            && !str_contains($name, '/') && !str_contains($name, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1;
    }

    private function parentPath(string $path): ?string
    {
        if ($path === '') return null;
        $separator = strrpos($path, '/');
        return $separator === false ? '' : substr($path, 0, $separator);
    }
}
