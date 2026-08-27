<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\ResourcePlugin\Contract\ExternalMusicPluginRegistry;
use app\application\ResourcePlugin\Contract\TypedExternalMusicSearchHook;
use Closure;
use support\Log;
use Throwable;

/**
 * 编排面向音乐库管理员的统一第三方搜索与下载 API。
 *
 * 本服务从活动插件目录动态发现能力，隔离单个搜索提供方失败，并把插件数组裁剪为核心固定 DTO。它不
 * 安装或配置插件、不解析第三方 URL、不执行正文下载，也不替插件访问数据库租约。HTTP Controller 必须
 * 先验证 `manage_library`；插件随后继续执行租约和音乐库对象级授权，二者缺一不可。
 */
final readonly class ExternalMusicService
{
    /**
     * 装配插件注册表与可选测试日志接收器。
     *
     * 生产默认使用结构化 Log；测试可注入闭包以断言/丢弃已脱敏上下文，避免依赖 Webman 日志配置。闭包
     * 只接收固定消息和不含搜索词、租约、用户或音乐库 ID 的上下文，不能用于改变提供方失败决策。
     */
    public function __construct(
        private ExternalMusicPluginRegistry $registry = new PhpResourcePluginRegistry(),
        private ?Closure $failureLogger = null,
    ) {
    }

    /**
     * 返回当前可从统一 API 调用的提供方目录。
     *
     * 只有包、manifest、能力接口和数据库账本均有效的活动插件才会出现。目录不执行上游网络探测，因此
     * searchable/downloadable 只表示协议能力，不承诺第三方服务此刻在线；配置状态仍在插件管理页面查看。
     *
     * @return array{providers:list<array{key:string,name:string,version:string,searchable:bool,downloadable:bool,searchTypes:list<string>}>}
     */
    public function providers(): array
    {
        return ['providers' => array_values($this->providerMap())];
    }

    /**
     * 对选定或全部可搜索插件执行有界聚合搜索。
     *
     * 每个插件分别签发绑定当前 actor 的短期租约。单插件异常只形成 `failed` 状态并记录不含搜索词的结构
     * 日志，其他插件结果仍返回；没有任何可用提供方时失败为服务不可用。聚合保持注册表/请求顺序，不
     * 伪造跨平台相关性排名，也不合并看似同名但身份不确定的歌曲。
     *
     * @return array<string,mixed>
     * @throws ExternalMusicInvalid 显式 pluginKeys 含不存在或不可搜索的插件
     * @throws ExternalMusicUnavailable 当前没有可搜索插件
     */
    public function search(ExternalMusicSearchRequest $request, string $actorId, string $requestId): array
    {
        $providers = array_filter(
            $this->providerMap(),
            static fn (array $provider): bool => $provider['searchable']
                && in_array($request->resourceType, $provider['searchTypes'], true),
        );
        if ($request->pluginKeys !== null) {
            $selected = [];
            foreach ($request->pluginKeys as $pluginKey) {
                if (!isset($providers[$pluginKey])) throw new ExternalMusicInvalid();
                $selected[$pluginKey] = $providers[$pluginKey];
            }
            $providers = $selected;
        }
        if ($providers === []) throw new ExternalMusicUnavailable();

        $items = [];
        $states = [];
        $limited = false;
        foreach ($providers as $pluginKey => $provider) {
            try {
                $hook = $this->registry->search($pluginKey);
                $result = $hook instanceof TypedExternalMusicSearchHook
                    ? $hook->searchTyped($request->resourceType, $request->query, $request->limit, $actorId)
                    : $hook->search($request->query, $request->limit, $actorId);
                $projected = $this->searchResult(
                    $pluginKey,
                    $provider['name'],
                    $provider['downloadable'],
                    $request,
                    $result,
                );
                array_push($items, ...$projected['items']);
                $limited = $limited || $projected['limited'];
                $states[] = ['pluginKey' => $pluginKey, 'pluginName' => $provider['name'],
                    'status' => 'succeeded', 'itemCount' => count($projected['items']),
                    'limited' => $projected['limited'], 'errorCode' => null];
            } catch (Throwable $throwable) {
                $this->logFailure('External music provider search failed.', [
                    'request_id' => $requestId,
                    'plugin_key' => $pluginKey,
                    'exception_class' => $throwable::class,
                ]);
                $states[] = ['pluginKey' => $pluginKey, 'pluginName' => $provider['name'],
                    'status' => 'failed', 'itemCount' => 0, 'limited' => false,
                    'errorCode' => 'EXTERNAL_MUSIC_PROVIDER_FAILED'];
            }
        }

        return ['query' => $request->query, 'resourceType' => $request->resourceType,
            'total' => count($items), 'limited' => $limited,
            'providers' => $states, 'items' => $items];
    }

    /**
     * 消费统一租约命令并创建耐久下载任务。
     *
     * 核心只传 leaseId 与 libraryId，不允许插件私有参数。插件必须用租约唯一约束实现幂等，并在同一短
     * 事务复验 actor、租约到期和目标库 manage 授权；HTTP 请求只排队，不执行网络、FFprobe 或文件发布。
     * 插件返回值经固定任务 DTO 裁剪，协议或执行失败不会回显异常正文。
     *
     * @param array<string,mixed> $actor 已通过实时 `manage_library` 校验的主体
     * @return array{job:array<string,mixed>}
     * @throws ExternalMusicInvalid 插件不存在或不具备下载能力
     * @throws ExternalMusicUnavailable 插件拒绝租约、暂不可用或返回非法投影
     */
    public function createDownload(
        ExternalMusicDownloadRequest $request,
        array $actor,
        string $requestId,
    ): array {
        $provider = $this->providerMap()[$request->pluginKey] ?? null;
        if (!is_array($provider) || !$provider['downloadable']) throw new ExternalMusicInvalid();
        try {
            $job = $this->registry->download($request->pluginKey)->createDownload(
                $request->pluginCommand(),
                $actor,
                $requestId,
            );
            return ['job' => ExternalMusicDownloadJob::fromPluginJob(
                $request->pluginKey,
                $provider['name'],
                $job,
            )->toArray()];
        } catch (Throwable $throwable) {
            $this->logFailure('External music download creation failed.', [
                'request_id' => $requestId,
                'plugin_key' => $request->pluginKey,
                'exception_class' => $throwable::class,
            ]);
            throw new ExternalMusicUnavailable('External music download is unavailable.', 0, $throwable);
        }
    }

    /**
     * 从注册表投影建立统一提供方映射。
     *
     * databaseInstalled 必须显式为 true；数据库版本不匹配的插件即使 manifest 合法也不可调用。名称和
     * 版本只来自注册表已裁剪字段，缺损条目直接忽略，不尝试加载其 PHP 类来“修复”目录。
     *
     * @return array<string,array{key:string,name:string,version:string,searchable:bool,downloadable:bool,searchTypes:list<string>}>
     */
    private function providerMap(): array
    {
        $providers = [];
        foreach ($this->registry->list() as $plugin) {
            if (($plugin['valid'] ?? false) !== true || ($plugin['enabled'] ?? true) !== true
                || ($plugin['databaseInstalled'] ?? false) !== true
                || !is_string($plugin['key'] ?? null) || !is_string($plugin['name'] ?? null)
                || !is_string($plugin['version'] ?? null) || !is_array($plugin['capabilities'] ?? null)) continue;
            $searchable = in_array('external_music_search', $plugin['capabilities'], true);
            $downloadable = in_array('external_download', $plugin['capabilities'], true);
            if (!$searchable && !$downloadable) continue;
            $searchTypes = [];
            if ($searchable) {
                try {
                    $hook = $this->registry->search($plugin['key']);
                    $searchTypes = $hook instanceof TypedExternalMusicSearchHook
                        ? $this->validatedSearchTypes($hook->searchTypes())
                        : ['track'];
                } catch (Throwable) {
                    $searchable = false;
                }
            }
            $providers[$plugin['key']] = ['key' => $plugin['key'], 'name' => $plugin['name'],
                'version' => $plugin['version'], 'searchable' => $searchable, 'downloadable' => $downloadable,
                'searchTypes' => $searchTypes];
        }
        return $providers;
    }

    /**
     * 验证一个插件搜索批次并转换为固定条目。
     *
     * 插件必须回显规范搜索词、返回 list items 以及布尔 limited；宣称结果可下载时，manifest 还必须声明
     * external_download。结果超过请求上限时核心截断并强制标记 limited，防止插件缺陷放大响应；原始
     * total 不作为业务事实，因为租约项目才是客户端可消费集合。
     *
     * @param array<string,mixed> $result 插件返回的未信任批次
     * @return array{limited:bool,items:list<array<string,mixed>>}
     */
    private function searchResult(
        string $pluginKey,
        string $pluginName,
        bool $providerDownloadable,
        ExternalMusicSearchRequest $request,
        array $result,
    ): array {
        if (($result['query'] ?? null) !== $request->query || !is_int($result['total'] ?? null)
            || ($result['total'] ?? -1) < 0 || !is_bool($result['limited'] ?? null)
            || !is_array($result['items'] ?? null) || !array_is_list($result['items'])) {
            throw new ExternalMusicUnavailable();
        }
        $rawItems = $result['items'];
        $truncated = count($rawItems) > $request->limit;
        $rawItems = array_slice($rawItems, 0, $request->limit);
        $items = [];
        foreach ($rawItems as $item) {
            if (!is_array($item) || array_is_list($item)) throw new ExternalMusicUnavailable();
            if (($item['downloadable'] ?? null) === true && !$providerDownloadable) {
                throw new ExternalMusicUnavailable();
            }
            $items[] = ExternalMusicSearchItem::fromPluginItem(
                $pluginKey,
                $pluginName,
                $request->resourceType,
                $item,
            )->toArray();
        }
        return ['limited' => $result['limited'] || $truncated, 'items' => $items];
    }

    /**
     * 校验类型化插件声明的固定能力集合。
     *
     * 能力读取不接触第三方网络；空集合、重复项或未知类型表示插件协议损坏，整个提供方从目录中退出，
     * 不能通过忽略错误值把实际不支持的搜索类型暴露给管理员。
     *
     * @param array<mixed> $types
     * @return non-empty-list<'track'|'album'>
     */
    private function validatedSearchTypes(array $types): array
    {
        if ($types === [] || !array_is_list($types)) throw new ExternalMusicUnavailable();
        $validated = [];
        foreach ($types as $type) {
            if (!is_string($type) || !in_array($type, ['track', 'album'], true)
                || in_array($type, $validated, true)) throw new ExternalMusicUnavailable();
            $validated[] = $type;
        }
        return $validated;
    }

    /**
     * 记录不含用户输入和资源引用的提供方失败上下文。
     *
     * 生产日志管道异常时使用 PHP error_log 写入同一份已脱敏摘要；诊断设施故障不能打破“单提供方失败
     * 隔离”或把已创建任务改判为另一类错误。测试通过显式闭包隔离框架配置。
     *
     * @param array{request_id:string,plugin_key:string,exception_class:class-string} $context
     */
    private function logFailure(string $message, array $context): void
    {
        try {
            if ($this->failureLogger !== null) {
                ($this->failureLogger)($message, $context);
                return;
            }
            Log::warning($message, $context);
        } catch (Throwable) {
            error_log($message . ' request_id=' . $context['request_id']
                . ' plugin_key=' . $context['plugin_key']
                . ' exception_class=' . $context['exception_class']);
        }
    }
}
