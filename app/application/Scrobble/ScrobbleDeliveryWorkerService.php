<?php

declare(strict_types=1);

namespace app\application\Scrobble;

use RuntimeException;
use stdClass;
use support\Db;
use Throwable;

/**
 * 单消费者领取并投递持久化 Scrobble outbox，不在 SQLite 事务内执行 DNS 或 HTTP。
 *
 * claimNext 只持有短 BEGIN IMMEDIATE，条件更新保证未来误启多个 Worker 时最多一个获得任务；execute 在
 * 事务外解密和请求，结束后用 running+worker_id 条件提交结果。可重试失败使用 30 秒至 30 分钟的有界
 * 指数退避，now-playing 最多两次、正式 scrobble 最多五次。崩溃留下的 running 租约五分钟后恢复，
 * Provider 端仍应按时间戳去重；本地唯一键保证不会因恢复创建第二条任务。
 */
final readonly class ScrobbleDeliveryWorkerService
{
    public function __construct(
        private ScrobbleCredentialCipher $cipher = new ScrobbleCredentialCipher(),
        private ScrobbleProviderGateway $gateway = new ScrobbleProviderGateway(),
    ) {
    }

    /**
     * 原子领取到期且账号、连接都仍启用的最早任务，返回不含密文的内部执行快照。
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(string $workerId): ?array
    {
        if ($workerId === '' || strlen($workerId) > 200) throw new RuntimeException('Scrobble worker ID is invalid.');
        return Db::transaction(function () use ($workerId): ?array {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            /** @var stdClass|null $row */
            $row = Db::table('scrobble_delivery_jobs as jobs')
                ->join('scrobble_connections as connections', 'connections.id', '=', 'jobs.connection_id')
                ->join('users', 'users.id', '=', 'jobs.user_id')
                ->where('jobs.status', 'queued')->where('jobs.next_attempt_at', '<=', $now)
                ->where('connections.enabled', 1)->where('users.status', 'active')->whereNull('users.deleted_at')
                ->orderBy('jobs.next_attempt_at')->orderBy('jobs.created_at')->orderBy('jobs.id')
                ->first(['jobs.id', 'jobs.attempt_count']);
            if (!$row instanceof stdClass) return null;
            $changed = Db::table('scrobble_delivery_jobs')->where('id', (string) $row->id)
                ->where('status', 'queued')->update([
                    'status' => 'running', 'attempt_count' => (int) $row->attempt_count + 1,
                    'worker_id' => $workerId, 'claimed_at' => $now, 'updated_at' => $now,
                ]);
            if ($changed !== 1) return null;
            /** @var stdClass $job */
            $job = Db::table('scrobble_delivery_jobs')->where('id', (string) $row->id)->first();
            return (array) $job;
        });
    }

    /**
     * 在无数据库事务状态下投递一个已领取任务，并把结果收敛为 succeeded、queued 或 failed。
     *
     * 连接状态和账号归属在发请求前重新读取；禁用、删除或账号停用会取消任务且不解密。凭据解密异常按
     * 不可重试失败处理。更新只命中当前 worker 的 running 租约，失去所有权时不会覆盖另一恢复者结果。
     *
     * @param array<string, mixed> $job claimNext 返回的内部快照。
     */
    public function execute(array $job, string $workerId): void
    {
        $jobId = (string) ($job['id'] ?? '');
        /** @var stdClass|null $connection */
        $connection = Db::table('scrobble_connections as connections')
            ->join('users', 'users.id', '=', 'connections.user_id')
            ->where('connections.id', (string) ($job['connection_id'] ?? ''))
            ->where('connections.user_id', (string) ($job['user_id'] ?? ''))
            ->where('connections.enabled', 1)->where('users.status', 'active')->whereNull('users.deleted_at')
            ->first(['connections.id', 'connections.provider', 'connections.endpoint_url', 'connections.credentials_ciphertext']);
        if (!$connection instanceof stdClass) {
            $this->cancel($jobId, $workerId, 'SCROBBLE_CONNECTION_UNAVAILABLE');
            return;
        }
        try {
            $credentials = $this->cipher->decrypt((string) $connection->credentials_ciphertext);
            try {
                $this->gateway->deliver((array) $connection, $credentials, $job);
            } finally {
                foreach ($credentials as &$secret) sodium_memzero($secret);
                unset($secret, $credentials);
            }
            $this->succeed($jobId, $workerId, (string) $connection->id);
        } catch (ScrobbleDeliveryFailure $failure) {
            $this->failOrRetry($job, $workerId, (string) $connection->id, $failure);
        } catch (Throwable) {
            $this->failOrRetry($job, $workerId, (string) $connection->id,
                new ScrobbleDeliveryFailure('SCROBBLE_CREDENTIAL_UNAVAILABLE', false));
        }
    }

    /** 把超过五分钟的孤儿租约退回队列；已禁用连接的任务直接取消。 */
    public function recoverStaleLeases(): int
    {
        $boundary = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return Db::transaction(function () use ($boundary, $now): int {
            /** @var list<stdClass> $rows */
            $rows = Db::table('scrobble_delivery_jobs as jobs')
                ->leftJoin('scrobble_connections as connections', 'connections.id', '=', 'jobs.connection_id')
                ->where('jobs.status', 'running')->where('jobs.claimed_at', '<=', $boundary)
                ->limit(100)->get(['jobs.id', 'connections.enabled'])->all();
            $changed = 0;
            foreach ($rows as $row) {
                $enabled = $row->enabled !== null && (int) $row->enabled === 1;
                $changed += Db::table('scrobble_delivery_jobs')->where('id', (string) $row->id)
                    ->where('status', 'running')->where('claimed_at', '<=', $boundary)->update([
                        'status' => $enabled ? 'queued' : 'cancelled',
                        'next_attempt_at' => $now, 'worker_id' => null, 'claimed_at' => null,
                        'completed_at' => $enabled ? null : $now,
                        'error_code' => $enabled ? 'SCROBBLE_LEASE_RECOVERED' : 'SCROBBLE_CONNECTION_UNAVAILABLE',
                        'updated_at' => $now,
                    ]);
            }
            return $changed;
        });
    }

    private function succeed(string $jobId, string $workerId, string $connectionId): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($jobId, $workerId, $connectionId, $now): void {
            $changed = Db::table('scrobble_delivery_jobs')->where('id', $jobId)
                ->where('status', 'running')->where('worker_id', $workerId)->update([
                    'status' => 'succeeded', 'completed_at' => $now, 'error_code' => null,
                    'worker_id' => null, 'claimed_at' => null, 'updated_at' => $now,
                ]);
            if ($changed === 1) {
                Db::table('scrobble_connections')->where('id', $connectionId)->update([
                    'last_success_at' => $now, 'last_error_code' => null, 'updated_at' => $now,
                ]);
            }
        });
    }

    private function failOrRetry(
        array $job,
        string $workerId,
        string $connectionId,
        ScrobbleDeliveryFailure $failure,
    ): void {
        $attempt = (int) ($job['attempt_count'] ?? 0);
        $maxAttempts = (string) ($job['delivery_type'] ?? '') === 'now_playing' ? 2 : 5;
        $retry = $failure->retryable && $attempt < $maxAttempts;
        $nowTimestamp = time();
        $now = gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp);
        $delays = [1 => 30, 2 => 120, 3 => 600, 4 => 1800];
        $next = gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp + ($delays[$attempt] ?? 1800));
        Db::transaction(function () use ($job, $workerId, $connectionId, $failure, $retry, $now, $next): void {
            $changed = Db::table('scrobble_delivery_jobs')->where('id', (string) $job['id'])
                ->where('status', 'running')->where('worker_id', $workerId)->update([
                    'status' => $retry ? 'queued' : 'failed',
                    'next_attempt_at' => $retry ? $next : $now,
                    'worker_id' => null, 'claimed_at' => null,
                    'completed_at' => $retry ? null : $now,
                    'error_code' => $failure->errorCode, 'updated_at' => $now,
                ]);
            if ($changed === 1) {
                Db::table('scrobble_connections')->where('id', $connectionId)->update([
                    'last_failure_at' => $now, 'last_error_code' => $failure->errorCode, 'updated_at' => $now,
                ]);
            }
        });
    }

    private function cancel(string $jobId, string $workerId, string $errorCode): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('scrobble_delivery_jobs')->where('id', $jobId)
            ->where('status', 'running')->where('worker_id', $workerId)->update([
                'status' => 'cancelled', 'completed_at' => $now, 'error_code' => $errorCode,
                'worker_id' => null, 'claimed_at' => null, 'updated_at' => $now,
            ]);
    }
}
