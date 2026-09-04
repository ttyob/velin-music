<?php

declare(strict_types=1);

namespace app\application\Scan;

use DateTimeImmutable;
use DateTimeZone;
use stdClass;
use support\Db;

/**
 * 把本地文件事件和周期计划收敛为现有扫描状态机的耐久任务。
 *
 * 本服务只读取 `music_libraries` 与扫描任务时间，不触碰媒体目录。scheduled 使用部署级间隔；watch
 * 除文件事件外使用更长周期做事件丢失校准。最近任务 created_at 与最近成功扫描时间中的较新者是下一
 * 次周期基线，因此失败、取消或人工扫描都会进入完整冷却期，不会因 last_scanned_at 未更新形成重试
 * 风暴。最终入队仍由 ScanJobService 在事务内复验模式、来源和活动任务唯一性。
 */
final readonly class ScanAutomationService
{
    /**
     * 配置两类部署级周期并注入唯一扫描命令入口。
     *
     * 秒数必须为正；生产下限由进程配置收紧，测试可使用短周期验证边界。构造不连接数据库、不启动
     * 计时器，实例可由单个自动化 Worker 在整个进程生命周期复用。
     */
    public function __construct(
        private int $scheduledIntervalSeconds = 21_600,
        private int $watchReconcileSeconds = 86_400,
        private ScanJobService $scanJobs = new ScanJobService(),
    ) {
        if ($this->scheduledIntervalSeconds < 1 || $this->watchReconcileSeconds < 1) {
            throw new \InvalidArgumentException('Scan automation intervals must be positive.');
        }
    }

    /**
     * 返回当前应由 helper 监听的本地库启动快照。
     *
     * 物理路径只在自动化 Worker 内传给 stdin 协议，不写日志、审计或任务。查询不探测目录；helper 会
     * 在添加 inotify watch 前重新验证 `/storage/music` 包含关系和真实路径身份。
     *
     * @return list<array{key:string,root:string}>
     */
    public function watchTargets(): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('music_libraries')->where('status', 'active')->where('source_type', 'local')
            ->where('scan_mode', 'watch')->orderBy('id')->get(['id', 'resolved_root_path'])->all();
        return array_map(static fn (stdClass $row): array => [
            'key' => (string) $row->id,
            'root' => (string) $row->resolved_root_path,
        ], $rows);
    }

    /**
     * 执行一次轻量计划判断，并返回可以从 Worker 内存删除的 watch 事件。
     *
     * watchEvents 是 helper 输出的音乐库 ULID 集合，不包含路径。目标库已有活动任务时事件不会消费，
     * 当前任务结束后会补排一次以覆盖扫描尾部发生的变化；配置已改为 manual/scheduled、库被停用或
     * 删除时消费旧事件。周期任务每轮最多对当前活动自动库各排一个，数据库唯一索引处理并发竞争。
     *
     * @param list<string> $watchEvents
     * @return array{queued:int,consumedWatchEvents:list<string>}
     */
    public function tick(array $watchEvents, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $eventSet = [];
        foreach (array_slice(array_values(array_unique($watchEvents)), 0, 100) as $libraryId) {
            if (is_string($libraryId) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) === 1) {
                $eventSet[$libraryId] = true;
            }
        }

        /** @var list<stdClass> $targets */
        $targets = Db::table('music_libraries')->where('status', 'active')
            ->whereIn('scan_mode', ['scheduled', 'watch'])
            ->get(['id', 'scan_mode', 'source_type', 'last_scanned_at'])->all();
        $targetIds = array_map(static fn (stdClass $row): string => (string) $row->id, $targets);
        $latestJobs = [];
        if ($targetIds !== []) {
            /** @var list<stdClass> $rows */
            $rows = Db::table('library_scan_jobs')->whereIn('library_id', $targetIds)
                ->groupBy('library_id')->get(['library_id', Db::raw('MAX(created_at) AS latest_created_at')])->all();
            foreach ($rows as $row) {
                $latestJobs[(string) $row->library_id] = (string) $row->latest_created_at;
            }
        }

        $queued = 0;
        $consumed = [];
        $eligibleEvents = [];
        foreach ($targets as $target) {
            $libraryId = (string) $target->id;
            $mode = (string) $target->scan_mode;
            $localWatch = $mode === 'watch' && (string) $target->source_type === 'local';
            if ($mode === 'watch' && !$localWatch) {
                continue;
            }
            if ($localWatch) {
                $eligibleEvents[$libraryId] = true;
            }
            $hasEvent = $localWatch && isset($eventSet[$libraryId]);
            $reason = $hasEvent ? 'watch' : ($mode === 'scheduled' ? 'scheduled' : 'reconcile');
            $interval = $mode === 'scheduled' ? $this->scheduledIntervalSeconds : $this->watchReconcileSeconds;
            if (!$hasEvent && !$this->isDue(
                $target->last_scanned_at === null ? null : (string) $target->last_scanned_at,
                $latestJobs[$libraryId] ?? null,
                $now->getTimestamp(),
                $interval,
            )) {
                continue;
            }
            $result = $this->scanJobs->queueAutomaticJob($libraryId, $reason);
            if ($result === 'queued') {
                ++$queued;
            }
            if ($hasEvent && $result !== 'active') {
                $consumed[] = $libraryId;
            }
        }
        foreach (array_keys($eventSet) as $libraryId) {
            if (!isset($eligibleEvents[$libraryId])) {
                $consumed[] = $libraryId;
            }
        }
        return ['queued' => $queued, 'consumedWatchEvents' => array_values(array_unique($consumed))];
    }

    /** 最近从未尝试过则立即到期；损坏时间按到期处理但不会扩大到客户端输入。 */
    private function isDue(?string $lastScannedAt, ?string $latestJobAt, int $now, int $interval): bool
    {
        $baseline = 0;
        foreach ([$lastScannedAt, $latestJobAt] as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $timestamp = strtotime($candidate);
            if ($timestamp !== false) {
                $baseline = max($baseline, $timestamp);
            }
        }
        return $baseline === 0 || $baseline + $interval <= $now;
    }
}
