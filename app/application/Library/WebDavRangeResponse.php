<?php

declare(strict_types=1);

namespace app\application\Library;

use Psr\Http\Message\StreamInterface;

/**
 * 表示一个已经通过对象身份与 Content-Range 复验的 WebDAV 字节区间。
 *
 * 正文保持为 PSR-7 流，调用方必须顺序读取并及时关闭；本对象不缓冲整首音频，也不拥有重试策略。
 * 起止位置均为闭区间且已证明位于扫描快照的对象大小内，因而本机探测代理可以直接生成可信的 206
 * 响应。任何协议不一致在构造本对象前即由 WebDavClient 失败关闭。
 */
final readonly class WebDavRangeResponse
{
    public function __construct(
        public int $start,
        public int $end,
        public int $totalSize,
        public StreamInterface $body,
    ) {
    }

    /** 返回区间正文的精确字节数，不读取流。 */
    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
