<?php

declare(strict_types=1);

namespace app\application\System;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RuntimeException;

/**
 * 读取固定自动备份目录的脱敏状态，供管理概览提醒部署管理员。
 *
 * 本服务只从当前 SQLite 数据库旁的 `backups/automatic` 读取已经原子发布的契约文件，不接受 HTTP
 * 路径，也不会创建目录、打开文件正文、校验数据库内容、触发备份或恢复。数据库及目录必须是无符号链接
 * 的规范绝对路径；身份异常会抛出稳定异常，由概览模块降级为 unknown，避免把被替换的目录误报为正常。
 * 返回值只包含时间、大小和数量，不包含数据库路径、备份文件名、摘要或恢复所需信息。
 */
final class SqliteBackupStatusService
{
    private const ATTENTION_AFTER_SECONDS = 30 * 60 * 60;
    private const CRITICAL_AFTER_SECONDS = 48 * 60 * 60;

    private string $databasePath;

    /**
     * 固定状态探针的数据源和采样时间。
     *
     * 生产环境不传参数并沿用 Webman SQLite 连接配置。测试可注入隔离数据库和 Unix 秒时间，但不能
     * 注入另一备份目录，因此目录边界始终由数据库位置唯一派生。构造过程不访问文件系统且无副作用。
     */
    public function __construct(?string $databasePath = null, private readonly ?int $now = null)
    {
        $configured = config('database.connections.sqlite.database');
        $this->databasePath = $databasePath ?? (is_string($configured) ? $configured : '');
    }

    /**
     * 返回最近一次成功自动备份的新鲜度与保留数量。
     *
     * 只有严格匹配 `YYYYMMDDTHHMMSSZ-<12 hex>.sqlite`、普通非链接且大小大于零的文件才可成为成功
     * 事实。没有契约文件表示严重缺失；超过 30 小时为需关注，超过 48 小时为严重。未来时间超过五分钟、
     * 契约文件身份异常、遍历失败或固定目录被链接替换都会失败关闭。该方法只读且幂等，文件系统并发变化
     * 最多使本次模块返回 unknown，不会修改或清理任何备份。
     *
     * @return array{status:string,data:array{lastSuccessAt:?string,lastSizeBytes:int,retainedCount:int,ageSeconds:?int,reasonCode:?string}}
     */
    public function status(): array
    {
        $database = realpath($this->databasePath);
        if ($database === false || $database !== $this->databasePath || is_link($database) || !is_file($database)) {
            throw new RuntimeException('SQLITE_BACKUP_STATUS_SOURCE_UNSAFE');
        }

        $root = dirname($database) . '/backups/automatic';
        $backupParent = dirname($root);
        if (is_link($backupParent) || (file_exists($backupParent)
            && (!is_dir($backupParent) || realpath($backupParent) !== $backupParent))) {
            throw new RuntimeException('SQLITE_BACKUP_STATUS_ROOT_UNSAFE');
        }
        if (is_link($root)) {
            throw new RuntimeException('SQLITE_BACKUP_STATUS_ROOT_UNSAFE');
        }
        if (!file_exists($root)) {
            return $this->missing();
        }
        if (!is_dir($root) || realpath($root) !== $root) {
            throw new RuntimeException('SQLITE_BACKUP_STATUS_ROOT_UNSAFE');
        }

        $backups = [];
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getFilename();
            if (preg_match('/^(\d{8}T\d{6}Z)-[a-f0-9]{12}\.sqlite$/D', $name, $matches) !== 1) {
                continue;
            }
            $path = $entry->getPathname();
            clearstatcache(true, $path);
            $size = $entry->isLink() || !$entry->isFile() ? false : $entry->getSize();
            if ($size === false || $size < 1 || realpath($path) !== $path) {
                throw new RuntimeException('SQLITE_BACKUP_STATUS_FILE_UNSAFE');
            }
            $createdAt = DateTimeImmutable::createFromFormat(
                '!Ymd\THis\Z',
                $matches[1],
                new DateTimeZone('UTC'),
            );
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($createdAt === false || ($dateErrors !== false
                && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $createdAt->format('Ymd\THis\Z') !== $matches[1]) {
                throw new RuntimeException('SQLITE_BACKUP_STATUS_TIMESTAMP_INVALID');
            }
            $backups[] = ['timestamp' => $createdAt->getTimestamp(), 'size' => $size];
        }

        if ($backups === []) {
            return $this->missing();
        }
        usort($backups, static fn (array $left, array $right): int => $right['timestamp'] <=> $left['timestamp']);
        $now = $this->now ?? time();
        if ($backups[0]['timestamp'] > $now + 300) {
            throw new RuntimeException('SQLITE_BACKUP_STATUS_TIMESTAMP_IN_FUTURE');
        }
        $age = max(0, $now - $backups[0]['timestamp']);
        $status = 'normal';
        $reason = null;
        if ($age > self::CRITICAL_AFTER_SECONDS) {
            $status = 'critical';
            $reason = 'BACKUP_OVERDUE';
        } elseif ($age > self::ATTENTION_AFTER_SECONDS) {
            $status = 'attention';
            $reason = 'BACKUP_STALE';
        }

        return ['status' => $status, 'data' => [
            'lastSuccessAt' => gmdate('Y-m-d\TH:i:s\Z', $backups[0]['timestamp']),
            'lastSizeBytes' => $backups[0]['size'],
            'retainedCount' => count($backups),
            'ageSeconds' => $age,
            'reasonCode' => $reason,
        ]];
    }

    /** 没有已发布契约文件时返回严重提醒；不创建缺失目录，也不猜测上次成功时间。 */
    private function missing(): array
    {
        return ['status' => 'critical', 'data' => [
            'lastSuccessAt' => null,
            'lastSizeBytes' => 0,
            'retainedCount' => 0,
            'ageSeconds' => null,
            'reasonCode' => 'BACKUP_MISSING',
        ]];
    }
}
