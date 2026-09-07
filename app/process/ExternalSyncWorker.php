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
 * 以单个受控网络进程消费到期系统歌单同步。
 *
 * 任务的领取、幂等、重试和失败状态仍由推荐领域服务拥有；本类只负责定时触发。外部 HTTP 是同步且有
 * 超时的，任务在触发前已经持久化，失败会由服务记录下一次时间，不会回退到 HTTP 请求内执行。
 */
final class ExternalSyncWorker
{
    private bool $playlistBusy = false;
    private bool $stopping = false;

    public function __construct(
        private readonly bool $playlistSyncEnabled = true,
        private readonly float $playlistSyncPollInterval = 60.0,
        private readonly LastfmRecommendationService $recommendations = new LastfmRecommendationService(),
        private readonly PublicPlaylistRecommendationService $publicRecommendations = new PublicPlaylistRecommendationService(),
    ) {
    }

    /** 把首次歌单同步延后到迁移和启动稳定后。 */
    public function onWorkerStart(Worker $worker): void
    {
        if ($this->playlistSyncEnabled) {
            Timer::add(15.0, [$this, 'syncPlaylist'], [], false);
            Timer::add(max(30.0, $this->playlistSyncPollInterval), [$this, 'syncPlaylist']);
        }
    }

    /** 优雅停止后不再领取新的同步规则，已执行网络请求按原超时收口。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 每轮最多执行一项外部操作，避免多条规则同时到期时放大第三方请求。 */
    public function syncPlaylist(): void
    {
        if (!$this->playlistSyncEnabled || $this->playlistBusy || $this->stopping) {
            return;
        }
        $this->playlistBusy = true;
        try {
            $requestId = 'playlist-sync-worker-' . (string) new Ulid();
            if (!$this->recommendations->syncOneDue($requestId)) {
                $this->publicRecommendations->syncOneDue($requestId);
            }
        } catch (PublicPlaylistCatalogUnavailable) {
            Log::warning('Public playlist catalog is temporarily unavailable.', [
                'error_code' => 'PUBLIC_PLAYLIST_PLUGIN_UNAVAILABLE',
            ]);
        } catch (LastfmRecommendationUnavailable) {
            Log::warning('Last.fm playlist catalog is temporarily unavailable.', [
                'error_code' => 'LASTFM_REFRESH_FAILED',
            ]);
        } catch (Throwable $throwable) {
            Log::error('Playlist catalog sync tick failed.', ['exception_class' => $throwable::class]);
        } finally {
            $this->playlistBusy = false;
            Context::destroy();
        }
    }

}
