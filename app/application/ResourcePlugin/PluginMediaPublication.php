<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

/** 插件媒体发布的稳定结果，不暴露本地物理路径、远端对象 ID 或连接信息。 */
final readonly class PluginMediaPublication
{
    public function __construct(
        public string $id,
        public string $libraryId,
        public string $sourceType,
        public string $relativePath,
        public int $size,
        public string $sha256,
    ) {
    }
}
