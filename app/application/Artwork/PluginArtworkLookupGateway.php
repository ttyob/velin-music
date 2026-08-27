<?php

declare(strict_types=1);

namespace app\application\Artwork;

use app\application\ResourcePlugin\Contract\ArtistArtworkScrapeRequest;
use app\application\ResourcePlugin\Contract\ArtistArtworkScrapeResult;
use app\application\ResourcePlugin\Contract\AlbumScrapeCompletionRequest;
use app\application\ResourcePlugin\Contract\AlbumScrapeCompletionResult;
use app\application\ResourcePlugin\Contract\ExternalMetadataScrapePluginRegistry;
use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use app\application\Metadata\AlbumScrapeArtworkCache;
use Throwable;

/**
 * 把 metadata-scrape 插件的封面结论适配到核心图片 Provider。
 *
 * 专辑图调用插件独立的专辑最终结果；艺人图使用独立的代表歌曲证明协议。
 * 插件不可用、没有资源或协议失败时返回空候选，绝不回退到核心音乐平台 Helper；音频内嵌图和扫描到的
 * 本地图片由其他本地索引链路负责。插件返回的 URL 只在当前调用栈中存在，后续仍由核心图片 Worker
 * 执行 CDN、DNS、尺寸、签名、许可和幂等导入校验。
 */
final readonly class PluginArtworkLookupGateway implements PluginMusicArtworkLookupGateway, PluginArtistArtworkLookupGateway
{
    public function __construct(
        private ExternalMetadataScrapePluginRegistry $plugins = new PhpResourcePluginRegistry(),
        private AlbumScrapeArtworkCache $albumArtworkCache = new AlbumScrapeArtworkCache(),
    ) {
    }

    /** 专辑图片只接受插件最终专辑结果；插件没有封面或不可用时返回空候选。 */
    public function lookup(array|string $first, array $second, array $third, array $proxyProfileIds = []): array
    {
        if (is_string($first)) {
            return $this->lookupArtist($first, $second, $third);
        }
        $query = $first;
        $keywords = $second;
        $sources = $third;
        $albumId = is_string($query['albumId'] ?? null) ? trim((string) $query['albumId']) : '';
        $albumTitle = trim((string) ($query['album'] ?? ''));
        if ($albumId !== '' && $albumTitle !== '') {
            $cachedUrl = $this->albumArtworkCache->find($albumId, $albumTitle, array_values($query['artists']));
            if ($cachedUrl !== null) {
                return [[
                    'source' => 'metadata-scrape', 'status' => 'matched', 'candidateCount' => 1,
                    'best' => [
                        'title' => $albumTitle, 'artists' => array_values($query['artists']), 'album' => $albumTitle,
                        'artworkUrl' => $cachedUrl, 'score' => 100, 'reasons' => ['PLUGIN_ALBUM_CACHE'],
                    ], 'errorCode' => null, 'latencyMs' => 0,
                ]];
            }
        }
        try {
            $plugin = $this->plugins->metadataAlbum('metadata-scrape');
            if ($albumTitle !== '') {
                $result = $plugin->scrapeAlbum(new AlbumScrapeCompletionRequest(
                    $albumTitle,
                    array_values($query['artists']),
                ));
                if ($result->status === AlbumScrapeCompletionResult::MATCHED && $result->artworkUrl !== null) {
                    return [[
                        'source' => 'metadata-scrape', 'status' => 'matched', 'candidateCount' => 1,
                        'best' => [
                            'title' => $albumTitle, 'artists' => array_values($query['artists']), 'album' => $albumTitle,
                            'artworkUrl' => $result->artworkUrl, 'score' => $result->confidence ?? 0,
                            'reasons' => ['PLUGIN_ALBUM_FINALIZED'],
                        ], 'errorCode' => null, 'latencyMs' => 0,
                    ]];
                }
            }
        } catch (Throwable) {
            // 专辑插件边界失败只影响本次插件尝试；不能调用核心 Helper 越过插件所有权边界。
        }
        // 专辑图片只允许走独立专辑协议；歌曲协议的封面属于歌曲身份，不能再次冒充专辑实体结果。
        return [];
    }

    /** 艺人图片只接受插件的同平台代表歌曲证明协议；插件失败时返回空候选。 */
    /** @param array<string,mixed> $representativeSong @param list<string> $sources */
    private function lookupArtist(string $artist, array $representativeSong, array $sources): array
    {
        try {
            $plugin = $this->plugins->metadataArtistArtwork('metadata-scrape');
            $result = $plugin->scrapeArtistArtwork(new ArtistArtworkScrapeRequest(
                $artist,
                (string) $representativeSong['title'],
                array_values($representativeSong['artists']),
                isset($representativeSong['album']) ? (string) $representativeSong['album'] : null,
                isset($representativeSong['durationMs']) ? (int) $representativeSong['durationMs'] : null,
            ));
            if ($result->status === ArtistArtworkScrapeResult::MATCHED && $result->artworkUrl !== null) {
                return [[
                    'source' => 'metadata-scrape', 'status' => 'matched', 'candidateCount' => 1,
                    'best' => [
                        'artistName' => $result->artistName, 'artworkUrl' => $result->artworkUrl,
                        'score' => $result->confidence ?? 0,
                        'reasons' => ['ARTIST_EXACT', 'REPRESENTATIVE_SONG_MATCH'],
                    ], 'errorCode' => null, 'latencyMs' => 0,
                ]];
            }
        } catch (Throwable) {
            // 插件异常不应转为核心第三方查询；调用方会把本次资源记为无候选或可重试失败。
        }
        return [];
    }
}
