<?php

declare(strict_types=1);

namespace app\application\Scan;

use app\application\Library\RemoteLibraryClient;
use app\application\Library\RemoteLibraryUnavailable;
use Generator;

/**
 * 通过有界 `Depth: 1` PROPFIND 递归发现 WebDAV 音频对象。
 *
 * 远端 href 已由 WebDavClient 证明位于配置根内；本层再限制最大目录深度、对象总数、隐藏项和音频扩展
 * 白名单。网络目录没有可信 device/inode，因此生成只用于库存变更比较的稳定路径摘要，真正的对象版本
 * 由 ETag、大小和修改时间共同绑定。扫描中任一目录请求失败会中止整次遍历，调用方不得据此标记旧
 * 库存缺失。
 */
final class WebDavLibraryDiscovery
{
    private const MAX_OBJECTS = 200_000;
    private const MAX_DEPTH = 64;

    /**
     * @param callable(int):void $checkpoint
     * @return Generator<int,DiscoveredAudioFile,mixed,array{processedEntries:int,ignoredEntries:int,failedEntries:int}>
     */
    public function files(
        string $libraryId,
        RemoteLibraryClient $client,
        callable $checkpoint,
        ?string $relativePath = null,
    ): Generator {
        $start = $relativePath ?? '';
        $queue = [[$start, 0]];
        $visitedDirectories = [$start => true];
        $processed = 0;
        $ignored = 0;
        $checkpoint(0);
        while ($queue !== []) {
            [$directory, $depth] = array_shift($queue);
            foreach ($client->listDirectory($directory) as $object) {
                if ($object->relativePath === $directory) continue;
                if (++$processed > self::MAX_OBJECTS) {
                    throw new RemoteLibraryUnavailable(
                        strtoupper($client->sourceType()) . '_OBJECT_LIMIT_EXCEEDED',
                        '网络音乐库对象数量超过单次扫描限制。',
                    );
                }
                if ($processed % 64 === 0) $checkpoint($processed);
                $name = basename($object->relativePath);
                if ($name === '' || str_starts_with($name, '.')) {
                    ++$ignored;
                    continue;
                }
                if ($object->directory) {
                    if ($depth >= self::MAX_DEPTH || isset($visitedDirectories[$object->relativePath])) {
                        ++$ignored;
                        continue;
                    }
                    $visitedDirectories[$object->relativePath] = true;
                    $queue[] = [$object->relativePath, $depth + 1];
                    continue;
                }
                $extension = strtolower(pathinfo($object->relativePath, PATHINFO_EXTENSION));
                if (!LibraryFileDiscovery::supportsAudioExtension($extension)) {
                    ++$ignored;
                    continue;
                }
                $identity = hash('sha256', $libraryId . "\0" . $object->relativePath);
                yield new DiscoveredAudioFile(
                    relativePath: $object->relativePath,
                    resolvedPath: $client->sourceType() . '://' . $libraryId . '/' . implode('/', array_map(
                        'rawurlencode',
                        explode('/', $object->relativePath),
                    )),
                    extension: $extension,
                    deviceId: (int) hexdec(substr($identity, 0, 7)),
                    inode: (int) hexdec(substr($identity, 7, 7)),
                    fileSize: $object->size,
                    modifiedAt: $object->modifiedAt,
                    remoteEtag: $object->etag,
                );
            }
        }
        $checkpoint($processed);
        return ['processedEntries' => $processed, 'ignoredEntries' => $ignored, 'failedEntries' => 0];
    }
}
