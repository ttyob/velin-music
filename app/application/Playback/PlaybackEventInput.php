<?php

declare(strict_types=1);

namespace app\application\Playback;

use DateTimeImmutable;

/**
 * 不可变的播放事件命令，用户身份始终由认证上下文提供，客户端不能在载荷中指定。
 *
 * `allowPlayCount=false` 用于 OpenSubsonic 的 ignoreScrobble 语义：事件仍更新当前播放状态和
 * 进度，但不能累计可计数收听时长，也不能写入歌曲播放统计。reportedState 与 playbackRate
 * 保存协议时间线所需的信息；普通 Web 播放事件沿用默认值，不改变既有计数行为。
 */
final readonly class PlaybackEventInput
{
    public function __construct(
        public string $eventId,
        public string $playbackId,
        public string $playerId,
        public string $songId,
        public int $queueVersion,
        public int $positionMs,
        public DateTimeImmutable $occurredAt,
        public string $type,
        public bool $allowPlayCount = true,
        public float $playbackRate = 1.0,
        public ?string $reportedState = null,
    ) {
    }
}
