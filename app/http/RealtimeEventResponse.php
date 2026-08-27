<?php

declare(strict_types=1);

namespace app\http;

use support\Response;

/**
 * Internal response marker transferring an authorized SSE subscription to the HTTP event loop.
 *
 * The actor was resolved from a revocable Session or Bearer token and the cursor from Last-Event-ID. Webman must not
 * serialize this marker: app\process\Http hands it to RealtimeEventRunner, which owns the socket and
 * repeats live authorization while the connection exists.
 */
final class RealtimeEventResponse extends Response
{
    /** @param array<string, mixed> $actor 初始认证身份；异步轮询只信任其用户 ID 并重建实时范围。 */
    public function __construct(
        public readonly array $actor,
        public readonly ?int $lastEventId,
        public readonly string $requestId,
    ) {
        parent::__construct(200);
    }
}
