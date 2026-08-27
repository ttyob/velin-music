<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Library\RemoteLibraryClient;
use app\application\Library\RemoteLibraryUnavailable;
use app\application\Library\WebDavObject;
use app\infrastructure\Media\FfprobeMediaProbe;
use app\infrastructure\Media\RangeAwareWebDavMetadataProbe;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 在首次实际媒体读取时补全扫描阶段有意缺失的技术事实。
 *
 * 本服务只处理 `duration_ms=0` 的已授权、已完成身份复验的歌曲。本地文件由 FFprobe 只读探测；网络库
 * 只允许通过一次性回环 Range 代理读取 FFprobe 所需片段，禁止完整下载回退。跨 Web Worker 使用
 * SQLite 持久租约去重，失败采用有界退避；任何探测、数据库或远端错误都返回原行并继续播放。
 *
 * 服务只写时长、编码、容器、码率、位深、采样率、声道和 ReplayGain，不采用探测结果中的标题、艺人、
 * 专辑或其他标签。网络与子进程始终在事务外运行，写入前在短事务内复验库存身份并执行 CAS；并发扫描、
 * 文件替换或租约转移都会使本次结果失效，不允许旧探测覆盖新事实。
 */
final readonly class MissingMediaTechnicalFactsHydrator
{
    private const LEASE_SECONDS = 180;
    private const RETRY_DELAYS = [300, 900, 3600, 21600];
    private const TECHNICAL_FIELDS = [
        'duration_ms', 'codec_name', 'container_name', 'bitrate', 'bit_depth', 'sample_rate', 'channels',
        'replaygain_track_gain', 'replaygain_track_peak', 'replaygain_album_gain', 'replaygain_album_peak',
    ];

    public function __construct(
        private MediaProbe $localProbe = new FfprobeMediaProbe(),
        private WebDavRemoteMetadataProbe $remoteProbe = new RangeAwareWebDavMetadataProbe(),
    ) {
    }

    /**
     * 对一个已通过播放路径校验的本地文件执行一次缺失技术事实补全。
     *
     * absolutePath 必须是音乐库内与 row 库存身份一致的规范可读文件。方法在探测前后复验 stat；成功后
     * 仅以 `duration_ms=0` 和库存身份 CAS 写库并重算所属专辑时长。失败、活动租约或冷却均不抛异常、
     * 不修改媒体，也不会阻止调用方继续打开原文件。
     */
    public function hydrateLocal(stdClass $row, string $absolutePath): stdClass
    {
        if ((int) ($row->duration_ms ?? 0) > 0) {
            return $row;
        }

        try {
            $identity = $this->identity($row);
            $lease = $this->claim($row, $identity);
            if ($lease === null) {
                return $this->refreshTechnicalFields($row);
            }
            try {
                if (!$this->localIdentityMatches($row, $absolutePath)) {
                    throw new MediaProbeFailed('PLAYBACK_PROBE_LOCAL_IDENTITY_CHANGED', '媒体身份已变化。');
                }
                $metadata = $this->localProbe->probe($absolutePath, $this->fallbackTitle($row));
                if ($metadata->durationMs <= 0) {
                    throw new MediaProbeFailed('PLAYBACK_PROBE_DURATION_MISSING', '媒体探测未返回有效时长。');
                }
                if (!$this->localIdentityMatches($row, $absolutePath)) {
                    throw new MediaProbeFailed('PLAYBACK_PROBE_LOCAL_IDENTITY_CHANGED', '媒体身份已变化。');
                }

                return $this->persist($row, $identity, $lease, $metadata);
            } catch (Throwable $failure) {
                $this->fail($row, $identity, $lease, $failure);
                return $this->refreshTechnicalFields($row);
            }
        } catch (Throwable) {
            return $row;
        }
    }

    /**
     * 对一个已通过目录身份复验的网络对象执行一次 Range 技术事实补全。
     *
     * client 和 object 必须绑定当前音乐库及扫描时的 ETag、大小和修改时间。探测后会重新列出父目录；
     * 对象消失或身份漂移时丢弃结果。协议忽略 Range、局部格式不可解析、远端限流或认证失败只进入冷却，
     * 绝不转为整文件下载，也不让播放请求因补全失败而返回错误。
     */
    public function hydrateRemote(stdClass $row, RemoteLibraryClient $client, WebDavObject $object): stdClass
    {
        if ((int) ($row->duration_ms ?? 0) > 0) {
            return $row;
        }

        try {
            $identity = $this->identity($row);
            $lease = $this->claim($row, $identity);
            if ($lease === null) {
                return $this->refreshTechnicalFields($row);
            }
            try {
                if (!$this->remoteIdentityMatches($row, $object)) {
                    throw new MediaProbeFailed('PLAYBACK_PROBE_REMOTE_IDENTITY_CHANGED', '网络媒体身份已变化。');
                }
                $metadata = $this->remoteProbe->probe($client, $object, $this->fallbackTitle($row));
                if ($metadata->durationMs <= 0) {
                    throw new MediaProbeFailed('PLAYBACK_PROBE_DURATION_MISSING', '媒体探测未返回有效时长。');
                }
                $current = $this->findRemoteObject($client, (string) $row->relative_path);
                if (!$current instanceof WebDavObject || !$this->remoteIdentityMatches($row, $current)) {
                    throw new MediaProbeFailed('PLAYBACK_PROBE_REMOTE_IDENTITY_CHANGED', '网络媒体身份已变化。');
                }

                return $this->persist($row, $identity, $lease, $metadata);
            } catch (Throwable $failure) {
                $this->fail($row, $identity, $lease, $failure);
                return $this->refreshTechnicalFields($row);
            }
        } catch (Throwable) {
            return $row;
        }
    }

    /**
     * 原子领取歌曲级租约；返回 null 表示另一个 Worker 正在探测或失败冷却尚未结束。
     *
     * `insertOrIgnore + 条件 update` 的每条语句在 SQLite 内独立原子化：新行只有一个 owner，存量行只有
     * 身份变化、租约到期或冷却到期时才允许替换。这里不持有跨语句事务，因此后续网络 I/O 不会占用写锁；
     * 未来 MySQL 仓储需使用等价唯一键条件更新保持相同所有权不变量。
     *
     * @return array{owner:string,failureCount:int}|null
     */
    private function claim(stdClass $row, string $identity): ?array
    {
        $songId = (string) $row->id;
        $inventoryId = (string) $row->inventory_file_id;
        $nowTimestamp = time();
        $now = gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp);
        $owner = (string) new Ulid();
        $existing = Db::table('playback_media_probe_states')->where('song_id', $songId)
            ->first(['inventory_file_id', 'inventory_identity_sha256', 'failure_count']);
        $failureCount = $existing instanceof stdClass
            && (string) $existing->inventory_file_id === $inventoryId
            && hash_equals((string) $existing->inventory_identity_sha256, $identity)
                ? max(0, min(10, (int) $existing->failure_count)) : 0;
        Db::table('playback_media_probe_states')->insertOrIgnore([
            'song_id' => $songId,
            'inventory_file_id' => $inventoryId,
            'inventory_identity_sha256' => $identity,
            'status' => 'running',
            'failure_count' => 0,
            'next_attempt_at' => null,
            'lease_owner' => $owner,
            'lease_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp + self::LEASE_SECONDS),
            'error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $owned = Db::table('playback_media_probe_states')->where('song_id', $songId)
            ->where('lease_owner', $owner)->exists();
        if (!$owned) {
            $updated = Db::table('playback_media_probe_states')->where('song_id', $songId)
                ->where(function ($query) use ($identity, $inventoryId, $now): void {
                    $query->where('inventory_file_id', '!=', $inventoryId)
                        ->orWhere('inventory_identity_sha256', '!=', $identity)
                        ->orWhere(function ($running) use ($now): void {
                            $running->where('status', 'running')->where('lease_expires_at', '<=', $now);
                        })->orWhere(function ($failed) use ($now): void {
                            $failed->where('status', 'failed')->where('next_attempt_at', '<=', $now);
                        });
                })->update([
                    'inventory_file_id' => $inventoryId,
                    'inventory_identity_sha256' => $identity,
                    'status' => 'running',
                    'failure_count' => $failureCount,
                    'next_attempt_at' => null,
                    'lease_owner' => $owner,
                    'lease_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp + self::LEASE_SECONDS),
                    'error_code' => null,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                return null;
            }
        }

        return ['owner' => $owner, 'failureCount' => $failureCount];
    }

    /**
     * 在短事务中复验租约、库存身份和缺失条件，再提交技术字段并更新专辑派生时长。
     *
     * @param array{owner:string,failureCount:int} $lease
     */
    private function persist(
        stdClass $row,
        string $identity,
        array $lease,
        MediaMetadata $metadata,
    ): stdClass {
        return Db::transaction(function () use ($row, $identity, $lease, $metadata): stdClass {
            $stateOwned = Db::table('playback_media_probe_states')->where('song_id', (string) $row->id)
                ->where('inventory_identity_sha256', $identity)
                ->where('lease_owner', $lease['owner'])->where('status', 'running')
                ->where('lease_expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))->exists();
            if (!$stateOwned) {
                return $this->refreshTechnicalFields($row);
            }
            $inventory = Db::table('library_file_inventory')->where('id', (string) $row->inventory_file_id)
                ->first([
                    'id', 'library_id', 'device_id', 'inode', 'file_size', 'modified_at',
                    'remote_etag', 'status', 'metadata_status',
                ]);
            if (!$inventory instanceof stdClass
                || (string) $inventory->status !== 'available'
                || (string) $inventory->metadata_status !== 'ready'
                || !hash_equals($identity, $this->identityFromInventory($row, $inventory))) {
                $this->releaseOwnedState((string) $row->id, $lease['owner']);
                return $row;
            }

            $song = Db::table('media_songs')->where('id', (string) $row->id)
                ->where('library_id', (string) $row->library_id)
                ->where('inventory_file_id', (string) $row->inventory_file_id)
                ->first(['album_id']);
            if (!$song instanceof stdClass) {
                $this->releaseOwnedState((string) $row->id, $lease['owner']);
                return $row;
            }
            $albumId = (string) $song->album_id;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $updated = Db::table('media_songs')->where('id', (string) $row->id)
                ->where('library_id', (string) $row->library_id)
                ->where('inventory_file_id', (string) $row->inventory_file_id)
                ->where('duration_ms', 0)->update($this->technicalValues($metadata) + ['updated_at' => $now]);
            if ($updated === 1) {
                $stats = Db::table('media_songs as songs')
                    ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
                    ->where('songs.album_id', $albumId)
                    ->where('files.status', 'available')->where('files.metadata_status', 'ready')
                    ->first([
                        Db::raw('COUNT(songs.id) AS song_count'),
                        Db::raw('COALESCE(SUM(songs.duration_ms), 0) AS duration_ms'),
                    ]);
                Db::table('media_albums')->where('id', $albumId)->update([
                    'song_count' => (int) ($stats?->song_count ?? 0),
                    'duration_ms' => (int) ($stats?->duration_ms ?? 0),
                    'updated_at' => $now,
                ]);
            }
            $this->releaseOwnedState((string) $row->id, $lease['owner']);

            return $this->refreshTechnicalFields($row);
        });
    }

    /**
     * 将失败转换为稳定错误码和有界退避；只有仍持有同一身份租约的 Worker 可以改写状态。
     *
     * @param array{owner:string,failureCount:int} $lease
     */
    private function fail(stdClass $row, string $identity, array $lease, Throwable $failure): void
    {
        try {
            $failureCount = min(10, $lease['failureCount'] + 1);
            $delayIndex = min(count(self::RETRY_DELAYS) - 1, max(0, $failureCount - 1));
            Db::table('playback_media_probe_states')->where('song_id', (string) $row->id)
                ->where('inventory_identity_sha256', $identity)
                ->where('lease_owner', $lease['owner'])->where('status', 'running')->update([
                    'status' => 'failed',
                    'failure_count' => $failureCount,
                    'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::RETRY_DELAYS[$delayIndex]),
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'error_code' => $this->failureCode($failure),
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
        } catch (Throwable) {
            // 播放补全是尽力而为；连失败状态都无法持久化时仍不能阻断已验证媒体的读取。
        }
    }

    /** @return array<string, int|float|string|null> 只选择允许从当前媒体探测更新的技术字段。 */
    private function technicalValues(MediaMetadata $metadata): array
    {
        return [
            'duration_ms' => $metadata->durationMs,
            'codec_name' => $metadata->codecName,
            'container_name' => $metadata->containerName,
            'bitrate' => $metadata->bitrate,
            'bit_depth' => $metadata->bitDepth,
            'sample_rate' => $metadata->sampleRate,
            'channels' => $metadata->channels,
            'replaygain_track_gain' => $metadata->replaygainTrackGain,
            'replaygain_track_peak' => $metadata->replaygainTrackPeak,
            'replaygain_album_gain' => $metadata->replaygainAlbumGain,
            'replaygain_album_peak' => $metadata->replaygainAlbumPeak,
        ];
    }

    /** 从数据库刷新公开播放描述实际使用的技术字段；不存在时保留调用方已验证快照。 */
    private function refreshTechnicalFields(stdClass $row): stdClass
    {
        $fresh = Db::table('media_songs')->where('id', (string) $row->id)->first(self::TECHNICAL_FIELDS);
        if (!$fresh instanceof stdClass) {
            return $row;
        }
        foreach (array_keys(get_object_vars($fresh)) as $field) {
            $row->{$field} = $fresh->{$field};
        }
        return $row;
    }

    /** 生成不含路径的库存身份摘要；字段顺序固定，供租约与写入 CAS 共同使用。 */
    private function identity(stdClass $row): string
    {
        return hash('sha256', implode("\0", [
            (string) $row->source_type, (string) $row->library_id, (string) $row->inventory_file_id,
            (string) $row->device_id, (string) $row->inode, (string) $row->file_size,
            (string) $row->modified_at, (string) ($row->remote_etag ?? ''),
        ]));
    }

    /** 使用事务内重新读取的库存事实生成与初始播放行相同的身份摘要。 */
    private function identityFromInventory(stdClass $row, stdClass $inventory): string
    {
        $copy = clone $row;
        foreach (['device_id', 'inode', 'file_size', 'modified_at', 'remote_etag'] as $field) {
            $copy->{$field} = $inventory->{$field};
        }
        $copy->library_id = $inventory->library_id;
        $copy->inventory_file_id = $inventory->id;
        return $this->identity($copy);
    }

    /** 本地探测前后都必须保持扫描记录的完整 stat 身份。 */
    private function localIdentityMatches(stdClass $row, string $absolutePath): bool
    {
        clearstatcache(true, $absolutePath);
        $stat = @stat($absolutePath);
        return is_array($stat)
            && (int) $stat['dev'] === (int) $row->device_id
            && (int) $stat['ino'] === (int) $row->inode
            && (int) $stat['size'] === (int) $row->file_size
            && (int) $stat['mtime'] === (int) $row->modified_at;
    }

    /** 网络对象必须保持相对路径、ETag、大小和修改时间全部一致。 */
    private function remoteIdentityMatches(stdClass $row, WebDavObject $object): bool
    {
        return !$object->directory
            && $object->relativePath === (string) $row->relative_path
            && $object->size === (int) $row->file_size
            && $object->modifiedAt === (int) $row->modified_at
            && hash_equals((string) $row->remote_etag, $object->etag);
    }

    /** 探测完成后重新列出父目录，避免把旧 ETag 的结果写给已替换对象。 */
    private function findRemoteObject(RemoteLibraryClient $client, string $relativePath): ?WebDavObject
    {
        $directory = dirname($relativePath);
        $directory = $directory === '.' ? '' : str_replace('\\', '/', $directory);
        foreach ($client->listDirectory($directory) as $candidate) {
            if ($candidate->relativePath === $relativePath && !$candidate->directory) {
                return $candidate;
            }
        }
        return null;
    }

    /** 租约释放使用 owner 条件，过期后接手的 Worker 不会被旧请求误删。 */
    private function releaseOwnedState(string $songId, string $owner): void
    {
        Db::table('playback_media_probe_states')->where('song_id', $songId)
            ->where('lease_owner', $owner)->delete();
    }

    /** 错误状态只保存稳定机器码，不保存第三方消息、路径或进程输出。 */
    private function failureCode(Throwable $failure): string
    {
        $code = match (true) {
            $failure instanceof MediaProbeFailed => $failure->errorCode,
            $failure instanceof RemoteLibraryUnavailable => $failure->errorCode,
            default => 'PLAYBACK_MEDIA_PROBE_FAILED',
        };
        return substr($code, 0, 96);
    }

    /** 探测标题只作为 FFprobe 标签缺失回退，最终不会进入业务元数据写入。 */
    private function fallbackTitle(stdClass $row): string
    {
        $filename = pathinfo((string) ($row->relative_path ?? ''), PATHINFO_FILENAME);
        return trim($filename) === '' ? (string) $row->title : $filename;
    }

}
