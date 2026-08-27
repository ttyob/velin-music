<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * 平台歌单插件或历史离线适配器输出的统一文档。
 *
 * `format` 是插件来源或离线适配器键，`title` 仅作为未提供名称时的安全显示回退；所有条目已经从平台 DTO
 * 转换为 PlatformPlaylistEntry，后续匹配和持久化不再依赖网易云或 QQ 的字段结构。
 */
final readonly class PlatformPlaylistDocument
{
    /**
     * @param list<PlatformPlaylistEntry> $entries
     */
    public function __construct(
        public string $format,
        public string $title,
        public array $entries,
    ) {
    }
}
