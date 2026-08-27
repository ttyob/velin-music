<?php

declare(strict_types=1);

namespace app\application\System;

use app\domain\System\HealthProbe;
use Throwable;

/**
 * 构建 Velin Music 公开就绪状态，并组合数据库与当前 Session 存储依赖。
 *
 * SQLite 是所有业务流程的必需依赖；Session 使用 Redis 时 Redis 也必须就绪，文件 Session 则报告
 * `not_required`。任一探测异常都收敛为稳定公开错误码，不返回路径、SQL、主机或凭据。检查只执行有界
 * 读操作，不触发迁移、修复、任务领取或媒体扫描。
 */
final readonly class HealthService
{
    public function __construct(private HealthProbe $databaseProbe, private ?HealthProbe $sessionProbe = null)
    {
    }

    /**
     * 为服务端生成的关联 ID 创建一次依赖快照。
     *
     * 全部必需探测为 ready/not_required 时返回 HTTP 200；任一失败返回 HTTP 503。可选 Session 探测为空
     * 仅用于隔离单元测试和旧调用兼容，生产 Controller 始终传入真实探测器。
     */
    public function inspect(string $requestId): HealthReport
    {
        try {
            $database = $this->databaseProbe->inspect();
            $ready = ($database['status'] ?? null) === 'ready';
        } catch (Throwable) {
            $ready = false;
            $database = [
                'status' => 'unavailable',
                'code' => 'DATABASE_UNAVAILABLE',
            ];
        }

        $checks = ['database' => $database];
        if ($this->sessionProbe !== null) {
            try {
                $session = $this->sessionProbe->inspect();
                $sessionReady = in_array($session['status'] ?? null, ['ready', 'not_required'], true);
            } catch (Throwable) {
                $sessionReady = false;
                $session = ['status' => 'unavailable', 'code' => 'SESSION_STORE_UNAVAILABLE'];
            }
            $checks['sessionStore'] = $session;
            $ready = $ready && $sessionReady;
        }

        return new HealthReport(
            httpStatus: $ready ? 200 : 503,
            payload: [
                'data' => [
                    'service' => 'Velin Music API',
                    'status' => $ready ? 'ready' : 'degraded',
                    'version' => getenv('VELIN_VERSION') ?: '0.1.0-dev',
                    'checks' => $checks,
                ],
                'meta' => [
                    'requestId' => $requestId,
                    'timestamp' => gmdate('c'),
                ],
            ],
        );
    }
}
