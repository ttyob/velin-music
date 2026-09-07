<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Playback\PlaybackHistoryService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Adapts the authenticated user's active Web/Subsonic sessions to getNowPlaying.
 *
 * The current privacy model exposes only the caller's own two-minute heartbeat window. It does not
 * enumerate other accounts or turn global play permission into activity visibility. HistoryService
 * reapplies live media grants before this adapter maps path-free song data, and reads do not extend
 * session lifetime or increment counts.
 */
final readonly class SubsonicNowPlayingService
{
    public function __construct(
        private PlaybackHistoryService $history = new PlaybackHistoryService(),
        private SubsonicCatalogService $catalog = new SubsonicCatalogService(),
    ) {
    }

    /** Returns at most 24 caller-owned active occurrences with protocol minute metadata. */
    public function get(array $actor): array
    {
        $this->requirePlay($actor);
        $entries = [];
        foreach ($this->history->nowPlaying($actor, 24) as $item) {
            if (!is_array($item['song'] ?? null)) {
                continue;
            }
            $entry = $this->catalog->mapAuthorizedSong($item['song']);
            $entry['username'] = (string) ($actor['username'] ?? '');
            $entry['minutesAgo'] = $this->minutesAgo($item['updatedAt'] ?? null);
            // 协议要求整数 playerId；只投影不可逆哈希，避免暴露客户端名称或内部播放器键。
            $entry['playerId'] = $this->protocolPlayerId($item['playerId'] ?? null);
            $entry['state'] = $this->reportedState($item['reportedState'] ?? null);
            $entry['playbackRate'] = max(0.1, min(16.0, (float) ($item['playbackRate'] ?? 1.0)));
            $entry['positionMs'] = $this->estimatedPosition($item);
            $entries[] = $entry;
        }

        return ['nowPlaying' => ['entry' => $entries]];
    }

    /** 把内部播放器键映射为稳定正整数，不允许客户端通过响应反推出原始标识。 */
    private function protocolPlayerId(mixed $value): int
    {
        $hash = hash('sha256', is_string($value) ? $value : '', true);
        $parts = unpack('Nvalue', substr($hash, 0, 4));

        return max(1, ((int) ($parts['value'] ?? 1)) & 0x7fffffff);
    }

    /** 只允许 OpenSubsonic v1 定义的四种时间线状态进入兼容响应。 */
    private function reportedState(mixed $value): string
    {
        return is_string($value) && in_array($value, ['starting', 'playing', 'paused', 'stopped'], true)
            ? $value
            : 'playing';
    }

    /**
     * 根据最后一次上报和播放倍速估算当前位置；暂停/开始/停止时保持锚点不动。
     *
     * 活跃会话查询本身只有两分钟窗口，且最终结果受歌曲时长限制，因此客户端断线不会产生无限进度。
     * 时间解析失败时退回已验证的非负锚点，不让畸形历史数据破坏整个 now-playing 响应。
     *
     * @param array<string, mixed> $item 已通过当前用户媒体授权过滤的播放会话投影。
     */
    private function estimatedPosition(array $item): int
    {
        $position = max(0, (int) ($item['positionMs'] ?? 0));
        if ($this->reportedState($item['reportedState'] ?? null) === 'playing') {
            try {
                $receivedAt = new DateTimeImmutable((string) ($item['lastReceivedAt'] ?? ''));
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $elapsedMs = max(0, ($now->getTimestamp() - $receivedAt->getTimestamp()) * 1000);
                $position += (int) floor($elapsedMs * max(0.1, min(16.0, (float) ($item['playbackRate'] ?? 1.0))));
            } catch (\Throwable) {
                // 保留最后一个可信位置；时间线字段是可选增强，不能让兼容端点整体失败。
            }
        }
        $duration = max(0, (int) (($item['song']['durationMs'] ?? 0)));

        return $duration > 0 ? min($position, $duration) : $position;
    }

    /** Converts a trusted API UTC timestamp to a non-negative whole-minute protocol value. */
    private function minutesAgo(mixed $value): int
    {
        if (!is_string($value)) {
            return 0;
        }
        try {
            $updatedAt = new DateTimeImmutable($value);
        } catch (\Throwable) {
            return 0;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return max(0, intdiv(max(0, $now->getTimestamp() - $updatedAt->getTimestamp()), 60));
    }

    /** Requires playback capability independently from the actor's retained library snapshot. */
    private function requirePlay(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('play', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Now-playing activity is not authorized.');
        }
    }
}
