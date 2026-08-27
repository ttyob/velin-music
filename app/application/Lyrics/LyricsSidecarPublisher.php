<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use Throwable;

/**
 * 在最终音乐库内独占新建或显式替换一个同基名 `.lrc` sidecar。
 *
 * 所有路径均来自服务端不可变方案，但跨请求/队列边界后仍按不可信输入处理。发布前要求音乐库
 * 真实根稳定、音频是根内非链接普通文件、目标与音频同目录且文件名匹配、音频 stat 身份与预览
 * 一致。内容先写入同目录独占临时文件并 `fflush/fsync`，再通过 `link()` 原子地建立不可覆盖的
 * 最终目录项；该做法比 PHP `rename()` 更能保证并发下绝不替换已有歌词。任何失败都会清理临时
 * 文件。替换模式会先把旧目录项硬链接到同目录不可预测备份名，从而完整保留旧 inode 和字节；新
 * 文件通过 `rename()` 原子替换目标。已经成功发布后的数据库失败由 `compensate()` 精确删除新建
 * 文件或把旧 inode 原样恢复；数据库成功后由 `finalize()` 删除旧备份。
 */
final class LyricsSidecarPublisher
{
    /**
     * 执行一个已经确认的发布步骤。
     *
     * @param array{device:int,inode:int,size:int,modifiedAt:int} $expectedAudioIdentity
     * @param null|array{device:int,inode:int,size:int,modifiedAt:int,sha256:string} $expectedTargetIdentity
     * @throws LyricsWritebackFailed 路径、身份、冲突、写入或验证失败；异常不含真实路径和正文。
     */
    public function publish(
        string $resolvedRoot,
        string $audioRelativePath,
        string $targetRelativePath,
        array $expectedAudioIdentity,
        string $content,
        string $expectedOutputSha256,
        ?array $expectedTargetIdentity = null,
    ): LyricsSidecarPublishResult {
        $root = realpath($resolvedRoot);
        if ($root === false || $root !== rtrim($resolvedRoot, DIRECTORY_SEPARATOR) || !is_dir($root)) {
            throw new LyricsWritebackFailed('LYRICS_ROOT_CHANGED', '音乐库根目录已变化。');
        }
        $audioPath = $this->absoluteWithinRoot($root, $audioRelativePath);
        $targetPath = $this->absoluteWithinRoot($root, $targetRelativePath);
        $canonicalAudio = realpath($audioPath);
        if (
            $canonicalAudio === false
            || $canonicalAudio !== $audioPath
            || is_link($audioPath)
            || !is_file($audioPath)
        ) {
            throw new LyricsWritebackFailed('LYRICS_AUDIO_CHANGED', '音频文件已变化。');
        }
        $audioStem = pathinfo($audioPath, PATHINFO_FILENAME);
        $targetStem = pathinfo($targetPath, PATHINFO_FILENAME);
        $languageSuffixPattern = '/^' . preg_quote($audioStem, '/')
            . '\.[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/';
        $validTargetStem = $targetStem === $audioStem
            || preg_match($languageSuffixPattern, $targetStem) === 1;
        if (
            dirname($targetPath) !== dirname($audioPath)
            || pathinfo($targetPath, PATHINFO_EXTENSION) !== 'lrc'
            || !$validTargetStem
        ) {
            throw new LyricsWritebackFailed('LYRICS_TARGET_INVALID', '歌词目标不满足同目录命名规则。');
        }
        $audioStat = @stat($audioPath);
        if (!$this->sameAudio($expectedAudioIdentity, $audioStat)) {
            throw new LyricsWritebackFailed('LYRICS_AUDIO_STALE', '音频文件身份已变化。');
        }
        $targetExists = file_exists($targetPath) || is_link($targetPath);
        if ($expectedTargetIdentity === null && $targetExists) {
            throw new LyricsWritebackFailed('LYRICS_TARGET_CONFLICT', '歌词目标已存在。');
        }
        if ($expectedTargetIdentity !== null) {
            $this->assertReplaceableTarget($targetPath, $expectedTargetIdentity);
        }
        if (
            $content === ''
            || strlen($content) > 1_048_576
            || !hash_equals($expectedOutputSha256, hash('sha256', $content))
        ) {
            throw new LyricsWritebackFailed('LYRICS_OUTPUT_STALE', '歌词输出摘要已变化。');
        }
        if (!is_writable(dirname($targetPath))) {
            throw new LyricsWritebackFailed('LYRICS_TARGET_NOT_WRITABLE', '歌词目标目录不可写。');
        }

        $temporary = dirname($targetPath) . DIRECTORY_SEPARATOR
            . '.velin-writeback-' . bin2hex(random_bytes(12)) . '.tmp';
        $backup = null;
        $keepBackup = false;
        $replacementPublished = false;
        $handle = null;
        try {
            $handle = @fopen($temporary, 'x+b');
            if (!is_resource($handle)) {
                throw new LyricsWritebackFailed('LYRICS_TEMP_CREATE_FAILED', '无法创建歌词临时文件。');
            }
            $offset = 0;
            while ($offset < strlen($content)) {
                $written = fwrite($handle, substr($content, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new LyricsWritebackFailed('LYRICS_TEMP_WRITE_FAILED', '歌词临时文件写入失败。');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new LyricsWritebackFailed('LYRICS_TEMP_SYNC_FAILED', '歌词临时文件落盘失败。');
            }
            fclose($handle);
            $handle = null;
            @chmod($temporary, 0644);

            if (!$this->sameAudio($expectedAudioIdentity, @stat($audioPath)) || realpath($audioPath) !== $audioPath) {
                throw new LyricsWritebackFailed('LYRICS_AUDIO_STALE', '写入期间音频文件发生变化。');
            }
            if ($expectedTargetIdentity === null) {
                if (!@link($temporary, $targetPath)) {
                    throw new LyricsWritebackFailed(
                        file_exists($targetPath) || is_link($targetPath)
                            ? 'LYRICS_TARGET_CONFLICT'
                            : 'LYRICS_PUBLISH_FAILED',
                        '歌词文件发布失败。',
                    );
                }
            } else {
                $this->assertReplaceableTarget($targetPath, $expectedTargetIdentity);
                $backup = dirname($targetPath) . DIRECTORY_SEPARATOR
                    . '.velin-lyrics-backup-' . bin2hex(random_bytes(12)) . '.lrc';
                if (!@link($targetPath, $backup)) {
                    throw new LyricsWritebackFailed('LYRICS_BACKUP_FAILED', '无法完整备份已有歌词。');
                }
                try {
                    $this->assertReplaceableTarget($backup, $expectedTargetIdentity);
                    $this->assertReplaceableTarget($targetPath, $expectedTargetIdentity);
                    if (!@rename($temporary, $targetPath)) {
                        throw new LyricsWritebackFailed('LYRICS_PUBLISH_FAILED', '歌词文件原子替换失败。');
                    }
                    $replacementPublished = true;
                    // 一旦旧目录项被替换，任何异常都必须保留备份，除非恢复动作已经消费它。
                    $keepBackup = true;
                } catch (LyricsWritebackFailed $failure) {
                    if ($replacementPublished && !$this->restoreExisting($targetPath, $backup, $expectedTargetIdentity)) {
                        throw new LyricsWritebackFailed('LYRICS_RESTORE_FAILED', '替换失败且旧歌词无法自动恢复。');
                    }
                    throw $failure;
                }
            }
            $targetStat = @lstat($targetPath);
            $targetHash = @hash_file('sha256', $targetPath);
            if (
                !is_array($targetStat) || is_link($targetPath) || !is_string($targetHash)
                || !hash_equals($expectedOutputSha256, $targetHash)
                || (int) ($targetStat['size'] ?? -1) !== strlen($content)
            ) {
                if ($expectedTargetIdentity !== null && is_string($backup)) {
                    if (!$this->restoreExisting($targetPath, $backup, $expectedTargetIdentity)) {
                        throw new LyricsWritebackFailed('LYRICS_RESTORE_FAILED', '验证失败且旧歌词无法自动恢复。');
                    }
                } else {
                    @unlink($targetPath);
                }
                throw new LyricsWritebackFailed('LYRICS_VERIFY_FAILED', '歌词文件发布验证失败。');
            }

            $result = new LyricsSidecarPublishResult(
                $targetPath,
                (int) $targetStat['dev'],
                (int) $targetStat['ino'],
                (int) $targetStat['size'],
                $targetHash,
                $backup,
                $expectedTargetIdentity['device'] ?? null,
                $expectedTargetIdentity['inode'] ?? null,
                $expectedTargetIdentity['size'] ?? null,
                $expectedTargetIdentity['sha256'] ?? null,
            );
            $keepBackup = $result->replacedExisting();

            return $result;
        } catch (LyricsWritebackFailed $failure) {
            throw $failure;
        } catch (Throwable $throwable) {
            throw new LyricsWritebackFailed('LYRICS_FILESYSTEM_FAILED', '歌词文件操作失败。');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
            if (!$keepBackup && is_string($backup) && (file_exists($backup) || is_link($backup))) {
                @unlink($backup);
            }
        }
    }

    /**
     * 只删除本次发布且从未变化的目标。
     *
     * 返回 false 表示身份漂移或删除失败，调用者必须保留失败任务供人工核对；方法不会递归、不会
     * 跟随链接，也不会删除预览前已存在的文件。
     */
    public function compensate(LyricsSidecarPublishResult $published): bool
    {
        $stat = @lstat($published->absoluteTarget);
        if (
            !is_array($stat) || is_link($published->absoluteTarget) || !is_file($published->absoluteTarget)
            || (int) ($stat['dev'] ?? -1) !== $published->device
            || (int) ($stat['ino'] ?? -1) !== $published->inode
            || (int) ($stat['size'] ?? -1) !== $published->size
        ) {
            return false;
        }
        $hash = @hash_file('sha256', $published->absoluteTarget);

        if (!is_string($hash) || !hash_equals($published->sha256, $hash)) {
            return false;
        }
        if (!$published->replacedExisting()) {
            return @unlink($published->absoluteTarget);
        }
        if (
            $published->originalDevice === null || $published->originalInode === null
            || $published->originalSize === null || $published->originalSha256 === null
        ) {
            return false;
        }

        return $this->restoreExisting($published->absoluteTarget, (string) $published->absoluteBackup, [
            'device' => $published->originalDevice,
            'inode' => $published->originalInode,
            'size' => $published->originalSize,
            'modifiedAt' => 0,
            'sha256' => $published->originalSha256,
        ], false);
    }

    /**
     * 数据库已经提交后精确删除替换备份。
     *
     * 返回 false 只表示备份身份漂移或清理失败；调用方必须记录诊断，但不得回滚已经提交的新歌词。
     */
    public function finalize(LyricsSidecarPublishResult $published): bool
    {
        if (!$published->replacedExisting()) {
            return true;
        }
        $backup = (string) $published->absoluteBackup;
        $stat = @lstat($backup);
        if (
            !is_array($stat) || is_link($backup) || !is_file($backup)
            || (int) ($stat['dev'] ?? -1) !== $published->originalDevice
            || (int) ($stat['ino'] ?? -1) !== $published->originalInode
            || (int) ($stat['size'] ?? -1) !== $published->originalSize
        ) {
            return false;
        }
        $hash = @hash_file('sha256', $backup);
        if (!is_string($hash) || !hash_equals((string) $published->originalSha256, $hash)) {
            return false;
        }

        return @unlink($backup);
    }

    /** 已有目标必须是未漂移、根内的普通小文件，且摘要与预览完全一致。 */
    private function assertReplaceableTarget(string $targetPath, array $expected): void
    {
        $stat = @lstat($targetPath);
        if (
            !is_array($stat) || is_link($targetPath) || !is_file($targetPath)
            || realpath($targetPath) !== $targetPath
            || (int) ($stat['dev'] ?? -1) !== $expected['device']
            || (int) ($stat['ino'] ?? -1) !== $expected['inode']
            || (int) ($stat['size'] ?? -1) !== $expected['size']
            || (int) ($stat['mtime'] ?? -1) !== $expected['modifiedAt']
            || (int) ($stat['size'] ?? -1) > 1_048_576
        ) {
            throw new LyricsWritebackFailed('LYRICS_TARGET_STALE', '已有歌词身份已变化或不可安全替换。');
        }
        $hash = @hash_file('sha256', $targetPath);
        if (!is_string($hash) || !hash_equals($expected['sha256'], $hash)) {
            throw new LyricsWritebackFailed('LYRICS_TARGET_STALE', '已有歌词身份已变化或不可安全替换。');
        }
    }

    /** 将同目录备份原子放回目标，并复验原 inode、大小和摘要。 */
    private function restoreExisting(
        string $targetPath,
        string $backupPath,
        array $expected,
        bool $checkModifiedAt = true,
    ): bool {
        try {
            $backupStat = @lstat($backupPath);
            if (
                !is_array($backupStat) || is_link($backupPath) || !is_file($backupPath)
                || (int) ($backupStat['dev'] ?? -1) !== $expected['device']
                || (int) ($backupStat['ino'] ?? -1) !== $expected['inode']
                || (int) ($backupStat['size'] ?? -1) !== $expected['size']
                || ($checkModifiedAt && (int) ($backupStat['mtime'] ?? -1) !== $expected['modifiedAt'])
            ) {
                return false;
            }
            $backupHash = @hash_file('sha256', $backupPath);
            if (!is_string($backupHash) || !hash_equals($expected['sha256'], $backupHash)
                || !@rename($backupPath, $targetPath)) {
                return false;
            }
            $restored = @lstat($targetPath);
            $restoredHash = @hash_file('sha256', $targetPath);

            return is_array($restored) && !is_link($targetPath) && is_file($targetPath)
                && (int) ($restored['dev'] ?? -1) === $expected['device']
                && (int) ($restored['ino'] ?? -1) === $expected['inode']
                && (int) ($restored['size'] ?? -1) === $expected['size']
                && is_string($restoredHash) && hash_equals($expected['sha256'], $restoredHash);
        } catch (Throwable) {
            return false;
        }
    }

    /** 将规范相对路径解析为根内字面路径；拒绝绝对路径、空段、点段和 NUL。 */
    private function absoluteWithinRoot(string $root, string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, "\0")) {
            throw new LyricsWritebackFailed('LYRICS_PATH_INVALID', '歌词写回路径无效。');
        }
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new LyricsWritebackFailed('LYRICS_PATH_INVALID', '歌词写回路径无效。');
            }
        }

        return $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    /** 对比预览保存的四元身份，禁止仅凭路径或 inode 判断同一音频。 */
    private function sameAudio(array $expected, array|false $actual): bool
    {
        return is_array($actual)
            && (int) ($actual['dev'] ?? -1) === $expected['device']
            && (int) ($actual['ino'] ?? -1) === $expected['inode']
            && (int) ($actual['size'] ?? -1) === $expected['size']
            && (int) ($actual['mtime'] ?? -1) === $expected['modifiedAt'];
    }
}
