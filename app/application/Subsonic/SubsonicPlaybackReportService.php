<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Playback\PlaybackEventInput;
use app\application\Playback\PlaybackEventService;
use app\application\Playback\PlaybackEventSongNotFound;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;

/**
 * 接收 OpenSubsonic playbackReport v1，并复用账号级播放事件状态机。
 *
 * 协议没有 playbackId，因此 `starting` 每次明确创建新会话；其后的 playing/paused/stopped 绑定
 * 当前账号、客户端和歌曲的最新未停止会话。客户端名只用于生成不可逆播放器键，数据库与响应均不
 * 保存明文。媒体授权仍由 PlaybackEventService 在写事务前重新验证，撤销音乐库授权后立即失效。
 */
final readonly class SubsonicPlaybackReportService
{
    public function __construct(private PlaybackEventService $events = new PlaybackEventService())
    {
    }

    /**
     * 校验一个时间线上报并返回协议要求的空成功体。
     *
     * ignoreScrobble 只关闭当前事件的收听累计和统计写入，不阻止 now-playing 会话、状态与位置更新。
     * playbackRate 限制在 0.1..16.0：覆盖常用变速范围，也阻止异常倍率扩大反跳播计数窗口。
     * Podcast 是协议枚举值，但产品需求明确不实现播客，因此返回标准“不支持”而不是伪造成功。
     *
     * @param array<string, mixed> $actor 已认证且包含实时能力与音乐库授权的账号主体。
     * @param array<string, mixed> $parameters 合并后的查询或表单参数。
     */
    public function report(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $mediaType = $this->requiredString($parameters['mediaType'] ?? null, 16, 'Media type');
        if ($mediaType === 'podcast') {
            throw new SubsonicFeatureUnsupported('Podcast playback reporting is not supported.');
        }
        if ($mediaType !== 'song') {
            throw new SubsonicRequestInvalid('Media type is invalid.');
        }

        $songId = $this->songId($parameters['mediaId'] ?? null);
        $positionMs = $this->unsignedInteger($parameters['positionMs'] ?? null, 604_800_000, 'Position');
        $state = $this->requiredString($parameters['state'] ?? null, 16, 'Playback state');
        if (!in_array($state, ['starting', 'playing', 'paused', 'stopped'], true)) {
            throw new SubsonicRequestInvalid('Playback state is invalid.');
        }
        $playbackRate = array_key_exists('playbackRate', $parameters)
            ? $this->playbackRate($parameters['playbackRate'])
            : 1.0;
        $ignoreScrobble = array_key_exists('ignoreScrobble', $parameters)
            ? $this->boolean($parameters['ignoreScrobble'], 'ignoreScrobble')
            : false;
        $client = $this->requiredString($parameters['c'] ?? null, 64, 'Client identifier');
        $userId = (string) ($actor['id'] ?? '');
        $playerId = 'opensubsonic-' . substr(hash('sha256', $client), 0, 32);
        $playbackId = $state === 'starting'
            ? $this->newOpaqueId('opensubsonic-play-')
            : $this->latestPlaybackId($userId, $playerId, $songId);
        $playbackId ??= $this->newOpaqueId('opensubsonic-play-');
        $eventId = $this->newOpaqueId('opensubsonic-event-');
        $eventType = match ($state) {
            'starting' => 'started',
            'playing' => 'progress',
            'paused' => 'paused',
            'stopped' => 'stopped',
        };

        try {
            $this->events->record($actor, new PlaybackEventInput(
                eventId: $eventId,
                playbackId: $playbackId,
                playerId: $playerId,
                songId: $songId,
                queueVersion: 0,
                positionMs: $positionMs,
                occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
                type: $eventType,
                allowPlayCount: !$ignoreScrobble,
                playbackRate: $playbackRate,
                reportedState: $state,
            ));
        } catch (PlaybackEventSongNotFound $exception) {
            // 不区分“歌曲不存在”和“当前账号无权访问”，避免通过稳定媒体 ID 探测其他音乐库。
            throw new SubsonicEntityNotFound('Playback media was not found.', previous: $exception);
        }

        return [];
    }

    /** 查找同一账号/客户端/歌曲最近的活动会话；停止态之后必须开启新的播放会话。 */
    private function latestPlaybackId(string $userId, string $playerId, string $songId): ?string
    {
        /** @var stdClass|null $row */
        $row = Db::table('playback_sessions')->where('user_id', $userId)
            ->where('player_id', $playerId)->where('song_id', $songId)
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->first(['playback_id', 'reported_state', 'status']);
        if (!$row instanceof stdClass) {
            return null;
        }
        $state = is_string($row->reported_state ?? null) ? (string) $row->reported_state : (string) $row->status;

        return $state === 'stopped' ? null : (string) $row->playback_id;
    }

    /** 生成长度受 PlaybackEventInput 约束的随机服务器标识，不拼接用户、媒体或客户端明文。 */
    private function newOpaqueId(string $prefix): string
    {
        return $prefix . (string) new Ulid();
    }

    /** 验证稳定歌曲 ULID，不进行整数转换或路径解析。 */
    private function songId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new SubsonicRequestInvalid('Media ID is invalid.');
        }

        return $value;
    }

    /** 读取长度有界的必填协议字符串，数组和空值一律拒绝。 */
    private function requiredString(mixed $value, int $maximum, string $label): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maximum) {
            throw new SubsonicRequestInvalid($label . ' is invalid.');
        }

        return $value;
    }

    /** 读取规范无符号十进制毫秒值，拒绝符号、小数、空格和数组。 */
    private function unsignedInteger(mixed $value, int $maximum, string $label): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            $number = (int) $value;
        } else {
            throw new SubsonicRequestInvalid($label . ' is invalid.');
        }
        if ($number < 0 || $number > $maximum) {
            throw new SubsonicRequestInvalid($label . ' is outside the supported range.');
        }

        return $number;
    }

    /** 接受 JSON 数字或表单十进制文本，并拒绝 NaN、无穷值和超出安全倍速范围的数值。 */
    private function playbackRate(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            $rate = (float) $value;
        } elseif (is_string($value) && preg_match('/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/', $value) === 1) {
            $rate = (float) $value;
        } else {
            throw new SubsonicRequestInvalid('Playback rate is invalid.');
        }
        if (!is_finite($rate) || $rate < 0.1 || $rate > 16.0) {
            throw new SubsonicRequestInvalid('Playback rate is outside the supported range.');
        }

        return $rate;
    }

    /** OpenSubsonic 布尔值只接受规范文本或原生布尔值，避免 PHP 宽松真值产生歧义。 */
    private function boolean(mixed $value, string $label): bool
    {
        return match ($value) {
            true, 'true' => true,
            false, 'false' => false,
            default => throw new SubsonicRequestInvalid($label . ' is invalid.'),
        };
    }

    /** 在任何媒体查询或播放状态写入前验证全局播放能力。 */
    private function requirePlay(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('play', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Playback reporting is not authorized.');
        }
    }
}
