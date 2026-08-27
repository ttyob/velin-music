<?php

declare(strict_types=1);

namespace app\application\Storage;

use Illuminate\Database\QueryException;

/**
 * 在大文件写入前执行统一容量与挂载身份准入（ADMIN-STORAGE-005/006）。
 *
 * 本服务只接收已经由上游从数据库固定记录解析出的服务端路径，不能直接连接到 HTTP 请求。每次判断在
 * SQLite 事务外执行 stat/realpath/disk_free_space；它不创建目录、不枚举文件。未建立过基线不会阻断
 * 既有部署，但一旦管理员确认过包含当前目标的根，设备号变化会失败关闭。容量严重阈值始终生效。
 */
final readonly class StorageWriteGuard
{
    public function __construct(private StorageGovernanceService $governance = new StorageGovernanceService())
    {
    }

    /** 新上传会话按完整声明大小预留，防止清单建立后必然穿透安全余量。 */
    public function assertUploadAllowed(string $watchRoot, int $declaredBytes): void
    {
        $this->assertPathAllowed($watchRoot, max(0, $declaredBytes), true);
    }

    /** 每个分片写入前重新检查实际剩余空间，应对会话建立后的外部磁盘占用。 */
    public function assertUploadChunkAllowed(string $watchRoot, int $chunkBytes): void
    {
        $this->assertPathAllowed($watchRoot, max(0, $chunkBytes), true);
    }

    /** 只有会落地完整临时文件的精确长度转码才扩大缓存。 */
    public function assertTranscodeCacheAllowed(string $cacheRoot, int $maximumBytes): void
    {
        $this->assertPathAllowed($cacheRoot, max(0, $maximumBytes), true);
    }

    /**
     * 整理始终检查已确认身份；只有复制或跨盘移动按完整文件大小检查容量。
     * 硬链接和软链接不会复制音频字节，移动在同设备使用 rename，因此不会按文件大小重复预留。
     */
    public function assertOrganizeAllowed(string $source, string $resultRoot, string $mode, int $sourceBytes): void
    {
        $sourceStat = @stat($source);
        $resultStat = @stat($resultRoot);
        $crossDeviceMove = $mode === 'move' && is_array($sourceStat) && is_array($resultStat)
            && (string) $sourceStat['dev'] !== (string) $resultStat['dev'];
        $expands = $mode === 'copy' || $crossDeviceMove;
        $this->assertPathAllowed($resultRoot, $expands ? max(0, $sourceBytes) : 0, $expands);
    }

    /**
     * 检查目标所在现有目录的容量以及最长匹配确认根的设备身份。
     *
     * 严重判断同时考虑当前百分比、写入后的预计百分比和绝对安全预留。身份比较使用 realpath 与 stat
     * 设备号；完整 mount point/filesystem 变化仍由后台诊断检测并展示，设备变化在 Worker 热路径直接阻断。
     */
    private function assertPathAllowed(string $path, int $additionalBytes, bool $capacitySensitive): void
    {
        $existing = $this->existingDirectory($path);
        if ($existing === null) throw new StorageWriteBlocked('STORAGE_TARGET_UNAVAILABLE');
        $real = realpath($existing);
        $stat = @stat($existing);
        if (!is_string($real) || !is_array($stat)) throw new StorageWriteBlocked('STORAGE_IDENTITY_UNKNOWN');

        try {
            $policy = $this->governance->policy();
            $baselines = $this->governance->baselines();
        } catch (QueryException) {
            $policy = [
                'criticalFreePercent' => StorageGovernanceService::DEFAULT_CRITICAL_PERCENT,
                'safetyReserveBytes' => StorageGovernanceService::DEFAULT_SAFETY_RESERVE_BYTES,
            ];
            $baselines = [];
        }
        $baseline = $this->longestBaseline($real, $baselines);
        if (is_array($baseline) && !hash_equals((string) $baseline['deviceId'], (string) $stat['dev'])) {
            throw new StorageWriteBlocked('STORAGE_MOUNT_IDENTITY_CHANGED');
        }
        if (!$capacitySensitive) return;

        $total = @disk_total_space($existing);
        $free = @disk_free_space($existing);
        if (!is_float($total) || !is_float($free) || $total <= 0) throw new StorageWriteBlocked('STORAGE_CAPACITY_UNKNOWN');
        $projected = $free - $additionalBytes;
        if ($projected <= (int) $policy['safetyReserveBytes']
            || ($projected / $total) * 100 < (int) $policy['criticalFreePercent']) {
            throw new StorageWriteBlocked('STORAGE_CAPACITY_CRITICAL');
        }
    }

    /** 向上寻找最近现有目录，只处理受信任服务端路径且绝不创建缺失父目录。 */
    private function existingDirectory(string $path): ?string
    {
        $candidate = $path;
        while ($candidate !== '' && $candidate !== DIRECTORY_SEPARATOR && !is_dir($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) return null;
            $candidate = $parent;
        }
        return is_dir($candidate) ? $candidate : null;
    }

    /** 选择包含目标的最长已确认路径，避免较宽根掩盖更具体根的设备身份。 */
    private function longestBaseline(string $realPath, array $baselines): ?array
    {
        $match = null;
        $length = -1;
        foreach ($baselines as $baseline) {
            $root = rtrim((string) ($baseline['resolvedPath'] ?? ''), DIRECTORY_SEPARATOR);
            if ($root === '' || ($realPath !== $root && !str_starts_with($realPath, $root . DIRECTORY_SEPARATOR))) continue;
            if (strlen($root) > $length) {
                $match = $baseline;
                $length = strlen($root);
            }
        }
        return $match;
    }
}
