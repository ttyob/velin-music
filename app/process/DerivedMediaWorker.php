<?php

declare(strict_types=1);

namespace app\process;

use app\application\Lyrics\LyricsWritebackWorkerService;
use app\application\Lyrics\LyricsAudioTagWritebackWorkerService;
use app\application\Metadata\AudioTagWritebackWorkerService;
use app\application\Artwork\ArtworkProviderWorkerService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 承载仍有效的歌词、封面和标签派生任务，不再消费旧目录刮削或待入库队列。
 *
 * The database remains the durable source of truth. Periodic reconciliation recovers missed file
 * events and the busy flag prevents overlapping traversals in one process. Playback and Web workers
 * never execute provider calls, hashing, artwork publication, or tag writeback. 逐曲元数据刮削与派生资源
 * outbox 由 MetadataBatchWorker 消费；本进程只保留独立歌词/封面/标签队列。旧目录发现、文件整理和
 * 待入库服务没有注入点，即使升级环境残留旧环境变量也无法重新领取历史任务。
 */
final class DerivedMediaWorker
{
    private const ARTWORK_STEPS_PER_TICK = 4;
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;

    public function __construct(
        private readonly float $pollInterval = 2.0,
        private readonly LyricsWritebackWorkerService $lyricsWriteback = new LyricsWritebackWorkerService(),
        private readonly LyricsAudioTagWritebackWorkerService $lyricsAudioTagWriteback = new LyricsAudioTagWritebackWorkerService(),
        private readonly AudioTagWritebackWorkerService $audioTagWriteback = new AudioTagWritebackWorkerService(),
        private readonly ArtworkProviderWorkerService $artworkProvider = new ArtworkProviderWorkerService(),
    ) {
        $this->workerId = (gethostname() ?: 'velin') . ':' . getmypid();
    }

    /** Starts one prompt reconciliation and a bounded periodic fallback pass. */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(1.0, [$this, 'tick'], [], false);
        Timer::add(max(2.0, $this->pollInterval), [$this, 'tick']);
    }

    /** Prevents a new discovery or file operation from starting during shutdown. */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /**
     * 顺序处理仍有效的歌词、标签和封面任务。
     *
     * 单进程边界继续限制 SQLite 写竞争。图片队列每次最多推进四个搜索/导入步骤，使常见的一张专辑图
     * 加一张艺人图可在同一 tick 收口，同时避免网络异常长期占用事件循环。此方法没有旧 scrape/import
     * 服务引用，不执行目录遍历、整理、移动或待入库扫描；进程停机标记会在每次 tick 开始时关闭新领取。
     */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            $this->lyricsWriteback->recoverStaleLeases();
            $lyricsJob = $this->lyricsWriteback->claimNext($this->workerId);
            if ($lyricsJob !== null && !$this->stopping) $this->lyricsWriteback->execute($lyricsJob);
            $this->lyricsWriteback->flushScanRequests();
            $this->lyricsAudioTagWriteback->recoverStaleLeases();
            $lyricsAudioTagJob = $this->lyricsAudioTagWriteback->claimNext($this->workerId);
            if ($lyricsAudioTagJob !== null && !$this->stopping) $this->lyricsAudioTagWriteback->execute($lyricsAudioTagJob);
            $this->audioTagWriteback->recoverStaleLeases();
            $audioTagJob = $this->audioTagWriteback->claimNext($this->workerId);
            if ($audioTagJob !== null && !$this->stopping) $this->audioTagWriteback->execute($audioTagJob);
            $this->audioTagWriteback->flushScanRequests();
            $this->artworkProvider->recoverStaleLeases();
            if (!$this->stopping) {
                $this->artworkProvider->drain($this->workerId, self::ARTWORK_STEPS_PER_TICK);
            }
        } catch (Throwable $throwable) {
            Log::error('Derived media pipeline tick failed.', [
                'worker_id' => $this->workerId,
                'exception_class' => $throwable::class,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }

}
