<?php

declare(strict_types=1);

namespace app\process;

use app\application\Recommendation\LastfmRecommendationService;
use app\application\Recommendation\LastfmRecommendationUnavailable;
use app\application\Recommendation\PublicPlaylistCatalogUnavailable;
use app\application\Recommendation\PublicPlaylistRecommendationService;
use support\Log;
use Symfony\Component\Uid\Ulid;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 单进程执行到期的 Last.fm 与公开平台系统歌单同步。
 *
 * 网络访问与 HTTP Worker 隔离，每个 tick 最多处理一条规则，避免上游故障或大量歌单同时到期时放大
 * 请求。规则的 nextSyncAt 在成功或失败后都会推进，进程重启只会让到期任务稍晚执行，不会丢失业务
 * 状态。SQLite 阶段固定 count=1；未来多实例必须增加数据库租约后才能提高消费者数量。
 */
final class LastfmPlaylistSyncWorker
{
    private bool $busy = false;
    private bool $stopping = false;

    public function __construct(
        private readonly float $pollInterval = 60.0,
        private readonly LastfmRecommendationService $recommendations = new LastfmRecommendationService(),
        private readonly PublicPlaylistRecommendationService $publicRecommendations = new PublicPlaylistRecommendationService(),
    ) {
    }

    /** 首次延迟执行，确保迁移和 Webman 引导完成；后续轮询不重入。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(15.0, [$this, 'tick'], [], false);
        Timer::add(max(30.0, $this->pollInterval), [$this, 'tick']);
    }

    /** 优雅停止后不再发起新的外部请求；正在执行的有界请求由超时自然结束。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 执行至多一条到期规则，并释放本次 Webman Context。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            $requestId = 'playlist-sync-worker-' . (string) new Ulid();
            // Last.fm 与公开平台共享单消费者；同一 tick 只执行一条规则，避免同时放大第三方请求。
            if (!$this->recommendations->syncOneDue($requestId)) {
                $this->publicRecommendations->syncOneDue($requestId);
            }
        } catch (PublicPlaylistCatalogUnavailable) {
            // 公开榜单由第三方插件提供；上游暂时不可用时保留旧歌单，不应制造系统级 error 记录。
            Log::warning('Public playlist catalog is temporarily unavailable.', [
                'error_code' => 'PUBLIC_PLAYLIST_PLUGIN_UNAVAILABLE',
            ]);
        } catch (LastfmRecommendationUnavailable) {
            // Last.fm 的网络或目录暂不可用属于可重试外部故障，下一轮仍会按规则冷却时间继续处理。
            Log::warning('Last.fm playlist catalog is temporarily unavailable.', [
                'error_code' => 'LASTFM_REFRESH_FAILED',
            ]);
        } catch (Throwable $throwable) {
            Log::error('Playlist catalog sync tick failed.', ['exception_class' => $throwable::class]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
