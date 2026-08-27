<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\ResourcePlugin\PhpResourcePluginInvalid;
use app\application\ResourcePlugin\PhpResourcePluginNotFound;
use app\application\ResourcePlugin\PhpResourcePluginOperationConflict;
use app\application\ResourcePlugin\PhpResourcePluginPackageConflict;
use app\application\ResourcePlugin\PhpResourcePluginPackageService;
use app\application\ResourcePlugin\PhpResourcePluginPackageUnavailable;
use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use app\application\ResourcePlugin\PhpResourcePluginDisabled;
use app\application\ResourcePlugin\PhpResourcePluginStateService;
use app\application\ResourcePlugin\ResourcePluginManager;
use app\application\System\ArtistDatabaseConflict;
use app\application\System\ArtistDatabaseInvalid;
use app\application\System\ArtistDatabaseUnavailable;
use app\application\System\ArtistDatabaseUploadNotFound;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Request;
use support\Response;
use Throwable;
use Webman\Http\UploadFile;

/**
 * 暴露同源后台专用的插件安装、卸载、目录与自有页面资源 API。
 *
 * Velin 只保留一种管理员审查后的受信 PHP 插件。核心提供固定搜索、下载和 Worker 分发协议，但不理解
 * Jackett、LX Music 等具体业务；插件安装后无需让 Webman 重新扫描路由或进程配置。所有入口拒绝
 * Authorization header，只接受实时 Cookie Session 和 `manage_system`；写操作另由路由绑定 CSRF。
 * 响应不包含插件物理路径、实现类、PHP 源码、资源引用、第三方秘密或异常正文。
 */
