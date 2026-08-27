<?php

declare(strict_types=1);

namespace app\http;

use JsonException;
use support\Response;

/**
 * Creates API JSON responses with consistent encoding and correlation headers.
 *
 * JSON_THROW_ON_ERROR prevents malformed partial responses. Callers must pass only DTO or
 * previously sanitized arrays; this factory does not redact secrets because doing so here
 * would hide incorrect ownership decisions in upstream application services.
 */
final class JsonResponseFactory
{
    /**
     * @param array<string, mixed> $payload JSON-safe response data.
     * @throws JsonException When a programmer supplies data that cannot be encoded.
     */
    public static function create(array $payload, int $status, string $requestId): Response
    {
        return response(
            body: json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            status: $status,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Request-ID' => $requestId,
            ],
        );
    }

    /**
     * Creates the shared API error envelope required by API-ERR-001.
     *
     * Details must contain only already-sanitized field errors or retry metadata. Exception
     * messages, SQL, paths, credentials, and raw upstream bodies must never reach this boundary.
     *
     * @param array<string, mixed> $details Optional safe structured error context.
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details = [],
    ): Response {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::create(
            [
                'error' => $error,
                'meta' => [
                    'requestId' => $requestId,
                    'timestamp' => gmdate('c'),
                ],
            ],
            $status,
            $requestId,
        );
    }
}
