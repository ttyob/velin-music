<?php

declare(strict_types=1);

namespace app\application\Media;

use stdClass;
use support\Db;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * 在扫描 Worker 中生成重复候选使用的字节哈希和 Chromaprint 声学指纹（ADMIN-META-011）。
 *
 * 本服务只接受已经由扫描发现并位于音乐库根内的规范绝对路径，但仍在读取前后复验 device、inode、
 * size 和 mtime，防止扫描期间文件或符号链接被替换。SHA-256 与指纹绑定同一个证据签名；只有两次
 * 身份复验均成功才写成 ready。HTTP 请求不会调用本服务，避免页面访问触发高成本磁盘读取或解码。
 *
 * 字节哈希和声学指纹失败彼此独立：SHA-256 成功后即使 fpcalc 不支持文件，字节证据仍保留 ready；
 * 稳定错误码只用于管理员判断证据缺失，不包含路径、命令输出或源标签。重复扫描对同一 ready 签名
 * 幂等跳过；failed 会在后续正常扫描重试，文件身份变化则由发现阶段先清空全部旧证据。
 */
final class AudioDuplicateEvidenceIndexer
{
    private const MAX_OUTPUT_BYTES = 1_048_576;

    public function __construct(
        private readonly ?string $fingerprintBinary = null,
        private readonly ?float $timeoutSeconds = null,
    ) {
    }

    /**
     * 为一个库存文件增量生成证据；不改变歌曲、标签、扫描结果或媒体内容。
     *
     * @param stdClass $row 必须含库存 ID、四元文件身份、现有证据状态和证据签名。
     * @throws MediaProbeFailed 文件在证据计算前后发生身份变化时抛出，让扫描记录真实失败而不是保存
     *         与错误文件绑定的摘要；fpcalc 自身失败会被持久化为声学证据 failed，不中断元数据扫描。
     */
    public function index(stdClass $row, string $absolutePath): void
    {
        $signature = $this->signature($row);
        if ((string) ($row->duplicate_evidence_signature ?? '') === $signature
            && (string) ($row->byte_hash_status ?? '') === 'ready'
            && (string) ($row->acoustic_fingerprint_status ?? '') === 'ready') {
            return;
        }

        $this->assertIdentity($row, $absolutePath);
        $byteHash = null;
        if ((string) ($row->duplicate_evidence_signature ?? '') === $signature
            && (string) ($row->byte_hash_status ?? '') === 'ready'
            && is_string($row->byte_sha256 ?? null)) {
            $byteHash = (string) $row->byte_sha256;
        } else {
            $computed = @hash_file('sha256', $absolutePath);
            if (!is_string($computed) || strlen($computed) !== 64) {
                $this->storeFailure($row, $signature, 'BYTE_HASH_FAILED');
                return;
            }
            $byteHash = $computed;
        }

        [$fingerprintHash, $duration, $errorCode] = $this->fingerprint($absolutePath);
        $this->assertIdentity($row, $absolutePath);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $values = [
            'byte_hash_status' => 'ready', 'byte_sha256' => $byteHash,
            'acoustic_fingerprint_status' => $errorCode === null ? 'ready' : 'failed',
            'acoustic_fingerprint_sha256' => $fingerprintHash,
            'acoustic_duration_seconds' => $duration,
            'duplicate_evidence_signature' => $signature,
            'duplicate_evidence_error_code' => $errorCode,
            'updated_at' => $now,
        ];
        if ($this->identityQuery($row)->update($values) !== 1) {
            throw new MediaProbeFailed('MEDIA_FILE_CHANGED', '文件内容在重复证据计算期间发生变化。');
        }
    }

