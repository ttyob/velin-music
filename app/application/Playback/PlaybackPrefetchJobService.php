<?php

declare(strict_types=1);

namespace app\application\Playback;

use app\application\Media\MediaStreamNotFound;
use app\application\Media\MediaStreamResolver;
use app\application\Media\MediaStreamService;
use app\application\Media\RemotePlaybackCache;
use stdClass;
use support\Db;
use support\Log;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 把当前播放事实收敛为每播放器唯一的服务端下一首预缓存任务，并在独立 Worker 中执行。
 *
 * 入队只写不透明 ID，可安全加入播放事件短事务。执行时以服务端已提交队列的 current_index 为准，不信任
 * 客户端 queueVersion 或下一首 ID；这是为了兼容 started 比队列保存响应更早到达的正常竞态。当前歌曲尚未
 * 对齐时最多短重试三次，新 started 会覆盖旧行并释放旧租约。网络下载、目录枚举和缓存写入全部发生在事务
 * 外，权限或媒体身份变化只丢弃性能任务，不影响当前播放事件。
 */
final readonly class PlaybackPrefetchJobService
{
    private const STALE_LEASE_SECONDS = 600;

    public function __construct(
        private MediaStreamResolver $streams = new MediaStreamService(),
        private RemotePlaybackCache $cache = new RemotePlaybackCache(),
        private PlaybackPrefetchActorResolver $actors = new LivePlaybackPrefetchActorResolver(),
    ) {
    }

    /**
     * 幂等覆盖当前账号/播放器的待处理意图。
     *
     * 调用方已验证 playerId、songId 和歌曲授权；本方法仍不预先冻结下一首，因为队列保存可能与 started
     * 并发。覆盖 running 行会清除 worker 租约，旧下载即使完成也不能删除新意图或把任务误标为成功。
     */
    public function enqueue(string $userId, string $playerId, string $currentSongId): void
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        /** @var stdClass|null $existing */
        $existing = Db::table('playback_prefetch_jobs')->where('user_id', $userId)
            ->where('player_id', $playerId)->first(['id']);
        $values = [
            'current_song_id' => $currentSongId,
            'status' => 'queued',
            'attempt' => 0,
            'next_attempt_at' => $now,
            'worker_id' => null,
            'heartbeat_at' => null,
            'error_code' => null,
            'requested_at' => $now,
            'updated_at' => $now,
        ];
        if ($existing instanceof stdClass) {
            Db::table('playback_prefetch_jobs')->where('id', (string) $existing->id)->update($values);
            return;
        }
        Db::table('playback_prefetch_jobs')->insert([
            'id' => (string) new Ulid(),
            'user_id' => $userId,
            'player_id' => $playerId,
            ...$values,
        ]);
    }

    /** 原子领取一条到期任务；返回值不包含账号、播放器、歌曲或路径，避免进入进程日志。 */
    public function claimNext(string $workerId): ?array
    {
        return Db::transaction(function () use ($workerId): ?array {
            $now = gmdate('Y-m-d\TH:i:s.v\Z');
            /** @var stdClass|null $row */
            $row = Db::table('playback_prefetch_jobs')->where('status', 'queued')
                ->where('next_attempt_at', '<=', $now)->orderBy('requested_at')->orderBy('id')->first(['id']);
            if (!$row instanceof stdClass) return null;
            $changed = Db::table('playback_prefetch_jobs')->where('id', (string) $row->id)
                ->where('status', 'queued')->whereNull('worker_id')->update([
                    'status' => 'running', 'worker_id' => $workerId, 'heartbeat_at' => $now,
                    'error_code' => null, 'updated_at' => $now,
                ]);
            return $changed === 1 ? ['id' => (string) $row->id] : null;
        });
    }

    /**
     * 执行一条已领取任务；所有慢操作均位于 SQLite 事务之外。
     *
     * 账号停用、play 撤权、库失权、本地下一首、队尾和单曲循环都直接终结。队列尚未对齐与瞬时网络/I/O
     * 故障有界退避；第三次仍失败删除任务，避免坏对象永久占用 Worker。缓存发布由 RemotePlaybackCache
     * 保证完整和原子，执行失败不会留下可命中的部分文件。
     *
     * @param array{id:string} $job claimNext 返回的不透明任务引用。
     */
    public function execute(array $job, string $workerId): void
    {
        /** @var stdClass|null $row */
        $row = Db::table('playback_prefetch_jobs')->where('id', $job['id'])
            ->where('status', 'running')->where('worker_id', $workerId)
            ->first(['id', 'user_id', 'current_song_id', 'attempt']);
        if (!$row instanceof stdClass) return;

        try {
            $actor = $this->actors->resolve((string) $row->user_id);
            if (!is_array($actor) || !in_array('play', $actor['capabilities'] ?? [], true)) {
                $this->complete($row, $workerId);
                return;
            }
            $nextSongId = $this->nextSongId((string) $row->user_id, (string) $row->current_song_id);
            if ($nextSongId === false) {
                $this->retry($row, $workerId, 'PLAYBACK_PREFETCH_QUEUE_NOT_READY');
                return;
            }
            if ($nextSongId === null || $nextSongId === (string) $row->current_song_id) {
                $this->complete($row, $workerId);
                return;
            }
            $media = $this->streams->resolve($actor, $nextSongId);
            $this->cache->ensure($media);
            $this->complete($row, $workerId);
        } catch (MediaStreamNotFound) {
            // 下一首已经失权或被删除时不重试；新队列/新播放会产生新的覆盖任务。
            $this->complete($row, $workerId);
        } catch (Throwable $failure) {
            $reason = is_string($failure->reasonCode ?? null) ? $failure->reasonCode : 'PLAYBACK_PREFETCH_FAILED';
            $this->retry($row, $workerId, $reason);
        }
    }

    /** 把进程崩溃遗留的租约恢复为 queued；媒体临时文件由缓存目录的一小时规则补偿。 */
    public function recoverStaleLeases(): int
    {
        $boundary = gmdate('Y-m-d\TH:i:s.v\Z', time() - self::STALE_LEASE_SECONDS);
        return Db::table('playback_prefetch_jobs')->where('status', 'running')
            ->where('heartbeat_at', '<=', $boundary)->update([
                'status' => 'queued', 'worker_id' => null, 'heartbeat_at' => null,
                'next_attempt_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
                'error_code' => 'PLAYBACK_PREFETCH_LEASE_RECOVERED',
                'updated_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
            ]);
    }

    /**
     * @return string|null|false 下一首歌曲 ID；null 表示队尾/单曲循环，false 表示队列保存尚未对齐。
     */
    private function nextSongId(string $userId, string $currentSongId): string|null|false
    {
        /** @var stdClass|null $queue */
        $queue = Db::table('play_queues')->where('user_id', $userId)
            ->first(['id', 'current_index', 'repeat_mode']);
        if (!$queue instanceof stdClass || $queue->current_index === null) return false;
        $position = (int) $queue->current_index;
        /** @var stdClass|null $current */
        $current = Db::table('play_queue_items')->where('queue_id', (string) $queue->id)
            ->where('position', $position)->first(['song_id']);
        if (!$current instanceof stdClass || (string) $current->song_id !== $currentSongId) return false;
        if ((string) $queue->repeat_mode === 'one') return null;
        $next = Db::table('play_queue_items')->where('queue_id', (string) $queue->id)
            ->where('position', $position + 1)->value('song_id');
        if (!is_string($next) && (string) $queue->repeat_mode === 'all') {
            $next = Db::table('play_queue_items')->where('queue_id', (string) $queue->id)
                ->where('position', 0)->value('song_id');
        }
        return is_string($next) ? $next : null;
    }

    /** 只删除仍由本次 Worker 持有的行；新 started 覆盖租约后此操作必须无效。 */
    private function complete(stdClass $row, string $workerId): void
    {
        Db::table('playback_prefetch_jobs')->where('id', (string) $row->id)
            ->where('status', 'running')->where('worker_id', $workerId)->delete();
    }

    /** 有界退避且不保存第三方异常正文；达到三次后记录脱敏原因并删除可重建任务。 */
    private function retry(stdClass $row, string $workerId, string $reason): void
    {
        $attempt = (int) $row->attempt + 1;
        $reason = preg_match('/^[A-Z0-9_]{3,96}$/D', $reason) === 1 ? $reason : 'PLAYBACK_PREFETCH_FAILED';
        if ($attempt >= 3) {
            // 终态日志只说明性能优化失败类别，不包含任务、账号、歌曲、播放器、路径、URL 或第三方正文。
            Log::warning('Playback prefetch abandoned after bounded retries.', ['reason_code' => $reason]);
            $this->complete($row, $workerId);
            return;
        }
        $delay = $attempt === 1 ? 1 : 3;
        Db::table('playback_prefetch_jobs')->where('id', (string) $row->id)
            ->where('status', 'running')->where('worker_id', $workerId)->update([
                'status' => 'queued', 'attempt' => $attempt,
                'next_attempt_at' => gmdate('Y-m-d\TH:i:s.v\Z', time() + $delay),
                'worker_id' => null, 'heartbeat_at' => null, 'error_code' => $reason,
                'updated_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
            ]);
    }
}
