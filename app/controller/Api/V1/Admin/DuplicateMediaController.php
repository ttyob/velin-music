<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Metadata\DuplicateMediaInvalid;
use app\application\Metadata\DuplicateMediaConflict;
use app\application\Metadata\DuplicateMediaService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露重复候选查询、非破坏性人工核对和强证据逻辑合并 API（ADMIN-META-011）。
 *
 * 入口要求已认证用户具备 `edit_metadata`；领域服务再应用实时音乐库 manage 范围。请求只能选择固定
 * 证据类型、库、文字和分页，不接受路径、inode、哈希、推荐分数或删除参数。响应不产生扫描任务、
 * 不提供媒体文件删除命令或对应 HTTP API。合并和回滚仅映射严格命令字段，实际权限、实时证据、短写
 * 事务、个人状态迁移及失败关闭均由领域服务承担，Controller 不接受路径、哈希或 inode。
 */
final class DuplicateMediaController
{
    /** 返回按组分页的重复候选、证据覆盖率和只读安全策略。 */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $data = (new DuplicateMediaService())->groups(
                $actor,
                $this->string($request->get('kind'), 'inode'),
                $this->optionalString($request->get('libraryId')),
                $this->optionalString($request->get('q')),
                $this->integer($request->get('limit'), 20),
                $this->integer($request->get('offset'), 0),
            );
            return JsonResponseFactory::create(['data' => $data, 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有维护元数据的权限。', 403, $requestId);
        } catch (DuplicateMediaInvalid) {
            return JsonResponseFactory::error('DUPLICATE_MEDIA_INVALID', '请检查证据类型、音乐库和分页参数。', 422, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Duplicate media query failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('DUPLICATE_MEDIA_UNAVAILABLE', '重复候选服务暂时不可用。', 503, $requestId);
        }
    }

    /**
     * 将一组实时重复证据标记为已人工核对并保留全部文件。
     *
     * Controller 只映射固定字段；领域服务重新验证成员、保留项和库管理范围。成功不会删除文件或迁移
     * 用户数据，返回 200 表示决定已持久化且该组会从待处理列表隐藏。
     */
    public function keepAll(Request $request, string $groupId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['kind', 'songIds', 'keepSongId']
                || !is_string($payload['kind']) || !is_array($payload['songIds'])
                || !is_string($payload['keepSongId'])) throw new DuplicateMediaInvalid('请求结构无效。');
            $decision = (new DuplicateMediaService())->keepAll($actor, $payload['kind'], $groupId,
                $payload['songIds'], $payload['keepSongId'], $requestId);
            return JsonResponseFactory::create(['data' => ['decision' => $decision], 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有维护元数据的权限。', 403, $requestId);
        } catch (DuplicateMediaInvalid) {
            return JsonResponseFactory::error('DUPLICATE_MEDIA_STALE', '重复组已经变化，请刷新后重新核对。', 409, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Duplicate media decision failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('DUPLICATE_MEDIA_UNAVAILABLE', '重复处理服务暂时不可用。', 503, $requestId);
        }
    }

    /**
     * 将同库同专辑的强证据组逻辑合并到管理员选择的保留歌曲。
     *
     * 固定确认文本用于阻止通用表单或误触直接提交；成功返回 200 和可撤销操作快照。参数错误为 422，
     * 实时成员、权限、证据或个人状态变化为 409；任何结果都不会删除、移动或修改媒体文件。
     */
    public function merge(Request $request, string $groupId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $request->post();
            if (!is_array($payload) || !$this->hasExactFields($payload, [
                'kind', 'libraryId', 'songIds', 'targetSongId', 'confirmation',
            ]) || !is_string($payload['kind']) || !is_string($payload['libraryId'])
                || !is_array($payload['songIds']) || !is_string($payload['targetSongId'])
                || !is_string($payload['confirmation'])) {
                throw new DuplicateMediaInvalid('请求结构无效。');
            }
            $result = (new DuplicateMediaService())->merge(
                $actor, $payload['kind'], $groupId, $payload['libraryId'], $payload['songIds'],
                $payload['targetSongId'], $payload['confirmation'], $requestId,
            );
            return JsonResponseFactory::create(['data' => $result, 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有维护元数据的权限。', 403, $requestId);
        } catch (DuplicateMediaInvalid) {
            return JsonResponseFactory::error('DUPLICATE_MEDIA_INVALID', '重复合并请求无效。', 422, $requestId);
        } catch (DuplicateMediaConflict) {
            return JsonResponseFactory::error('DUPLICATE_MEDIA_STALE', '重复组或个人状态已经变化，请刷新后重试。', 409, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Duplicate media merge failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('DUPLICATE_MEDIA_UNAVAILABLE', '重复合并服务暂时不可用。', 503, $requestId);
        }
    }

    /**
     * 在执行后状态未变化时撤销一次逻辑合并。
     *
     * expectedVersion 是管理员页面最后读取的操作版本，固定确认文本为第二重显式意图。不存在、无权、
     * 已撤销、版本过期或用户随后修改状态统一返回 409，避免泄露跨库操作并禁止旧快照覆盖新事实。
     */
    public function rollback(Request $request, string $operationId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $request->post();
            if (!is_array($payload) || !$this->hasExactFields($payload, ['expectedVersion', 'confirmation'])
                || !is_int($payload['expectedVersion']) || !is_string($payload['confirmation'])) {
                throw new DuplicateMediaInvalid('请求结构无效。');
            }
            $operation = (new DuplicateMediaService())->rollback(
                $actor, $operationId, $payload['expectedVersion'], $payload['confirmation'], $requestId,
            );
            return JsonResponseFactory::create(['data' => ['operation' => $operation], 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有维护元数据的权限。', 403, $requestId);
        } catch (DuplicateMediaInvalid) {
            return JsonResponseFactory::error('DUPLICATE_MEDIA_INVALID', '重复合并回滚请求无效。', 422, $requestId);
        } catch (DuplicateMediaConflict) {
            return JsonResponseFactory::error('DUPLICATE_MEDIA_STALE', '合并操作或个人状态已经变化，不能自动撤销。', 409, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Duplicate media merge rollback failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('DUPLICATE_MEDIA_UNAVAILABLE', '重复合并回滚服务暂时不可用。', 503, $requestId);
        }
    }

    /** 空值使用默认类型；数组、对象和空字符串不会被宽松转换。 */
    private function string(mixed $value, string $default): string
    {
        if ($value === null) return $default;
        if (!is_string($value) || $value === '') throw new DuplicateMediaInvalid('字符串参数无效。');
        return $value;
    }

    /** 查询和库筛选允许省略，显式空字符串统一归一为 null。 */
    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return $this->string($value, '');
    }

    /** 只接受规范非负十进制整数，防止浮点、指数和带符号字符串进入分页。 */
    private function integer(mixed $value, int $default): int
    {
        if ($value === null || $value === '') return $default;
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', $value) === 1) return (int) $value;
        throw new DuplicateMediaInvalid('分页参数无效。');
    }

    /** 严格拒绝缺失和额外字段，但 JSON 对象的字段顺序不属于命令语义。 */
    private function hasExactFields(array $payload, array $fields): bool
    {
        $actual = array_keys($payload);
        sort($actual);
        sort($fields);
        return $actual === $fields;
    }
}
