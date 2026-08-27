<?php

declare(strict_types=1);

namespace app\application\Metadata;

use stdClass;
use support\Db;

/**
 * 保存专辑资料任务已验证的封面地址，供独立图片 Provider 在短期内复用。
 *
 * 只保存专辑身份摘要、地址和更新时间，不保存第三方原始响应或图片字节。读取必须同时满足稳定
 * 专辑 ID、当前标题/艺人摘要和新鲜期；缓存失效时回到独立专辑查询。写入应位于专辑资料事务内，
 * 因而身份校验或任务租约失败会一起回滚，不会留下误归属的地址。
 */
final readonly class AlbumScrapeArtworkCache
{
    private const FRESH_SECONDS = 7_776_000;

    /** @param list<string> $artists */
    public function find(string $albumId, string $title, array $artists): ?string
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('album_scrape_artwork_cache')) return null;
        $row = Db::table('album_scrape_artwork_cache')->where('album_id', $albumId)
            ->where('identity_sha256', $this->identityDigest($title, $artists))
            ->where('updated_at', '>=', gmdate('Y-m-d\\TH:i:s\\Z', time() - self::FRESH_SECONDS))
            ->first(['artwork_url']);
        if (!$row instanceof stdClass || !is_string($row->artwork_url) || trim($row->artwork_url) === '') return null;
        return trim($row->artwork_url);
    }

    /** @param list<string> $artists */
    public function put(string $albumId, string $title, array $artists, ?string $artworkUrl, string $now): void
    {
        if ($artworkUrl === null || trim($artworkUrl) === ''
            || !Db::connection()->getSchemaBuilder()->hasTable('album_scrape_artwork_cache')) return;
        Db::table('album_scrape_artwork_cache')->updateOrInsert([
            'album_id' => $albumId,
        ], [
            'album_id' => $albumId,
            'identity_sha256' => $this->identityDigest($title, $artists),
            'artwork_url' => trim($artworkUrl),
            'updated_at' => $now,
        ]);
    }

    /** @param list<string> $artists */
    private function identityDigest(string $title, array $artists): string
    {
        return hash('sha256', json_encode([
            'title' => trim($title), 'artists' => array_values(array_map('strval', $artists)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
