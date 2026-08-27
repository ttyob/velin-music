<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\ResourcePlugin\Contract\ExternalDownloadHook;
use app\application\ResourcePlugin\Contract\AdminProviderLoginHook;
use app\application\ResourcePlugin\Contract\AdminProviderHealthHook;
use app\application\ResourcePlugin\Contract\ExternalMusicPluginRegistry;
use app\application\ResourcePlugin\Contract\ExternalMusicSearchHook;
use app\application\ResourcePlugin\Contract\ExternalMusicCompletionHook;
use app\application\ResourcePlugin\Contract\ExternalMetadataScrapeHook;
use app\application\ResourcePlugin\Contract\ExternalPlaylistIdentificationHook;
use app\application\ResourcePlugin\Contract\ExternalPlaylistPluginRegistry;
use app\application\ResourcePlugin\Contract\ExternalPlaylistSyncHook;
use app\application\ResourcePlugin\Contract\ExternalAlbumScrapeHook;
use app\application\ResourcePlugin\Contract\ExternalArtistArtworkHook;
use app\application\ResourcePlugin\Contract\ExternalArtistProfileHook;
use app\application\ResourcePlugin\Contract\ExternalMetadataScrapePluginRegistry;
use app\application\ResourcePlugin\Contract\MetadataScrapeAdminHook;
use app\application\ResourcePlugin\Contract\AdminSubscriptionHook;
use app\application\ResourcePlugin\Contract\AdminSubscriptionDiagnosticsHook;
use app\application\ResourcePlugin\Contract\PhpResourcePlugin;
use app\application\ResourcePlugin\Contract\PluginDatabaseLifecycle;
use app\application\ResourcePlugin\Contract\PluginWorkerHook;
use app\application\ResourcePlugin\Contract\PluginTranscodeWorkerHook;
use app\application\ResourcePlugin\Contract\BulkDownloadCleanupHook;
use app\application\ResourcePlugin\Contract\RetryableExternalDownloadHook;
use Throwable;

/**
 * 从部署者控制的 Webman `plugin` 目录发现进程内 PHP 资源插件。
 *
 * 每个一级插件目录可提供 `config/resource_plugin.php`，文件必须返回精确 manifest 字段并指定一个实现
 * PhpResourcePlugin 的无参类。注册表先验证真实路径没有逃出包根，再实例化并复验 descriptor；能力与
 * 钩子接口一一对应，避免仅靠字符串声明获得搜索、下载或 Worker 权限。这里不缓存实例，使测试和长驻
 * Worker 重载后都重新读取已安装包；未知或损坏插件只形成 invalid 投影，不阻断 Velin 其他功能。
 */
