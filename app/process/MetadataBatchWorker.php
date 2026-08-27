<?php

declare(strict_types=1);

namespace app\process;

use app\application\Metadata\MetadataBatchWorkerService;
use app\application\Metadata\MetadataWorkerWakeSignal;
use app\application\Metadata\ScrapeAssetPublicationService;
use app\application\Metadata\MetadataSyncScrapeWorkerService;
use app\infrastructure\Metadata\RedisMetadataWorkerWakeSignal;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/** 独立执行批量元数据方案，避免大范围数据库写入阻塞 HTTP、播放、扫描或刮削进程。 */
final class MetadataBatchWorker
{
    private const SYNC_SCRAPE_STEPS_PER_TICK = 20;
    private const SYNC_SCRAPE_BUDGET_SECONDS = 10.0;
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;

    public function __construct(
        private readonly float $pollInterval = 2.0,
        private readonly MetadataBatchWorkerService $batches = new MetadataBatchWorkerService(),
        private readonly MetadataSyncScrapeWorkerService $syncScrape = new MetadataSyncScrapeWorkerService(),
        private readonly ScrapeAssetPublicationService $assetPublications = new ScrapeAssetPublicationService(),
        private readonly MetadataWorkerWakeSignal $wakeSignal = new RedisMetadataWorkerWakeSignal(),
    ) {
        $this->workerId = (gethostname() ?: 'velin') . ':metadata-batch:' . getmypid();
    }

    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(1.0, [$this, 'tick'], [], false);
        Timer::add(max(1.0, $this->pollInterval), [$this, 'tick']);
        Timer::add(0.2, [$this, 'consumeWakeSignal']);
    }

    public function onWorkerStop(Worker $worker): void { $this->stopping = true; }

    /**
     * 消费可丢失 Redis 标记并立即尝试一次常规 tick。
     *
     * 信号不携带任务身份且不能替代 SQLite 领取；当前 tick 正忙或进程停机时只合并通知，周期轮询仍会
     * 恢复。Redis 实现失败返回 false，不向事件循环抛错。200ms 检查只访问一个固定短 TTL key，避免
     * 为降低数据库轮询而持续执行 SQLite 空查询。
     */
    public function consumeWakeSignal(): void
    {
        if ($this->busy || $this->stopping || !$this->wakeSignal->consume()) return;
        $this->tick();
    }

    /**
     * 每次领取一个字段方案，并有界连续推进逐曲刮削与派生资源。
     *
     * 逐曲 drain 仍由本进程串行执行，最多二十个目标/发布步骤且墙钟预算十秒；慢平台调用返回后预算
     * 已耗尽便停止，不会继续占用事件循环。资源未终态或未来 next_attempt_at 会让当前父批次领取返回
     * 空并提前结束；人工候选搜索仍按既有 position 规则推进。周期 tick 与 Redis 唤醒仍是崩溃、外部
     * 图片 Worker 和漏信号的恢复边界。
     */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            $this->batches->recoverStaleLeases();
            $plan = $this->batches->claimNext($this->workerId);
            if ($plan !== null && !$this->stopping) $this->batches->execute($plan, fn (): bool => $this->stopping);
            // 在线平台查询复用该独立元数据消费者，但使用完全独立的目标租约；HTTP 永不执行网络查询。
            $this->syncScrape->recoverStaleLeases();
            $this->assetPublications->recoverStaleLeases();
            if (!$this->stopping) {
                $this->syncScrape->drain(
                    $this->workerId . ':sync-scrape',
                    self::SYNC_SCRAPE_STEPS_PER_TICK,
                    self::SYNC_SCRAPE_BUDGET_SECONDS,
                    fn (): bool => $this->stopping,
                );
            }
        } catch (Throwable $throwable) {
            Log::error('Metadata batch worker tick failed.', ['worker_id' => $this->workerId, 'exception_class' => $throwable::class]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
