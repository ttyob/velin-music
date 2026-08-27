<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Library\RemoteLibraryClientFactory;
use app\application\Library\RemoteLibraryUnavailable;
use app\application\Library\WebDavObject;
use stdClass;
use support\Db;
use Throwable;

/**
 * 把一首已授权歌曲解析为经过实时身份复验的统一媒体读取源。
 *
 * Controller 负责全局 play 能力，本服务通过活动音乐库授权限制对象范围。本地文件在流式读取前重新验证
 * 根目录、规范路径、设备号、inode、大小和 mtime；WebDAV 对象重新验证根内相对路径、ETag、大小和
 * mtime。挂载漂移、链接替换或远端版本变化都会失败，未验证的数据库路径与远端坐标不会被打开。
 * 身份通过但数据库时长未知时，会在返回读取源前调用受租约保护的技术事实补全；补全失败保持时长未知
 * 并继续播放，普通请求中的网络库只允许 Range；完整源副本只能由独立有界的下一首 Worker 创建。
 */
final class MediaStreamService implements MediaStreamResolver
{
    public function __construct(
        private readonly RemoteLibraryClientFactory $remoteClients = new RemoteLibraryClientFactory(),
        private readonly MissingMediaTechnicalFactsHydrator $technicalFacts = new MissingMediaTechnicalFactsHydrator(),
        private readonly RemotePlaybackCache $remotePlaybackCache = new RemotePlaybackCache(),
    ) {
    }

