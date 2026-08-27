<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\System\PrometheusMetricsService;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露受部署令牌保护的 Prometheus 抓取端点（ND-307）。
 *
 * `VELIN_METRICS_TOKEN` 未配置或少于 32 字节时端点以 404 失败关闭；配置后只接受标准 Bearer 头并用
 * 常量时间比较。令牌、请求头和来源地址不会进入日志。响应不包含用户、任务 ID、物理路径或媒体名称。
 */
final class MetricsController
{
    /** 返回 Prometheus 0.0.4 文本；认证失败不调用数据库和指标存储。 */
    public function show(Request $request): Response
    {
        $configured = getenv('VELIN_METRICS_TOKEN');
        if (!is_string($configured) || strlen($configured) < 32 || strlen($configured) > 256) {
            return response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store']);
        }
        $authorization = $request->header('authorization');
        $supplied = is_string($authorization) && str_starts_with($authorization, 'Bearer ')
            ? substr($authorization, 7) : '';
        if ($supplied === '' || !hash_equals($configured, $supplied)) {
            return response('Unauthorized', 401, ['Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store', 'WWW-Authenticate' => 'Bearer realm="velin-metrics"']);
        }
        try {
            return response((new PrometheusMetricsService())->render(), 200, [
                'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        } catch (Throwable $throwable) {
            Log::error('Prometheus metrics collection failed.', ['exception_class' => $throwable::class]);
            return response('Metrics unavailable', 503, ['Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store']);
        }
    }
}
