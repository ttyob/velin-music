<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\Library\RemoteLibraryClientFactory;
use app\application\Library\RemoteLibraryUnavailable;
use app\application\Library\RemotePublicationConflict;
use Illuminate\Database\QueryException;
use app\application\ResourcePlugin\Contract\PluginDomainEvent;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 把资源插件产生的完整音频统一发布到本地或远程音乐库。
 *
 * 插件只能提交自身核心工作区内的普通暂存文件和描述元数据，不能提交最终目录或远端定位符。服务按
 * `艺人/专辑/歌曲.格式` 生成根内路径，专辑为空时省略专辑层；所有片段移除分隔符、控制字符、点段、
 * Windows 保留名并限制 UTF-8 长度。首次使用稳定名称，冲突时仅追加任务短码，始终不覆盖已有媒体。
 *
 * 发布账本先在短事务中预留路径，再在事务外执行文件系统或网络副作用，最后以 CAS 提交 published。
 * Worker 若在外部成功后崩溃，同一任务和源身份会复用原账本；本地以 SHA-256、远端由协议实现复验后
 * 恢复。部分批次中已成功的文件直接返回，不会重复上传。失败只记录稳定错误，暂存和下载源均由插件
 * 原状态机继续持有；本服务不删除源文件、不触发扫描，也不在远端删除最终对象。
 */
