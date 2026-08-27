<?php

declare(strict_types=1);

namespace app\application\Playback;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;
use support\Db;
use Throwable;

/**
 * 记录当前账号的每日有效听歌时长，并从稀疏日事实生成日、周、月、年查询结果。
 *
 * 写入必须由播放事件服务在已经开启的短事务内调用；本服务不自行提交事务，也不读取歌曲或扩大授权。
 * 时长使用服务端反跳播逻辑认可的正增量，按用户 IANA 时区把区间拆到本地自然日，跨午夜和 DST 均以
 * 实际 UTC 毫秒分段。0 或负值完全忽略，因而无收听日期永不插入占位行。
 *
 * 查询只允许认证 actor 自己的数据，范围最多十年；周固定从周一开始。返回桶只来自已有正值日记录，
 * 不补 0，不暴露歌曲、播放器或设备信息。SQLite 日期函数未参与分桶，未来迁移 MySQL 时该领域口径可
 * 原样复用。
 */
final class ListeningTimeService
{
    private const MAX_RANGE_DAYS = 3_660;

    /**
     * 把一段有效收听区间累加到账号日事实。
     *
     * [endedAt] 是服务端确认该增量的 UTC 时刻，[listenedMs] 是本事件相对上个幂等事件新增的有效毫秒。
     * 区间按“结束时刻向前回溯”分配；单事件当前最多 30 秒，但实现支持任意正区间。调用方必须已持有
     * SQLite 写事务；更新失败向外抛出并由调用方回滚，重试只能通过原 eventId 重新进入幂等检查。
     */
    public function addInterval(
        string $userId,
        string $timezone,
        DateTimeImmutable $endedAt,
        int $listenedMs,
        string $updatedAt,
    ): void {
        if ($listenedMs <= 0) {
            return;
        }
        $zone = $this->timezone($timezone);
        $endMs = $this->epochMs($endedAt);
        $cursorMs = $endMs - $listenedMs;

        while ($cursorMs < $endMs) {
            $cursor = $this->fromEpochMs($cursorMs);
            $local = $cursor->setTimezone($zone);
            $localDate = $local->format('Y-m-d');
            $nextMidnight = $local->setTime(0, 0)->add(new DateInterval('P1D'))->setTimezone(new DateTimeZone('UTC'));
            $segmentEndMs = min($endMs, $this->epochMs($nextMidnight));
            $segmentMs = $segmentEndMs - $cursorMs;
            if ($segmentMs <= 0) {
                throw new ListeningTimeInvalid('Listening interval could not advance.');
            }
            $this->incrementDay($userId, $localDate, $segmentMs, $updatedAt);
            $cursorMs = $segmentEndMs;
        }
    }

