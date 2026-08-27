<?php

declare(strict_types=1);

namespace app\infrastructure\Audit;

use JsonException;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * Persists security and administration audit events without accepting arbitrary log messages.
 *
 * Callers provide stable action/object/result fields and already-redacted structured metadata.
 * Passwords, tokens, cookies, physical paths, and raw request bodies are forbidden. Inserts are
 * synchronous at security boundaries so a successful login/logout cannot silently omit its audit
 * event; later high-volume business events may use a transactional outbox instead.
 */
final class AuditLogger
{
    /**
     * Writes one immutable audit record.
     *
     * @param array<string, bool|int|string|null> $metadata Sanitized bounded metadata.
     * @throws JsonException If caller supplies an invalid value despite the declared contract.
     */
    public function record(
        ?string $actorUserId,
        string $action,
        string $objectType,
        ?string $objectId,
        string $result,
        string $requestId,
        array $metadata = [],
    ): void {
        Db::table('audit_logs')->insert([
            'id' => (string) new Ulid(),
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'result' => $result,
            'request_id' => $requestId,
            'metadata_json' => json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