final readonly class PluginMediaPublicationService
{
    private const FORMATS = ['mp3', 'flac', 'm4a', 'aac', 'ogg', 'opus', 'wav', 'wma', 'ape', 'alac', 'aiff', 'aif'];

    public function __construct(
        private RemoteLibraryClientFactory $remoteClients = new RemoteLibraryClientFactory(),
        private ?PhpResourcePluginWorkspaceService $workspaces = null,
        private PluginEventPublisher $pluginEvents = new PluginEventPublisher(),
    ) {
    }

    /**
     * 发布一个音频文件；sourceIdentity 在同一任务内必须稳定且唯一，Jackett 可使用源树相对路径。
     *
     * metadata 是插件已经写入 localPath 的受限描述字段；省略时使用生成路径的艺人、专辑和标题构造
     * 兼容快照。快照在上传前完成严格校验，并与源摘要及远端发现 ETag 一起进入账本。这样
     * `filename_only` 扫描无需下载正文，也只能对同一个远端对象恢复 raw 标签。
     *
     * @throws PluginMediaPublicationFailed 输入、权限事实、暂存身份、目标冲突或协议上传失败
     */
    public function publish(
        string $pluginKey,
        string $taskId,
        string $libraryId,
        string $localPath,
        string $sourceIdentity,
        string $artist,
        string $album,
        string $title,
        string $format,
        ?array $metadata = null,
    ): PluginMediaPublication {
        $this->assertIdentifiers($pluginKey, $taskId, $libraryId, $sourceIdentity);
        $format = strtolower(trim($format));
        if (!in_array($format, self::FORMATS, true)) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_FORMAT_INVALID');
        }
        $publishedMetadata = PluginPublishedMediaMetadata::fromPublicationCommand(
            $metadata,
            $artist,
            $album,
            $title,
        );
        $metadataJson = $publishedMetadata->toJson();
        $source = $this->validatedSource($pluginKey, $localPath);
        $size = filesize($source);
        $sha256 = hash_file('sha256', $source);
        if (!is_int($size) || $size < 1 || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_SOURCE_INVALID');
        }

        /** @var stdClass|null $library */
        $library = Db::table('music_libraries')->where('id', $libraryId)->where('status', 'active')
            ->first(['source_type', 'resolved_root_path']);
        if (!$library instanceof stdClass || !in_array((string) $library->source_type,
            ['local', 'webdav', 'onedrive', 'google_drive'], true)) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_LIBRARY_UNAVAILABLE');
        }

        $fingerprint = hash('sha256', $sourceIdentity);
        $basePath = $this->relativePath($artist, $album, $title, $format, null);
        $collisionPath = $this->relativePath($artist, $album, $title, $format, strtolower(substr($taskId, -8)));
        $row = $this->reserve($pluginKey, $taskId, $libraryId, $fingerprint, (string) $library->source_type,
            $basePath, $collisionPath, $size, $sha256, $metadataJson);
        if ((string) $row->status === 'published') return $this->result($row);

        for ($candidate = 0; $candidate < 2; ++$candidate) {
            $relativePath = (string) $row->relative_path;
            Db::table('plugin_media_publications')->where('id', (string) $row->id)
                ->whereIn('status', ['pending', 'failed', 'uploading'])->update([
                    'status' => 'uploading', 'error_code' => null, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            try {
                $remote = (string) $library->source_type === 'local' ? null
                    : $this->remoteClients->forWritableLibrary($libraryId)
                        ->publishFile($relativePath, $source, $size, $sha256);
                $version = $remote === null
                    ? $this->publishLocal((string) $library->resolved_root_path, $source, $relativePath, $size, $sha256)
                    : $remote->version;
                $finished = gmdate('Y-m-d\TH:i:s\Z');
                $committed = Db::table('plugin_media_publications')->where('id', (string) $row->id)
                    ->where('status', 'uploading')->update([
                        'status' => 'published', 'remote_version' => $version,
                        'discovery_etag' => $remote?->discoveryEtag,
                        'error_code' => null, 'published_at' => $finished, 'updated_at' => $finished,
                    ]);
                if ($committed !== 1) {
                    throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_PUBLICATION_LEASE_LOST');
                }
                $row->status = 'published';
                // 文件与发布账本已经提交；Redis 只承载有限期扩展通知，故障不能触发远端删除或本地补偿。
                $this->pluginEvents->publish(
                    PluginDomainEvent::MEDIA_PUBLISHED,
                    'plugin_media_publication',
                    (string) $row->id,
                    'plugin',
                    $pluginKey,
                    ['libraryId' => $libraryId, 'taskId' => $taskId, 'format' => $format, 'sizeBytes' => $size],
                );
                return $this->result($row);
            } catch (RemotePublicationConflict $failure) {
                if ($candidate === 0 && $relativePath === $basePath && $collisionPath !== $basePath
                    && $this->switchToCollisionPath((string) $row->id, $collisionPath)) {
                    $row->relative_path = $collisionPath;
                    continue;
                }
                $this->recordFailure((string) $row->id, $failure->errorCode);
                throw new PluginMediaPublicationFailed($failure->errorCode);
            } catch (PluginMediaPublicationFailed $failure) {
                $this->recordFailure((string) $row->id, $failure->errorCode);
                throw $failure;
            } catch (RemoteLibraryUnavailable $failure) {
                $this->recordFailure((string) $row->id, $failure->errorCode);
                throw new PluginMediaPublicationFailed($failure->errorCode);
            } catch (Throwable) {
                $this->recordFailure((string) $row->id, 'PLUGIN_MEDIA_PUBLICATION_FAILED');
                throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_PUBLICATION_FAILED');
            }
        }
        throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_PUBLICATION_FAILED');
    }

    /** 在短事务中复用或创建单文件账本；同任务源身份不允许漂移到另一个库或摘要。 */
    private function reserve(
        string $pluginKey,
        string $taskId,
        string $libraryId,
        string $fingerprint,
        string $sourceType,
        string $basePath,
        string $collisionPath,
        int $size,
        string $sha256,
        string $metadataJson,
    ): stdClass {
        try {
            return Db::transaction(function () use ($pluginKey, $taskId, $libraryId, $fingerprint, $sourceType,
                $basePath, $collisionPath, $size, $sha256, $metadataJson): stdClass {
                /** @var stdClass|null $existing */
                $existing = Db::table('plugin_media_publications')->where('plugin_key', $pluginKey)
                    ->where('task_id', $taskId)->where('source_fingerprint', $fingerprint)->first();
                if ($existing instanceof stdClass) {
                    if ((string) $existing->library_id !== $libraryId || (int) $existing->size_bytes !== $size
                        || !hash_equals((string) $existing->sha256, $sha256)) {
                        throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_IDEMPOTENCY_MISMATCH');
                    }
                    if ($existing->metadata_json === null) {
                        Db::table('plugin_media_publications')->where('id', (string) $existing->id)
                            ->whereNull('metadata_json')->update(['metadata_json' => $metadataJson,
                                'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
                        $existing->metadata_json = $metadataJson;
                    } elseif (!hash_equals((string) $existing->metadata_json, $metadataJson)) {
                        throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_IDEMPOTENCY_MISMATCH');
                    }
                    return $existing;
                }
                $relativePath = Db::table('plugin_media_publications')->where('library_id', $libraryId)
                    ->where('relative_path', $basePath)->exists() ? $collisionPath : $basePath;
                $id = (string) new Ulid();
                $now = gmdate('Y-m-d\TH:i:s\Z');
                Db::table('plugin_media_publications')->insert([
                    'id' => $id, 'plugin_key' => $pluginKey, 'task_id' => $taskId,
                    'library_id' => $libraryId, 'source_fingerprint' => $fingerprint,
                    'relative_path' => $relativePath, 'source_type' => $sourceType,
                    'size_bytes' => $size, 'sha256' => $sha256, 'status' => 'pending',
                    'remote_version' => null, 'discovery_etag' => null, 'metadata_json' => $metadataJson,
                    'error_code' => null, 'published_at' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                /** @var stdClass $created */
                $created = Db::table('plugin_media_publications')->where('id', $id)->first();
                return $created;
            });
        } catch (QueryException $failure) {
            /** @var stdClass|null $existing */
            $existing = Db::table('plugin_media_publications')->where('plugin_key', $pluginKey)
                ->where('task_id', $taskId)->where('source_fingerprint', $fingerprint)->first();
            if ($existing instanceof stdClass
                && (string) $existing->library_id === $libraryId
                && (int) $existing->size_bytes === $size
                && hash_equals((string) $existing->sha256, $sha256)
                && is_string($existing->metadata_json)
                && hash_equals((string) $existing->metadata_json, $metadataJson)) return $existing;
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_TARGET_CONFLICT');
        }
    }

    /** 本地库使用逐级真实目录复验和同设备硬链接实现原子非覆盖；源文件由插件稍后精确清理。 */
    private function publishLocal(
        string $libraryRoot,
        string $source,
        string $relativePath,
        int $size,
        string $sha256,
    ): string {
        $root = realpath($libraryRoot);
        if (!is_string($root) || $root !== rtrim($libraryRoot, DIRECTORY_SEPARATOR)
            || !is_dir($root) || is_link($libraryRoot) || !is_writable($root)) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_LIBRARY_UNAVAILABLE');
        }
        $segments = explode('/', $relativePath);
        $name = array_pop($segments);
        $directory = $root;
        foreach ($segments as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
            if (!is_dir($directory) && !@mkdir($directory, 0750) && !is_dir($directory)) {
                throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_TARGET_UNAVAILABLE');
            }
            if (is_link($directory) || realpath($directory) !== $directory) {
                throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_TARGET_INVALID');
            }
        }
        $target = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_file($target) && !is_link($target)) {
            $targetSize = filesize($target);
            $targetHash = hash_file('sha256', $target);
            if ($targetSize === $size && is_string($targetHash) && hash_equals($sha256, $targetHash)) {
                return hash('sha256', $relativePath . "\0" . $size . "\0" . $sha256);
            }
            throw new RemotePublicationConflict();
        }
        if (file_exists($target) || is_link($target)) throw new RemotePublicationConflict();
        $sourceStat = @stat($source);
        $targetStat = @stat($directory);
        if (!is_array($sourceStat) || !is_array($targetStat)
            || (string) ($sourceStat['dev'] ?? '') !== (string) ($targetStat['dev'] ?? '')) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_TARGET_FILESYSTEM_MISMATCH');
        }
        if (!@link($source, $target)) {
            if (file_exists($target) || is_link($target)) throw new RemotePublicationConflict();
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_TARGET_UNAVAILABLE');
        }
        @chmod($target, 0640);
        return hash('sha256', $relativePath . "\0" . $size . "\0" . $sha256);
    }

    /** 只有当前上传中的账本可切换到同任务冲突名；唯一约束防止并发抢占。 */
    private function switchToCollisionPath(string $id, string $collisionPath): bool
    {
        try {
            return Db::table('plugin_media_publications')->where('id', $id)->where('status', 'uploading')
                ->update(['relative_path' => $collisionPath, 'status' => 'pending',
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]) === 1;
        } catch (QueryException) {
            return false;
        }
    }

    private function recordFailure(string $id, string $errorCode): void
    {
        Db::table('plugin_media_publications')->where('id', $id)->where('status', 'uploading')->update([
            'status' => 'failed', 'error_code' => substr($errorCode, 0, 96),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** 暂存必须是插件工作区内的真实普通文件，路径和链接不能借上传器越过插件隔离边界。 */
    private function validatedSource(string $pluginKey, string $localPath): string
    {
        try {
            $workspace = ($this->workspaces ?? new PhpResourcePluginWorkspaceService())->mediaDirectory($pluginKey);
        } catch (PhpResourcePluginWorkspaceUnavailable) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_WORKSPACE_UNAVAILABLE');
        }
        $root = realpath($workspace);
        $source = realpath($localPath);
        if (!is_string($root) || !is_string($source) || $source !== $localPath || is_link($localPath)
            || !is_file($source) || !is_readable($source)
            || !str_starts_with($source, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_SOURCE_INVALID');
        }
        return $source;
    }

    /** @return string 根内正斜杠相对路径。 */
    private function relativePath(
        string $artist,
        string $album,
        string $title,
        string $format,
        ?string $suffix,
    ): string {
        $segments = [$this->safeSegment($artist, '未知艺人')];
        $album = $this->safeSegment($album, '');
        if ($album !== '') $segments[] = $album;
        $song = $this->safeSegment($title, '未知歌曲');
        if ($suffix !== null) $song .= ' [' . $suffix . ']';
        $segments[] = $song . '.' . $format;
        return implode('/', $segments);
    }

    /** 生成跨 Linux、WebDAV、Graph、Drive 和未来 Windows 客户端均可安全表示的单段名称。 */
    private function safeSegment(string $value, string $fallback): string
    {
        $value = preg_replace('~[\\\\/\x00-\x1F\x7F:*?"<>|]+~u', '_', trim($value)) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value, " .\t\n\r\0\x0B");
        $value = mb_substr($value, 0, 120, 'UTF-8');
        if ($value === '' || $value === '.' || $value === '..'
            || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/iu', $value) === 1) {
            $value = $fallback;
        }
        return $value;
    }

    private function assertIdentifiers(string $pluginKey, string $taskId, string $libraryId, string $sourceIdentity): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $pluginKey) !== 1
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $taskId) !== 1
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $libraryId) !== 1
            || $sourceIdentity === '' || strlen($sourceIdentity) > 2048 || str_contains($sourceIdentity, "\0")) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_COMMAND_INVALID');
        }
    }

    private function result(stdClass $row): PluginMediaPublication
    {
        return new PluginMediaPublication((string) $row->id, (string) $row->library_id,
            (string) $row->source_type, (string) $row->relative_path, (int) $row->size_bytes, (string) $row->sha256);
    }
}
