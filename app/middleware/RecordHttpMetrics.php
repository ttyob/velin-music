<?php

declare(strict_types=1);

namespace app\middleware;

use app\infrastructure\Observability\HttpMetricStore;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 在全局最外层记录低基数 HTTP 请求数、状态类别和同步调度延迟（ND-307）。
 *
 * 中间件不读取 Session、查询参数、请求体、IP 或响应正文。下游抛出异常时先记录 5xx 再原样抛出，
 * 仍由 Webman 异常处理器决定最终响应；指标存储自身失败会被隔离，绝不能把成功业务请求改成错误。
 */
final readonly class RecordHttpMetrics implements MiddlewareInterface
{
    public function __construct(private HttpMetricStore $metrics = new HttpMetricStore())
    {
    }

    /** 使用单调纳秒时钟包围下游同步处理；事件循环接管后的流式时长由独立指标负责。 */
    public function process(Request $request, callable $handler): Response
    {
        $started = hrtime(true);
        $status = 500;
        try {
            $response = $handler($request);
            $status = $response->getStatusCode();
            return $response;
        } catch (Throwable $throwable) {
            throw $throwable;
        } finally {
            $durationUs = (int) max(0, intdiv(hrtime(true) - $started, 1_000));
            $this->metrics->record($request->method(), $request->path(), $status, $durationUs);
        }
    }
}
