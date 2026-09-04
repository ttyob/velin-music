<?php

declare(strict_types=1);

namespace app\application\System;

use app\infrastructure\Audit\AuditLogger;
use PDO;
use RuntimeException;

/**
 * 在固定数据库数据根内创建经过完整性验证的 SQLite 在线备份。
 *
 * 服务不接受用户路径或文件名。`VACUUM INTO` 从独立连接读取一致快照并写入同目录 `.part`，随后使用
 * 新连接执行 quick_check 与 foreign_key_check，全部通过才原子 rename 为只读备份。目标目录、锁、
 * 临时文件和最终文件均拒绝符号链接，且从不覆盖同名文件；异常会删除本次未发布临时文件，已发布备份
 * 保持可恢复。自动清理只删除命名契约匹配且超过最近 14 份的普通文件，不访问媒体或任意外部目录。
 */
final class SqliteBackupService
{
    private string $databasePath;
    private string $backupRoot;

    public function __construct(
        ?string $databasePath = null,
        ?string $backupRoot = null,
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
        $configured = config('database.connections.sqlite.database');
        $selected = $databasePath ?? (is_string($configured) ? $configured : '');
        // 容器把只读镜像内 `/app/database` 固定映射到 `/data/database`。备份必须跟随当前 PDO 实际使用的
        // 已存在数据库文件规范化一次，后续所有输出仍从该真实父目录派生；请求不能提供此路径。
        $resolved = $selected === '' ? false : realpath($selected);
        $this->databasePath = is_string($resolved) ? $resolved : $selected;
        $this->backupRoot = $backupRoot ?? dirname($this->databasePath) . '/backups/automatic';
    }

    /** 自动创建一份备份；不伪装管理员，也不写管理员审计。 */
    public function createAutomatic(): array
    {
        return $this->create();
    }

    /**
     * 为已在 CLI 重新验证 manage_system 的管理员创建备份并写脱敏审计。
     *
     * 文件快照先完成再写审计，因为文件系统操作无法随 SQLite 事务回滚；审计失败时已验证备份仍保留，
     * 返回失败使运维可据此复核。审计仅含不透明 backupId、大小和来源，不记录物理路径。
     */
    public function createManual(string $actorUserId, string $requestId): array
    {
        if ($actorUserId === '' || $requestId === '') {
            throw new RuntimeException('SQLITE_BACKUP_ACTOR_INVALID');
        }
        $result = $this->create();
        $this->audit->record($actorUserId, 'system.database.backup.create', 'database_backup',
            $result['backupId'], 'success', $requestId, ['byteSize' => $result['byteSize'], 'source' => 'cli']);
        return $result;
    }

    /** @return array{backupId:string,byteSize:int,createdAt:string,retained:int} */
    private function create(): array
    {
        $source = realpath($this->databasePath);
        if ($source === false || $source !== $this->databasePath || !is_file($source) || is_link($source)
            || $source === ':memory:') {
            throw new RuntimeException('SQLITE_BACKUP_SOURCE_UNSAFE');
        }
        $root = $this->prepareRoot();
        $lockPath = $root . '/.backup.lock';
        if (is_link($lockPath)) throw new RuntimeException('SQLITE_BACKUP_LOCK_UNSAFE');
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('SQLITE_BACKUP_BUSY');
        }

        $createdAt = gmdate('Y-m-d\TH:i:s\Z');
        $backupId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
        $temporary = $root . '/.' . $backupId . '.sqlite.part';
        $final = $root . '/' . $backupId . '.sqlite';
        try {
            if (file_exists($temporary) || file_exists($final) || is_link($temporary) || is_link($final)) {
                throw new RuntimeException('SQLITE_BACKUP_COLLISION');
            }
            $pdo = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('VACUUM INTO ' . $pdo->quote($temporary));
            $pdo = null;
            if (!is_file($temporary) || is_link($temporary) || !chmod($temporary, 0600)) {
                throw new RuntimeException('SQLITE_BACKUP_TEMPORARY_UNSAFE');
            }
            $this->verify($temporary);
            if (!@rename($temporary, $final)) throw new RuntimeException('SQLITE_BACKUP_PUBLISH_FAILED');
            $size = filesize($final);
            if ($size === false || $size < 1) throw new RuntimeException('SQLITE_BACKUP_PUBLISH_FAILED');
            $retained = $this->prune($root, $final);
            return ['backupId' => $backupId, 'byteSize' => $size, 'createdAt' => $createdAt, 'retained' => $retained];
        } finally {
            if (is_file($temporary) && !is_link($temporary)) @unlink($temporary);
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 建立并复验唯一固定备份目录；父 database 目录也不得经符号链接替换。 */
    private function prepareRoot(): string
    {
        $parent = dirname($this->backupRoot);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('SQLITE_BACKUP_ROOT_UNAVAILABLE');
        }
        if (is_link($parent) || (file_exists($this->backupRoot) && is_link($this->backupRoot))) {
            throw new RuntimeException('SQLITE_BACKUP_ROOT_UNSAFE');
        }
        if (!is_dir($this->backupRoot) && !@mkdir($this->backupRoot, 0700) && !is_dir($this->backupRoot)) {
            throw new RuntimeException('SQLITE_BACKUP_ROOT_UNAVAILABLE');
        }
        $resolved = realpath($this->backupRoot);
        if ($resolved === false || $resolved !== $this->backupRoot || !is_writable($resolved)) {
            throw new RuntimeException('SQLITE_BACKUP_ROOT_UNSAFE');
        }
        @chmod($resolved, 0700);
        return $resolved;
    }

    /** 用独立只读连接验证发布前快照，任何损坏或外键违规都拒绝 rename。 */
    private function verify(string $path): void
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $quick = $pdo->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN);
        $foreign = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;
        if ($quick !== ['ok'] || $foreign !== []) throw new RuntimeException('SQLITE_BACKUP_INTEGRITY_FAILED');
    }

    /** 删除最近十四份之外的契约文件；当前新备份始终保留，删除失败不伪造成功。 */
    private function prune(string $root, string $current): int
    {
        $files = glob($root . '/*.sqlite') ?: [];
        $files = array_values(array_filter($files, static fn (string $path): bool => !is_link($path)
            && is_file($path) && preg_match('/\/\d{8}T\d{6}Z-[a-f0-9]{12}\.sqlite$/D', $path) === 1));
        $others = array_values(array_filter($files, static fn (string $path): bool => $path !== $current));
        usort($others, static fn (string $left, string $right): int => strcmp(basename($right), basename($left)));
        foreach (array_slice($others, 13) as $path) {
            if (!@unlink($path)) throw new RuntimeException('SQLITE_BACKUP_RETENTION_FAILED');
        }
        return min(14, count($files));
    }
}
