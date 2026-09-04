<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Account\AccountAvatarService;
use app\application\Artwork\ArtworkNotFound;
use app\application\Artwork\ArtworkService;
use app\application\Artwork\ArtworkTransformFailed;
use app\application\Artwork\ArtworkTransformService;
use app\application\Artwork\ArtworkUnavailable;
use app\application\Artwork\ResolvedArtwork;
use app\application\Media\MediaStreamNotFound;
use app\application\Media\MediaStreamService;
use app\application\Media\MediaStreamUnavailable;
use app\application\Media\PlayableMedia;
use app\application\Playback\PlayerProfileService;
use app\application\Playlist\PlaylistCoverImage;
use app\application\Playlist\PlaylistCoverService;
use app\application\Playlist\PlaylistNotFound;
use app\application\System\SystemLimitSettingsService;
use app\http\ByteRangeParser;
use app\http\LocalFileResponseFactory;
use app\http\MediaSourceResponseFactory;
use app\http\UnsatisfiableByteRange;
use RuntimeException;
use support\Request;
use support\Response;

/**
 * 在盐值 token 认证后生成 Subsonic 音频、下载和图片原始响应。
 *
 * 服务复用 Web 端文件解析器，包括实时音乐库授权、规范根约束、符号链接/挂载防护和扫描期完整文件身份
 * 校验。本地物理路径仅进入 Go 加密投递描述或 Workerman 内部文件响应，不向客户端公开；发送源文件不会
 * 增加播放次数。转码交给有并发上限的非阻塞 FFmpeg supervisor，不支持的变换会显式失败，不能静默偏离
 * 客户端协商结果。
 */
