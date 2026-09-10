<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\System\BasicSystemSettingsConflict;
use app\application\System\BasicSystemSettingsInvalid;
use app\application\System\BasicSystemSettingsService;
use app\application\System\BasicSystemSettingsUnavailable;
use app\application\System\SystemLimitSettingsConflict;
use app\application\System\SystemLimitSettingsInvalid;
use app\application\System\SystemLimitSettingsService;
use app\application\System\SystemLimitSettingsUnavailable;
use app\application\System\NetworkProxySettingsConflict;
use app\application\System\NetworkProxySettingsInvalid;
use app\application\System\NetworkProxySettingsService;
use app\application\System\NetworkProxySettingsUnavailable;
use app\application\System\DlnaSettingsConflict;
use app\application\System\DlnaSettingsInvalid;
use app\application\System\DlnaSettingsService;
use app\application\System\DlnaSettingsUnavailable;
use app\application\Dlna\DlnaHelperSupervisor;
use app\application\Airplay\AirplayCompanionSupervisor;
use app\application\System\AirplaySettingsConflict;
use app\application\System\AirplaySettingsInvalid;
use app\application\System\AirplaySettingsService;
use app\application\System\AirplaySettingsUnavailable;
use app\application\System\RuntimeMaintenanceInvalid;
use app\application\System\RuntimeMaintenanceService;
use app\application\System\RuntimeMaintenanceUnavailable;
use app\application\Metadata\MetadataScrapePolicyConflict;
use app\application\Metadata\MetadataScrapePolicyInvalid;
use app\application\Metadata\MetadataScrapePolicyService;
use app\application\Metadata\MetadataScrapePolicyUnavailable;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 将基础设置、全局限制和固定运行维护领域服务映射到管理员 HTTP API。
 *
 * 所有请求先实时要求 `manage_system`；PUT/POST 写请求还由路由级 CSRF 中间件保护。Controller 只负责认证、输入
 * 映射和稳定 HTTP 错误，不读取部署变量、密钥、服务器路径或历史值，也不在异常响应中返回底层消息。
 */
