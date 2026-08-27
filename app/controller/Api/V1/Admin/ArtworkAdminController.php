<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Artwork\ArtworkAdminConflict;
use app\application\Artwork\ArtworkAdminInvalid;
use app\application\Artwork\ArtworkAdminNotFound;
use app\application\Artwork\ArtworkAdminService;
use app\application\Artwork\ArtworkProviderAdminService;
use app\application\Artwork\ArtworkProviderRemoteFailure;
use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露受 `edit_metadata` 与实体全部库 manage 双重保护的封面候选命令。
 *
 * 上传使用原始请求体而不是 multipart，避免临时文件名和额外副本进入业务层。裁剪框来自四个固定
 * 请求头；服务层重新验证实际 MIME、像素和作用域。Controller 不记录正文、摘要或内部存储信息。
 */
final class ArtworkAdminController
{
    /** 返回一个实体/库的候选和选择状态。 */
    public function show(Request $request, string $type, string $entityId): Response
    {
        return $this->run($request, fn (array $actor): array => (new ArtworkAdminService())->detail(
            $actor, $type, $entityId, (string) $request->get('libraryId', ''),
        ));
    }

    /** 接收不超过 20 MiB 的原始图片并创建未选中的规范化候选。 */
    public function upload(Request $request, string $type, string $entityId): Response
    {
        return $this->run($request, function (array $actor) use ($entityId, $request, $type): array {
            $crop = [];
            foreach (['x', 'y', 'width', 'height'] as $key) {
                $value = (string) $request->header('x-crop-' . $key, '');
                if (preg_match('/^(?:0|[1-9][0-9]{0,4}|10000)$/', $value) !== 1 || (int) $value > 10_000) {
                    throw new ArtworkAdminInvalid('裁剪请求头无效。');
                }
                $crop[$key] = (int) $value;
            }
            return (new ArtworkAdminService())->upload($actor, $type, $entityId,
                (string) $request->header('x-library-id', ''), $request->rawBody(),
                (string) $request->header('content-type', ''), $crop, RequestContext::requestId());
        });
    }

    /** 使用请求中的候选 ID 与 expectedVersion 原子更新选择。 */
    public function select(Request $request, string $type, string $entityId): Response
    {
        return $this->run($request, function (array $actor) use ($entityId, $request, $type): array {
            $payload = $request->post();
            if (!is_array($payload) || !is_string($payload['libraryId'] ?? null)
                || !is_string($payload['candidateId'] ?? null) || !is_int($payload['expectedVersion'] ?? null)) {
                throw new ArtworkAdminInvalid('选择命令无效。');
            }
            return (new ArtworkAdminService())->select($actor, $type, $entityId, $payload['libraryId'],
                $payload['candidateId'], $payload['expectedVersion'], RequestContext::requestId());
        });
    }

    /** 删除选择覆盖并恢复扫描器来源优先级。 */
    public function restore(Request $request, string $type, string $entityId): Response
    {
        return $this->run($request, function (array $actor) use ($entityId, $request, $type): array {
            $payload = $request->post();
            if (!is_array($payload) || !is_string($payload['libraryId'] ?? null)
                || !is_int($payload['expectedVersion'] ?? null)) throw new ArtworkAdminInvalid('恢复命令无效。');
            return (new ArtworkAdminService())->restore($actor, $type, $entityId, $payload['libraryId'],
                $payload['expectedVersion'], RequestContext::requestId());
        });
    }