    /**
     * 返回不向公开投影暴露本地路径、远端地址或凭据的内部播放描述。
     *
     * @param array<string, mixed> $actor 已清洗且仍需按实时授权收敛的会话身份。
     * @return PlayableMedia 路径与凭据均留在服务端的媒体读取描述；时长可能已由本次请求补全。
     * @throws MediaStreamNotFound ID 无效、对象不存在或不在身份的活动音乐库范围内。
     * @throws MediaStreamUnavailable 已授权对象的当前身份无法证明与扫描快照一致。
     */
    public function resolve(array $actor, string $songId): PlayableMedia
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1) {
            throw new MediaStreamNotFound('Song is not available in the current scope.');
        }
        // 旧 ID 只负责定位规范目标，下面仍对目标重新执行活动库、库存和账号 grant 校验；因此重定向
        // 不能把调用者带入无权音乐库，也不能绕过播放前的本地或远端文件身份复验。
        $songId = (new SongDuplicateRedirectResolver())->resolve($songId);

        $query = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('songs.id', $songId)
            ->where('libraries.status', 'active')
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as stream_grant', function ($join) use ($actor): void {
                $join->on('stream_grant.library_id', '=', 'songs.library_id')
                    ->where('stream_grant.user_id', '=', (string) $actor['id']);
            });
        }

        /** @var stdClass|null $row */
        $row = $query->first([
            'songs.id', 'songs.title', 'songs.library_id', 'songs.album_id',
            'songs.inventory_file_id', 'files.relative_path',
            'files.resolved_path', 'files.extension', 'files.remote_etag',
            'songs.duration_ms', 'songs.codec_name', 'songs.container_name', 'songs.bitrate',
            'songs.bit_depth', 'songs.sample_rate', 'songs.channels',
            'songs.replaygain_track_gain', 'songs.replaygain_track_peak',
            'songs.replaygain_album_gain', 'songs.replaygain_album_peak',
            'files.device_id', 'files.inode', 'files.file_size', 'files.modified_at',
            'libraries.resolved_root_path', 'libraries.source_type',
        ]);
        if (!$row instanceof stdClass) {
            throw new MediaStreamNotFound('Song is not available in the current scope.');
        }

        if ((string) $row->source_type !== 'local') {
            // 缓存只能在本次远端目录身份复验成功后替换读取源；撤权、对象变化或连接失效时不得直接打开
            // 昨日缓存。缓存损坏则保留已验证远端源，当前播放不依赖预缓存一定成功。
            return $this->remotePlaybackCache->useCached($this->validateWebDavObject($row));
        }
        return $this->validateFile($row);
    }

    /**
     * 实时复验远端对象后返回仅允许有界 Range 的读取源。
     *
     * 每次解析都重新列出父目录，要求路径、ETag、大小和修改时间与扫描库存完全一致。远端删除、替换、
     * 撤销凭据或 TLS 失败都会暂停播放，直到重新扫描或修复连接；本步骤和后续直放均不创建完整本地副本。
     */
    private function validateWebDavObject(stdClass $row): PlayableMedia
    {
        try {
            $client = $this->remoteClients->forLibrary((string) $row->library_id);
            $relative = (string) $row->relative_path;
            $directory = dirname($relative);
            $directory = $directory === '.' ? '' : str_replace('\\', '/', $directory);
            $current = null;
            foreach ($client->listDirectory($directory) as $candidate) {
                if ($candidate->relativePath === $relative && !$candidate->directory) $current = $candidate;
            }
            if (!$current instanceof WebDavObject
                || $current->size !== (int) $row->file_size
                || $current->modifiedAt !== (int) $row->modified_at
                || !hash_equals((string) $row->remote_etag, $current->etag)) {
                throw new MediaStreamUnavailable(strtoupper((string) $row->source_type) . '_OBJECT_IDENTITY_CHANGED');
            }
        } catch (MediaStreamUnavailable $failure) {
            throw $failure;
        } catch (RemoteLibraryUnavailable) {
            throw new MediaStreamUnavailable(strtoupper((string) $row->source_type) . '_SOURCE_UNAVAILABLE');
        }
        if ((int) $row->duration_ms === 0) {
            try {
                $row = $this->technicalFacts->hydrateRemote($row, $client, $current);
            } catch (Throwable) {
                // 技术信息补全是播放附带的尽力操作；任何实现错误都不能覆盖已通过的媒体身份验证。
            }
        }
        return $this->playable($row, new WebDavMediaSource($client, $current), (int) $row->file_size, (int) $row->modified_at, [
            (string) $row->id,
            (string) $row->remote_etag,
            (string) $row->file_size,
            (string) $row->modified_at,
        ]);
    }

    /**
     * 重新验证本地规范根、文件路径和扫描时记录的完整 stat 身份。
     *
     * 音乐库根自身仍必须解析为登记的规范根，文件必须位于其真实子路径内且保持设备号、inode、大小和
     * mtime 一致；挂载或符号链接目标被替换时失败关闭，不允许另一个文件树借用已授权路径名进入播放。
     */
    private function validateFile(stdClass $row): PlayableMedia
    {
        $registeredRoot = rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR);
        $currentRoot = realpath($registeredRoot);
        if ($currentRoot === false || $currentRoot !== $registeredRoot || !is_dir($currentRoot)) {
            throw new MediaStreamUnavailable('MEDIA_LIBRARY_ROOT_CHANGED');
        }

        $registeredPath = (string) $row->resolved_path;
        $currentPath = realpath($registeredPath);
        $rootPrefix = $registeredRoot . DIRECTORY_SEPARATOR;
        if ($currentPath === false
            || $currentPath !== $registeredPath
            || !str_starts_with($currentPath, $rootPrefix)
            || !is_file($currentPath)
            || !is_readable($currentPath)
        ) {
            throw new MediaStreamUnavailable('MEDIA_FILE_PATH_CHANGED');
        }

        $stat = @stat($currentPath);
        if (!is_array($stat)
            || (int) $stat['dev'] !== (int) $row->device_id
            || (int) $stat['ino'] !== (int) $row->inode
            || (int) $stat['size'] !== (int) $row->file_size
            || (int) $stat['mtime'] !== (int) $row->modified_at
            || (int) $stat['size'] <= 0
        ) {
            throw new MediaStreamUnavailable('MEDIA_FILE_IDENTITY_CHANGED');
        }

        if ((int) $row->duration_ms === 0) {
            try {
                $row = $this->technicalFacts->hydrateLocal($row, $currentPath);
            } catch (Throwable) {
                // 本地 FFprobe 或补全状态写入失败不能阻断已经验证安全的原文件读取。
            }
        }
        return $this->playable($row, new LocalMediaSource($currentPath), (int) $stat['size'], (int) $stat['mtime'], [
            (string) $row->id,
            (string) $stat['dev'],
            (string) $stat['ino'],
            (string) $stat['size'],
            (string) $stat['mtime'],
        ]);
    }

    /** @param list<string> $etagParts 构造不暴露本地路径或远端 URL 的统一播放描述。 */
    private function playable(stdClass $row, MediaReadableSource $source, int $size, int $modifiedAt, array $etagParts): PlayableMedia
    {
        $extension = strtolower((string) $row->extension);
        $title = $this->safeTitle((string) $row->title);
        return new PlayableMedia(
            songId: (string) $row->id,
            title: (string) $row->title,
            source: $source,
            downloadName: $title . '.' . $extension,
            mimeType: $this->mimeType($extension),
            fileSize: $size,
            modifiedAt: $modifiedAt,
            etag: '"' . hash('sha256', implode("\0", $etagParts)) . '"',
            durationMs: max(0, (int) $row->duration_ms),
            bitrate: $row->bitrate === null ? null : max(0, (int) $row->bitrate),
            replayGainTrackGain: $this->finiteFloat($row->replaygain_track_gain),
            replayGainTrackPeak: $this->positiveFiniteFloat($row->replaygain_track_peak),
            replayGainAlbumGain: $this->finiteFloat($row->replaygain_album_gain),
            replayGainAlbumPeak: $this->positiveFiniteFloat($row->replaygain_album_peak),
        );
    }

    /** 将 SQLite 数值列收敛为有限浮点数，损坏值按标签缺失处理，绝不送入 FFmpeg 参数。 */
    private function finiteFloat(mixed $value): ?float
    {
        if ($value === null || !is_numeric($value)) return null;
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    /** ReplayGain peak 必须大于零；零值无法参与防削波上限计算。 */
    private function positiveFiniteFloat(mixed $value): ?float
    {
        $number = $this->finiteFloat($value);
        return $number !== null && $number > 0.0 ? $number : null;
    }

    /** Removes separators and control characters before a title enters Content-Disposition. */
    private function safeTitle(string $title): string
    {
        $title = preg_replace('/[\x00-\x1F\x7F\\\\\/]+/u', ' ', trim($title)) ?? '';
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        if ($title === '') {
            return 'audio';
        }

        return mb_substr($title, 0, 160, 'UTF-8');
    }

    /** Maps only scanner-supported extensions to conservative browser audio media types. */
    private function mimeType(string $extension): string
    {
        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            'm4a', 'm4b', 'alac' => 'audio/mp4',
            'ogg', 'oga', 'opus' => 'audio/ogg',
            'wav' => 'audio/wav',
            'aif', 'aiff' => 'audio/aiff',
            'wma' => 'audio/x-ms-wma',
            'ape' => 'audio/x-ape',
            default => 'application/octet-stream',
        };
    }
}
