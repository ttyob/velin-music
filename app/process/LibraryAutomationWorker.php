<?php

declare(strict_types=1);

namespace app\process;

use app\application\Storage\StorageLayout;

use app\application\Scan\ScanAutomationService;
use app\infrastructure\Scan\LibraryWatchProcess;
use app\infrastructure\Scan\LibraryWatchUnavailable;
use DateTimeImmutable;
use DateTimeZone;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 独立监督本地文件事件并为 scheduled/watch 音乐库创建耐久扫描任务。
 *
 * 该进程与长时间执行扫描的 LibraryScanWorker 分离，因此扫描期间仍能接收 inotify 事件。helper 只持有
 * 当前数据库快照中的本地 watch 根；配置每 15 秒刷新，变化时完整重启 helper，重启窗口由每日校准
 * 弥补。事件在内存中按库合并，只有成功排队或配置失效才消费；进程崩溃最多丢失尚未排队的瞬时事件，
 * 不会丢失任务或业务数据。scheduled 与 watch 校准即使 helper 不可用也继续运行。
 */
final class LibraryAutomationWorker
{
    private bool $busy = false;
    private bool $stopping = false;
    private ?LibraryWatchProcess $watcher = null;
    private string $watchSignature = '';
    private float $nextRefreshAt = 0.0;
    private float $nextRestartAt = 0.0;
    private readonly ScanAutomationService $automation;
    /** @var array<string,true> */
    private array $pendingWatchEvents = [];

    /**
     * 配置轮询、配置刷新、helper 退避和两类扫描周期。
     *
     * 所有值只来自服务端进程配置；浏览器不能改变 helper 路径或资源周期。注入 automation 仅供测试或
     * 未来数据库适配器使用，默认实例直到首个 tick 才访问数据库，构造本身没有进程和文件副作用。
     */
    public function __construct(
        private readonly float $pollInterval = 1.0,
        private readonly float $refreshInterval = 15.0,
        private readonly float $restartBackoff = 30.0,
        private readonly int $watchDebounceMs = 2_000,
        private readonly ?string $helperPath = null,
        int $scheduledIntervalSeconds = 21_600,
        int $watchReconcileSeconds = 86_400,
        ?ScanAutomationService $automation = null,
    ) {
        $this->automation = $automation ?? new ScanAutomationService(
            $scheduledIntervalSeconds,
            $watchReconcileSeconds,
        );
    }

    /** 启动非重叠轮询；首次 tick 立即同步配置并执行到期计划。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(0.2, [$this, 'tick'], [], false);
        Timer::add(max(0.5, $this->pollInterval), [$this, 'tick']);
    }

    /** 停止接收新事件并回收 helper；已经持久化的扫描任务不受影响。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
        $this->watcher?->close();
        $this->watcher = null;
    }

    /**
     * 完成一次配置同步、非阻塞事件读取和计划入队。
     *
     * 任一阶段失败只记录稳定分类，不包含库 ID、路径或 helper 输出；finally 释放 Webman Context。互斥
     * 标记防止慢 SQLite 写入导致定时器重入。helper 失败按固定退避重启，避免缺失二进制或 inotify
     * 配额耗尽时持续 fork；自动排队服务仍会运行 watch 的周期校准。
     */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) {
            return;
        }
        $this->busy = true;
        try {
            $now = microtime(true);
            if ($now >= $this->nextRefreshAt) {
                $this->refreshWatcher($now);
                $this->nextRefreshAt = $now + max(1.0, $this->refreshInterval);
            }
            if ($this->watcher !== null) {
                try {
                    foreach ($this->watcher->poll() as $event) {
                        $this->pendingWatchEvents[$event['key']] = true;
                    }
                } catch (LibraryWatchUnavailable $exception) {
                    $this->watcher->close();
                    $this->watcher = null;
                    $this->nextRestartAt = $now + max(1.0, $this->restartBackoff);
                    Log::warning('Library watch helper became unavailable.', [
                        'reason_code' => $exception->reasonCode,
                    ]);
                }
            }
            $result = $this->automation->tick(
                array_keys($this->pendingWatchEvents),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
            foreach ($result['consumedWatchEvents'] as $libraryId) {
                unset($this->pendingWatchEvents[$libraryId]);
            }
        } catch (Throwable $throwable) {
            Log::error('Library scan automation tick failed.', [
                'exception_class' => $throwable::class,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }

    /** 用有序目标摘要识别配置变化，重启时不把路径或摘要写入日志。 */
    private function refreshWatcher(float $now): void
    {
        $targets = $this->automation->watchTargets();
        $signature = hash('sha256', json_encode($targets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($targets === []) {
            $this->watcher?->close();
            $this->watcher = null;
            $this->watchSignature = $signature;
            return;
        }
        if ($this->watcher !== null && hash_equals($this->watchSignature, $signature)) {
            return;
        }
        if ($this->watcher === null && hash_equals($this->watchSignature, $signature) && $now < $this->nextRestartAt) {
            return;
        }
        $this->watcher?->close();
        $this->watcher = null;
        $this->watchSignature = $signature;
        try {
            $this->watcher = new LibraryWatchProcess(
                $targets,
                $this->helperPath,
                StorageLayout::LIBRARY_ROOT,
                $this->watchDebounceMs,
            );
            $this->nextRestartAt = 0.0;
        } catch (LibraryWatchUnavailable $exception) {
            $this->nextRestartAt = $now + max(1.0, $this->restartBackoff);
            Log::warning('Library watch helper could not start.', [
                'reason_code' => $exception->reasonCode,
            ]);
        }
    }
}
