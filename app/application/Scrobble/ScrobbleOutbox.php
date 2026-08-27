<?php

declare(strict_types=1);

namespace app\application\Scrobble;

use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;

/**
 * 在内部播放事实事务中为该账号所有已启用连接追加外部投递任务。
 *
 * 本类绝不发网络请求、解密凭据或读取媒体路径。任务冻结播放发生时的标题、艺术家、专辑、时长与时间，
 * 后续改标签或删除媒体不会把远端历史改成另一首歌；唯一键由连接、源事件和投递类型组成，重复的 Web
 * eventId 或 Subsonic scrobble 不会二次排队。调用方必须已经完成歌曲授权，并把本方法放在创建内部播放
 * 事件的同一事务中，以避免“内部已计数但外部任务丢失”的状态。
 */
final readonly class ScrobbleOutbox
{
    /**
     * 为 started/now-playing 或首次计数事件逐连接排队；无启用连接时是无副作用成功。
     *
     * @param array<string, mixed> $song 已由 MediaQueryService 按当前账号授权过滤的无路径投影。
     */
    public function enqueue(
        string $userId,
        string $sourceEventKey,
        string $deliveryType,
        array $song,
        string $occurredAt,
    ): void {
        if (!in_array($deliveryType, ['now_playing', 'scrobble'], true)
            || strlen($sourceEventKey) < 1 || strlen($sourceEventKey) > 200) {
            throw new ScrobbleInvalid('Scrobble outbox event is invalid.');
        }
        /** @var list<stdClass> $connections */
        $connections = Db::table('scrobble_connections')->where('user_id', $userId)
            ->where('enabled', 1)->get(['id'])->all();
        if ($connections === []) return;
        $artists = is_array($song['artists'] ?? null) ? $song['artists'] : [];
        $artistNames = [];
        foreach ($artists as $artist) {
            if (is_array($artist) && is_string($artist['name'] ?? null) && trim($artist['name']) !== '') {
                $artistNames[] = trim($artist['name']);
            }
        }
        $title = trim((string) ($song['title'] ?? ''));
        $album = is_array($song['album'] ?? null) ? trim((string) ($song['album']['title'] ?? '')) : '';
        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($connections as $connection) {
            Db::table('scrobble_delivery_jobs')->insertOrIgnore([
                'id' => (string) new Ulid(), 'connection_id' => (string) $connection->id,
                'user_id' => $userId, 'source_event_key' => $sourceEventKey,
                'delivery_type' => $deliveryType, 'song_title' => $title === '' ? 'Unknown Title' : $title,
                'artist_name' => $artistNames === [] ? 'Unknown Artist' : implode(' / ', $artistNames),
                'album_title' => $album === '' ? null : $album,
                'duration_ms' => max(0, (int) ($song['durationMs'] ?? 0)),
                'occurred_at' => $occurredAt, 'status' => 'queued', 'attempt_count' => 0,
                'next_attempt_at' => $now, 'worker_id' => null, 'claimed_at' => null,
                'completed_at' => null, 'error_code' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
