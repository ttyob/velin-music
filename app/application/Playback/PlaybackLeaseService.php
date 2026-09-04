<?php

declare(strict_types=1);

namespace app\application\Playback;

use app\application\Media\MediaStreamService;
use app\application\System\SystemLimitSettingsService;
use app\application\User\UserRuntimeLimitExceeded;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;
use Closure;
use app\infrastructure\Database\SqliteWriteGate;

/**
 * 管理 Web 播放器的全站短期并发租约（ADMIN-PAGE-028）。
 *
 * 媒体授权和文件身份复验在写事务前执行；SQLite IMMEDIATE 事务中清理所有过期短行、按全站计数并
 * 写入一个租约，关闭多个 Worker 同时越过全局上限的窗口。已有账号/playerId 健康租约可以切歌或
 * 续租，不因管理员降低限额而中断；租约所有权仍按账号隔离。
 */
final readonly class PlaybackLeaseService
{
    private const TTL_SECONDS = 45;

    public function __construct(
        private SystemLimitSettingsService $limits = new SystemLimitSettingsService(),
        private MediaStreamService $streams = new MediaStreamService(),
        private ?Closure $authorizationCheck = null,
        private SqliteWriteGate $writeGate = new SqliteWriteGate(),
    ) {
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function acquire(array $actor, string $songId, string $playerId): array
    {
        $userId = $this->actorId($actor);
        $this->ulid($songId);
        $this->playerId($playerId);
        // 解析会重复当前库授权和媒体文件身份，且明确位于 SQLite 写事务之外。
        if ($this->authorizationCheck instanceof Closure) {
            ($this->authorizationCheck)($actor, $songId);
        } else {
            $this->streams->resolve($actor, $songId);
        }
        $maximum = (int) $this->limits->get()['maxConcurrentStreams'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + self::TTL_SECONDS);
        $leaseId = (string) new Ulid();
        $current = 0;

        // 跨进程闸门只覆盖下面的短 SQL 事务；媒体授权和系统设置读取已经在锁外完成。
        return $this->writeGate->run(function () use ($current, $expiresAt, $leaseId, $maximum, $now,
            $playerId, $songId, $userId): array {
            $pdo = Db::connection()->getPdo();
            $pdo->exec('BEGIN IMMEDIATE');
            $transactionOpen = true;
            try {
                Db::table('playback_leases')->where('expires_at', '<=', $now)->delete();
                /** @var stdClass|null $existing */
                $existing = Db::table('playback_leases')->where('user_id', $userId)
                    ->where('player_id', $playerId)->first(['id']);
                if ($existing instanceof stdClass) {
                    $leaseId = (string) $existing->id;
                    Db::table('playback_leases')->where('id', $leaseId)->update([
                        'song_id' => $songId, 'heartbeat_at' => $now, 'expires_at' => $expiresAt,
                    ]);
                    $current = (int) Db::table('playback_leases')->where('expires_at', '>', $now)->count();
                } else {
                    $current = (int) Db::table('playback_leases')->where('expires_at', '>', $now)->count();
                    if ($current >= $maximum) {
                        throw new UserRuntimeLimitExceeded('PLAYBACK_CONCURRENCY_EXCEEDED', $current, $maximum);
                    }
                    Db::table('playback_leases')->insert([
                        'id' => $leaseId, 'user_id' => $userId, 'player_id' => $playerId, 'song_id' => $songId,
                        'acquired_at' => $now, 'heartbeat_at' => $now, 'expires_at' => $expiresAt,
                    ]);
                    ++$current;
                }
                // PDO 不跟踪原始 BEGIN IMMEDIATE，必须使用 SQL COMMIT/ROLLBACK 并自行记录状态。
                $pdo->exec('COMMIT');
                $transactionOpen = false;
            } catch (Throwable $throwable) {
                if ($transactionOpen) {
                    try { $pdo->exec('ROLLBACK'); } catch (Throwable) { /* 保留原始领域或数据库异常。 */ }
                }
                throw $throwable;
            }
            return ['id' => $leaseId, 'expiresAt' => $expiresAt, 'current' => $current, 'maximum' => $maximum];
        });
    }

    /** 已有健康租约无条件续期；过期租约必须重新走 acquire，不能复活绕过并发判断。 */
    public function heartbeat(array $actor, string $leaseId): array
    {
        $userId = $this->actorId($actor);
        $this->ulid($leaseId);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + self::TTL_SECONDS);
        $changed = $this->writeGate->run(fn (): int => Db::table('playback_leases')->where('id', $leaseId)
            ->where('user_id', $userId)->where('expires_at', '>', $now)
            ->update(['heartbeat_at' => $now, 'expires_at' => $expiresAt]));
        if ($changed !== 1) throw new PlaybackLeaseNotFound('播放租约不存在或已过期。');
        return ['id' => $leaseId, 'expiresAt' => $expiresAt];
    }

    /** 幂等释放当前账号自己的租约，不向调用者暴露它此前是否存在。 */
    public function release(array $actor, string $leaseId): bool
    {
        $userId = $this->actorId($actor);
        $this->ulid($leaseId);
        return $this->writeGate->run(fn (): bool => Db::table('playback_leases')->where('id', $leaseId)
            ->where('user_id', $userId)->delete() > 0);
    }

    /** @param array<string,mixed> $actor */
    private function actorId(array $actor): string
    {
        $id = $actor['id'] ?? null;
        if (!is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
            throw new PlaybackLeaseInvalid('播放账号标识无效。');
        }
        return $id;
    }

    private function ulid(string $value): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new PlaybackLeaseInvalid('播放对象标识无效。');
        }
    }

    private function playerId(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $value) !== 1) {
            throw new PlaybackLeaseInvalid('播放器标识无效。');
        }
    }
}