final readonly class SubsonicMediaService
{
    /** 复用已有授权安全的文件解析器和经过测试的单区间 Range 解析器。 */
    public function __construct(
        private MediaStreamService $streams = new MediaStreamService(),
        private ArtworkService $artwork = new ArtworkService(),
        private ArtworkTransformService $transforms = new ArtworkTransformService(),
        private ByteRangeParser $ranges = new ByteRangeParser(),
        private SubsonicTranscodeService $transcodes = new SubsonicTranscodeService(),
        private SystemLimitSettingsService $limits = new SystemLimitSettingsService(),
        private PlayerProfileService $players = new PlayerProfileService(),
        private PlaylistCoverService $playlistCovers = new PlaylistCoverService(),
    ) {
    }

    /**
     * Direct-plays one source song with HTTP validators and single-range seeking.
     *
     * Direct play accepts absent/`raw`/source-equal format with zero offset and bitrate limit. Other
     * supported requests become MP3/AAC/Opus plans supervised outside synchronous Controller work.
     * `estimateContentLength=true` uses a private spool for exact HTTP length; normal conversions are
     * chunked immediately. Neither direct nor converted byte delivery increments playback counts.
     *
     * @param array<string, mixed> $actor Authenticated principal with live grants.
     * @param array<string, mixed> $parameters Merged Subsonic parameters.
     */
    public function stream(Request $request, array $actor, array $parameters, string $requestId): Response
    {
        $this->requireCapability($actor, 'play');
        $media = $this->resolveSong($actor, $parameters['id'] ?? null);
        $parameters = $this->players->applySubsonicParameters($actor, $parameters);
        $transcoded = $this->transcodes->negotiate($media, $parameters, $requestId, $actor);
        if ($transcoded !== null) {
            return $transcoded;
        }

        return $this->audioResponse($request, $media, $requestId, false);
    }

    /**
     * 下载一首已授权源歌曲，不允许把专辑、艺人或播放列表 ID 解释为集合归档。
     *
     * 下载权限独立于播放权限，并继续应用活动音乐库授权和当前文件身份复验。文件名由扫描器支持的后缀与
     * 去除控制符/分隔符的标题构造，原文件支持 Range 续传；集合 ID 与不可见歌曲统一返回不存在，不能
     * 启动 ZIP、建立暂存链接或隐式批量读取媒体。
     *
     * @param array<string, mixed> $actor 已认证且仍需实时验证音乐库授权的账号身份。
     */
    public function download(Request $request, array $actor, array $parameters, string $requestId): Response
    {
        $this->requireCapability($actor, 'download');
        $this->limits->assertFeatureAllowed('download');
        $media = $this->resolveSong($actor, $parameters['id'] ?? null);

        // 下载转码与播放转码必须共用格式白名单、全局限额、并发槽和非阻塞子进程监管。
        // 唯一差异是 Content-Disposition 使用 attachment；仅请求原格式时仍走支持 Range/ETag
        // 的零转换文件响应，避免无意义地启动 FFmpeg 或写入临时文件。
        $transcoded = $this->transcodes->negotiate($media, $parameters, $requestId, $actor, true);
        if ($transcoded !== null) {
            return $transcoded;
        }

        return $this->audioResponse($request, $media, $requestId, true);
    }

    /**
     * 按类型化 `coverArt` ID 返回歌曲、专辑、艺人或歌单图片。
     *
     * 新目录投影分别生成 `so:`、`al:`、`ar:`、`pl:` 前缀，避免多个实体共用 ULID 空间时发生歧义；
     * 历史客户端缓存的裸 ULID 继续按专辑解释。媒体图片委托 ArtworkService 复验音乐库授权，歌单图片
     * 委托 PlaylistCoverService 复验 owner/server 可见性和 BLOB 摘要；前缀本身不授予访问权。可选 size
     * 只允许 16-2048 像素，缩小结果使用用途分离 ETag，所有图片均不放大或暴露存储位置。
     *
     * @param array<string, mixed> $actor 已通过 Subsonic 认证、仍需实时复验授权的账号。
     * @param array<string, mixed> $parameters 已合并且尚需严格校验的协议参数。
     */
    public function coverArt(Request $request, array $actor, array $parameters, string $requestId): Response
    {
        $this->requireCapability($actor, 'play');
        [$kind, $entityId] = $this->artworkId($parameters['id'] ?? null);
        $size = array_key_exists('size', $parameters)
            ? $this->integer($parameters['size'], 16, 2048, 'Artwork size')
            : null;
        if ($kind === 'playlist') {
            return $this->playlistCoverResponse($request, $actor, $entityId, $size, $requestId);
        }
        try {
            $artwork = match ($kind) {
                'song' => $this->artwork->resolveSong($actor, $entityId),
                'artist' => $this->artwork->resolveArtist($actor, $entityId),
                default => $this->artwork->resolveAlbum($actor, $entityId),
            };
            if ($size !== null) {
                $artwork = $this->transforms->resize($artwork, $size);
            }
        } catch (ArtworkNotFound $exception) {
            throw new SubsonicEntityNotFound('Artwork was not found.', previous: $exception);
        } catch (ArtworkUnavailable $exception) {
            throw new RuntimeException('Authorized artwork failed runtime validation.', previous: $exception);
        } catch (ArtworkTransformFailed $exception) {
            throw new RuntimeException('Authorized artwork transformation failed.', previous: $exception);
        }
        $headers = $this->artworkHeaders($artwork, $requestId);
        if ($this->isNotModified($request, $artwork->etag, $artwork->modifiedAt)) {
            return response('', 304, $headers);
        }

        return (new LocalFileResponseFactory())->create(
            $artwork->path,
            200,
            $headers,
            0,
            $artwork->fileSize,
        );
    }

    /**
     * 返回经过共享可见性与摘要校验的歌单封面，并在内存中生成有界缩略图。
     *
     * 歌单封面是数据库内固定 800x800 WebP，不能交给只接受已授权文件路径的 ArtworkTransformService。
     * 请求尺寸不小于 800 时直接返回原字节以禁止放大；更小尺寸最多创建一个 800x800 源画布和目标画布，
     * 不写临时文件。解码、重采样或编码失败作为服务端完整性错误失败关闭。私有失权、无封面和非法实体
     * 统一映射为协议 not-found，避免图片端点成为歌单枚举通道。
     */
    private function playlistCoverResponse(
        Request $request,
        array $actor,
        string $playlistId,
        ?int $size,
        string $requestId,
    ): Response {
        try {
            $image = $this->playlistCovers->image($actor, $playlistId);
        } catch (PlaylistNotFound $exception) {
            throw new SubsonicEntityNotFound('Artwork was not found.', previous: $exception);
        }
        if ($size !== null && $size < 800) {
            $image = $this->resizePlaylistCover($image, $size);
        }
        $headers = $this->playlistCoverHeaders($image, $requestId);
        if ($this->isNotModified($request, $image->etag, $image->modifiedAt)) {
            return response('', 304, $headers);
        }
        $headers['Content-Length'] = (string) strlen($image->bytes);

        return response($image->bytes, 200, $headers);
    }

    /**
     * 将已验证的固定 WebP 缩小为正方形，并派生与原内容和尺寸绑定的稳定缓存标识。
     *
     * 前置条件是 PlaylistCoverService 已完成字节长度和 SHA-256 校验，size 位于 16-799。函数无持久化
     * 副作用；任何 GD 失败都丢弃输出缓冲并释放画布，不返回半截图片。ETag 直接绑定实际输出字节并加入
     * 用途标签，避免 GD/WebP 版本差异或其他协议变换复用错误验证器；modifiedAt 保留原封面的更新时间。
     */
    private function resizePlaylistCover(PlaylistCoverImage $image, int $size): PlaylistCoverImage
    {
        $source = @imagecreatefromstring($image->bytes);
        if (!$source instanceof \GdImage || imagesx($source) !== 800 || imagesy($source) !== 800) {
            if ($source instanceof \GdImage) imagedestroy($source);
            throw new RuntimeException('PLAYLIST_COVER_DECODE_FAILED');
        }
        $target = imagecreatetruecolor($size, $size);
        if (!$target instanceof \GdImage) {
            imagedestroy($source);
            throw new RuntimeException('PLAYLIST_COVER_CANVAS_FAILED');
        }
        $bufferLevel = ob_get_level();
        try {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefill($target, 0, 0, $transparent);
            if (!imagecopyresampled($target, $source, 0, 0, 0, 0, $size, $size, 800, 800)) {
                throw new RuntimeException('PLAYLIST_COVER_RESAMPLE_FAILED');
            }
            ob_start();
            if (!imagewebp($target, null, 82)) {
                throw new RuntimeException('PLAYLIST_COVER_ENCODE_FAILED');
            }
            $bytes = ob_get_clean();
            if (!is_string($bytes) || $bytes === '') {
                throw new RuntimeException('PLAYLIST_COVER_ENCODE_FAILED');
            }
        } catch (\Throwable $throwable) {
            while (ob_get_level() > $bufferLevel) ob_end_clean();
            throw $throwable;
        } finally {
            imagedestroy($target);
            imagedestroy($source);
        }
        $digest = hash('sha256', "velin:subsonic:playlist-cover:v1\0" . $bytes);

        return new PlaylistCoverImage($bytes, '"playlist-cover-size-' . substr($digest, 0, 32) . '"', $image->modifiedAt);
    }

    /** 构造内存歌单 WebP 的私有条件缓存头；正文长度只在 200 响应中加入。 */
    private function playlistCoverHeaders(PlaylistCoverImage $image, string $requestId): array
    {
        return [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600, must-revalidate',
            'ETag' => $image->etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $image->modifiedAt) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $requestId,
        ];
    }

    /**
     * 返回认证账号自己的自定义 PNG 或确定性本地占位头像，不访问 Gravatar 或任何外部服务。
     *
     * Subsonic 以 username 请求头像；当前兼容面刻意不允许借此枚举其他账号，大小写不匹配之外的其他
     * 用户统一返回 not-found。自定义头像来自已验证的账号 BLOB；占位图只由不可公开的用户 ULID 经
     * 用途分离哈希生成，不读取邮箱、显示名、主题或任意客户端颜色。响应使用内容 ETag 和 private 缓存。
     *
     * @param array<string,mixed> $actor 已通过 salt+token 认证的账号投影。
     * @throws SubsonicRequestInvalid username 缺失、为数组、空白或超过协议边界。
     * @throws SubsonicEntityNotFound 请求的 username 不是当前认证账号。
     */
    public function avatar(Request $request, array $actor, mixed $requestedUsername, string $requestId): Response
    {
        if (!is_string($requestedUsername)) throw new SubsonicRequestInvalid('Avatar username is required.');
        $requestedUsername = trim($requestedUsername);
        if ($requestedUsername === '' || strlen($requestedUsername) > 254) {
            throw new SubsonicRequestInvalid('Avatar username is invalid.');
        }
        $username = is_string($actor['username'] ?? null) ? $actor['username'] : '';
        $userId = is_string($actor['id'] ?? null) ? $actor['id'] : '';
        if ($username === '' || $userId === '' || strcasecmp($username, $requestedUsername) !== 0) {
            throw new SubsonicEntityNotFound('Avatar user was not found.');
        }
        $image = (new AccountAvatarService())->image($actor);
        $etag = $image->etag;
        $headers = [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=86400, must-revalidate',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $requestId,
        ];
        if ($this->isNotModified($request, $etag, 0)) return response('', 304, $headers);
        $bytes = $image->bytes;
        $headers['Content-Length'] = (string) strlen($bytes);
        return response($bytes, 200, $headers);
    }

    /** 构造完整或单区间源文件响应；本地文件优先由 Go 发送，PHP 不缓冲音频正文。 */
    private function audioResponse(
        Request $request,
        PlayableMedia $media,
        string $requestId,
        bool $attachment,
    ): Response {
        $headers = $this->audioHeaders($media, $requestId, $attachment);
        if ($this->isNotModified($request, $media->etag, $media->modifiedAt)) {
            return response('', 304, $headers);
        }
        $rangeHeader = (string) $request->header('range', '');
        $range = null;
        if ($rangeHeader !== '' && $this->ifRangeMatches($request, $media->etag, $media->modifiedAt)) {
            try {
                $range = $this->ranges->parse($rangeHeader, $media->fileSize);
            } catch (UnsatisfiableByteRange) {
                return response('', 416, [
                    'Content-Range' => 'bytes */' . $media->fileSize,
                    'Accept-Ranges' => 'bytes',
                    'Cache-Control' => 'private, no-cache, must-revalidate',
                    'X-Request-ID' => $requestId,
                ]);
            }
        }

        return (new MediaSourceResponseFactory())->create(
            $media,
            $range === null ? 200 : 206,
            $headers,
            $range?->offset ?? 0,
            $range?->length ?? $media->fileSize,
        );
    }

    /** 解析严格歌曲 ID，并把不可见或失效记录统一映射为协议 not-found。 */
    private function resolveSong(array $actor, mixed $songId): PlayableMedia
    {
        $songId = $this->requiredId($songId);
        try {
            return $this->streams->resolve($actor, $songId);
        } catch (MediaStreamNotFound $exception) {
            throw new SubsonicEntityNotFound('Song was not found.', previous: $exception);
        } catch (MediaStreamUnavailable $exception) {
            throw new RuntimeException('Authorized media failed runtime validation.', previous: $exception);
        }
    }

    /** 构造私有音频缓存头和符合 RFC 5987 的 UTF-8 下载文件名。 */
    private function audioHeaders(PlayableMedia $media, string $requestId, bool $attachment): array
    {
        return [
            'Content-Type' => $media->mimeType,
            'Content-Disposition' => ($attachment ? 'attachment' : 'inline')
                . "; filename=\"audio\"; filename*=UTF-8''" . rawurlencode($media->downloadName),
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'ETag' => $media->etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $media->modifiedAt) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $requestId,
        ];
    }

    /**
     * 构造 Subsonic 原图的私有缓存头，不暴露扫描文件名和物理路径。
     *
     * `withFile()` 会在 Workerman 编码阶段按照原图或缩放缓存文件的实际范围生成唯一
     * Content-Length。这里预设长度会在框架递归合并时产生重复响应头，并被 Nginx 判定为无效
     * 上游响应；文件授权、路径约束和身份校验仍由共享解析器负责，不受此响应头调整影响。
     */
    private function artworkHeaders(ResolvedArtwork $artwork, string $requestId): array
    {
        return [
            'Content-Type' => $artwork->mimeType,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600, must-revalidate',
            'ETag' => $artwork->etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $artwork->modifiedAt) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $requestId,
        ];
    }

    /** 按协议优先校验 If-None-Match，再按秒精度校验日期验证器。 */
    private function isNotModified(Request $request, string $etag, int $modifiedAt): bool
    {
        $ifNoneMatch = trim((string) $request->header('if-none-match', ''));
        if ($ifNoneMatch !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || ltrim($candidate, 'W/') === $etag) {
                    return true;
                }
            }

            return false;
        }
        $date = strtotime((string) $request->header('if-modified-since', ''));

        return $date !== false && $modifiedAt <= $date;
    }

    /** 仅在可选 If-Range 仍能强校验当前源文件时采用 Range。 */
    private function ifRangeMatches(Request $request, string $etag, int $modifiedAt): bool
    {
        $ifRange = trim((string) $request->header('if-range', ''));
        if ($ifRange === '') {
            return true;
        }
        if (str_starts_with($ifRange, '"') || str_starts_with($ifRange, 'W/')) {
            return $ifRange === $etag;
        }
        $date = strtotime($ifRange);

        return $date !== false && $modifiedAt <= $date;
    }

    /** 解析一个有上限、非负且使用规范十进制形式的参数。 */
    private function integer(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new SubsonicRequestInvalid($label . ' is invalid.');
        }
        if ($integer < $minimum || $integer > $maximum) {
            throw new SubsonicRequestInvalid($label . ' is outside the supported range.');
        }

        return $integer;
    }

    /** 在文件查询前拒绝缺失、数组、类路径或格式错误的不透明媒体 ID。 */
    private function requiredId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new SubsonicRequestInvalid('A required media ID is missing or invalid.');
        }

        return $value;
    }

    /**
     * 解析服务端生成的封面身份，同时保留旧版裸专辑 ULID。
     *
     * 类型和值都来自固定语法，不能包含路径、URL 或客户端自定义 Provider；无效输入在查询数据库前
     * 终止。返回的实体 ID 仍会由 ArtworkService 进行重定向和实时授权复验。
     *
     * @return array{0:'album'|'song'|'artist'|'playlist',1:string}
     */
    private function artworkId(mixed $value): array
    {
        if (!is_string($value)) {
            throw new SubsonicRequestInvalid('A required artwork ID is missing or invalid.');
        }
        if (preg_match('/^(al|so|ar|pl):([0-9A-HJKMNP-TV-Z]{26})$/', $value, $match) === 1) {
            return [match ($match[1]) {
                'so' => 'song',
                'ar' => 'artist',
                'pl' => 'playlist',
                default => 'album',
            }, $match[2]];
        }
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1) {
            return ['album', $value];
        }
        throw new SubsonicRequestInvalid('A required artwork ID is missing or invalid.');
    }

    /** 在实时音乐库授权之外继续强制全局操作能力。 */
    private function requireCapability(array $actor, string $capability): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array($capability, $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Subsonic media operation is not authorized.');
        }
    }
}
