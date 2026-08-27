<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * 已完成可见性校验和摘要复验的歌单封面响应值。
 *
 * bytes 永远是服务端生成的 800x800 WebP，不包含上传元数据；ETag 来自内容摘要，modifiedAt 只用于
 * 私有条件缓存。该对象不包含歌单所有者、物理路径或数据库行，Controller 只能以内联图片返回。
 */
final readonly class PlaylistCoverImage
{
    /** 创建只读图片响应值；调用方已验证摘要与字节一致。 */
    public function __construct(
        public string $bytes,
        public string $etag,
        public int $modifiedAt,
    ) {
    }
}
