<?php

declare(strict_types=1);

namespace app\application\System;

/**
 * Immutable result returned by the readiness application service.
 *
 * The HTTP status is kept beside the payload so controllers cannot accidentally report a
 * failed database check as HTTP 200. The payload contains no raw exception, SQL, or server
 * path and is therefore safe for the public readiness endpoint.
 */
final readonly class HealthReport
{
    /**
     * @param int $httpStatus HTTP status selected from the readiness outcome.
     * @param array<string, mixed> $payload Versioned JSON response body.
     */
    public function __construct(
        public int $httpStatus,
        public array $payload,
    ) {
    }
}
