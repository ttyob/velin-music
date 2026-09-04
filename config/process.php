<?php

declare(strict_types=1);

use app\process\Http;
use app\process\DerivedMediaWorker;
use app\process\LibraryAutomationWorker;
use app\process\LibraryScanWorker;
use app\process\LastfmPlaylistSyncWorker;
use app\process\MediaGatewayProcess;
use app\process\MetadataBatchWorker;
use app\process\NotificationCleanupWorker;
use app\process\PersonalDataExportWorker;
use app\process\PlaybackPrefetchWorker;
use app\process\PlaylistAutoCompletionWorker;
use app\process\ResourcePluginEventWorker;
use app\process\ResourcePluginTranscodeWorker;
use app\process\ResourcePluginWorker;
use app\process\RuntimeMaintenanceWorker;
use app\process\ScrobbleDeliveryWorker;
use app\process\UploadWorker;
use app\process\AutomaticDatabaseBackupWorker;
use support\Log;
use support\Request;

global $argv;

$mediaGatewayEnabled = (bool) config('media_delivery.enabled', false);
$mediaGatewayPublicPort = (int) config('media_delivery.public_port', 8787);
$mediaGatewayInternalPort = (int) config('media_delivery.internal_port', 18787);

return [
    /*
     * Go 网关拥有唯一公开端口，仅把 API 和动态协议反代到回环 Webman；公开静态文件与 SPA 页面由 Go
     * 从独立 public 根直接发送。进程由 Workerman master 监督但不可热重载，避免 reload 窗口争用 8787。
     */
    'media-gateway' => [
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
    'webman' => [
        'handler' => Http::class,
        'listen' => $mediaGatewayEnabled
            ? 'http://127.0.0.1:' . $mediaGatewayInternalPort
            : 'http://0.0.0.0:' . $mediaGatewayPublicPort,
        // SQLite benefits from bounded concurrency; operators can tune after measurement.
        'count' => max(1, (int) (getenv('VELIN_WEB_WORKERS') ?: 4)),
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
    'library-scan-worker' => [
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
    'library-scan-automation-worker' => [
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
    /*
     * Last.fm 系统榜单在独立单消费者中按持久化 nextSyncAt 刷新；HTTP 请求只修改规则或触发单次手动
     * 刷新。关闭进程会保留规则和旧歌单内容，重新启用后继续处理到期项。
     */
    'lastfm-playlist-sync-worker' => [
        'handler' => LastfmPlaylistSyncWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_LASTFM_SYNC_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(30.0, (float) (getenv('VELIN_LASTFM_SYNC_POLL_SECONDS') ?: 60)),
        ],
    ],
    /*
     * 歌词、封面和标签派生队列与扫描隔离，避免平台延迟和受控文件写入阻塞音乐库扫描。旧目录发现、
     * 文件整理和待入库流程已退役；逐曲刮削及其资源 outbox 由 MetadataBatchWorker 处理。
     */
    'derived-media-worker' => [
        'handler' => DerivedMediaWorker::class,
        'count' => 1,
        'reloadable' => true,
        'constructor' => [
            'pollInterval' => max(2.0, (float) (getenv('VELIN_DERIVED_MEDIA_POLL_SECONDS') ?: 2)),
        ],
    ],
    /*
     * 上传文件哈希、内容探测和原子公布使用独立单消费者，避免阻塞 HTTP 与刮削目录校准。任务状态
     * 已持久化，禁用或重启只会延后 ready 会话，不会在请求进程内退化执行。
     */
    'upload-worker' => [
        'handler' => UploadWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_UPLOAD_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(1.0, (float) (getenv('VELIN_UPLOAD_POLL_SECONDS') ?: 2)),
        ],
    ],
    /*
     * 通知与实时事件保留任务独立于请求和扫描 Worker。每次唤醒只在批次数与墙钟预算内连续推进若干
     * 短事务，既能在升级后尽快排空积压，也不会长期占住 SQLite 写锁而阻塞用户请求。
     */
    'notification-cleanup-worker' => [
        'handler' => NotificationCleanupWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(
            getenv('VELIN_NOTIFICATION_CLEANUP_ENABLED') ?: 'true',
            FILTER_VALIDATE_BOOL,
        ),
        'constructor' => [
            'interval' => max(60.0, (float) (getenv('VELIN_NOTIFICATION_CLEANUP_SECONDS') ?: 300)),
            'batchSize' => max(1, min(1000, (int) (getenv('VELIN_NOTIFICATION_CLEANUP_BATCH') ?: 500))),
            'maxBatchesPerTick' => max(1, min(20, (int) (getenv('VELIN_NOTIFICATION_CLEANUP_MAX_BATCHES') ?: 8))),
            'timeBudgetSeconds' => max(0.1, min(10.0, (float) (getenv('VELIN_NOTIFICATION_CLEANUP_TIME_BUDGET_SECONDS') ?: 2))),
        ],
    ],
    /*
     * 运行维护只回收核心固定所有者的过期日志、可重建缓存和无活动锁临时文件；不扫描媒体或插件目录。
     * 单进程配合跨进程锁，保证自动周期和管理员手动清理不会并行删除同一目录项。
     */
    'runtime-maintenance-worker' => [
        'handler' => RuntimeMaintenanceWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_RUNTIME_MAINTENANCE_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'interval' => max(3_600.0, (float) (getenv('VELIN_RUNTIME_MAINTENANCE_SECONDS') ?: 21_600)),
        ],
    ],
    /*
     * SQLite 在线备份使用独立单进程和固定数据根。VACUUM INTO 不在 HTTP Worker 中运行，服务内锁会
     * 与 CLI 手工备份互斥；关闭后不会删除现有备份，重新启用时从下一次周期继续。
     */
    'automatic-database-backup-worker' => [
        'handler' => AutomaticDatabaseBackupWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_AUTOMATIC_BACKUP_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'interval' => max(3_600.0, (float) (getenv('VELIN_AUTOMATIC_BACKUP_SECONDS') ?: 86_400)),
        ],
    ],
    /* 个人数据导出分页读取和私有产物写入使用独立单消费者，停用账号会在数据库状态机中请求取消。 */
    'personal-data-export-worker' => [
        'handler' => PersonalDataExportWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_PERSONAL_EXPORT_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(1.0, (float) (getenv('VELIN_PERSONAL_EXPORT_POLL_SECONDS') ?: 3)),
        ],
    ],
    /* 批量字段覆盖逐对象短事务执行；SQLite 阶段固定单消费者。 */
    'metadata-batch-worker' => [
        'handler' => MetadataBatchWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_METADATA_BATCH_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(1.0, (float) (getenv('VELIN_METADATA_BATCH_POLL_SECONDS') ?: 2)),
        ],
    ],
    /*
     * 外部播放记录投递必须与 HTTP、扫描和刮削隔离；SQLite 阶段固定单消费者，在短事务外执行 DNS/HTTP。
     * 禁用后任务仍耐久保留，重新启用进程即可继续消费，不允许回退到请求内同步发送。
     */
    'scrobble-delivery-worker' => [
        'handler' => ScrobbleDeliveryWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_SCROBBLE_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(1.0, (float) (getenv('VELIN_SCROBBLE_POLL_SECONDS') ?: 2)),
        ],
    ],
    /*
     * 下一首远程原文件缓存固定由单消费者执行；不提供环境开关，Docker 与裸机服务启动后都自动消费。
     * 任务和缓存都有代码内置上限，失败只降低切歌性能，不允许回退到 HTTP 请求中同步完整下载。
     */
    'playback-prefetch-worker' => [
        'handler' => PlaybackPrefetchWorker::class,
        'count' => 1,
        'reloadable' => true,
    ],
    /*
     * 所有资源插件共用一个核心常驻调度器。插件首次安装后由下一轮目录发现自动开始消费，不能再要求
     * 插件通过 config/process.php 改变主进程拓扑；SQLite 阶段固定单消费者以约束写并发。
     */
    'resource-plugin-worker' => [
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
    'resource-plugin-event-worker' => [
        'handler' => ResourcePluginEventWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_PLUGIN_EVENT_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
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
    'resource-plugin-transcode-worker' => [
        'handler' => ResourcePluginTranscodeWorker::class,
        'count' => max(1, min(16, (int) (getenv('VELIN_RESOURCE_PLUGIN_TRANSCODE_WORKERS') ?: 2))),
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_RESOURCE_PLUGIN_TRANSCODE_WORKER_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            'pollInterval' => max(0.5, (float) (getenv('VELIN_RESOURCE_PLUGIN_TRANSCODE_POLL_SECONDS') ?: 1)),
        ],
    ],
    /* 系统歌单自动补全只在后台开关开启后领取缺失条目；三次候选耗尽后任务永久失败。 */
    'playlist-auto-completion-worker' => [
        'handler' => PlaylistAutoCompletionWorker::class,
        'count' => 1,
        'reloadable' => true,
        'enable' => filter_var(getenv('VELIN_PLAYLIST_AUTO_COMPLETION_ENABLED') ?: 'true', FILTER_VALIDATE_BOOL),
        'constructor' => [
            // 补全任务只做短事务和状态轮询；一秒调度可及时收口已完成下载，实际下载仍由插件 Worker 控制。
            'pollInterval' => max(1.0, (float) (getenv('VELIN_PLAYLIST_AUTO_COMPLETION_POLL_SECONDS') ?: 1)),
        ],
    ],
    'monitor' => [
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