final class SystemSettingsController
{
    /**
     * 返回主程序统一的刮削字段优先级与繁转简策略。
     *
     * 授权在读取设置前完成；响应包含完整固定字段与版本，供弹窗执行 CAS 更新。该读取不启动刮削、不
     * 重算历史数据，也不暴露插件私有配置或音频标签原始映射。
     */
    public function showMetadataScrapePolicy(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new MetadataScrapePolicyService())->get());
    }

    /**
     * 原子保存完整刮削策略。
     *
     * actor 仅来自实时 Session，PUT 由路由 CSRF 中间件保护。服务会拒绝缺字段、未知枚举和旧版本；
     * 成功只影响后续扫描与刮削，不静默覆盖历史入库值、歌词、封面或音频标签。
     */
    public function updateMetadataScrapePolicy(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new MetadataScrapePolicyInvalid('刮削策略参数无效。');
            return (new MetadataScrapePolicyService())->update(
                $payload, (string) $actor['id'], RequestContext::requestId(),
            );
        });
    }

    /**
     * 返回主程序固定运行维护分类的受管量和过期可清理量。
     *
     * 授权发生在文件系统扫描前；响应只包含数量、字节和固定保留策略，不返回服务器路径、文件名或未知
     * 目录项。该读取不删除文件、不写审计，也不会创建缺失的缓存目录。
     */
    public function showMaintenance(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new RuntimeMaintenanceService())->status());
    }

    /**
     * 清理管理员选择的固定分类并返回实际结果。
     *
     * POST 正文只能包含 categories 封闭列表，路径和保留期不可由客户端控制；CSRF 在路由层先验证。
     * actor ID 来自实时 Session，完成后由服务写一条脱敏审计。单文件失败作为部分结果返回，方便管理员
     * 重试；目录身份异常则失败关闭且不继续删除。
     */
    public function cleanupMaintenance(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['categories']
                || !is_array($payload['categories'])) {
                throw new RuntimeMaintenanceInvalid('运行维护参数无效。');
            }
            return (new RuntimeMaintenanceService())->cleanup(
                $payload['categories'],
                (string) $actor['id'],
                RequestContext::requestId(),
            );
        });
    }

    /**
     * 读取固定基础设置快照。
     *
     * 返回值包含 expectedVersion 所需的当前版本；响应始终 no-store。迁移缺失或内容损坏返回 503，
     * 不用 Controller 常量伪造默认配置，也没有写入副作用。
     */
    public function show(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new BasicSystemSettingsService())->get());
    }

    /**
     * 严格接收完整配置与版本号并执行原子替换。
     *
     * actor ID 只能来自实时 Session/认证服务，不能由请求正文提供。409 不自动重放旧表单；成功响应
     * 返回递增后的完整快照。CSRF 在进入本方法前由显式路由中间件验证。
     */
    public function update(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new BasicSystemSettingsInvalid('基础设置参数无效。');
            }

            return (new BasicSystemSettingsService())->update($payload, (string) $actor['id'], RequestContext::requestId());
        });
    }

    /**
     * 读取全站统一限制快照。
     *
     * 授权发生在读取前，响应携带独立版本并禁用缓存；接口不接受用户 ID，也不返回用户覆盖或个人用量。
     */
    public function showLimits(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new SystemLimitSettingsService())->get());
    }

    /** 读取全站 DLNA 开关；仅 `manage_system` 管理员可见，响应不包含 helper 路径或设备信息。 */
    public function showDlna(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new DlnaSettingsService())->get());
    }

    /** 原子更新全站 DLNA 开关；保存本身不执行 SSDP/SOAP，后续请求按新状态决定是否按需调用 helper。 */
    public function updateDlna(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new DlnaSettingsInvalid('DLNA 设置参数无效。');
            }
            $updated = (new DlnaSettingsService())->update($payload, (string) $actor['id'], RequestContext::requestId());
            try {
                $supervisor = new DlnaHelperSupervisor();
                $running = true;
                if ($updated['enabled']) {
                    $running = $supervisor->start();
                } else {
                    $supervisor->stop();
                }
                if ($updated['enabled'] && $running !== true) {
                    Log::warning('DLNA helper did not become ready after enabling.', [
                        'request_id' => RequestContext::requestId(),
                    ]);
                }
            } catch (Throwable $lifecycleFailure) {
                Log::warning('DLNA helper lifecycle reconciliation failed.', [
                    'request_id' => RequestContext::requestId(),
                    'enabled' => $updated['enabled'],
                    'exception_class' => $lifecycleFailure::class,
                ]);
            }
            return $updated;
        });
    }

    /**
     * 读取全站 AirPlay 开关与实际运行状态。
     *
     * 仅 `manage_system` 管理员可见；running 通过固定 helper 的 PID 身份与本机健康检查得到，响应不包含
     * 可执行路径、PID、端口响应、设备或配对信息。持久设置可读但 companion 故障时仍返回 running=false。
     */
    public function showAirplay(Request $request): Response
    {
        return $this->execute($request, static function (): array {
            $settings = (new AirplaySettingsService())->get();
            return [...$settings, 'running' => self::airplayRunning(new AirplayCompanionSupervisor())];
        });
    }

    /**
     * 原子更新 AirPlay 开关，并在事务提交后立即协调内置进程组。
     *
     * OwnTone 启停不能与 SQLite 原子提交：设置是唯一期望状态，running 是本次协调后的事实。启动失败
     * 不撤销设置，周期 Worker 会继续恢复；停止失败同样不伪造成功状态，日志只记录异常类型与 requestId。
     */
    public function updateAirplay(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new AirplaySettingsInvalid('AirPlay 设置参数无效。');
            }
            $updated = (new AirplaySettingsService())->update(
                $payload,
                (string) $actor['id'],
                RequestContext::requestId(),
            );
            $supervisor = new AirplayCompanionSupervisor();
            try {
                if ($updated['enabled']) {
                    $supervisor->start();
                } else {
                    $supervisor->stop();
                }
            } catch (Throwable $lifecycleFailure) {
                Log::warning('AirPlay companion lifecycle reconciliation failed.', [
                    'request_id' => RequestContext::requestId(),
                    'enabled' => $updated['enabled'],
                    'exception_class' => $lifecycleFailure::class,
                ]);
            }
            return [...$updated, 'running' => self::airplayRunning($supervisor)];
        });
    }

    /**
     * 把 companion 状态探测收敛为不泄露细节的布尔事实。
     *
     * 设置读写与进程状态无法组成同一事务；锁或 helper 短暂不可用时必须保留已提交的期望状态并返回
     * running=false，交给周期 Worker 恢复。日志只记录异常类型和 requestId，不包含 PID 或系统路径。
     */
    private static function airplayRunning(AirplayCompanionSupervisor $supervisor): bool
    {
        try {
            return $supervisor->running();
        } catch (Throwable $statusFailure) {
            Log::warning('AirPlay companion status probe failed.', [
                'request_id' => RequestContext::requestId(),
                'exception_class' => $statusFailure::class,
            ]);
            return false;
        }
    }

    /**
     * 严格接收完整全局限制对象并执行原子版本替换。
     *
     * actor ID 只来自实时认证主体，CSRF 由路由中间件先行验证。成功只改变后续播放、转码、下载或任务
     * 准入，不终止现有任务或媒体流；后台上传不读取此对象。版本冲突由浏览器重新加载后人工确认。
     */
    public function updateLimits(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new SystemLimitSettingsInvalid('系统限制参数无效。');
            }

            return (new SystemLimitSettingsService())->update(
                $payload,
                (string) $actor['id'],
                RequestContext::requestId(),
            );
        });
    }

    /**
     * 读取数据源共用的系统代理脱敏快照。
     *
     * 授权发生在数据库读取前；响应只包含主机、端口、用户名和 passwordConfigured，不解密或返回密码。
     * 该操作不探测代理，也不影响当前正在运行的数据源请求。
     */
    public function showProxy(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new NetworkProxySettingsService())->snapshot());
    }

    /**
     * 使用独立版本锁原子保存系统代理。
     *
     * actor 只取自实时 Session，PUT 由路由 CSRF 中间件保护。密码省略或空字符串表示保留密文，响应
     * 永不回显；保存事务不访问代理或第三方数据源，成功只影响后续且已勾选代理的数据源请求。
     */
    public function updateProxy(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new NetworkProxySettingsInvalid();
            }
            return (new NetworkProxySettingsService())->update(
                $payload,
                (string) $actor['id'],
                RequestContext::requestId(),
            );
        });
    }

    /**
     * 先实时授权再执行设置操作并生成统一响应。
     *
     * 授权失败时不会读取配置；成功和失败响应均通过统一工厂禁用缓存。未知异常仅按类名和 requestId
     * 写入日志，避免 SQL、路径或原始请求内容泄露到浏览器。
     */
    private function execute(Request $request, callable $operation): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            return JsonResponseFactory::create(['data' => $operation($actor), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有管理系统设置的权限。', 403, $requestId);
            }
            if ($throwable instanceof BasicSystemSettingsInvalid) {
                return JsonResponseFactory::error('SYSTEM_SETTINGS_VALIDATION_FAILED', '系统设置参数无效。', 422, $requestId);
            }
            if ($throwable instanceof SystemLimitSettingsInvalid) {
                return JsonResponseFactory::error('SYSTEM_LIMITS_VALIDATION_FAILED', '系统限制参数无效。', 422, $requestId);
            }
            if ($throwable instanceof NetworkProxySettingsInvalid) {
                return JsonResponseFactory::error('NETWORK_PROXY_VALIDATION_FAILED', '代理配置参数无效。', 422, $requestId);
            }
            if ($throwable instanceof DlnaSettingsInvalid) {
                return JsonResponseFactory::error('DLNA_SETTINGS_VALIDATION_FAILED', 'DLNA 设置参数无效。', 422, $requestId);
            }
            if ($throwable instanceof AirplaySettingsInvalid) {
                return JsonResponseFactory::error('AIRPLAY_SETTINGS_VALIDATION_FAILED', 'AirPlay 设置参数无效。', 422, $requestId);
            }
            if ($throwable instanceof RuntimeMaintenanceInvalid) {
                return JsonResponseFactory::error('RUNTIME_MAINTENANCE_VALIDATION_FAILED', '清理分类无效。', 422, $requestId);
            }
            if ($throwable instanceof MetadataScrapePolicyInvalid) {
                return JsonResponseFactory::error('METADATA_SCRAPE_POLICY_VALIDATION_FAILED', '刮削配置参数无效。', 422, $requestId);
            }
            if ($throwable instanceof RuntimeMaintenanceUnavailable) {
                return JsonResponseFactory::error('RUNTIME_MAINTENANCE_UNAVAILABLE', '运行维护暂时不可用。', 503, $requestId);
            }
            if ($throwable instanceof BasicSystemSettingsConflict) {
                return JsonResponseFactory::error('SYSTEM_SETTINGS_VERSION_CONFLICT', '系统设置已变化，请刷新后重试。', 409, $requestId);
            }
            if ($throwable instanceof SystemLimitSettingsConflict) {
                return JsonResponseFactory::error('SYSTEM_LIMITS_VERSION_CONFLICT', '系统限制已变化，请刷新后重试。', 409, $requestId);
            }
            if ($throwable instanceof NetworkProxySettingsConflict) {
                return JsonResponseFactory::error('NETWORK_PROXY_VERSION_CONFLICT', '代理配置已变化，请刷新后重试。', 409, $requestId);
            }
            if ($throwable instanceof DlnaSettingsConflict) {
                return JsonResponseFactory::error('DLNA_SETTINGS_VERSION_CONFLICT', 'DLNA 设置已变化，请刷新后重试。', 409, $requestId);
            }
            if ($throwable instanceof AirplaySettingsConflict) {
                return JsonResponseFactory::error('AIRPLAY_SETTINGS_VERSION_CONFLICT', 'AirPlay 设置已变化，请刷新后重试。', 409, $requestId);
            }
            if ($throwable instanceof MetadataScrapePolicyConflict) {
                return JsonResponseFactory::error('METADATA_SCRAPE_POLICY_VERSION_CONFLICT', '刮削配置已变化，请刷新后重试。', 409, $requestId);
            }
            Log::error('System settings request failed.', ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            $code = $throwable instanceof BasicSystemSettingsUnavailable
                || $throwable instanceof SystemLimitSettingsUnavailable
                || $throwable instanceof NetworkProxySettingsUnavailable
                || $throwable instanceof DlnaSettingsUnavailable
                || $throwable instanceof AirplaySettingsUnavailable
                || $throwable instanceof MetadataScrapePolicyUnavailable
                ? 'SYSTEM_SETTINGS_UNAVAILABLE'
                : 'SYSTEM_SETTINGS_REQUEST_FAILED';
            return JsonResponseFactory::error($code, '系统设置暂时不可用。', 503, $requestId);
        }
    }
}
