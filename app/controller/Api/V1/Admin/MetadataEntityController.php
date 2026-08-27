<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Metadata\MetadataEntityConflict;
use app\application\Metadata\MetadataEntityInvalid;
use app\application\Metadata\MetadataEntityNotFound;
use app\application\Metadata\MetadataEntityService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露艺术家/专辑合并拆分工作台 API（ADMIN-META-010/012）。
 *
 * 每个入口先要求 Session/Bearer 身份和全局 `edit_metadata`，领域服务随后按实体实时关联的全部音乐库
 * 复验 manage 范围。Controller 只接受白名单字段并映射 HTTP 语义，不读取关系快照、不接受物理路径，
 * 也不执行扫描或媒体文件操作；写路由另由路由层强制 CSRF。
 */
final class MetadataEntityController
{
    /** 返回可完整管理的实体候选；艺术家跨库引用会在服务端完整裁剪。 */
    public function entities(Request $request): Response
    {
        return $this->execute($request, function (array $actor) use ($request): array {
            return (new MetadataEntityService())->entities(
                $actor,
                $this->string($request->get('type'), '实体类型无效。'),
                $this->optionalString($request->get('q')),
                $this->optionalString($request->get('libraryId')),
                $this->integer($request->get('limit'), 50),
                $this->integer($request->get('offset'), 0),
            );
        });
    }

    /** 计算合并影响；预览不冻结关系，提交时必须携带返回的实体更新时间。 */
    public function mergePreview(Request $request): Response
    {
        return $this->execute($request, function (array $actor) use ($request): array {
            $payload = $this->payload($request, ['type', 'sourceId', 'targetId']);
            return (new MetadataEntityService())->mergeImpact(
                $actor, $this->string($payload['type']), $this->string($payload['sourceId']),
                $this->string($payload['targetId']),
            );
        });
    }

    /** 返回拆分选择器使用的来源歌曲摘要，仍要求实体全部库 manage。 */
    public function songs(Request $request, string $type, string $entityId): Response
    {
        return $this->execute($request, function (array $actor) use ($request, $type, $entityId): array {
            return (new MetadataEntityService())->entitySongs(
                $actor, $type, $entityId,
                $this->integer($request->get('limit'), 500), $this->integer($request->get('offset'), 0),
            );
        });
    }