final readonly class PhpResourcePluginRegistry implements ExternalMusicPluginRegistry, ExternalMetadataScrapePluginRegistry, ExternalPlaylistPluginRegistry
{
    private const REQUIRED_FIELDS = ['capabilities', 'class', 'key', 'name', 'protocolVersion', 'version'];
    private const OPTIONAL_FIELDS = ['adminPage', 'metadata'];
    private const CAPABILITIES = [
        'admin_page_search', 'admin_page_download', 'admin_page_subscription', 'external_music_completion',
        'metadata_scrape', 'metadata_album', 'metadata_artist_artwork', 'metadata_artist_profile', 'metadata_artist_database', 'worker', 'database_lifecycle',
        'transcode_worker',
        'playlist_identification', 'playlist_sync',
    ];

    public function __construct(private ?string $root = null)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $root = $this->rootPath();
        if (!is_dir($root)) return [];
        $items = [];
        foreach (glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_link($directory)) continue;
            $key = basename($directory);
            $state = $this->state($key);
            $activeMarker = $directory . '/.velin-installed';
            $restartMarker = $directory . '/.velin-restart-required';
            $removalMarker = $directory . '/.velin-remove-pending';
            $active = is_file($activeMarker) && !is_link($activeMarker);
            $restartRequired = is_file($restartMarker) && !is_link($restartMarker);
            $pendingRemoval = is_file($removalMarker) && !is_link($removalMarker);
            if (!$active || $restartRequired || $pendingRemoval) {
                $databaseVersion = $this->ledgerVersion($key);
                $items[] = ['key' => $key, 'name' => $key, 'version' => null, 'protocolVersion' => null,
                    'capabilities' => [], 'packageInstalled' => false, 'databaseInstalled' => $databaseVersion !== null,
                    'databaseVersion' => $databaseVersion, 'pendingRemoval' => $pendingRemoval,
                    'restartRequired' => true, 'enabled' => $state['enabled'], 'stateVersion' => $state['version'],
                    'valid' => false, 'adminPage' => null, 'metadata' => null, 'errorCode' => $pendingRemoval
                        ? 'PHP_PLUGIN_REMOVAL_PENDING' : 'PHP_PLUGIN_PACKAGE_INACTIVE'];
                continue;
            }
            try {
                $loaded = $this->loadDirectory($directory);
                $database = $this->databaseState($loaded['plugin'], $loaded['manifest']['key']);
                $manifest = $this->publicManifest($loaded['manifest']);
                if (!$database['installed'] || !$state['enabled']) $manifest['adminPage'] = null;
                $items[] = $manifest + ['packageInstalled' => true,
                    'pendingRemoval' => false, 'restartRequired' => false,
                    'enabled' => $state['enabled'], 'stateVersion' => $state['version'],
                    'databaseInstalled' => $database['installed'], 'databaseVersion' => $database['version'],
                    'valid' => true, 'errorCode' => $state['errorCode']
                        ?? (!$state['enabled'] ? 'PHP_PLUGIN_DISABLED' : $database['errorCode'])];
            } catch (Throwable) {
                if (is_file($directory . '/config/resource_plugin.php')) {
                    $items[] = ['key' => $key, 'name' => $key, 'version' => null, 'protocolVersion' => null,
                        'capabilities' => [], 'packageInstalled' => true, 'databaseInstalled' => false,
                        'databaseVersion' => null, 'pendingRemoval' => false, 'restartRequired' => false, 'valid' => false,
                        'enabled' => $state['enabled'], 'stateVersion' => $state['version'],
                        'adminPage' => null, 'metadata' => null, 'errorCode' => 'PHP_PLUGIN_MANIFEST_INVALID'];
                }
            }
        }
        usort($items, static fn (array $left, array $right): int => strcmp((string) $left['key'], (string) $right['key']));
        return $items;
    }

    /** 返回已严格校验的插件实例；key 不能转换为路径片段之外的内容。 */
    public function get(string $key): PhpResourcePlugin
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $key) !== 1) throw new PhpResourcePluginNotFound();
        $directory = $this->rootPath() . DIRECTORY_SEPARATOR . $key;
        if (!is_dir($directory) || is_link($directory)
            || !is_file($directory . '/.velin-installed') || is_link($directory . '/.velin-installed')
            || is_file($directory . '/.velin-restart-required')
            || is_file($directory . '/.velin-remove-pending')) {
            throw new PhpResourcePluginNotFound();
        }
        if (!$this->state($key)['enabled']) throw new PhpResourcePluginDisabled('PHP_PLUGIN_DISABLED');
        return $this->getPackage($key);
    }

    /** 生命周期安装器读取已经校验但尚未激活的包；运行期钩子不得调用该入口。 */
    public function getPackage(string $key): PhpResourcePlugin
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $key) !== 1) throw new PhpResourcePluginNotFound();
        $directory = $this->rootPath() . DIRECTORY_SEPARATOR . $key;
        if (!is_dir($directory) || is_link($directory)) throw new PhpResourcePluginNotFound();
        return $this->loadDirectory($directory)['plugin'];
    }

    /**
     * 返回已激活插件的后台页面声明和经过真实路径约束的公开目录。
     *
     * 页面只属于活动且数据库版本一致的插件；待升级或待卸载包即使文件仍在磁盘也不可访问。返回的
     * `publicRoot` 只供核心静态资源 Controller 内部使用，不能进入 JSON 清单或日志。无页面声明的插件
     * 返回 null，它仍可仅提供 Worker 或 API 能力。
     *
     * @return array{path:string,title:string,description:string,entry:string,entryUrl:string,capability:string,publicRoot:string}|null
     */
    public function adminPage(string $key): ?array
    {
        $plugin = $this->get($key);
        $this->assertDatabaseCurrent($plugin, $key);
        $directory = $this->rootPath() . DIRECTORY_SEPARATOR . $key;
        $loaded = $this->loadDirectory($directory);
        $page = $loaded['manifest']['adminPage'];
        if ($page === null) return null;
        $publicRoot = realpath($directory . '/public');
        if (!is_string($publicRoot) || is_link($directory . '/public') || !is_dir($publicRoot)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_ADMIN_PAGE_INVALID');
        }
        return $this->publicAdminPage($key, $page) + ['entry' => $page['entry'], 'publicRoot' => $publicRoot];
    }

    /** 返回外部音乐搜索钩子；能力字符串和 PHP 接口必须同时满足。 */
    public function search(string $key): ExternalMusicSearchHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof ExternalMusicSearchHook
            || !in_array('admin_page_search', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid();
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /**
     * 返回插件自有后台页面可调用的搜索钩子。
     *
     * `admin_page_search` 只授权同源 `manage_system` 页面通过核心固定动态路由操作插件；已删除的统一
     * 外部搜索能力不会再作为 manifest 声明接受。调用方必须已经完成 Session、CSRF 和数据库版本校验。
     */
    public function adminSearch(string $key): ExternalMusicSearchHook
    {
        $plugin = $this->get($key);
        $capabilities = $plugin->descriptor()['capabilities'];
        if (!$plugin instanceof ExternalMusicSearchHook
            || !in_array('admin_page_search', $capabilities, true)) {
            throw new PhpResourcePluginInvalid();
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /**
     * 返回插件后台页面的可选渠道登录钩子。
     *
     * 登录不能仅凭方法名动态调用；插件必须同时是有效搜索插件、声明页面搜索能力并显式实现接口。
     * 核心不解释渠道或登录类型，也不会取得第三方凭据。
     */
    public function providerLogin(string $key): AdminProviderLoginHook
    {
        $plugin = $this->get($key);
        $capabilities = $plugin->descriptor()['capabilities'];
        if (!$plugin instanceof AdminProviderLoginHook
            || !in_array('admin_page_search', $capabilities, true)) {
            throw new PhpResourcePluginInvalid();
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回显式声明渠道健康能力的搜索插件。 */
    public function providerHealth(string $key): AdminProviderHealthHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof AdminProviderHealthHook
            || !in_array('admin_page_search', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid();
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回外部下载钩子；缺失时不会回退到核心 qBittorrent 实现。 */
    public function download(string $key): ExternalDownloadHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof ExternalDownloadHook
            || !in_array('admin_page_download', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid();
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回按歌曲身份直接补全的钩子；能力字符串与 PHP 接口必须同时满足。 */
    public function completion(string $key): ExternalMusicCompletionHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof ExternalMusicCompletionHook
            || !in_array('external_music_completion', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_COMPLETION_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回最终元数据刮削钩子；插件内部必须已完成候选评分和来源选择。 */
    public function metadataScrape(string $key): ExternalMetadataScrapeHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof ExternalMetadataScrapeHook
            || !in_array('metadata_scrape', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_METADATA_SCRAPE_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /**
     * 返回平台歌单链接识别 Hook。
     *
     * capability、接口和数据库账本必须同时有效；插件停用、升级或缺失时立即失败关闭。核心不再包含
     * 固定渠道识别实现，因此这里不能尝试任何插件外兼容路径。
     */
    public function playlistIdentification(string $key): ExternalPlaylistIdentificationHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof ExternalPlaylistIdentificationHook
            || !in_array('playlist_identification', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_PLAYLIST_IDENTIFICATION_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /**
     * 返回平台歌单目录/同步 Hook。
     *
     * 核心只把平台无关的条目交给匹配和事务层；来源凭据、限流和平台请求始终由插件拥有。能力不完整时
     * 失败关闭，不能从元数据或下载 Hook 推断歌单同步能力。
     */
    public function playlistSync(string $key): ExternalPlaylistSyncHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof ExternalPlaylistSyncHook
            || !in_array('playlist_sync', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_PLAYLIST_SYNC_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回最终专辑刮削钩子；插件内部必须完成来源聚合，核心只接收一条专辑结论。 */
    public function metadataAlbum(string $key): ExternalAlbumScrapeHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof ExternalAlbumScrapeHook
            || !in_array('metadata_album', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_ALBUM_SCRAPE_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回最终艺人资料图钩子；能力字符串与 PHP 接口必须同时满足。 */
    public function metadataArtistArtwork(string $key): ExternalArtistArtworkHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof ExternalArtistArtworkHook
            || !in_array('metadata_artist_artwork', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_ARTIST_ARTWORK_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回最终艺人文字资料钩子；能力字符串和 PHP 接口必须同时满足。 */
    public function metadataArtistProfile(string $key): ExternalArtistProfileHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof ExternalArtistProfileHook
            || !in_array('metadata_artist_profile', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_ARTIST_PROFILE_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /**
     * 返回元数据插件的私有后台管理钩子。
     *
     * `metadata_scrape` 只代表核心刮削调用能力，只有插件同时实现本接口时才开放数据源、任务和日志
     * 管理。因此旧版本插件可以继续被核心调用，但不会因缺少管理实现而被动态路由误调用。
     */
    public function metadataScrapeAdmin(string $key): MetadataScrapeAdminHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof MetadataScrapeAdminHook
            || !in_array('metadata_scrape', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_METADATA_SCRAPE_ADMIN_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /**
     * 返回插件自有后台页面可调用的下载钩子。
     *
     * 页面能力与补全能力分开声明，使 Jackett 等工作流可以保留自身的入库选项而不向补全 API 暴露租约。
     * 无页面能力、包状态变化或数据库版本不匹配时均失败关闭，不按方法名绕过 manifest。
     */
    public function adminDownload(string $key): ExternalDownloadHook
    {
        $plugin = $this->get($key);
        $capabilities = $plugin->descriptor()['capabilities'];
        if (!$plugin instanceof ExternalDownloadHook
            || !in_array('admin_page_download', $capabilities, true)) {
            throw new PhpResourcePluginInvalid();
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /**
     * 返回插件后台订阅钩子。
     *
     * 订阅是 Jackett 等插件的私有管理能力，不能从搜索或下载接口推断；能力字符串、接口和数据库账本
     * 必须同时通过校验，插件停用或升级状态不一致时立即失败关闭。
     */
    public function adminSubscription(string $key): AdminSubscriptionHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof AdminSubscriptionHook
            || !in_array('admin_page_subscription', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_SUBSCRIPTION_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回显式声明订阅诊断扩展的插件；普通订阅插件仍可只实现 AdminSubscriptionHook。 */
    public function adminSubscriptionDiagnostics(string $key): AdminSubscriptionDiagnosticsHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof AdminSubscriptionDiagnosticsHook
            || !in_array('admin_page_subscription', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_SUBSCRIPTION_DIAGNOSTICS_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /**
     * 返回显式实现人工重试扩展的下载钩子。
     *
     * 页面下载能力只证明插件能创建和列出任务，不能据此推断失败任务一定可重放。这里同时检查可选接口、
     * 能力声明和数据库账本；一次性下载器会失败关闭，不会通过动态方法名调用未知代码。返回实例仍由
     * Controller 注入实时 actor，插件必须继续执行目标库和任务级授权复验。
     */
    public function retryableDownload(string $key): RetryableExternalDownloadHook
    {
        $plugin = $this->get($key);
        $capabilities = $plugin->descriptor()['capabilities'];
        if (!$plugin instanceof RetryableExternalDownloadHook
            || !in_array('admin_page_download', $capabilities, true)) {
            throw new PhpResourcePluginInvalid();
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回显式声明批量下载记录清理能力的插件；只支持单条清理的插件不会被动态调用未知方法。 */
    public function bulkDownloadCleanup(string $key): BulkDownloadCleanupHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof BulkDownloadCleanupHook
            || !in_array('admin_page_download', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid();
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /** 返回后台消费钩子；核心通用 Worker 会在每轮调用前重新复验活动状态与数据库版本。 */
    public function worker(string $key): PluginWorkerHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof PluginWorkerHook || !in_array('worker', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid();
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    /**
     * 返回当前可由核心通用 Worker 调度的插件 key。
     *
     * 该投影只包含完整通过 manifest、接口和数据库账本校验且声明 `worker` 的活动包。调用方必须在
     * 非协程 timer 上下文执行，因为 list() 会读取 SQLite；结果不形成长期快照，安装和卸载标记会在
     * 下一轮重新观察。损坏或版本不匹配的插件失败关闭，不会被误当作空任务成功。
     *
     * @return list<string>
     */
    public function workerKeys(): array
    {
        $keys = [];
        foreach ($this->list() as $item) {
            if (($item['valid'] ?? false) === true
                && ($item['enabled'] ?? true) === true
                && in_array('worker', $item['capabilities'] ?? [], true)) {
                $keys[] = (string) $item['key'];
            }
        }
        return $keys;
    }

    /**
     * 返回声明独立媒体发布 Worker 的活动插件 key。
     *
     * 该列表与普通下载 Worker 分开，允许下载轮询和 FFmpeg/文件发布使用不同的进程数。每个 key 仍需
     * 同时通过 manifest、接口和数据库版本校验；缺失或停用插件不会被核心猜测为可发布任务。
     *
     * @return list<string>
     */
    public function transcodeWorkerKeys(): array
    {
        $keys = [];
        foreach ($this->list() as $item) {
            if (($item['valid'] ?? false) === true
                && ($item['enabled'] ?? true) === true
                && in_array('transcode_worker', $item['capabilities'] ?? [], true)) {
                $keys[] = (string) $item['key'];
            }
        }
        return $keys;
    }

    /** 返回已严格校验的独立媒体发布钩子。 */
    public function transcodeWorker(string $key): PluginTranscodeWorkerHook
    {
        $plugin = $this->get($key);
        if (!$plugin instanceof PluginTranscodeWorkerHook
            || !in_array('transcode_worker', $plugin->descriptor()['capabilities'], true)) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_TRANSCODE_WORKER_UNAVAILABLE');
        }
        $this->assertDatabaseCurrent($plugin, $key);
        return $plugin;
    }

    private function rootPath(): string
    {
        $root = rtrim($this->root ?? base_path('plugin'), DIRECTORY_SEPARATOR);
        if ($root === '' || $root[0] !== DIRECTORY_SEPARATOR) throw new PhpResourcePluginInvalid();
        return $root;
    }

    /**
     * 确认插件数据库已安装且版本与当前代码一致。
     *
     * 仅复制插件目录不会自动执行 DDL；请求和 Worker 在账本缺失或版本不一致时统一失败关闭。生命周期
     * 服务通过 get() 加载未安装插件，因此仍能执行首次安装，不形成“必须先安装才能安装”的循环依赖。
     */
    private function assertDatabaseCurrent(PhpResourcePlugin $plugin, string $key): void
    {
        if (!$plugin instanceof PluginDatabaseLifecycle) return;
        $state = $this->databaseState($plugin, $key);
        if (!$state['installed']) throw new PhpResourcePluginInvalid(
            $state['errorCode'] ?? 'PHP_PLUGIN_DATABASE_NOT_INSTALLED',
        );
    }

    /** @return array{installed:bool,version:?int,errorCode:?string} */
    private function databaseState(PhpResourcePlugin $plugin, string $key): array
    {
        if (!$plugin instanceof PluginDatabaseLifecycle) {
            return ['installed' => true, 'version' => null, 'errorCode' => null];
        }
        try {
            if (!\support\Db::connection()->getSchemaBuilder()->hasTable('php_resource_plugin_migrations')) {
                return ['installed' => false, 'version' => null, 'errorCode' => 'PHP_PLUGIN_CORE_MIGRATION_REQUIRED'];
            }
            $value = \support\Db::table('php_resource_plugin_migrations')->where('plugin_key', $key)
                ->value('database_version');
            if ($value === null) {
                return ['installed' => false, 'version' => null, 'errorCode' => 'PHP_PLUGIN_DATABASE_NOT_INSTALLED'];
            }
            $version = (int) $value;
            $current = $version === $plugin->databaseVersion();
            return ['installed' => $current, 'version' => $version,
                'errorCode' => $current ? null : 'PHP_PLUGIN_DATABASE_VERSION_MISMATCH'];
        } catch (Throwable) {
            return ['installed' => false, 'version' => null, 'errorCode' => 'PHP_PLUGIN_DATABASE_UNAVAILABLE'];
        }
    }

    /** 未执行插件代码时只读取通用账本版本；数据库不可用与未安装都投影为 null。 */
    private function ledgerVersion(string $key): ?int
    {
        try {
            if (!\support\Db::connection()->getSchemaBuilder()->hasTable('php_resource_plugin_migrations')) return null;
            $value = \support\Db::table('php_resource_plugin_migrations')->where('plugin_key', $key)
                ->value('database_version');
            return $value === null ? null : (int) $value;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 读取核心持久化的插件启用状态。
     *
     * 历史数据库或缩减单元测试没有状态表时保持升级兼容，解释为默认启用、版本 1；正式运行时迁移总在
     * Webman 启动前完成。状态行缺失同样代表从未切换过。表存在但读取异常时必须失败关闭为 disabled，
     * 防止 SQLite 故障把管理员已停用的受信代码重新暴露给页面、钩子或 Worker。该方法不执行插件代码。
     *
     * @return array{enabled:bool,version:int,errorCode:?string}
     */
    private function state(string $key): array
    {
        try {
            if (!\support\Db::connection()->getSchemaBuilder()->hasTable('php_resource_plugin_states')) {
                return ['enabled' => true, 'version' => 1, 'errorCode' => null];
            }
            $row = \support\Db::table('php_resource_plugin_states')->where('plugin_key', $key)
                ->first(['enabled', 'version']);
            if (!$row instanceof \stdClass) {
                return ['enabled' => true, 'version' => 1, 'errorCode' => null];
            }
            return ['enabled' => (int) $row->enabled === 1, 'version' => max(2, (int) $row->version),
                'errorCode' => null];
        } catch (Throwable) {
            return ['enabled' => false, 'version' => 1, 'errorCode' => 'PHP_PLUGIN_STATE_UNAVAILABLE'];
        }
    }

    /** @return array{manifest:array<string,mixed>,plugin:PhpResourcePlugin} */
    private function loadDirectory(string $directory): array
    {
        $this->loadCoreContracts();
        $packageRoot = realpath($directory);
        $manifestPath = $directory . '/config/resource_plugin.php';
        $manifestReal = realpath($manifestPath);
        if (!is_string($packageRoot) || !is_string($manifestReal) || is_link($manifestPath)
            || !str_starts_with($manifestReal, $packageRoot . DIRECTORY_SEPARATOR) || !is_file($manifestReal)) {
            throw new PhpResourcePluginInvalid();
        }
        $manifest = require $manifestReal;
        $keys = is_array($manifest) ? array_keys($manifest) : [];
        sort($keys);
        $allowedFields = [...self::REQUIRED_FIELDS, ...self::OPTIONAL_FIELDS];
        sort($allowedFields);
        if (!is_array($manifest) || array_diff($keys, $allowedFields) !== []
            || array_diff(self::REQUIRED_FIELDS, $keys) !== [] || $manifest['protocolVersion'] !== 1
            || !is_string($manifest['key']) || $manifest['key'] !== basename($directory)
            || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $manifest['key']) !== 1
            || !$this->text($manifest['name'] ?? null, 1, 120) || !$this->text($manifest['version'] ?? null, 1, 64)
            || !is_string($manifest['class']) || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{2,255}$/D', $manifest['class']) !== 1
            || !is_array($manifest['capabilities']) || !array_is_list($manifest['capabilities'])
            || $manifest['capabilities'] === [] || count(array_unique($manifest['capabilities'])) !== count($manifest['capabilities'])
            || array_diff($manifest['capabilities'], self::CAPABILITIES) !== []
            || !$this->validMetadata($manifest['metadata'] ?? null)
            || !$this->validAdminPage($packageRoot, $manifest['key'], $manifest['adminPage'] ?? null)) {
            throw new PhpResourcePluginInvalid();
        }
        $manifest['adminPage'] ??= null;
        $manifest['metadata'] ??= null;
        if ($manifest['adminPage'] === null
            && array_intersect($manifest['capabilities'], ['admin_page_search', 'admin_page_download']) !== []) {
            throw new PhpResourcePluginInvalid();
        }
        // 插件 key 可包含 URL 友好的连字符；PHP namespace 段必须把连字符确定性映射为下划线。
        $namespaceKey = str_replace('-', '_', $manifest['key']);
        $classPrefix = 'plugin\\' . $namespaceKey . '\\';
        if (!str_starts_with($manifest['class'], $classPrefix)) throw new PhpResourcePluginInvalid();
        $classPath = $packageRoot . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, substr($manifest['class'], strlen($classPrefix))) . '.php';
        $classReal = realpath($classPath);
        if (!is_string($classReal) || is_link($classPath) || !is_file($classReal)
            || !str_starts_with($classReal, $packageRoot . DIRECTORY_SEPARATOR)) throw new PhpResourcePluginInvalid();
        $loader = static function (string $class) use ($namespaceKey, $packageRoot): void {
            $prefix = 'plugin\\' . $namespaceKey . '\\';
            if (!str_starts_with($class, $prefix)
                || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D',
                    substr($class, strlen($prefix))) !== 1) return;
            $path = $packageRoot . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR,
                substr($class, strlen($prefix))) . '.php';
            $real = realpath($path);
            if (is_string($real) && !is_link($path) && is_file($real)
                && str_starts_with($real, $packageRoot . DIRECTORY_SEPARATOR)) require_once $real;
        };
        // 活动插件会在实例化后继续由搜索/下载/Worker 调用包内服务；若此处立即注销，懒加载的
        // application/* 类会在同一长驻进程中变成 Class not found。临时 staging/quarantine 根只能
        // 在当前生命周期使用，仍保持原来的 finally 注销语义。活动根按真实包路径去重，升级必须重启
        // 进程，因此不会让旧包加载器指向新包或跨包读取文件。
        $persistent = $this->isActiveRoot($packageRoot);
        if ($persistent) {
            self::registerPersistentLoader($packageRoot, $namespaceKey, $loader);
        } else {
            spl_autoload_register($loader, true, true);
        }
        try {
            if (!class_exists($manifest['class'], false)) require_once $classReal;
            $plugin = new $manifest['class']();
        } catch (Throwable $throwable) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_CLASS_INVALID', 0, $throwable);
        } finally {
            if (!$persistent) spl_autoload_unregister($loader);
        }
        if (!$plugin instanceof PhpResourcePlugin) throw new PhpResourcePluginInvalid();
        $descriptor = $plugin->descriptor();
        if (($descriptor['key'] ?? null) !== $manifest['key'] || ($descriptor['name'] ?? null) !== $manifest['name']
            || ($descriptor['version'] ?? null) !== $manifest['version']
            || ($descriptor['capabilities'] ?? null) !== $manifest['capabilities']
            || !$this->text($descriptor['description'] ?? null, 1, 500)) throw new PhpResourcePluginInvalid();
        foreach ($manifest['capabilities'] as $capability) {
            $valid = match ($capability) {
                'admin_page_search' => $plugin instanceof ExternalMusicSearchHook,
                'admin_page_download' => $plugin instanceof ExternalDownloadHook,
                'admin_page_subscription' => $plugin instanceof AdminSubscriptionHook,
                'external_music_completion' => $plugin instanceof ExternalMusicCompletionHook,
                'metadata_scrape' => $plugin instanceof ExternalMetadataScrapeHook,
                'metadata_album' => $plugin instanceof ExternalAlbumScrapeHook,
                'metadata_artist_artwork' => $plugin instanceof ExternalArtistArtworkHook,
                'metadata_artist_profile' => $plugin instanceof ExternalArtistProfileHook,
                'metadata_artist_database' => $plugin instanceof MetadataScrapeAdminHook,
                'playlist_identification' => $plugin instanceof ExternalPlaylistIdentificationHook,
                'playlist_sync' => $plugin instanceof ExternalPlaylistSyncHook,
                'worker' => $plugin instanceof PluginWorkerHook,
                'transcode_worker' => $plugin instanceof PluginTranscodeWorkerHook,
                'database_lifecycle' => $plugin instanceof PluginDatabaseLifecycle,
            };
            if (!$valid) throw new PhpResourcePluginInvalid();
        }
        return ['manifest' => $manifest, 'plugin' => $plugin];
    }

    /**
     * 在解析插件实现类前显式载入全部核心插件合同。
     *
     * 生产镜像启用了 Composer authoritative classmap；后台上传新插件时不能假设镜像曾经执行过
     * `composer dump-autoload`。如果插件实现了镜像构建后新增的合同，PHP 会在类声明阶段直接失败，注册表
     * 只能把完整插件投影成 `PHP_PLUGIN_MANIFEST_INVALID`，Worker 也会跳过它。这里只允许从核心固定目录
     * 加载接口文件，逐个检查接口是否已经存在并使用 `require_once`，不执行插件代码、不改变包加载器，且
     * 同一长驻进程重复发现时保持幂等。
     */
    private function loadCoreContracts(): void
    {
        $contracts = [
            AdminProviderHealthHook::class,
            AdminProviderLoginHook::class,
            AdminSubscriptionDiagnosticsHook::class,
            AdminSubscriptionHook::class,
            ExternalDownloadHook::class,
            ExternalMusicCompletionHook::class,
            ExternalMetadataScrapeHook::class,
            ExternalPlaylistIdentificationHook::class,
            ExternalPlaylistPluginRegistry::class,
            ExternalPlaylistSyncHook::class,
            ExternalAlbumScrapeHook::class,
            ExternalArtistArtworkHook::class,
            ExternalArtistProfileHook::class,
            MetadataScrapeAdminHook::class,
            ExternalMusicPluginRegistry::class,
            ExternalMusicSearchHook::class,
            PhpResourcePlugin::class,
            PluginDatabaseLifecycle::class,
            PluginWorkerHook::class,
            PluginTranscodeWorkerHook::class,
            RetryableExternalDownloadHook::class,
            TypedExternalMusicSearchHook::class,
        ];
        $contractRoot = base_path('app/application/ResourcePlugin/Contract');
        foreach ($contracts as $contract) {
            if (interface_exists($contract, false)) continue;
            $path = $contractRoot . DIRECTORY_SEPARATOR . basename(str_replace('\\', DIRECTORY_SEPARATOR, $contract)) . '.php';
            if (is_file($path) && !is_link($path)) require_once $path;
        }
    }

    /**
     * 为活动包登记一次按包根隔离的自动加载器。
     *
     * 注册表会被每个请求和 Worker 轮次重新实例化；使用函数静态表避免重复注册闭包。包目录一旦被
     * 替换就要求重启，旧 realpath 不会重新指向其他包；路径校验仍由闭包本身执行，不能加载包根外文件。
     */
    private static function registerPersistentLoader(string $packageRoot, string $namespaceKey, callable $loader): void
    {
        static $registered = [];
        $key = $packageRoot . "\0" . $namespaceKey;
        if (isset($registered[$key])) return;
        spl_autoload_register($loader, true, true);
        $registered[$key] = true;
    }

    /** 判断包是否位于当前活动插件根；安装临时目录绝不能留下长驻加载器。 */
    private function isActiveRoot(string $packageRoot): bool
    {
        $root = realpath($this->root ?? base_path('plugin'));
        return is_string($root) && dirname($packageRoot) === $root;
    }

    private function text(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') >= $minimum && mb_strlen($value, 'UTF-8') <= $maximum
            && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
    }

    /**
     * 校验插件公开介绍元数据。
     *
     * 元数据由受信插件包提供，但仍必须限制为固定的纯文本结构，避免插件把 HTML、脚本、凭据或任意
     * 网络资源注入宿主后台。缺失值代表旧版本插件，保持兼容；存在时字段、长度、编码和 HTTPS 外链均
     * 经过严格校验，失败关闭而不是部分展示。该信息只用于说明，不参与权限、搜索或下载决策。
     */
    private function validMetadata(mixed $metadata): bool
    {
        if ($metadata === null) return true;
        if (!is_array($metadata) || array_is_list($metadata)) return false;
        $keys = array_keys($metadata);
        sort($keys);
        if ($keys !== ['author', 'introduction', 'usage']
            || !$this->text($metadata['introduction'] ?? null, 1, 1000)) return false;

        $author = $metadata['author'] ?? null;
        if (!is_array($author) || array_is_list($author)) return false;
        $authorKeys = array_keys($author);
        sort($authorKeys);
        if ($authorKeys !== ['name', 'url'] || !$this->text($author['name'] ?? null, 1, 120)) return false;
        $url = $author['url'] ?? null;
        if ($url !== null && $url !== '' && (!is_string($url) || strlen($url) > 2048
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !str_starts_with(strtolower($url), 'https://'))) return false;

        $usage = $metadata['usage'] ?? null;
        if (!is_array($usage) || !array_is_list($usage) || $usage === [] || count($usage) > 12) return false;
        foreach ($usage as $step) {
            if (!$this->text($step, 1, 500)) return false;
        }
        return true;
    }

    /**
     * 校验插件自有后台页面声明。
     *
     * 核心固定管理路由，插件不能覆盖任意后台页面；入口只能是 `public/` 下的普通 HTML 文件，真实路径
     * 必须仍位于包根且不能经过链接。标题和说明进入核心导航，因此执行与 manifest 其他文本相同的
     * UTF-8、长度和控制字符约束。首版只允许 `manage_system`，避免插件自行创造权限含义。
     */
    private function validAdminPage(string $packageRoot, string $key, mixed $page): bool
    {
        if ($page === null) return true;
        if (!is_array($page) || array_is_list($page)) return false;
        $keys = array_keys($page);
        sort($keys);
        if ($keys !== ['capability', 'description', 'entry', 'path', 'title']
            || $page['path'] !== '/admin/plugins/' . $key
            || $page['capability'] !== 'manage_system'
            || !$this->text($page['title'] ?? null, 1, 80)
            || !$this->text($page['description'] ?? null, 1, 180)
            || !is_string($page['entry'])
            || preg_match('~^public/(?:[A-Za-z0-9][A-Za-z0-9._-]*/)*[A-Za-z0-9][A-Za-z0-9._-]*\.html$~D', $page['entry']) !== 1) {
            return false;
        }
        $entryPath = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $page['entry']);
        $entryReal = realpath($entryPath);
        return is_string($entryReal) && is_file($entryReal) && !is_link($entryPath)
            && str_starts_with($entryReal, $packageRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
    }

    /**
     * 将已校验 manifest 投影为浏览器可见清单。
     *
     * 只返回插件身份、能力、页面入口和非敏感说明元数据；物理路径、实现类和插件配置永远不出现在
     * 响应中。旧包没有 metadata 时统一返回 null，前端可据此隐藏说明区域。
     *
     * @return array{key:string,name:string,version:string,protocolVersion:int,capabilities:list<string>,adminPage:?array<string,string>,metadata:?array<string,mixed>}
     */
    private function publicManifest(array $manifest): array
    {
        return ['key' => $manifest['key'], 'name' => $manifest['name'], 'version' => $manifest['version'],
            'protocolVersion' => $manifest['protocolVersion'], 'capabilities' => $manifest['capabilities'],
            'adminPage' => $manifest['adminPage'] === null ? null
                : $this->publicAdminPage($manifest['key'], $manifest['adminPage']),
            'metadata' => $this->publicMetadata($manifest['metadata']),];
    }

    /** 只投影经过 manifest 校验的固定元数据字段，避免未来内部字段意外泄露。 */
    private function publicMetadata(?array $metadata): ?array
    {
        if ($metadata === null) return null;
        return ['introduction' => $metadata['introduction'],
            'author' => ['name' => $metadata['author']['name'], 'url' => $metadata['author']['url']],
            'usage' => array_values($metadata['usage'])];
    }

    /** @return array{path:string,title:string,description:string,entryUrl:string,capability:string} */
    private function publicAdminPage(string $key, array $page): array
    {
        return ['path' => $page['path'], 'title' => $page['title'], 'description' => $page['description'],
            'entryUrl' => '/api/v1/admin/resource-plugins/' . rawurlencode($key) . '/page/'
                . implode('/', array_map('rawurlencode', explode('/', substr($page['entry'], strlen('public/'))))),
            'capability' => $page['capability']];
    }
}