final class ResourcePluginController
{
    /** 返回当前安装插件及其可选后台页面投影。 */
    public function index(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new ResourcePluginManager())->list());
    }

    /** 返回插件升级备份的版本清单，不执行备份代码。 */
    public function backups(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array => (new PhpResourcePluginPackageService())->backups($pluginKey));
    }

    /** 清理指定插件的旧升级备份；活动包、数据库和媒体不在操作范围。 */
    public function purgeBackups(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_diff(array_keys($payload), ['keep']) !== []
                || !is_int($payload['keep'] ?? null)) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginPackageService())->purgeBackups($pluginKey, $payload['keep']);
        });
    }

    /**
     * 上传、校验并安装一个受信 PHP 插件 ZIP。
     *
     * 文件在实时权限与 CSRF 通过后才交给包管理器；上传名只用于 `.zip` 扩展名校验，不作为目录或插件
     * key。首次安装在数据库初始化和包原子发布后立即由核心固定 API 与通用 Worker 发现；同 key 升级
     * 因长驻进程可能已加载旧依赖类，仍明确返回 restartRequired=true 并在重启前失败关闭。
     */
    public function installPhp(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $file = $request->file('file');
            if (!$file instanceof UploadFile || !$file->isValid()) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginPackageService())->install(
                $file->getPathname(), (string) $file->getUploadName(), (string) $actor['id'],
                RequestContext::requestId(),
            );
        }, 201);
    }

    /**
     * 使用精确 key 确认并登记两阶段卸载。
     *
     * HTTP 阶段只原子停用插件并写待卸载标记，不删除仍可能被旧 Worker 使用的表或文件；启动期会在所有
     * Worker 前执行插件数据库清理。重复确认保持幂等，错误确认词不会产生任何副作用。
     */
    public function uninstallPhp(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['confirmation']
                || !is_string($payload['confirmation'])) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginPackageService())->requestUninstall(
                $pluginKey, $payload['confirmation'], (string) $actor['id'], RequestContext::requestId(),
            );
        });
    }

    /**
     * 启用或停用一个仍保留安装包和数据库的插件。
     *
     * 请求必须携带目录投影中的 expectedVersion，避免两个后台页面互相覆盖。停用只阻止后续页面、钩子和
     * Worker 领取，不删除配置、任务、凭据、工作区或媒体；已经进入调用栈的操作仍按原事务和文件边界
     * 收口。重新启用会在状态写入前复验包、manifest 与数据库版本。Session、manage_system 和 CSRF
     * 继续由核心固定边界执行，插件代码不能自行恢复启用状态。
     */
    public function updatePhpState(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new PhpResourcePluginInvalid();
            $keys = array_keys($payload);
            sort($keys);
            if ($keys !== ['enabled', 'expectedVersion']
                || !is_bool($payload['enabled'] ?? null)
                || !is_int($payload['expectedVersion'] ?? null)) {
                throw new PhpResourcePluginInvalid('PHP_PLUGIN_STATE_REQUEST_INVALID');
            }
            return (new PhpResourcePluginStateService())->update(
                $pluginKey,
                $payload['enabled'],
                $payload['expectedVersion'],
                (string) $actor['id'],
                RequestContext::requestId(),
            );
        });
    }

    /** 返回插件搜索连接的脱敏状态；插件 key 只用于注册表查找，不能转换为任意类名或路径。 */
    public function searchStatus(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->adminSearch($pluginKey)->searchStatus());
    }

    /** 返回插件搜索配置快照；秘密只能由插件投影为 configured 等非敏感事实。 */
    public function searchConfiguration(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->adminSearch($pluginKey)->searchConfiguration());
    }

    /** 返回插件渠道健康快照。 */
    public function providerHealth(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->providerHealth($pluginKey)->providerHealth());
    }

    /** 对指定渠道执行固定有界探测，不接受外部搜索词或凭据。 */
    public function testProvider(Request $request, string $pluginKey, string $providerKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey, $providerKey): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->providerHealth($pluginKey)
                ->testProvider($providerKey, $actor, RequestContext::requestId());
        });
    }

    /**
     * 保存插件搜索配置。
     *
     * payload 必须是 JSON 对象并由插件按自己的版本化白名单再次校验；核心只注入实时管理员身份和请求
     * ID，不允许页面指定 actor。CSRF 在固定路由层执行，插件不得在数据库事务中进行网络探测。
     */
    public function updateSearchConfiguration(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->adminSearch($pluginKey)->updateSearchConfiguration(
                $payload, $actor, RequestContext::requestId(),
            );
        });
    }

    /**
     * 执行一次有界外部音乐搜索。
     *
     * 核心固定只接受 query 与可选 limit，搜索词不进入 URL；插件返回值必须遵守租约投影合同，浏览器
     * 永远不能获得 URL、磁力、torrent、Cookie 或签名上下文。上游失败不会回显搜索词或异常正文。
     */
    public function search(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new PhpResourcePluginInvalid();
            $keys = array_keys($payload);
            sort($keys);
            if (!in_array($keys, [['query'], ['limit', 'query']], true)
                || !is_string($payload['query'] ?? null)
                || (isset($payload['limit']) && !is_int($payload['limit']))) {
                throw new PhpResourcePluginInvalid();
            }
            return (new PhpResourcePluginRegistry())->adminSearch($pluginKey)->search(
                $payload['query'], $payload['limit'] ?? 30, (string) $actor['id'],
            );
        });
    }

    /**
     * 启动插件固定渠道的账号登录。
     *
     * 请求只能指定插件目录声明的登录类型，渠道来自路由白名单段；插件返回值不得包含 Cookie、第三方
     * key 或任意 Header。核心不代理第三方 URL，也不把登录能力推断为所有搜索插件都支持。
     */
    public function startProviderLogin(Request $request, string $pluginKey, string $providerKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey, $providerKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['loginType']
                || !is_string($payload['loginType'])) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->providerLogin($pluginKey)->startProviderLogin(
                $providerKey, $payload['loginType'], $actor, RequestContext::requestId(),
            );
        });
    }

    /**
     * 检查当前管理员持有的 opaque 登录会话。
     *
     * 浏览器不能提交 Cookie、二维码 key 或替代 actor；插件必须复验会话所有者、渠道、到期时间和并发
     * 领取状态。成功响应只返回状态，随后页面重新读取脱敏渠道目录。
     */
    public function checkProviderLogin(Request $request, string $pluginKey, string $providerKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey, $providerKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['sessionId']
                || !is_string($payload['sessionId'])) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->providerLogin($pluginKey)->checkProviderLogin(
                $providerKey, $payload['sessionId'], $actor, RequestContext::requestId(),
            );
        });
    }

    /** 返回插件下载器的脱敏连接状态，不返回远端任务、SID、密码或内容路径。 */
    public function downloadStatus(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->adminDownload($pluginKey)->downloadStatus());
    }

    /** 返回插件下载器配置快照；所有秘密字段必须保持只写。 */
    public function downloadConfiguration(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->adminDownload($pluginKey)->downloadConfiguration());
    }

    /** 使用实时管理员身份和请求 ID 保存插件下载配置；插件负责字段白名单与乐观版本锁。 */
    public function updateDownloadConfiguration(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->adminDownload($pluginKey)->updateDownloadConfiguration(
                $payload, $actor, RequestContext::requestId(),
            );
        });
    }

    /** 返回当前管理员可用的下载目标和插件固定选项，不投影物理路径。 */
    public function downloadOptions(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (array $actor): array =>
            (new PhpResourcePluginRegistry())->adminDownload($pluginKey)->downloadOptions($actor));
    }

    /** 返回插件自有订阅规则和不含路径/秘密的表单选项。 */
    public function subscriptions(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (array $actor): array =>
            (new PhpResourcePluginRegistry())->adminSubscription($pluginKey)->subscriptions($actor));
    }

    /** 创建插件订阅规则；具体字段由插件白名单校验，核心不解释 PT 语义。 */
    public function createSubscription(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->adminSubscription($pluginKey)->createSubscription(
                $payload, $actor, RequestContext::requestId(),
            );
        }, 201);
    }

    /** 使用插件自己的 expectedVersion 合同更新订阅规则。 */
    public function updateSubscription(Request $request, string $pluginKey, string $subscriptionId): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey, $subscriptionId): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->adminSubscription($pluginKey)->updateSubscription(
                $subscriptionId, $payload, $actor, RequestContext::requestId(),
            );
        });
    }

    /** 删除订阅规则；插件不得级联删除已提交下载任务或已发布媒体。 */
    public function deleteSubscription(Request $request, string $pluginKey, string $subscriptionId): Response
    {
        return $this->execute($request, static function (array $actor) use ($pluginKey, $subscriptionId): array {
            return (new PhpResourcePluginRegistry())->adminSubscription($pluginKey)->deleteSubscription(
                $subscriptionId, $actor, RequestContext::requestId(),
            );
        });
    }

    /** 把订阅标记为立即检查；实际网络请求由通用插件 Worker 消费。 */
    public function runSubscription(Request $request, string $pluginKey, string $subscriptionId): Response
    {
        return $this->execute($request, static function (array $actor) use ($pluginKey, $subscriptionId): array {
            return (new PhpResourcePluginRegistry())->adminSubscription($pluginKey)->runSubscription(
                $subscriptionId, $actor, RequestContext::requestId(),
            );
        });
    }

    /** 返回订阅运行历史和脱敏统计。 */
    public function subscriptionRuns(Request $request, string $pluginKey, string $subscriptionId): Response
    {
        return $this->execute($request, static fn (array $actor): array =>
            (new PhpResourcePluginRegistry())->adminSubscriptionDiagnostics($pluginKey)
                ->subscriptionRuns($subscriptionId, $actor, 20));
    }

    /** 试跑订阅规则；请求只执行受控搜索，不提交下载器。 */
    public function trialSubscription(Request $request, string $pluginKey, string $subscriptionId): Response
    {
        return $this->execute($request, static function (array $actor) use ($pluginKey, $subscriptionId, $request): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->adminSubscriptionDiagnostics($pluginKey)
                ->trialSubscription($subscriptionId, $actor, RequestContext::requestId());
        });
    }

    /**
     * 从插件签发的服务端租约创建耐久下载任务。
     *
     * 命令是 JSON 对象且由插件严格复验；协议禁止 URL、磁力、torrent、InfoHash、保存目录和下载器参数。
     * 插件必须把同一租约视为幂等键，HTTP 请求只提交任务，不执行下载或文件入库。
     */
    public function createDownload(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->adminDownload($pluginKey)->createDownload(
                $payload, $actor, RequestContext::requestId(),
            );
        });
    }

    /**
     * 调用插件通用单曲补全 API。
     *
     * 请求只允许 title、artist、可选 album 和已授权 libraryId；插件返回的是不透明任务句柄，不返回搜索
     * 列表。HTTP 200 只表示任务已排队，是否成功必须继续读取 completionStatus，不能把创建记录当成成功。
     */
    public function completeMusic(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->completion($pluginKey)->completeMusic(
                $payload, $actor, RequestContext::requestId(),
            );
        });
    }

    /** 返回单个通用补全任务的最终状态和脱敏进度日志。 */
    public function musicCompletionStatus(Request $request, string $pluginKey, string $taskId): Response
    {
        return $this->execute($request, static fn (array $actor): array =>
            (new PhpResourcePluginRegistry())->completion($pluginKey)->completionStatus($taskId, $actor));
    }

    /** 返回元数据插件自有来源目录；核心不再读取或投影固定来源表。 */
    public function metadataSources(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)->metadataSources());
    }

    /** 更新插件来源的启用状态和优先级；插件负责版本 CAS 与字段白名单。 */
    public function updateMetadataSource(Request $request, string $pluginKey, string $sourceKey): Response
    {
        return $this->execute($request, static function () use ($request, $pluginKey, $sourceKey): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)
                ->updateMetadataSource($sourceKey, $payload);
        });
    }

    /**
     * 返回插件刮削任务的脱敏分页列表。
     *
     * Cookie Session 与 `manage_system` 仍由统一执行边界校验。核心只接受十进制非负 offset 和 1-50 的
     * limit，并在调用插件前校验状态枚举，防止插件实现差异把无效过滤静默解释成全量查询。插件负责返回
     * 精确 total 和归一化 offset；响应不包含核心任务、媒体路径或第三方来源标识。
     */
    public function metadataTasks(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function () use ($request, $pluginKey): array {
            $status = $request->get('status');
            if ($status !== null && $status !== ''
                && (!is_string($status) || !in_array($status,
                    ['running', 'matched', 'unmatched', 'unavailable', 'failed'], true))) {
                throw new PhpResourcePluginInvalid('METADATA_SCRAPE_TASK_FILTER_INVALID');
            }
            $query = [
                'limit' => self::boundedQueryInteger($request->get('limit'), 20, 1, 50),
                'offset' => self::boundedQueryInteger($request->get('offset'), 0, 0, 1_000_000),
            ];
            if (is_string($status) && $status !== '') $query['status'] = $status;
            return (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)->metadataTasks($query);
        });
    }

    /**
     * 清理元数据插件的终态任务记录；请求体必须为空，running 任务、核心任务和媒体数据均不受影响。
     */
    public function clearMetadataTasks(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function () use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)->clearMetadataTasks();
        });
    }

    /** 返回单个插件刮削任务及其执行阶段时间线日志；插件内部来源键不会进入 HTTP 投影。 */
    public function metadataTask(Request $request, string $pluginKey, string $taskId): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)->metadataTask($taskId));
    }

    /**
     * 重试元数据插件的失败任务。
     *
     * 请求体固定为空 JSON 对象，任务身份只能来自 opaque taskId；Session、manage_system、插件活动状态
     * 和 CSRF 由统一边界校验。插件复用原任务记录并把新的尝试写入同一日志序列。
     */
    public function retryMetadataTask(Request $request, string $pluginKey, string $taskId): Response
    {
        return $this->execute($request, static function () use ($request, $pluginKey, $taskId): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)->retryMetadataTask($taskId);
        });
    }

    /** 返回元数据插件自有艺人辅助 SQLite 的脱敏状态和可恢复上传偏移。 */
    public function artistDatabaseStatus(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)->artistDatabaseStatus());
    }

    /** 创建插件管理的唯一艺人库分块上传会话。 */
    public function artistDatabaseUpload(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function () use ($request, $pluginKey): array {
            $payload = $request->post();
            $fileName = is_array($payload) ? ($payload['fileName'] ?? null) : null;
            $totalBytes = is_array($payload) ? ($payload['totalBytes'] ?? null) : null;
            if (!is_string($fileName) || !is_int($totalBytes)) throw new ArtistDatabaseInvalid('INVALID_COMMAND');
            return (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)
                ->startArtistDatabaseUpload($fileName, $totalBytes);
        }, 201);
    }

    /** 接收插件艺人库的一个连续原始分块。 */
    public function artistDatabaseChunk(Request $request, string $pluginKey, string $uploadId, string $offset): Response
    {
        return $this->execute($request, static function () use ($request, $pluginKey, $uploadId, $offset): array {
            if (preg_match('/^(0|[1-9][0-9]{0,12})$/', $offset) !== 1) {
                throw new ArtistDatabaseInvalid('INVALID_OFFSET');
            }
            return (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)->appendArtistDatabaseChunk(
                $uploadId, (int) $offset, $request->rawBody(), strtolower((string) $request->header('x-chunk-sha256', '')),
            );
        });
    }

    /** 完成插件艺人库完整性校验并原子发布。 */
    public function artistDatabaseComplete(Request $request, string $pluginKey, string $uploadId): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)->completeArtistDatabaseUpload($uploadId));
    }

    /** 取消插件艺人库上传会话，不影响当前已发布库。 */
    public function artistDatabaseCancel(Request $request, string $pluginKey, string $uploadId): Response
    {
        return $this->execute($request, static function () use ($pluginKey, $uploadId): array {
            (new PhpResourcePluginRegistry())->metadataScrapeAdmin($pluginKey)->cancelArtistDatabaseUpload($uploadId);
            return ['cancelled' => true];
        });
    }

    /** 返回插件最近下载任务的脱敏投影；limit 由核心固定，页面不能扩大查询范围。 */
    public function downloadJobs(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->adminDownload($pluginKey)->downloadJobs(50));
    }

    /**
     * 请求插件重新排队一个可恢复的失败下载任务。
     *
     * 请求正文必须为空，浏览器只能指定 opaque 任务 ID，不能替换原租约、URL、音质、目标库或路径。
     * 核心先完成 Cookie Session、`manage_system` 与 CSRF 校验，再把实时 actor 交给可选重试钩子；插件
     * 不实现该钩子、数据库版本不一致或任务状态不允许重放时统一失败关闭。
     */
    public function retryDownload(Request $request, string $pluginKey, string $jobId): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey, $jobId): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->retryableDownload($pluginKey)->retryDownload(
                $jobId, $actor, RequestContext::requestId(),
            );
        });
    }

    /** 返回插件最近下载诊断的脱敏投影；核心固定查询上限，禁止页面读取原始第三方响应。 */
    public function downloadDiagnosticLogs(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static fn (): array =>
            (new PhpResourcePluginRegistry())->retryableDownload($pluginKey)->downloadDiagnosticLogs(100));
    }

    /** 清空插件自有诊断日志；空请求体、实时权限、CSRF 和数据库版本均由固定边界校验。 */
    public function clearDownloadDiagnosticLogs(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->retryableDownload($pluginKey)
                ->clearDownloadDiagnosticLogs($actor, RequestContext::requestId());
        });
    }

    /** 删除插件拥有的一条精确诊断日志；请求不能携带删除条件或文件参数。 */
    public function clearDownloadDiagnosticLog(Request $request, string $pluginKey, string $logId): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey, $logId): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->retryableDownload($pluginKey)
                ->clearDownloadDiagnosticLog($logId, $actor, RequestContext::requestId());
        });
    }

    /**
     * 清理单条终态下载记录。
     *
     * 请求正文必须为空，浏览器不能要求删除媒体或指定路径。插件负责实时目标库授权、终态 CAS、受控
     * staging 所有权和租约引用清理；成功入库文件及核心扫描历史不随记录删除。
     */
    public function clearDownloadJob(Request $request, string $pluginKey, string $jobId): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey, $jobId): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->retryableDownload($pluginKey)->clearDownloadJob(
                $jobId, $actor, RequestContext::requestId(),
            );
        });
    }

    /**
     * 清空插件全部可管理的终态下载记录。
     *
     * 请求正文必须为空；活动任务、未知暂存内容、已发布媒体、核心扫描事实和审计不会被删除。
     * 具体的库范围、暂存所有权、终态 CAS 和级联诊断清理由插件领域服务负责。
     */
    public function clearDownloadJobs(Request $request, string $pluginKey): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $pluginKey): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new PhpResourcePluginInvalid();
            return (new PhpResourcePluginRegistry())->bulkDownloadCleanup($pluginKey)->clearDownloadJobs(
                $actor, RequestContext::requestId(),
            );
        });
    }

    /**
     * 在核心鉴权边界内发送插件自有后台页面资源。
     *
     * 插件必须处于活动状态且数据库版本与代码一致。请求路径逐段拒绝空值、`.`、`..`、反斜杠和控制
     * 字符，再用 realpath 证明目标位于插件 `public` 根；PHP、source map 和未知类型永不发送。HTML 使用
     * no-store 与同源 CSP，静态哈希资源只允许私有缓存。该端点允许同源 iframe 与插件自己的同源 API
     * 通信，但这不构成沙箱：已安装 PHP 包本身就是部署者信任的进程内代码。
     */
    public function pageAsset(Request $request, string $pluginKey, string $assetPath): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $this->authorize($request);
            $page = (new PhpResourcePluginRegistry())->adminPage($pluginKey);
            if ($page === null) throw new PhpResourcePluginNotFound();
            $path = $this->assetPath($page['publicRoot'], $assetPath);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeTypes = [
                'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8',
                'js' => 'text/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8',
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
                'svg' => 'image/svg+xml', 'woff2' => 'font/woff2',
            ];
            if (!isset($mimeTypes[$extension])) throw new PhpResourcePluginNotFound();
            $headers = [
                'Content-Type' => $mimeTypes[$extension], 'X-Content-Type-Options' => 'nosniff',
                'X-Request-ID' => $requestId,
                'Cache-Control' => $extension === 'html' ? 'private, no-store' : 'private, max-age=31536000, immutable',
            ];
            if ($extension === 'html') {
                $headers['Content-Security-Policy'] = "default-src 'self'; base-uri 'none'; form-action 'self'; "
                    . "frame-ancestors 'self'; object-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                    . "img-src 'self' data:; font-src 'self'; connect-src 'self'";
                $webServiceId = $request->get('__velin_web_service');
                if (is_string($webServiceId) && preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
                    $webServiceId,
                ) === 1) {
                    return response(
                        $this->htmlWithWebServiceRoute($path, $webServiceId),
                        200,
                        $headers,
                    );
                }
            }
            return response('', 200, $headers)->withFile($path);
        } catch (Throwable $throwable) {
            if ($throwable instanceof PhpResourcePluginInvalid) {
                return JsonResponseFactory::error(
                    'PLUGIN_PAGE_UNAVAILABLE', '插件页面暂时不可用。', 503, $requestId,
                );
            }
            return $this->error($throwable, $requestId);
        }
    }

    /** 统一 JSON 操作的 Session 权限与稳定错误映射。 */
    private function execute(Request $request, callable $operation, int $successStatus = 200): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->authorize($request);
            return JsonResponseFactory::create(['data' => $operation($actor), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], $successStatus, $requestId);
        } catch (Throwable $throwable) {
            return $this->error($throwable, $requestId);
        }
    }

    /** @return array<string,mixed> */
    private function authorize(Request $request): array
    {
        if (trim((string) $request->header('authorization')) !== '') throw new AuthorizationDenied();
        return (new AuthorizationService())->requireCapability($request, 'manage_system');
    }

    /** 把相对 URL 路径收敛到已校验的插件 public 根。 */
    private function assetPath(string $publicRoot, string $assetPath): string
    {
        if ($assetPath === '' || str_contains($assetPath, '\\') || str_contains($assetPath, "\0")) {
            throw new PhpResourcePluginNotFound();
        }
        $segments = explode('/', $assetPath);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new PhpResourcePluginNotFound();
        }
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $segment) !== 1) {
                throw new PhpResourcePluginNotFound();
            }
        }
        $candidate = $publicRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $real = realpath($candidate);
        if (!is_string($real) || !is_file($real) || is_link($candidate)
            || !str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR)) throw new PhpResourcePluginNotFound();
        return $real;
    }

    /**
     * 为远程 Web Service 代理保留页面资源路由标识。
     *
     * 只有已经通过 UUID 白名单的核心查询参数会进入 URL；HTML 使用 DOM 解析，只修改同源 script/src 与
     * link/href 的根绝对路径，不拼接标签或执行插件脚本。普通部署不调用本方法。读取失败、无效 HTML 或
     * DOM 序列化失败均失败关闭为插件页暂不可用，不回退到会请求错误代理根的原文件。
     */
    private function htmlWithWebServiceRoute(string $path, string $webServiceId): string
    {
        $html = file_get_contents($path);
        if (!is_string($html) || strlen($html) > 1024 * 1024) throw new PhpResourcePluginInvalid();
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML($html, LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
                throw new PhpResourcePluginInvalid();
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $suffix = '?__velin_web_service=' . rawurlencode($webServiceId);
        foreach ([['script', 'src'], ['link', 'href']] as [$tag, $attribute]) {
            foreach ($document->getElementsByTagName($tag) as $element) {
                $url = $element->getAttribute($attribute);
                if (str_starts_with($url, '/api/v1/admin/resource-plugins/')) {
                    $element->setAttribute($attribute, $url . $suffix);
                }
            }
        }
        $rendered = $document->saveHTML();
        if (!is_string($rendered) || $rendered === '') throw new PhpResourcePluginInvalid();
        return str_starts_with(strtolower(ltrim($rendered)), '<!doctype')
            ? $rendered
            : "<!doctype html>\n" . $rendered;
    }

    /**
     * 解析管理端 GET 分页整数，并拒绝模糊数值表示。
     *
     * Webman 查询参数通常为字符串；这里只接受无符号十进制或已经是整数的测试输入，不接受小数、指数、
     * 符号和空白。缺失值使用固定默认值，越界直接返回 422 而不是静默截断，避免页面误以为请求页与实际页
     * 一致。该方法不读取业务数据，也不修改请求。
     */
    private static function boundedQueryInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') return $default;
        if (is_int($value)) $parsed = $value;
        elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) $parsed = (int) $value;
        else throw new PhpResourcePluginInvalid('PHP_PLUGIN_PAGINATION_INVALID');
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new PhpResourcePluginInvalid('PHP_PLUGIN_PAGINATION_INVALID');
        }
        return $parsed;
    }

    /** 对文件与 JSON 请求使用同一脱敏错误合同。 */
    private function error(Throwable $throwable, string $requestId): Response
    {
        $error = match (true) {
            $throwable instanceof AuthenticationRequired => ['AUTHENTICATION_REQUIRED', '请先登录。', 401],
            $throwable instanceof AuthorizationDenied => ['PERMISSION_DENIED', '没有管理插件的权限。', 403],
            $throwable instanceof PhpResourcePluginDisabled => ['PLUGIN_DISABLED', '插件已禁用。', 409],
            $throwable instanceof PhpResourcePluginNotFound => ['PLUGIN_NOT_FOUND', '插件或页面资源不存在。', 404],
            $throwable instanceof PhpResourcePluginPackageConflict => ['PLUGIN_PACKAGE_CONFLICT', '插件包状态冲突。', 409],
            $throwable instanceof PhpResourcePluginOperationConflict => ['PLUGIN_OPERATION_CONFLICT', '插件任务状态已变化，请刷新后重试。', 409],
            $throwable instanceof PhpResourcePluginInvalid,
                $throwable instanceof \InvalidArgumentException => ['PLUGIN_REQUEST_INVALID', '插件请求无效。', 422],
            $throwable instanceof PhpResourcePluginPackageUnavailable => ['PLUGIN_STORAGE_UNAVAILABLE', '插件存储暂时不可用。', 507],
            $throwable instanceof ArtistDatabaseUploadNotFound => ['ARTIST_DATABASE_UPLOAD_NOT_FOUND', '上传会话不存在或已过期。', 404],
            $throwable instanceof ArtistDatabaseConflict => ['ARTIST_DATABASE_UPLOAD_CONFLICT', '上传状态已变化，请刷新后重试。', 409],
            $throwable instanceof ArtistDatabaseInvalid => ['ARTIST_DATABASE_INVALID', '文件不是符合要求的艺人 SQLite 数据库。', 422],
            $throwable instanceof ArtistDatabaseUnavailable => ['ARTIST_DATABASE_STORAGE_UNAVAILABLE', '艺人库目录暂时不可用。', 507],
            default => ['PLUGIN_OPERATION_FAILED', '插件操作暂时不可用。', 503],
        };
        return JsonResponseFactory::error($error[0], $error[1], $error[2], $requestId);
    }
}
