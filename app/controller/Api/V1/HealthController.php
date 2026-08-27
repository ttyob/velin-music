<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\System\HealthService;
use app\http\JsonResponseFactory;
use app\infrastructure\Database\SqliteHealthProbe;
use app\infrastructure\System\RedisSessionHealthProbe;
use support\Request;
use support\Response;

/**
 * 暴露无需认证的 API 就绪检查（NFR-OPS-003）。
 *
 * Controller 只组合 SQLite 与 Session 存储探测，不执行业务查询，也不返回路径或环境值。requestId 每次
 * 由服务端生成，不信任客户端关联头，防止外部输入伪造运维追踪身份。
 */
final class HealthController
{
    /** 返回 Webman、SQLite 和当前 Session 存储的就绪状态；降级时使用稳定 HTTP 503 投影。 */
    public function show(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(16));
        $report = (new HealthService(new SqliteHealthProbe(), new RedisSessionHealthProbe()))->inspect($requestId);

        return JsonResponseFactory::create($report->payload, $report->httpStatus, $requestId);
    }
}
