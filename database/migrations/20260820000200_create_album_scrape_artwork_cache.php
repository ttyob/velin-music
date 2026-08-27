<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 建立专辑资料刮削与独立封面查询之间的短期缓存边界。
 *
 * 专辑插件完成身份评分和 URL 校验后，图片 Worker 可以复用同一结论，避免每首歌曲重复查询专辑插件。
 * 缓存不替代图片候选/导入表；图片 Worker 仍负责下载、尺寸、SHA、许可和发布校验。删除或过期缓存不
 * 影响专辑字段和已发布封面，后续查询会重新走独立专辑协议。
 */
final class CreateAlbumScrapeArtworkCache extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE album_scrape_artwork_cache (
    album_id TEXT PRIMARY KEY NOT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    identity_sha256 TEXT NOT NULL CHECK (length(identity_sha256) = 64),
    artwork_url TEXT NOT NULL CHECK (length(artwork_url) BETWEEN 1 AND 2000),
    updated_at TEXT NOT NULL
);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE album_scrape_artwork_cache');
    }
}