    /**
     * 返回当前账号指定日期范围内的非零统计桶。
     *
     * from/to 使用账号当前时区下的 `YYYY-MM-DD` 且两端都包含；省略时分别采用日 30 天、周 12 周、
     * 月 12 月、年 5 年的展示窗口。范围和 period 在发起 SQL 前校验，账号 ID 只取 actor，查询无写入。
     *
     * @param array<string,mixed> $actor 已认证并具有 play 能力的当前账号。
     * @return array{period:string,timezone:string,from:string,to:string,totalMs:int,buckets:list<array{startDate:string,endDate:string,listenedMs:int}>}
     */
    public function summary(
        array $actor,
        string $period,
        ?string $from = null,
        ?string $to = null,
        ?DateTimeImmutable $now = null,
    ): array {
        if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
            throw new ListeningTimeInvalid('period is invalid.');
        }
        $userId = (string) ($actor['id'] ?? '');
        if ($userId === '') {
            throw new ListeningTimeInvalid('actor is invalid.');
        }
        $zone = $this->timezone((string) ($actor['timezone'] ?? 'UTC'));
        $today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone)->setTime(0, 0);
        [$fromDate, $toDate] = $this->range($period, $from, $to, $today, $zone);

        $rows = Db::table('user_daily_listening_times')
            ->where('user_id', $userId)
            ->where('local_date', '>=', $fromDate->format('Y-m-d'))
            ->where('local_date', '<=', $toDate->format('Y-m-d'))
            ->orderBy('local_date')
            ->get(['local_date', 'listened_ms']);

        /** @var array<string,array{startDate:string,endDate:string,listenedMs:int}> $buckets */
        $buckets = [];
        $totalMs = 0;
        foreach ($rows as $row) {
            if (!$row instanceof stdClass || (int) $row->listened_ms <= 0) {
                continue;
            }
            $day = $this->parseDate((string) $row->local_date, $zone);
            [$key, $startDate, $endDate] = $this->bucket($period, $day);
            $milliseconds = (int) $row->listened_ms;
            $totalMs += $milliseconds;
            if (isset($buckets[$key])) {
                $buckets[$key]['listenedMs'] += $milliseconds;
            } else {
                $buckets[$key] = compact('startDate', 'endDate') + ['listenedMs' => $milliseconds];
            }
        }

        return [
            'period' => $period,
            'timezone' => $zone->getName(),
            'from' => $fromDate->format('Y-m-d'),
            'to' => $toDate->format('Y-m-d'),
            'totalMs' => $totalMs,
            'buckets' => array_values($buckets),
        ];
    }

    /** 在调用方写事务中更新已有正值行，或仅为正增量创建新行。 */
    private function incrementDay(
        string $userId,
        string $localDate,
        int $milliseconds,
        string $updatedAt,
    ): void {
        $changed = Db::table('user_daily_listening_times')
            ->where('user_id', $userId)->where('local_date', $localDate)
            ->update([
                'listened_ms' => Db::raw('listened_ms + ' . $milliseconds),
                'updated_at' => $updatedAt,
            ]);
        if ($changed === 0) {
            Db::table('user_daily_listening_times')->insert([
                'user_id' => $userId,
                'local_date' => $localDate,
                'listened_ms' => $milliseconds,
                'updated_at' => $updatedAt,
            ]);
        }
    }

    /** @return array{DateTimeImmutable,DateTimeImmutable} 解析并限制闭区间。 */
    private function range(
        string $period,
        ?string $from,
        ?string $to,
        DateTimeImmutable $today,
        DateTimeZone $zone,
    ): array {
        $toDate = $to === null ? $today : $this->parseDate($to, $zone);
        $fromDate = $from === null ? match ($period) {
            'day' => $toDate->sub(new DateInterval('P29D')),
            'week' => $toDate->modify('monday this week')->sub(new DateInterval('P11W')),
            'month' => $toDate->modify('first day of this month')->sub(new DateInterval('P11M')),
            'year' => $toDate->setDate((int) $toDate->format('Y') - 4, 1, 1),
        } : $this->parseDate($from, $zone);
        if ($fromDate > $toDate || (int) $fromDate->diff($toDate)->format('%a') > self::MAX_RANGE_DAYS) {
            throw new ListeningTimeInvalid('date range is invalid.');
        }

        return [$fromDate, $toDate];
    }

    /** @return array{string,string,string} 生成稳定桶键和完整自然周期边界。 */
    private function bucket(string $period, DateTimeImmutable $day): array
    {
        return match ($period) {
            'day' => [$day->format('Y-m-d'), $day->format('Y-m-d'), $day->format('Y-m-d')],
            'week' => $this->weekBucket($day),
            'month' => [
                $day->format('Y-m'),
                $day->modify('first day of this month')->format('Y-m-d'),
                $day->modify('last day of this month')->format('Y-m-d'),
            ],
            'year' => [$day->format('Y'), $day->format('Y-01-01'), $day->format('Y-12-31')],
            default => throw new ListeningTimeInvalid('period is invalid.'),
        };
    }

    /** @return array{string,string,string} ISO 周以周一为首日，跨年时仍以实际起始日作键。 */
    private function weekBucket(DateTimeImmutable $day): array
    {
        $start = $day->modify('monday this week');
        return [$start->format('Y-m-d'), $start->format('Y-m-d'), $start->add(new DateInterval('P6D'))->format('Y-m-d')];
    }

    /** 严格解析规范日期，拒绝 PHP 自动归一化的 2 月 31 日等输入。 */
    private function parseDate(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new ListeningTimeInvalid('date is invalid.');
        }
        return $date;
    }

    /** 认证投影应始终提供合法时区；内部兼容调用缺失时区时只回退 UTC，非法值失败关闭。 */
    private function timezone(string $value): DateTimeZone
    {
        if ($value === '') {
            return new DateTimeZone('UTC');
        }
        try {
            return new DateTimeZone($value);
        } catch (Throwable) {
            throw new ListeningTimeInvalid('timezone is invalid.');
        }
    }

    /** 把带微秒的时间转换成整数 epoch 毫秒，避免浮点累计误差。 */
    private function epochMs(DateTimeImmutable $value): int
    {
        return ((int) $value->format('U')) * 1000 + intdiv((int) $value->format('u'), 1000);
    }

    /** 从整数 epoch 毫秒重建 UTC 时间，用于跨自然日分段。 */
    private function fromEpochMs(int $value): DateTimeImmutable
    {
        $seconds = intdiv($value, 1000);
        $milliseconds = $value % 1000;
        $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $milliseconds * 1000));
        if ($date === false) {
            throw new ListeningTimeInvalid('timestamp is invalid.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}
