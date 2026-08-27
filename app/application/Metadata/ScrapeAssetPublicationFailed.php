<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/**
 * 表示派生资源发布可映射为稳定任务终态的失败。
 *
 * `errorCode` 只来自应用白名单，异常消息不得包含物理路径、歌词正文或图片内容。调用方根据
 * `conflict` 区分“目标已被其他内容占用”和一般失败；两者都不得自动覆盖或删除现场文件。
 */
final class ScrapeAssetPublicationFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $conflict = false)
    {
        parent::__construct('刮削派生资源发布失败。');
    }
}
