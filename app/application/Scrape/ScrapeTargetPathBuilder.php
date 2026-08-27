<?php

declare(strict_types=1);

namespace app\application\Scrape;

/** Builds portable, result-root-relative paths from metadata and a server-owned rename policy. */
final class ScrapeTargetPathBuilder
{
    /**
     * Uses `Album Artist/[Year - ]Album/Title.ext`. Missing release identity maps to
     * the artist-level `单曲` directory without a fabricated year, while unknown artist/title still
     * fail closed. When rename is disabled, the same metadata-derived directory is retained but the
     * safe source basename replaces `Title`; an absent/invalid source name fails rather
     * than silently reverting to a renamed file. Extension normalization remains mandatory.
     *
     * 曲号和碟号属于媒体标签与排序字段，不再写进物理文件名。这样整理结果保持“歌名.ext”的直观
     * 形式，同时播放器仍能按 trackNumber/discNumber 排序；同名歌曲继续由目标冲突保护要求人工处理，
     * 不通过隐式编号掩盖重复候选。
     *
     * Every component is independently cleaned, truncated, and checked against dot traversal. This
     * method performs no filesystem I/O. The caller must still resolve the final parent beneath the
     * registered result root and revalidate source identity immediately before publication.
     */
    public function build(
        ScrapeMetadataCandidate $candidate,
        string $extension,
        bool $renameEnabled = true,
        ?string $sourceFileName = null,
    ): string {
        $metadata = $candidate->metadata;
        $artists = is_array($metadata['albumArtists'] ?? null) ? $metadata['albumArtists'] : [];
        $artist = $this->component((string) ($artists[0] ?? ''));
        $album = $this->component((string) ($metadata['albumTitle'] ?? ''));
        $title = $this->component((string) ($metadata['title'] ?? ''));
        if ($artist === '' || $artist === '未知艺术家' || $title === '') {
            throw new ScrapeMetadataInvalid('元数据不足，无法生成安全的整理路径。');
        }
        if ($album === '' || $album === '未知专辑') {
            $album = '单曲';
        }
        $extension = strtolower(trim($extension));
        if (preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
            throw new ScrapeMetadataInvalid('音频扩展名无效。');
        }
        $year = $album !== '单曲' && is_int($metadata['releaseYear'] ?? null) && $metadata['releaseYear'] >= 1000
            ? $metadata['releaseYear'] . ' - ' : '';
        $fileBase = $title;
        if (!$renameEnabled) {
            $fileBase = $this->component(pathinfo((string) $sourceFileName, PATHINFO_FILENAME));
            if ($fileBase === '') {
                throw new ScrapeMetadataInvalid('源文件名无效，无法在关闭重命名时生成安全路径。');
            }
        }
        return $artist . '/' . $year . $album . '/' . $fileBase . '.' . $extension;
    }

    /** Cleans separators/control characters and applies a byte-safe practical component ceiling. */
    private function component(string $value): string
    {
        $value = trim((string) preg_replace('~[\x00-\x1F\x7F\\\\/:*?"<>|]+~u', ' ', $value));
        $value = trim((string) preg_replace('/\s+/u', ' ', $value), ". \t\n\r\0\x0B");
        if ($value === '' || $value === '.' || $value === '..') return '';
        return mb_strcut($value, 0, 180, 'UTF-8');
    }
}
