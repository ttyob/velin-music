<?php

declare(strict_types=1);

namespace app\application\System;

use app\application\Storage\StorageLayout;

use PDO;
use Throwable;

/**
 * 提供元数据插件使用的只读艺人辅助 SQLite 与唯一活动上传存储内核。
 *
 * 默认根固定在 `/data/cache/artist-database`，属于应用数据根下的可重建缓存，不接受环境变量、客户端路径
 * 或原始文件名作为物理路径。每个分块在进程锁下按服务端记录的连续偏移写入；完成时校验文件头、
 * `quick_check`、必需表列和最低数据量，再以同文件系统 rename 原子替换。任何校验失败都会删除临时
 * 文件并保留旧库，读取状态和匹配查询从不修改业务数据库。该类不再由系统设置 Controller 直接调用，
 * 管理入口必须经 `metadata-scrape` 插件适配器和核心统一的插件权限路由进入；保留固定命名空间是为了
 * 让核心只读身份解析器与插件在迁移期间共享同一物理库和异常合同。
 */
final class ArtistDatabaseStorageService
{
    public const CHUNK_BYTES = 8 * 1024 * 1024;
    public const MAX_BYTES = 8 * 1024 * 1024 * 1024;
    private const SESSION_TTL_SECONDS = 86400;
    private const FREE_SPACE_RESERVE_BYTES = 64 * 1024 * 1024;
    private const DATABASE_NAME = 'musicbrainz_artists_zh.sqlite';

    /**
     * 创建指向固定辅助库根的短生命周期服务。
     *
     * 生产代码不得传入自定义路径；可注入根和最低行数只用于隔离单元测试。构造函数不访问文件系统，
     * 目录创建和权限检查推迟到公开操作，使 Controller 能先完成实时授权。
     */
    public function __construct(
        private readonly string $root = StorageLayout::ARTIST_DATABASE_ROOT,
        private readonly int $minimumArtists = 100_000,
        private readonly int $minimumNames = 100_000,
    ) {
    }

    /**
     * 返回不含物理路径的安装和上传状态。
     *
     * 本方法不会执行昂贵的完整性检查；发布清单记录的是最近一次成功校验结果，读取时仅复验文件仍为
     * 普通 SQLite 文件。清单缺失或与当前 inode 大小不符时失败关闭为 unavailable，避免把手工替换或
     * 崩溃残留误报为已验证数据库。
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $this->ensureRoot();
        return $this->withLock(function (): array {
            $this->expireUpload();
            $database = $this->databasePath();
            $manifest = $this->readJson($this->manifestPath());
            $available = $this->isRegularSqlite($database)
                && $this->validManifest($manifest)
                && ($manifest['sizeBytes'] ?? null) === filesize($database);
            $upload = $this->readSession();
            return [
                'available' => $available,
                'fileName' => $available ? self::DATABASE_NAME : null,
                'sizeBytes' => $available ? $manifest['sizeBytes'] : null,
                'sha256' => $available ? $manifest['sha256'] : null,
                'artistCount' => $available ? $manifest['artistCount'] : null,
                'artistNameCount' => $available ? $manifest['artistNameCount'] : null,
                'updatedAt' => $available ? $manifest['updatedAt'] : null,
                'upload' => $upload === null ? null : $this->publicSession($upload),
                'chunkBytes' => self::CHUNK_BYTES,
                'maxBytes' => self::MAX_BYTES,
            ];
        });
    }

    /**
     * 创建唯一活动上传并返回服务端分配的随机会话。
     *
     * 文件名必须以小写 `.sqlite` 结尾，大小以字节计且不能超过 8 GiB。原文件名只用于扩展名校验，
     * 不落盘也不进入状态响应。并发请求由根目录锁串行化；未过期会话存在时返回冲突，不覆盖其分块。
     *
     * @return array<string,mixed>
     */
    public function start(string $fileName, int $totalBytes): array
    {
        if ($fileName === '' || strlen($fileName) > 255 || preg_match('/[\/\\\\\x00-\x1f]/', $fileName) === 1
            || basename($fileName) !== $fileName
            || pathinfo($fileName, PATHINFO_EXTENSION) !== 'sqlite') {
            throw new ArtistDatabaseInvalid('ARTIST_DATABASE_EXTENSION_INVALID');
        }
        if ($totalBytes < 100 || $totalBytes > self::MAX_BYTES) {
            throw new ArtistDatabaseInvalid('ARTIST_DATABASE_SIZE_INVALID');
        }
        $this->ensureRoot();
        return $this->withLock(function () use ($totalBytes): array {
            $this->expireUpload();
            if ($this->readSession() !== null) throw new ArtistDatabaseConflict('ARTIST_DATABASE_UPLOAD_ACTIVE');
            $free = @disk_free_space($this->root);
            if (is_float($free) && $free < $totalBytes + self::FREE_SPACE_RESERVE_BYTES) {
                throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_SPACE_INSUFFICIENT');
            }
            $id = bin2hex(random_bytes(16));
            $part = $this->partPath($id);
            $handle = @fopen($part, 'x+b');
            if ($handle === false) throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_STAGING_CREATE_FAILED');
            fclose($handle);
            @chmod($part, 0640);
            $now = gmdate('c');
            $session = ['id' => $id, 'totalBytes' => $totalBytes, 'receivedBytes' => 0,
                'createdAt' => $now, 'updatedAt' => $now];
            try {
                $this->writeJsonAtomically($this->sessionPath(), $session);
            } catch (Throwable $throwable) {
                @unlink($part);
                throw $throwable;
            }
            return $this->publicSession($session);
        });
    }