    /** 按预览版本和固定确认文本提交原子合并。 */
    public function merge(Request $request): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request): array {
            $payload = $this->payload($request, [
                'type', 'sourceId', 'targetId', 'expectedSourceUpdatedAt', 'expectedTargetUpdatedAt', 'confirmation',
            ]);
            return (new MetadataEntityService())->merge(
                $actor, $this->string($payload['type']), $this->string($payload['sourceId']),
                $this->string($payload['targetId']), $this->string($payload['expectedSourceUpdatedAt']),
                $this->string($payload['expectedTargetUpdatedAt']), $this->string($payload['confirmation']), $requestId,
            );
        }, 201);
    }

    /** 计算部分歌曲拆到新实体后的关系影响，不创建新 ID。 */
    public function splitPreview(Request $request): Response
    {
        return $this->execute($request, function (array $actor) use ($request): array {
            $payload = $this->payload($request, ['type', 'sourceId', 'songIds', 'newName']);
            return (new MetadataEntityService())->splitImpact(
                $actor, $this->string($payload['type']), $this->string($payload['sourceId']),
                $this->stringList($payload['songIds']), $this->string($payload['newName']),
            );
        });
    }

    /** 按来源版本、歌曲集合和固定确认文本提交原子拆分。 */
    public function split(Request $request): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request): array {
            $payload = $this->payload($request, [
                'type', 'sourceId', 'songIds', 'newName', 'expectedSourceUpdatedAt', 'confirmation',
            ]);
            return (new MetadataEntityService())->split(
                $actor, $this->string($payload['type']), $this->string($payload['sourceId']),
                $this->stringList($payload['songIds']), $this->string($payload['newName']),
                $this->string($payload['expectedSourceUpdatedAt']), $this->string($payload['confirmation']), $requestId,
            );
        }, 201);
    }

    /** 返回当前操作者仍可完整管理的合并拆分历史。 */
    public function operations(Request $request): Response
    {
        return $this->execute($request, function (array $actor) use ($request): array {
            return (new MetadataEntityService())->operations(
                $actor, $this->integer($request->get('limit'), 50), $this->integer($request->get('offset'), 0),
            );
        });
    }

    /** 返回单个脱敏操作投影；快照、用户 ID 和服务器路径不会进入响应。 */
    public function operation(Request $request, string $operationId): Response
    {
        return $this->execute($request, static function (array $actor) use ($operationId): array {
            return ['operation' => (new MetadataEntityService())->operation($actor, $operationId)];
        });
    }

    /** 仅在当前关系仍等于执行后摘要时回滚，不提供强制覆盖选项。 */
    public function rollback(Request $request, string $operationId): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request, $operationId): array {
            $payload = $this->payload($request, ['expectedVersion', 'confirmation']);
            return ['operation' => (new MetadataEntityService())->rollback(
                $actor, $operationId, $this->positiveInteger($payload['expectedVersion']),
                $this->string($payload['confirmation']), $requestId,
            )];
        });
    }

    /**
     * 统一执行授权、响应封装和稳定错误映射。
     *
     * 领域异常只返回不含对象细节的固定中文消息；意外异常仅记录 requestId 与异常类，禁止日志保存请求体、
     * 实体名称、用户 ID 或关系快照。404 同时覆盖不存在与对象范围失权，避免枚举其他音乐库。
     *
     * @param callable(array<string,mixed>, string):array<string,mixed> $operation
     */
    private function execute(Request $request, callable $operation, int $status = 200): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $data = $operation($actor, $requestId);
            return JsonResponseFactory::create(['data' => $data, 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], $status, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有维护元数据的权限。', 403, $requestId);
        } catch (MetadataEntityInvalid) {
            return JsonResponseFactory::error('METADATA_ENTITY_INVALID', '请检查实体、歌曲、版本和确认信息。', 422, $requestId);
        } catch (MetadataEntityNotFound) {
            return JsonResponseFactory::error('METADATA_ENTITY_NOT_FOUND', '实体或操作不存在。', 404, $requestId);
        } catch (MetadataEntityConflict $throwable) {
            return JsonResponseFactory::error('METADATA_ENTITY_CONFLICT', $throwable->getMessage(), 409, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Metadata entity command failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('METADATA_ENTITY_UNAVAILABLE', '元数据关系服务暂时不可用。', 503, $requestId);
        }
    }

    /** 严格读取 JSON/Form 对象并拒绝未知或缺失字段，防止客户端偷偷扩展命令语义。 */
    private function payload(Request $request, array $allowed): array
    {
        $payload = $request->post();
        if (!is_array($payload) || array_diff(array_keys($payload), $allowed) !== []
            || array_diff($allowed, array_keys($payload)) !== []) {
            throw new MetadataEntityInvalid('请求字段无效。');
        }
        return $payload;
    }

    /** 只接受非空字符串；业务长度和 ULID 格式由领域服务统一验证。 */
    private function string(mixed $value, string $message = '请求字段无效。'): string
    {
        if (!is_string($value) || $value === '') throw new MetadataEntityInvalid($message);
        return $value;
    }

    /** 空查询值归一为 null，数组和对象直接拒绝。 */
    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return $this->string($value);
    }

    /** 只接受字符串列表，不将标量或混合数组静默转换为歌曲集合。 */
    private function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) throw new MetadataEntityInvalid('歌曲集合无效。');
        foreach ($value as $item) if (!is_string($item)) throw new MetadataEntityInvalid('歌曲集合无效。');
        return $value;
    }

    /** 读取非负分页整数；字符串只允许规范十进制表示。 */
    private function integer(mixed $value, int $default): int
    {
        if ($value === null || $value === '') return $default;
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', $value) === 1) return (int) $value;
        throw new MetadataEntityInvalid('分页参数无效。');
    }

    /** 只接受正整数乐观版本，禁止浮点和宽松数值转换。 */
    private function positiveInteger(mixed $value): int
    {
        $parsed = $this->integer($value, 0);
        if ($parsed < 1) throw new MetadataEntityInvalid('操作版本无效。');
        return $parsed;
    }
}