    /**
     * 返回重新授权后的候选 WebP 字节，支持私有 ETag。
     *
     * 字符串正文的 Content-Length 由 Workerman 编码器统一生成，业务头禁止提前设置；否则框架递归
     * 合并会输出两个同名长度，浏览器直连可能容忍，但 Vite/Nginx 代理会把它判为非法上游响应。
     */
    public function image(Request $request, string $candidateId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $image = (new ArtworkAdminService())->image($actor, $candidateId);
            $headers = $this->imageHeaders($image, $requestId, false);
            if (trim((string) $request->header('if-none-match', '')) === $image['etag']) return response('', 304, $headers);
            return response($image['bytes'], 200, $headers);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 创建一个不在 HTTP 请求内访问第三方的远程封面搜索任务。 */
    public function createProviderSearch(Request $request, string $type, string $entityId): Response
    {
        return $this->run($request, function (array $actor) use ($entityId, $request, $type): array {
            $payload = $request->post();
            if (!is_array($payload) || array_diff(array_keys($payload), ['libraryId']) !== []
                || !is_string($payload['libraryId'] ?? null)) throw new ArtworkAdminInvalid('搜索命令无效。');
            return (new ArtworkProviderAdminService())->createSearch($type, $entityId, $payload['libraryId'],
                (string) $request->header('idempotency-key', ''), $actor, RequestContext::requestId());
        }, 202);
    }

    /** 返回最近一次远程搜索及无字节、无远端 ID 的候选摘要。 */
    public function latestProviderSearch(Request $request, string $type, string $entityId): Response
    {
        return $this->run($request, fn (array $actor): array => (new ArtworkProviderAdminService())->search(
            $type, $entityId, (string) $request->get('libraryId', ''), null, $actor,
        ));
    }

    /** 返回指定远程搜索及候选摘要；跨实体或失权不会泄露对象存在性。 */
    public function providerSearch(Request $request, string $type, string $entityId, string $searchJobId): Response
    {
        return $this->run($request, fn (array $actor): array => (new ArtworkProviderAdminService())->search(
            $type, $entityId, (string) $request->get('libraryId', ''), $searchJobId, $actor,
        ));
    }

    /**
     * 代理一张已冻结候选原图用于显式预览。
     *
     * 服务层会在远端读取前后复验权限、证据与摘要；响应不缓存为公开资源，不包含第三方地址或
     * Provider URL。许可归属通过独立 JSON 候选摘要展示，不放入可被误解析的响应头。
     */
    public function providerPreview(Request $request, string $type, string $entityId, string $searchJobId,
        string $candidateId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $image = (new ArtworkProviderAdminService())->preview($type, $entityId,
                (string) $request->get('libraryId', ''), $searchJobId, $candidateId, $actor);
            $headers = $this->imageHeaders($image, $requestId, true);
            if (trim((string) $request->header('if-none-match', '')) === $image['etag']) {
                return response('', 304, $headers);
            }
            return response($image['bytes'], 200, $headers);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 创建显式导入任务；图片导入完成后仍需管理员另行选择使用。 */
    public function createProviderImport(Request $request, string $type, string $entityId, string $searchJobId,
        string $candidateId): Response
    {
        return $this->run($request, function (array $actor) use ($candidateId, $entityId, $request,
            $searchJobId, $type): array {
            $payload = $request->post();
            if (!is_array($payload) || array_diff(array_keys($payload), ['libraryId', 'expectedSearchVersion']) !== []
                || !is_string($payload['libraryId'] ?? null)
                || !is_int($payload['expectedSearchVersion'] ?? null)) throw new ArtworkAdminInvalid('导入命令无效。');
            return (new ArtworkProviderAdminService())->createImport($type, $entityId, $payload['libraryId'],
                $searchJobId, $candidateId, $payload['expectedSearchVersion'],
                (string) $request->header('idempotency-key', ''), $actor, RequestContext::requestId());
        }, 202);
    }

    /** 返回一个远程封面导入任务的当前授权快照。 */
    public function providerImport(Request $request, string $type, string $entityId, string $jobId): Response
    {
        return $this->run($request, fn (array $actor): array => (new ArtworkProviderAdminService())->import(
            $type, $entityId, (string) $request->get('libraryId', ''), $jobId, $actor,
        ));
    }

    /**
     * 构造内存图片正文的安全响应头，不设置由 Workerman 负责的 Content-Length。
     *
     * 预览必须每次重新验证远端冻结资源，因此使用 no-cache；已导入候选是本地不可变 BLOB，可私有
     * 缓存一小时。两种响应都禁止 MIME 嗅探，且不会通过头部暴露远端 URL、资源 ID 或图片摘要全文。
     *
     * @param array{mimeType:string,etag:string} $image
     */
    private function imageHeaders(array $image, string $requestId, bool $preview): array
    {
        return ['Content-Type' => $image['mimeType'], 'Content-Disposition' => 'inline',
            'Cache-Control' => $preview ? 'private, no-cache' : 'private, max-age=3600, must-revalidate',
            'ETag' => $image['etag'], 'X-Content-Type-Options' => 'nosniff', 'X-Request-ID' => $requestId];
    }

    /** 执行统一授权并包装 JSON 数据；写命令的 CSRF 由显式路由中间件完成。 */
    private function run(Request $request, callable $operation, int $status = 200): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            return JsonResponseFactory::create(['data' => ['artwork' => $operation($actor)],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')]], $status, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 将权限、不可枚举对象、输入和版本冲突映射为稳定且不含图片细节的错误。 */
    private function failure(Throwable $throwable, string $requestId): Response
    {
        if ($throwable instanceof AuthenticationRequired) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        if ($throwable instanceof AuthorizationDenied) return JsonResponseFactory::error('PERMISSION_DENIED', '没有编辑元数据的权限。', 403, $requestId);
        if ($throwable instanceof ArtworkAdminNotFound) return JsonResponseFactory::error('ARTWORK_NOT_FOUND', '实体或封面候选不存在。', 404, $requestId);
        if ($throwable instanceof ArtworkAdminInvalid) return JsonResponseFactory::error('ARTWORK_INVALID', '图片、裁剪框或命令格式无效。', 422, $requestId);
        if ($throwable instanceof ArtworkAdminConflict) return JsonResponseFactory::error('ARTWORK_CONFLICT', '封面候选或选择已变化，请刷新。', 409, $requestId);
        if ($throwable instanceof ArtworkProviderRemoteFailure) {
            $status = $throwable->reasonCode === 'ARTWORK_PROVIDER_LICENSE_UNAVAILABLE' ? 410 : 503;
            return JsonResponseFactory::error($throwable->reasonCode, '远程封面服务暂时不可用或资源许可已变化。', $status, $requestId);
        }
        Log::error('Admin artwork command failed.', ['request_id' => $requestId, 'exception_class' => $throwable::class]);
        return JsonResponseFactory::error('ARTWORK_UNAVAILABLE', '封面管理服务暂时不可用。', 503, $requestId);
    }
}