    /**
     * 以固定 argv 调用 fpcalc 并把完整指纹摘要化，禁止 shell、原始输出日志和无界内存。
     *
     * `fpcalc -json` 返回媒体时长和压缩指纹；数据库只保存 SHA-256，既能稳定分组，也避免把长指纹
     * 暴露给 API。超时、不可执行、退出失败或格式异常都映射为稳定错误码，调用方仍保存字节哈希。
     *
     * @return array{?string,?int,?string} 指纹 SHA-256、秒级时长和失败码。
     */
    private function fingerprint(string $absolutePath): array
    {
        $binary = $this->fingerprintBinary ?? (getenv('VELIN_FPCALC_PATH') ?: base_path('bin/fpcalc'));
        if (!str_starts_with($binary, '/') || !is_file($binary) || !is_executable($binary)) {
            return [null, null, 'FPCALC_UNAVAILABLE'];
        }
        $process = new Process([$binary, '-json', $absolutePath]);
        $configured = $this->timeoutSeconds
            ?? (is_numeric(getenv('VELIN_FPCALC_TIMEOUT')) ? (float) getenv('VELIN_FPCALC_TIMEOUT') : 120.0);
        $process->setTimeout(max(5.0, min(900.0, $configured)));
        $stdout = '';
        $bytes = 0;
        $oversized = false;
        try {
            $exit = $process->run(function (string $type, string $chunk) use (&$bytes, &$oversized, &$stdout, $process): void {
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_OUTPUT_BYTES) {
                    $oversized = true;
                    $process->stop(0.1);
                    return;
                }
                if ($type === Process::OUT) $stdout .= $chunk;
            });
        } catch (ProcessTimedOutException) {
            return [null, null, 'FPCALC_TIMEOUT'];
        } catch (Throwable) {
            return [null, null, 'FPCALC_EXECUTION_FAILED'];
        }
        if ($oversized) return [null, null, 'FPCALC_OUTPUT_TOO_LARGE'];
        if ($exit !== 0) return [null, null, 'FPCALC_REJECTED_FILE'];
        try {
            $decoded = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [null, null, 'FPCALC_INVALID_OUTPUT'];
        }
        $fingerprint = is_array($decoded) ? ($decoded['fingerprint'] ?? null) : null;
        $duration = is_array($decoded) ? ($decoded['duration'] ?? null) : null;
        if (!is_string($fingerprint) || $fingerprint === '' || !is_numeric($duration)) {
            return [null, null, 'FPCALC_INVALID_OUTPUT'];
        }
        return [hash('sha256', $fingerprint), max(0, (int) round((float) $duration)), null];
    }

    /** 字节读取失败时清空两类摘要；同一身份后续扫描仍可安全重试。 */
    private function storeFailure(stdClass $row, string $signature, string $errorCode): void
    {
        $this->identityQuery($row)->update([
            'byte_hash_status' => 'failed', 'byte_sha256' => null,
            'acoustic_fingerprint_status' => 'failed', 'acoustic_fingerprint_sha256' => null,
            'acoustic_duration_seconds' => null, 'duplicate_evidence_signature' => $signature,
            'duplicate_evidence_error_code' => $errorCode, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** 生成不含路径的文件身份版本；任一分量变化都会使旧证据失效。 */
    private function signature(stdClass $row): string
    {
        return implode(':', [(int) $row->device_id, (int) $row->inode, (int) $row->file_size, (int) $row->modified_at]);
    }

    /** 读取前后校验普通文件和四元身份，拒绝路径在计算期间被替换。 */
    private function assertIdentity(stdClass $row, string $absolutePath): void
    {
        if (is_link($absolutePath) || !is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new MediaProbeFailed('MEDIA_PATH_CHANGED', '文件路径在重复证据计算期间发生变化。');
        }
        $stat = @stat($absolutePath);
        if (!is_array($stat) || (int) $stat['dev'] !== (int) $row->device_id
            || (int) $stat['ino'] !== (int) $row->inode || (int) $stat['size'] !== (int) $row->file_size
            || (int) $stat['mtime'] !== (int) $row->modified_at) {
            throw new MediaProbeFailed('MEDIA_FILE_CHANGED', '文件内容在重复证据计算期间发生变化。');
        }
    }

    /** 条件更新复用发现时的精确身份，避免并发扫描把旧证据写回新文件。 */
    private function identityQuery(stdClass $row): mixed
    {
        return Db::table('library_file_inventory')->where('id', (string) $row->id)->where('status', 'available')
            ->where('device_id', (int) $row->device_id)->where('inode', (int) $row->inode)
            ->where('file_size', (int) $row->file_size)->where('modified_at', (int) $row->modified_at);
    }
}
