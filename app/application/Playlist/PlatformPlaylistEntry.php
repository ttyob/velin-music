<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * 表示一个平台歌单条目的脱敏统一模型。
 *
 * 平台私有 ID 只用于本次导入的审计/幂等计算，不进入播放列表数据库；标题、艺人、专辑和时长
 * 是匹配本地授权歌曲的证据。解析器不得把下载地址、Cookie、路径或原始平台对象放入该模型。
 */
final readonly class PlatformPlaylistEntry
{
    /**
     * @param list<string> $artists
     */
    public function __construct(
        public string $title,
        public array $artists,
        public ?string $album,
        public ?int $durationMs,
        public ?string $sourceItemId,
    ) {
    }
}
