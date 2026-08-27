<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Lyrics\LyricsAdminQueryService;
use app\application\Lyrics\LyricsCleanupService;
use app\application\Lyrics\LyricsManualOverrideService;
use app\application\Lyrics\LyricsPrimarySelectionService;
use app\application\Lyrics\LyricsWritebackConflict;
use app\application\Lyrics\LyricsWritebackInvalid;
use app\application\Lyrics\LyricsWritebackNotFound;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露歌词管理工作台查询和数据库人工覆盖 API。
 *
 * 两个入口都要求 `edit_metadata`，应用服务再按歌曲所属音乐库实时校验 read/manage grant。列表不含
 * 正文，单曲详情只返回结构化行；响应和错误永不包含路径、来源定位摘要或数据库异常。该 Controller
 * 人工覆盖命令只委托数据库领域服务；本 Controller 不执行第三方请求、文件写入或任务创建，写回仍由
 * 独立 Dry Run/确认端点负责。
 */
final class LyricsAdminController
{
    /** 返回当前对象范围内的有界歌词管理歌曲页。 */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $data = (new LyricsAdminQueryService())->page(
                $actor,
                $this->optionalString($request->get('libraryId'), '音乐库筛选无效。'),
                $this->optionalString($request->get('state'), '歌词状态筛选无效。'),
                $this->optionalString($request->get('language'), '歌词语言筛选无效。'),
                $this->optionalString($request->get('kind'), '歌词类型筛选无效。'),
                $this->optionalString($request->get('source'), '歌词来源筛选无效。'),
                $this->optionalString($request->get('q'), '搜索词无效。'),
                $this->integer($request->get('limit'), 50, '分页大小无效。'),
                $this->integer($request->get('offset'), 0, '分页偏移无效。'),
            );

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 返回一个重新授权后的单曲歌词对照详情，正文不会进入日志或其他持久层。 */
    public function show(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $song = (new LyricsAdminQueryService())->song($songId, $actor);

            return JsonResponseFactory::create([
                'data' => ['song' => $song],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /**
     * 创建或按乐观版本保存数据库人工覆盖。
     *
     * 严格请求只接受语言、普通/逐行/逐字类型、结构化行和可空预期版本。逐字行/词结构由领域服务
     * 完整校验；正文直接交给领域服务且 Controller 不记录请求体。成功只修改数据库，绝不隐式写
     * sidecar 或音频标签。
     */
    public function saveManual(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload(
                $request,
                ['expectedLyricId', 'expectedVersion', 'kind', 'language', 'lines'],
            );
            if (!is_string($payload['language']) || !is_string($payload['kind']) || !is_array($payload['lines'])) {
                throw new LyricsWritebackInvalid('人工歌词请求无效。');
            }
            $song = (new LyricsManualOverrideService())->save(
                $songId,
                $payload['language'],
                $payload['kind'],
                $payload['lines'],
                $this->nullableUlid($payload['expectedLyricId'], '人工歌词标识无效。'),
                $this->nullablePositiveInteger($payload['expectedVersion'], '人工歌词版本无效。'),
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['song' => $song],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /**
     * 清除一个版本锁定的 manual 覆盖。
     *
     * 请求必须通过 Session、CSRF、全局能力和歌曲实时库范围；正文不随请求提交。成功只删除指定歌曲下
     * 匹配版本的 manual 数据库行并写脱敏审计，不删除 sidecar、Provider 数据或音频文件；并发变化
     * 返回 409，客户端必须刷新而不能把旧命令自动重放。
     */
    public function clearManual(Request $request, string $songId, string $lyricId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['expectedVersion']);
            $song = (new LyricsManualOverrideService())->clear(
                $songId,
                $lyricId,
                $this->positiveInteger($payload['expectedVersion'], '人工歌词版本无效。'),
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['song' => $song],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /**
     * 清理一首歌曲当前登记的全部歌词版本。
     *
     * 请求必须通过 Session、CSRF、`edit_metadata` 和实时音乐库范围，并携带弹窗看到的完整歌词 ID/版本
     * 快照。领域服务在事务内复核集合，删除主版本选择、解析诊断和所有来源索引；相邻文件和音频标签不会
     * 被修改，无引用托管缓存留待独立受控治理流程回收。并发变化或写回方案引用返回 409，客户端必须
     * 刷新详情后由管理员重新确认，不能自动重放该破坏性命令。
     */
    public function clearAll(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['expectedLyrics']);
            if (!is_array($payload['expectedLyrics']) || !array_is_list($payload['expectedLyrics'])) {
                throw new LyricsWritebackInvalid('待清理歌词版本无效。');
            }
            $song = (new LyricsCleanupService())->clearAll(
                $songId,
                $payload['expectedLyrics'],
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['song' => $song],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /**
     * 以歌词版本和选择版本双重 CAS 设置主歌词。
     *
     * 请求通过 Session、CSRF、`edit_metadata` 和实时库范围；成功只更新选择投影和脱敏审计，不修改
     * 歌词正文、来源优先级或文件。首次选择 expectedSelectionVersion 为 null，并发变化返回 409。
     */
    public function setPrimary(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['expectedLyricVersion', 'expectedSelectionVersion', 'lyricId']);
            if (!is_string($payload['lyricId'])) throw new LyricsWritebackInvalid('歌词标识无效。');
            $song = (new LyricsPrimarySelectionService())->set(
                $songId,
                $payload['lyricId'],
                $this->positiveInteger($payload['expectedLyricVersion'], '歌词版本无效。'),
                $this->nullablePositiveInteger($payload['expectedSelectionVersion'], '主歌词选择版本无效。'),
                $actor,
                $requestId,
            );
            return JsonResponseFactory::create(['data' => ['song' => $song], 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /**
     * 按选择版本清除主歌词并恢复默认优先级链。
     *
     * 命令不携带正文且不删除歌词或文件；不存在、失权、旧版本分别映射为既有 404/409 安全响应。
     */
    public function clearPrimary(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['expectedSelectionVersion']);
            $song = (new LyricsPrimarySelectionService())->clear(
                $songId,
                $this->positiveInteger($payload['expectedSelectionVersion'], '主歌词选择版本无效。'),
                $actor,
                $requestId,
            );
            return JsonResponseFactory::create(['data' => ['song' => $song], 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 读取可选标量；空字符串表示未筛选，数组和对象一律拒绝。 */
    private function optionalString(mixed $value, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new LyricsWritebackInvalid($message);
        }

        return $value;
    }

    /** 只接受规范非负十进制整数，不把浮点、布尔或科学计数法静默转换。 */
    private function integer(mixed $value, int $default, string $message): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,9})$/', $value) === 1) {
            return (int) $value;
        }
        throw new LyricsWritebackInvalid($message);
    }

    /** 严格读取命令字段，防止新增正文相关参数被旧版本静默接受。 */
    private function payload(Request $request, array $allowedKeys): array
    {
        $payload = $request->post();
        if (!is_array($payload)) {
            throw new LyricsWritebackInvalid('请求内容无效。');
        }
        $keys = array_keys($payload);
        sort($keys);
        sort($allowedKeys);
        if ($keys !== $allowedKeys) {
            throw new LyricsWritebackInvalid('请求包含未知或缺失字段。');
        }

        return $payload;
    }

    /** 只接受正整数，拒绝 PHP 的布尔、浮点和宽松字符串转换。 */
    private function positiveInteger(mixed $value, string $message): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        throw new LyricsWritebackInvalid($message);
    }

    /** 创建时允许 null，更新时只接受正整数版本。 */
    private function nullablePositiveInteger(mixed $value, string $message): ?int
    {
        return $value === null ? null : $this->positiveInteger($value, $message);
    }

    /** 更新时只接受规范 ULID，创建时以 null 明确表示没有既有并发身份。 */
    private function nullableUlid(mixed $value, string $message): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1) {
            return $value;
        }
        throw new LyricsWritebackInvalid($message);
    }

    /** 将领域失败映射为稳定且不泄露对象存在性的 HTTP 响应。 */
    private function failure(Throwable $throwable, string $requestId): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有维护歌词的权限。', 403, $requestId);
        }
        if ($throwable instanceof LyricsWritebackInvalid) {
            return JsonResponseFactory::error(
                'LYRICS_ADMIN_VALIDATION_FAILED',
                $throwable->getMessage(),
                422,
                $requestId,
            );
        }
        if ($throwable instanceof LyricsWritebackNotFound) {
            return JsonResponseFactory::error('LYRICS_ADMIN_NOT_FOUND', '歌曲不存在或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof LyricsWritebackConflict) {
            return JsonResponseFactory::error('LYRICS_ADMIN_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        Log::error('Lyrics administration query failed.', [
            'request_id' => $requestId,
            'exception_class' => $throwable::class,
        ]);

        return JsonResponseFactory::error(
            'LYRICS_ADMIN_UNAVAILABLE',
            '歌词工作台暂时不可用。',
            503,
            $requestId,
        );
    }
}
