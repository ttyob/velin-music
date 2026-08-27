<?php

declare(strict_types=1);

namespace app\http;

use app\application\Media\MediaReadableSource;
use support\Response;

/**
 * 把一个已授权远端范围从 Controller 交给自定义 HTTP 流 supervisor。
 *
 * 普通 Webman 编码器不得序列化该对象；Http::send 会在销毁请求 Context 后启动 MediaSourceStreamRunner。
 * source 可能持有 WebDAV 凭据，只存在进程内存且不得记录。offset/length 已由统一 ByteRangeParser 约束，
 * runner 仍会复验正数、总大小和每个上游短区间。
 */
final class MediaSourceStreamResponse extends Response
{
    /**
     * @param array<string,string> $headers 只含已清洗的媒体响应头，不含远端定位或凭据。
     */
    public function __construct(
        public readonly MediaReadableSource $source,
        public readonly int $statusCode,
        public readonly array $streamHeaders,
        public readonly int $offset,
        public readonly int $length,
        public readonly int $totalSize,
        public readonly ?string $deliveryTicketId = null,
    ) {
        parent::__construct($statusCode);
    }
}
