<?php

declare(strict_types=1);

namespace app\application\Metadata;

use Throwable;

/**
 * 在受控缓存根或音乐库根内原子、非覆盖地发布一个派生文件。
 *
 * 路径只能由服务端规则生成。缓存模式可逐级创建固定相对目录；相邻模式要求父目录已经存在，并额外
 * 复验音频字面路径、真实路径和 device/inode/size/mtime 四元身份。正文先写到目标同目录的独占临时
 * 文件并落盘，再用硬链接原子创建最终目录项，因此并发任务不能覆盖已有目标。若目标已是同一摘要，
 * 视为前次“文件成功、数据库未提交”的幂等重放；不同内容、软链接或非普通文件一律冲突关闭。
 *
 * 成功只保证目标文件字节、大小和摘要匹配，不修改音频、已有资源或目录权限。失败会尽力删除本次临时
 * 文件；已经由其他进程创建的最终目标永远不会被清理或补偿。
 */
final class ScrapeAssetFilePublisher
{
    /**
     * 发布一个不可变资源并返回由调用方生成的安全相对路径。
     *
     * @param null|array{relativePath:string,device:int,inode:int,size:int,modifiedAt:int} $audioIdentity
     * @throws ScrapeAssetPublicationFailed 路径、身份、冲突、写入或落盘校验失败。
     */
    public function publish(
        string $configuredRoot,
        string $relativePath,
        string $content,
        string $expectedSha256,
        bool $createParents,
        ?array $audioIdentity = null,
    ): string {
        $root = realpath($configuredRoot);
        if ($root === false || $root !== rtrim($configuredRoot, DIRECTORY_SEPARATOR)
            || is_link($configuredRoot) || !is_dir($root) || !is_writable($root)) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_ROOT_UNAVAILABLE');
        }
        if ($content === '' || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1
            || !hash_equals($expectedSha256, hash('sha256', $content))) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_SOURCE_STALE');
        }
        $segments = $this->segments($relativePath);
        $filename = array_pop($segments);
        $directory = $this->prepareDirectory($root, $segments, $createParents);
        $directoryStat = @stat($directory);
        if (!is_array($directoryStat)) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_PARENT_INVALID');
        }
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        if ($audioIdentity !== null) {
            $this->assertAudio($root, $audioIdentity);
        }
        if (file_exists($target) || is_link($target)) {
            if ($this->matchesExisting($target, $expectedSha256, strlen($content))) {
                return str_replace('\\', '/', $relativePath);
            }
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_TARGET_CONFLICT', true);
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.velin-scrape-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = null;
        try {
            $handle = @fopen($temporary, 'x+b');
            if (!is_resource($handle)) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_TEMP_CREATE_FAILED');
            }
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($handle, substr($content, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_TEMP_WRITE_FAILED');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_TEMP_SYNC_FAILED');
            }
            fclose($handle);
            $handle = null;
            @chmod($temporary, 0640);
            if ($audioIdentity !== null) {
                $this->assertAudio($root, $audioIdentity);
            }
            $currentDirectoryStat = @stat($directory);
            if (realpath($directory) !== $directory || !is_array($currentDirectoryStat)
                || (int) ($currentDirectoryStat['dev'] ?? -1) !== (int) ($directoryStat['dev'] ?? -2)
                || (int) ($currentDirectoryStat['ino'] ?? -1) !== (int) ($directoryStat['ino'] ?? -2)) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_PARENT_STALE');
            }
            if (!@link($temporary, $target)) {
                if ($this->matchesExisting($target, $expectedSha256, $length)) {
                    return str_replace('\\', '/', $relativePath);
                }
                throw new ScrapeAssetPublicationFailed(
                    file_exists($target) || is_link($target)
                        ? 'SCRAPE_ASSET_TARGET_CONFLICT' : 'SCRAPE_ASSET_PUBLISH_FAILED',
                    file_exists($target) || is_link($target),
                );
            }
            if (!$this->matchesExisting($target, $expectedSha256, $length)) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_VERIFY_FAILED');
            }

            return str_replace('\\', '/', $relativePath);
        } catch (ScrapeAssetPublicationFailed $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_FILESYSTEM_FAILED');
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (file_exists($temporary) || is_link($temporary)) @unlink($temporary);
        }
    }

    /** @return list<string> 将相对路径拆成无点段、无控制字符的字面片段。 */
    private function segments(string $relativePath): array
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, "\0")) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_PATH_INVALID');
        }
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_PATH_INVALID');
            }
        }
        return $segments;
    }

    /**
     * 逐级确认或创建根内目录，每一步都拒绝链接和非目录节点。
     *
     * 目录创建使用 0750 且不修改已有权限；并发创建同一内容寻址目录是幂等的。最终 realpath 必须与
     * 字面路径一致，从而阻止检查后父目录被替换为指向根外的软链接。
     *
     * @param list<string> $segments
     */
    private function prepareDirectory(string $root, array $segments, bool $createParents): string
    {
        $current = $root;
        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (!file_exists($current) && !$createParents) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_PARENT_UNAVAILABLE');
            }
            if (!file_exists($current) && !@mkdir($current, 0750) && !is_dir($current)) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_PARENT_CREATE_FAILED');
            }
            if (is_link($current) || !is_dir($current) || realpath($current) !== $current) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_PARENT_INVALID');
            }
        }
        if (!is_writable($current)) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_PARENT_NOT_WRITABLE');
        }
        return $current;
    }

    /** 相邻发布前后都复验库存保存的音频身份，禁止路径替换或跨根链接。 */
    private function assertAudio(string $root, array $expected): void
    {
        $segments = $this->segments($expected['relativePath']);
        $audio = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $stat = @stat($audio);
        if (realpath($audio) !== $audio || is_link($audio) || !is_file($audio) || !is_array($stat)
            || (int) ($stat['dev'] ?? -1) !== $expected['device']
            || (int) ($stat['ino'] ?? -1) !== $expected['inode']
            || (int) ($stat['size'] ?? -1) !== $expected['size']
            || (int) ($stat['mtime'] ?? -1) !== $expected['modifiedAt']) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_AUDIO_STALE');
        }
    }

    /** 仅把未漂移的普通文件视为幂等目标，软链接、目录和摘要不符均返回 false。 */
    private function matchesExisting(string $target, string $sha256, int $size): bool
    {
        $stat = @lstat($target);
        if (!is_array($stat) || is_link($target) || !is_file($target)
            || realpath($target) !== $target || (int) ($stat['size'] ?? -1) !== $size) return false;
        $actual = @hash_file('sha256', $target);
        return is_string($actual) && hash_equals($sha256, $actual);
    }
}
