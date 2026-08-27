<?php

declare(strict_types=1);

namespace app\exception;

use app\http\JsonResponseFactory;
use app\http\RequestContext;
use app\infrastructure\Observability\SystemErrorRecorder;
use support\exception\Handler as WebmanHandler;
use Throwable;
use WeakMap;
use Webman\Exception\BusinessException;
use Webman\Http\Request;
use Webman\Http\Response;
use support\exception\MissingInputException;
use support\exception\PageNotFoundException;

/**
 * Velin Music 全局异常边界：记录未处理 Throwable，并向内部 API 返回统一安全错误。
 *
 * 已知 BusinessException、404 和缺参路由错误不进入系统故障表。其他异常先写脱敏聚合记录，再写原有
 * 文件日志；任一记录器失败都不能遮蔽原异常。请求 ID 使用 WeakMap 在 report/render 两阶段复用，响应
 * 永不包含异常消息、SQL、路径或堆栈。Subsonic 路径保留控制器协议边界，通常不会到达本 handler。
 */
final class Handler extends WebmanHandler
{
    /** @var list<class-string> */
    public $dontReport = [BusinessException::class, PageNotFoundException::class, MissingInputException::class];

    /** @var WeakMap<Request,string> */
    private WeakMap $requestIds;

    public function __construct($logger, $debug)
    {
        parent::__construct($logger, $debug);
        $this->requestIds = new WeakMap();
    }

    /** 捕获未处理异常；捕获失败只影响后台诊断，不影响既有文件日志和响应。 */
    public function report(Throwable $exception): void
    {
        if ($this->shouldntReport($exception)) {
            return;
        }
        $request = null;
        try {
            $candidate = request();
            $request = $candidate instanceof Request ? $candidate : null;
        } catch (Throwable) {
        }
        $requestId = $request === null ? null : $this->requestId($request);
        (new SystemErrorRecorder())->recordThrowable($exception, $request, $requestId);
        $this->logger->error('Unhandled backend exception.', [
            'request_id' => $requestId,
            'exception_class' => $exception::class,
            'system_error_recorded' => true,
        ]);
    }

    /** 内部 JSON API 固定返回 500 信封；其他路由沿用 Webman 安全渲染且生产不暴露堆栈。 */
    public function render(Request $request, Throwable $exception): Response
    {
        if (method_exists($exception, 'render') && ($response = $exception->render($request))) {
            return $response;
        }
        $path = '/' . ltrim($request->path(), '/');
        if (str_starts_with($path, '/api/v1/') && !str_starts_with($path, '/api/v1/rest/')) {
            return JsonResponseFactory::error(
                'INTERNAL_SERVER_ERROR',
                '服务器处理请求时发生异常。',
                500,
                $this->requestId($request),
            );
        }

        return parent::render($request, $exception);
    }

    /** 为同一请求的 report/render 生命周期分配一个服务端关联标识，WeakMap 自动释放长驻进程内存。 */
    private function requestId(Request $request): string
    {
        if (!isset($this->requestIds[$request])) {
            $this->requestIds[$request] = RequestContext::requestId();
        }

        return $this->requestIds[$request];
    }
}
