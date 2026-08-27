<?php

declare(strict_types=1);

namespace app\application\Library;

/**
 * 远端发布成功后的无秘密事实。
 *
 * 路径由核心生成，version 是上传恢复使用的脱敏版本摘要；discoveryEtag 必须与后续目录发现写入库存的
 * ETag 完全同源。统一发布账本用后者把上传前受信标签绑定到扫描对象，外部删除重建或改写对象后不得
 * 继续沿用旧标签。旧客户端可省略该字段，此时扫描器只允许使用可由库存 ETag 重新计算的历史版本。
 */
final readonly class RemotePublishedObject
{
    public function __construct(
        public string $relativePath,
        public int $size,
        public string $sha256,
        public string $version,
        public ?string $discoveryEtag = null,
    ) {
    }
}
