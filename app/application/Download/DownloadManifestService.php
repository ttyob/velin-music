<?php

declare(strict_types=1);

namespace app\application\Download;

use app\application\Media\MediaQueryService;
use app\application\Media\MediaStreamService;
use app\application\Playlist\PlaylistService;

/**
 * 展开歌曲、专辑或播放列表为当前账号可下载的不可变媒体清单。
 *
 * 专辑和播放列表先通过既有对象级授权查询获得歌曲 ID，再逐首调用 MediaStreamService 复验运行时文件
 * 身份。清单不读取媒体正文，也不在请求内计算大文件 SHA-256；强摘要明确返回 null，客户端至少验证
 * Content-Length 与 ETag。最多返回 5000 首且去重，避免恶意巨型列表造成无界 stat/WebDAV 请求。
 */
final readonly class DownloadManifestService
{
    private const MAX_ITEMS = 5000;

    public function __construct(private MediaStreamService $streams = new MediaStreamService())
    {
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function create(array $actor, string $type, string $id): array
    {
        if (!in_array($type, ['song', 'album', 'playlist'], true)
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
            throw new DownloadManifestInvalid('下载清单目标无效。');
        }
        $songIds = match ($type) {
            'song' => [$id],
            'album' => $this->albumSongIds($actor, $id),
            'playlist' => $this->playlistSongIds($actor, $id),
        };
        $songIds = array_values(array_unique($songIds));
        if (count($songIds) > self::MAX_ITEMS) {
            throw new DownloadManifestInvalid('下载清单超过单次上限。');
        }
        $items = [];
        foreach ($songIds as $songId) {
            $media = $this->streams->resolve($actor, $songId);
            $version = trim($media->etag, '"');
            $items[] = [
                'mediaId' => $media->songId,
                'version' => $version,
                'etag' => $media->etag,
                'mimeType' => $media->mimeType,
                'contentLength' => $media->fileSize,
                'durationMs' => $media->durationMs,
                'checksum' => null,
                'downloadUrl' => '/api/v1/downloads/' . rawurlencode($media->songId),
                'artworkUrl' => '/api/v1/songs/' . rawurlencode($media->songId) . '/cover',
                'lyricsUrl' => '/api/v1/songs/' . rawurlencode($media->songId) . '/lyrics',
            ];
        }
        $manifestVersion = hash('sha256', implode("\0", array_map(
            static fn (array $item): string => $item['mediaId'] . ':' . $item['version'], $items,
        )));
        return ['source' => ['type' => $type, 'id' => $id], 'version' => $manifestVersion,
            'etag' => '"' . $manifestVersion . '"', 'itemCount' => count($items),
            'totalBytes' => array_sum(array_column($items, 'contentLength')), 'items' => $items];
    }

    /** @param array<string,mixed> $actor @return list<string> */
    private function albumSongIds(array $actor, string $albumId): array
    {
        $detail = (new MediaQueryService())->albumDetail($actor, $albumId);
        return array_values(array_filter(array_map(static fn (mixed $song): ?string =>
            is_array($song) && is_string($song['id'] ?? null) ? $song['id'] : null,
            is_array($detail['songs'] ?? null) ? $detail['songs'] : [])));
    }

    /** @param array<string,mixed> $actor @return list<string> */
    private function playlistSongIds(array $actor, string $playlistId): array
    {
        $detail = (new PlaylistService())->detail($actor, $playlistId);
        return array_values(array_filter(array_map(static fn (mixed $item): ?string =>
            is_array($item) && is_array($item['song'] ?? null) && is_string($item['song']['id'] ?? null)
                ? $item['song']['id'] : null,
            is_array($detail['songs'] ?? null) ? $detail['songs'] : [])));
    }
}