    /**
     * 追加一个原始二进制分块。
     *
     * offset 必须精确等于服务端 receivedBytes，分块为 1..8 MiB 且不得越过声明总大小；分块 SHA-256
     * 必须是 64 位小写十六进制并与正文一致。写入后执行 fflush/fsync，再更新会话，因此响应丢失时客户
     * 端可读取状态并从服务端偏移继续，绝不能猜测偏移或覆盖既有字节。
     *
     * @return array<string,mixed>
     */
    public function append(string $id, int $offset, string $bytes, string $sha256): array
    {
        $length = strlen($bytes);
        if ($length < 1 || $length > self::CHUNK_BYTES) throw new ArtistDatabaseInvalid('ARTIST_DATABASE_CHUNK_SIZE_INVALID');
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new ArtistDatabaseInvalid('ARTIST_DATABASE_CHUNK_HASH_INVALID');
        }
        $this->assertId($id);
        $this->ensureRoot();
        return $this->withLock(function () use ($id, $offset, $bytes, $length): array {
            $session = $this->requireSession($id);
            if ($offset !== $session['receivedBytes']) throw new ArtistDatabaseConflict('ARTIST_DATABASE_OFFSET_CONFLICT');
            if ($offset + $length > $session['totalBytes']) throw new ArtistDatabaseInvalid('ARTIST_DATABASE_CHUNK_OVERFLOW');
            $free = @disk_free_space($this->root);
            if (is_float($free) && $free < $length + self::FREE_SPACE_RESERVE_BYTES) {
                throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_SPACE_INSUFFICIENT');
            }
            $part = $this->partPath($id);
            if (!$this->isRegularFile($part) || filesize($part) !== $offset) {
                throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_STAGING_STATE_INVALID');
            }
            $handle = @fopen($part, 'c+b');
            if ($handle === false || fseek($handle, $offset) !== 0) {
                if (is_resource($handle)) fclose($handle);
                throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_STAGING_OPEN_FAILED');
            }
            $written = 0;
            while ($written < $length) {
                $count = fwrite($handle, substr($bytes, $written));
                if ($count === false || $count === 0) {
                    fclose($handle);
                    throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_STAGING_WRITE_FAILED');
                }
                $written += $count;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                fclose($handle);
                throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_STAGING_SYNC_FAILED');
            }
            fclose($handle);
            $session['receivedBytes'] += $length;
            $session['updatedAt'] = gmdate('c');
            $this->writeJsonAtomically($this->sessionPath(), $session);
            return $this->publicSession($session);
        });
    }

    /**
     * 校验完整临时库并原子发布。
     *
     * 校验期间持有唯一上传锁，避免完成与取消、追加交错。PDO 开启 query_only，不创建索引或修复上传
     * 内容；必需的 `artists`、`artist_names`、`artist_names_fts` 及查询列必须存在，数据量低于生产门槛
     * 视为传错库。只有全部校验和 SHA-256 完成后才 rename，失败删除当前临时文件和会话，旧库不变。
     *
     * @return array<string,mixed>
     */
    public function complete(string $id): array
    {
        $this->assertId($id);
        $this->ensureRoot();
        return $this->withLock(function () use ($id): array {
            $session = $this->requireSession($id);
            $part = $this->partPath($id);
            if ($session['receivedBytes'] !== $session['totalBytes'] || !$this->isRegularFile($part)
                || filesize($part) !== $session['totalBytes']) {
                throw new ArtistDatabaseConflict('ARTIST_DATABASE_UPLOAD_INCOMPLETE');
            }
            try {
                $facts = $this->validate($part);
                $facts['sizeBytes'] = $session['totalBytes'];
                $facts['sha256'] = hash_file('sha256', $part);
                $facts['updatedAt'] = gmdate('c');
                if (!is_string($facts['sha256'])) throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_HASH_FAILED');
                $this->writeJsonAtomically($this->manifestPendingPath(), $facts);
                @chmod($part, 0440);
                $backup = $this->root . '/.previous.sqlite';
                @unlink($backup);
                $hadPrevious = $this->isRegularFile($this->databasePath());
                if ($hadPrevious && !@link($this->databasePath(), $backup)) {
                    throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_ROLLBACK_LINK_FAILED');
                }
                if (!@rename($part, $this->databasePath())) {
                    @unlink($backup);
                    throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_PUBLISH_FAILED');
                }
                if (!@rename($this->manifestPendingPath(), $this->manifestPath())) {
                    @unlink($this->databasePath());
                    if ($hadPrevious) @rename($backup, $this->databasePath());
                    throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_MANIFEST_PUBLISH_FAILED');
                }
                @unlink($backup);
                @unlink($this->sessionPath());
                return $this->statusWithoutLock($facts);
            } catch (Throwable $throwable) {
                @unlink($part);
                @unlink($this->manifestPendingPath());
                @unlink($this->sessionPath());
                if ($throwable instanceof ArtistDatabaseException) throw $throwable;
                throw new ArtistDatabaseInvalid('ARTIST_DATABASE_SQLITE_INVALID', 0, $throwable);
            }
        });
    }

    /** 取消当前会话并删除其受控临时文件；已发布数据库和清单不受影响，重复取消返回 404。 */
    public function cancel(string $id): void
    {
        $this->assertId($id);
        $this->ensureRoot();
        $this->withLock(function () use ($id): void {
            $this->requireSession($id);
            @unlink($this->partPath($id));
            @unlink($this->sessionPath());
        });
    }

    /** @return array{artistCount:int,artistNameCount:int} */
    private function validate(string $path): array
    {
        $header = file_get_contents($path, false, null, 0, 16);
        if ($header !== "SQLite format 3\0") throw new ArtistDatabaseInvalid('ARTIST_DATABASE_MAGIC_INVALID');
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only = ON');
        $quick = $pdo->query('PRAGMA quick_check')->fetchColumn();
        if ($quick !== 'ok') throw new ArtistDatabaseInvalid('ARTIST_DATABASE_QUICK_CHECK_FAILED');
        $required = [
            'artists' => ['mbid', 'name_zh', 'name_original', 'country'],
            'artist_names' => ['mbid', 'name_zh', 'name_original'],
            'artist_names_fts' => ['mbid'],
        ];
        foreach ($required as $table => $columns) {
            $rows = $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
            $actual = array_column($rows, 'name');
            if (array_diff($columns, $actual) !== []) throw new ArtistDatabaseInvalid('ARTIST_DATABASE_SCHEMA_INVALID');
        }
        $artists = (int) $pdo->query('SELECT COUNT(*) FROM artists')->fetchColumn();
        $names = (int) $pdo->query('SELECT COUNT(*) FROM artist_names')->fetchColumn();
        if ($artists < $this->minimumArtists || $names < $this->minimumNames) {
            throw new ArtistDatabaseInvalid('ARTIST_DATABASE_ROWS_INSUFFICIENT');
        }
        return ['artistCount' => $artists, 'artistNameCount' => $names];
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function statusWithoutLock(array $facts): array
    {
        return ['available' => true, 'fileName' => self::DATABASE_NAME,
            'sizeBytes' => $facts['sizeBytes'], 'sha256' => $facts['sha256'],
            'artistCount' => $facts['artistCount'], 'artistNameCount' => $facts['artistNameCount'],
            'updatedAt' => $facts['updatedAt'], 'upload' => null,
            'chunkBytes' => self::CHUNK_BYTES, 'maxBytes' => self::MAX_BYTES];
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    private function publicSession(array $session): array
    {
        return ['id' => $session['id'], 'totalBytes' => $session['totalBytes'],
            'receivedBytes' => $session['receivedBytes'], 'createdAt' => $session['createdAt'],
            'updatedAt' => $session['updatedAt']];
    }

    /** @return array<string,mixed> */
    private function requireSession(string $id): array
    {
        $this->expireUpload();
        $session = $this->readSession();
        if ($session === null || !hash_equals((string) $session['id'], $id)) {
            throw new ArtistDatabaseUploadNotFound('ARTIST_DATABASE_UPLOAD_NOT_FOUND');
        }
        return $session;
    }

    private function expireUpload(): void
    {
        $session = $this->readSession();
        if ($session === null) return;
        $created = strtotime((string) ($session['createdAt'] ?? '')) ?: 0;
        if ($created > 0 && $created >= time() - self::SESSION_TTL_SECONDS) return;
        if (isset($session['id']) && is_string($session['id']) && preg_match('/^[a-f0-9]{32}$/', $session['id']) === 1) {
            @unlink($this->partPath($session['id']));
        }
        @unlink($this->sessionPath());
    }

    /** @return array<string,mixed>|null */
    private function readSession(): ?array
    {
        $value = $this->readJson($this->sessionPath());
        if (!is_array($value) || !isset($value['id'], $value['totalBytes'], $value['receivedBytes'])) return null;
        return $value;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!$this->isRegularFile($path)) return null;
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 复验发布清单的完整类型边界。
     *
     * 清单与数据库同属受控目录，但仍可能因断电或手工修改而损坏；缺字段、错误摘要或低于生产门槛的
     * 计数都不能被 status 投影为 available。这里只检查小型清单，不重新扫描数百万行数据库。
     */
    private function validManifest(?array $manifest): bool
    {
        return is_array($manifest)
            && is_int($manifest['sizeBytes'] ?? null) && $manifest['sizeBytes'] >= 100
            && is_string($manifest['sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $manifest['sha256']) === 1
            && is_int($manifest['artistCount'] ?? null) && $manifest['artistCount'] >= $this->minimumArtists
            && is_int($manifest['artistNameCount'] ?? null) && $manifest['artistNameCount'] >= $this->minimumNames
            && is_string($manifest['updatedAt'] ?? null) && strtotime($manifest['updatedAt']) !== false;
    }

    /**
     * 在目标目录内先完整落盘 JSON，再原子替换状态文件。
     *
     * 临时名由服务端随机生成且不跟随符号链接；写入失败会清理临时文件。rename 成功是可见性边界，
     * JSON 状态不需要与业务数据库建立跨存储事务。
     */
    private function writeJsonAtomically(string $path, array $value): void
    {
        $temporary = $this->root . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_STATE_CREATE_FAILED');
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_STATE_WRITE_FAILED');
            }
            fclose($handle);
            $handle = null;
            @chmod($temporary, 0640);
            if (!@rename($temporary, $path)) throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_STATE_PUBLISH_FAILED');
        } finally {
            if (is_resource($handle)) fclose($handle);
            @unlink($temporary);
        }
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0750, true) && !is_dir($this->root)) {
            throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_ROOT_CREATE_FAILED');
        }
        if (is_link($this->root) || !is_writable($this->root)) {
            throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_ROOT_INVALID');
        }
    }

    private function assertId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) throw new ArtistDatabaseUploadNotFound('ARTIST_DATABASE_UPLOAD_NOT_FOUND');
    }

    private function isRegularSqlite(string $path): bool
    {
        return $this->isRegularFile($path) && file_get_contents($path, false, null, 0, 16) === "SQLite format 3\0";
    }

    private function isRegularFile(string $path): bool
    {
        $stat = @lstat($path);
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && !is_link($path);
    }

    /** 锁覆盖单个根内的状态和文件操作；回调不得再次调用会获取同一锁的公开方法。 */
    private function withLock(callable $operation): mixed
    {
        $handle = @fopen($this->root . '/.upload.lock', 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            throw new ArtistDatabaseUnavailable('ARTIST_DATABASE_LOCK_FAILED');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function databasePath(): string { return $this->root . '/' . self::DATABASE_NAME; }
    private function sessionPath(): string { return $this->root . '/upload.json'; }
    private function manifestPath(): string { return $this->root . '/manifest.json'; }
    private function manifestPendingPath(): string { return $this->root . '/manifest.pending.json'; }
    private function partPath(string $id): string { return $this->root . '/.' . $id . '.part'; }
}
