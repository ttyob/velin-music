<?php

declare(strict_types=1);

use app\process\Http;
use app\process\DerivedMediaWorker;
use app\process\ExternalSyncWorker;
use app\process\LibraryAutomationWorker;
use app\process\LibraryScanWorker;
use app\process\MaintenanceWorker;
use app\process\MediaGatewayProcess;
use app\process\MetadataBatchWorker;
use app\process\PlaybackPrefetchWorker;
use app\process\PlaylistAutoCompletionWorker;
use app\process\ResourcePluginEventWorker;
use app\process\ResourcePluginTranscodeWorker;
use app\process\ResourcePluginWorker;
use app\process\UploadWorker;
use support\Log;
use support\Request;

global $argv;

$mediaGatewayEnabled = (bool) config('media_delivery.enabled', false);
$mediaGatewayPublicPort = (int) config('media_delivery.public_port', 8787);
$mediaGatewayInternalPort = (int) config('media_delivery.internal_port', 18787);
$notificationCleanupEnabled = filter_var(getenv('VELIN_NOTIFICATION_CLEANUP_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL);
$runtimeMaintenanceEnabled = filter_var(getenv('VELIN_RUNTIME_MAINTENANCE_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL);
$lastfmPlaylistSyncEnabled = filter_var(getenv('VELIN_LASTFM_SYNC_WORKER_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);

return [
    /*
     * Go 网关拥有唯一公开端口，仅把 API 和动态协议反代到回环 Webman；公开静态文件与 SPA 页面由 Go
     * 从独立 public 根直接发送。进程由 Workerman master 监督但不可热重载，避免 reload 窗口争用 8787。
     */
    'velin-gateway' => [
        'handler' => MediaGatewayProcess::class,
        'count' => 1,
        'reloadable' => false,
        'enable' => $mediaGatewayEnabled,
        'constructor' => [
            'binaryPath' => (string) config('media_delivery.binary_path'),
            'listenAddress' => '0.0.0.0:' . $mediaGatewayPublicPort,
            'upstreamOrigin' => 'http://127.0.0.1:' . $mediaGatewayInternalPort,
            'staticRoot' => (string) config('media_delivery.static_root'),
            'allowedRoots' => (array) config('media_delivery.allowed_roots', []),
        ],
    ],
    'velin-http' => [
        'handler' => Http::class,
        'listen' => $mediaGatewayEnabled
            ? 'http://127.0.0.1:' . $mediaGatewayInternalPort
            : 'http://0.0.0.0:' . $mediaGatewayPublicPort,
        // SQLite benefits from bounded concurrency; two HTTP workers preserve basic parallelism without excess idle memory.
        'count' => max(1, (int) (getenv('VELIN_WEB_WORKERS') ?: 2)),
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path(),
        ],
    ],
    /*
     * The scan consumer has no listening socket and remains a single process while SQLite is the
     * primary database. It owns all media-directory traversal; Web workers only persist commands.
     * Disabling it leaves queued jobs durable for a later restart and does not execute them inline.
     */
    'velin-scan' => [
        'handler' => LibraryScanWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(
            getenv('VELIN_SCAN_WORKER_ENABLED') ?: 'true',
            FILTER_VALIDATE_BOOL,
        ),
        'constructor' => [
            'pollInterval' => max(0.5, (float) (getenv('VELIN_SCAN_POLL_SECONDS') ?: 2)),
        ],
    ],
    /*
     * 自动化进程只把 scheduled 到期和本地 inotify 事件转为耐久扫描任务，不执行遍历。独立进程保证
     * 长扫描期间仍接收事件；helper 不可用时 scheduled 与 watch 周期校准继续运行。
     */
    'velin-scan-automation' => [
        'handler' => LibraryAutomationWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_SCAN_AUTOMATION_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(0.5, (float) (getenv('VELIN_SCAN_AUTOMATION_POLL_SECONDS') ?: 1)),
            'refreshInterval' => max(5.0, (float) (getenv('VELIN_SCAN_AUTOMATION_REFRESH_SECONDS') ?: 15)),
            'restartBackoff' => max(5.0, (float) (getenv('VELIN_SCAN_WATCH_RESTART_SECONDS') ?: 30)),
            'watchDebounceMs' => max(250, min(30_000, (int) (getenv('VELIN_SCAN_WATCH_DEBOUNCE_MS') ?: 2_000))),
            'helperPath' => getenv('VELIN_LIBRARY_WATCH_HELPER_PATH') ?: null,
            'scheduledIntervalSeconds' => max(300, (int) (getenv('VELIN_SCHEDULED_SCAN_SECONDS') ?: 21_600)),
            'watchReconcileSeconds' => max(900, (int) (getenv('VELIN_WATCH_RECONCILE_SECONDS') ?: 86_400)),
        ],
    ],
    /* Last.fm/公开歌单同步是低频耐久外部网络任务；用户端外部 scrobble 已移除。 */
    'velin-external-sync' => [
        'handler' => ExternalSyncWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => $lastfmPlaylistSyncEnabled,
        'constructor' => [
            'playlistSyncEnabled' => $lastfmPlaylistSyncEnabled,
            'playlistSyncPollInterval' => max(30.0, (float) (getenv('VELIN_LASTFM_SYNC_POLL_SECONDS') ?: 60)),
        ],
    ],
    /*
     * 歌词、封面和标签派生队列与扫描隔离，避免平台延迟和受控文件写入阻塞音乐库扫描。旧目录发现、
     * 文件整理和待入库流程已退役；逐曲刮削及其资源 outbox 由 MetadataBatchWorker 处理。
     */
    'velin-derived-media' => [
        'handler' => DerivedMediaWorker::class,
        'count' => 1,
        'reloadable' => true,
        'constructor' => [
            'pollInterval' => max(2.0, (float) (getenv('VELIN_DERIVED_MEDIA_POLL_SECONDS') ?: 2)),
        ],
    ],
    /*
     * 上传文件哈希、内容探测和原子公布使用独立单消费者，避免阻塞 HTTP 与刮削目录校准。默认不加入
     * Workerman master；上传会话创建/发布时由 DynamicWorkerSupervisor 按需拉起同名 CLI 长期 Worker。
     * 任务状态已持久化，启动失败或进程重启只会延后会话，不会在请求进程内退化执行。
     */
    'velin-upload' => [
        'handler' => UploadWorker::class,
        'count' => 1,
        'reloadable' => true,
        // 上传 Worker 由上传会话提交点动态启动；保留定义仅用于兼容旧的进程名，不在启动阶段创建进程。
        'enable' => false,
        'constructor' => [
            'pollInterval' => max(1.0, (float) (getenv('VELIN_UPLOAD_POLL_SECONDS') ?: 2)),
        ],
    ],
    /* 通知保留和 runtime 文件回收共用 Timer 容器，但保留各自开关、时限和文件锁。 */
    'velin-maintenance' => [
        'handler' => MaintenanceWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => $notificationCleanupEnabled || $runtimeMaintenanceEnabled,
        'constructor' => [
            'notificationCleanupEnabled' => $notificationCleanupEnabled,
            'notificationCleanupInterval' => max(60.0, (float) (getenv('VELIN_NOTIFICATION_CLEANUP_SECONDS') ?: 300)),
            'notificationCleanupBatchSize' => max(1, min(1000, (int) (getenv('VELIN_NOTIFICATION_CLEANUP_BATCH') ?: 500))),
            'notificationCleanupMaxBatchesPerTick' => max(1, min(20, (int) (getenv('VELIN_NOTIFICATION_CLEANUP_MAX_BATCHES') ?: 8))),
            'notificationCleanupTimeBudgetSeconds' => max(0.1, min(10.0, (float) (getenv('VELIN_NOTIFICATION_CLEANUP_TIME_BUDGET_SECONDS') ?: 2))),
            'runtimeMaintenanceEnabled' => $runtimeMaintenanceEnabled,
            'runtimeMaintenanceInterval' => max(3_600.0, (float) (getenv('VELIN_RUNTIME_MAINTENANCE_SECONDS') ?: 21_600)),
        ],
    ],
    /* 批量字段覆盖逐对象短事务执行；SQLite 阶段固定单消费者。 */
    'velin-metadata' => [
        'handler' => MetadataBatchWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_METADATA_BATCH_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(1.0, (float) (getenv('VELIN_METADATA_BATCH_POLL_SECONDS') ?: 2)),
        ],
    ],
    /*
     * 下一首远程原文件缓存固定由单消费者执行；不提供环境开关，Docker 与裸机服务启动后都自动消费。
     * 任务和缓存都有代码内置上限，失败只降低切歌性能，不允许回退到 HTTP 请求中同步完整下载。
     */
    'velin-playback-prefetch' => [
        'handler' => PlaybackPrefetchWorker::class,
        'count' => 1,
        'reloadable' => true,
    ],
    /*
     * 所有资源插件共用一个核心常驻调度器。插件首次安装后由下一轮目录发现自动开始消费，不能再要求
     * 插件通过 config/process.php 改变主进程拓扑；SQLite 阶段固定单消费者以约束写并发。
     */
    'velin-plugin-dispatch' => [
        'handler' => ResourcePluginWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_RESOURCE_PLUGIN_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(1.0, (float) (getenv('VELIN_RESOURCE_PLUGIN_POLL_SECONDS') ?: 2)),
        ],
    ],
    /*
     * 用户插件事件只保存在 Redis 有限期队列中，不增加 SQLite 写并发。单消费者按插件独立游标投递；
     * Redis 或插件故障不会阻断核心业务，事件过期后自动删除且不从业务库补造。
     */
    'velin-plugin-events' => [
        'handler' => ResourcePluginEventWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_PLUGIN_EVENT_WORKER_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(0.5, (float) (getenv('VELIN_PLUGIN_EVENT_POLL_SECONDS') ?: 2)),
        ],
    ],
    /*
     * 下载完成后的媒体发布/转码独立于来源轮询。进程数是安全上限，实际 FFmpeg 并发仍由后台
     * system.limits.maxConcurrentTranscodes 和插件的 TranscodeAdmission 动态收口；调低后台限制不会
     * 中断已有转码。默认两个进程与系统默认并发一致，避免十六个空闲进程持续轮询；需要扩容时必须同时
     * 调高后台限制和显式环境变量，进程数仍限制在 1-16，空闲进程不会启动 FFmpeg。
     */
    'velin-plugin-transcode' => [
        'handler' => ResourcePluginTranscodeWorker::class,
        'count' => max(1, min(16, (int) (getenv('VELIN_RESOURCE_PLUGIN_TRANSCODE_WORKERS') ?: 2))),
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_RESOURCE_PLUGIN_TRANSCODE_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(0.5, (float) (getenv('VELIN_RESOURCE_PLUGIN_TRANSCODE_POLL_SECONDS') ?: 1)),
        ],
    ],
    /* 系统歌单自动补全只在后台开关开启后领取缺失条目；三次候选耗尽后任务永久失败。 */
    'velin-playlist-completion' => [
        'handler' => PlaylistAutoCompletionWorker::class,
        'count' => 1,
        'reloadable' => true,
        // 歌单补全 Worker 由后台开启/新增缺失条目时动态启动，默认不创建常驻进程。
        'enable' => false,
        'constructor' => [
            // 补全任务只做短事务和状态轮询；一秒调度可及时收口已完成下载，实际下载仍由插件 Worker 控制。
            'pollInterval' => max(1.0, (float) (getenv('VELIN_PLAYLIST_AUTO_COMPLETION_POLL_SECONDS') ?: 1)),
        ],
    ],
    'velin-monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        // 生产与缺少 .env 的启动均关闭源码轮询；裸机开发通过 .env.example 显式启用。
        'enable' => filter_var(
            getenv('VELIN_MONITOR_ENABLED') ?: 'false',
            FILTER_VALIDATE_BOOL,
        ),
        'constructor' => [
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            'monitorExtensions' => ['php', 'html', 'htm', 'env'],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv, true) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ],
        ],
    ],
];
