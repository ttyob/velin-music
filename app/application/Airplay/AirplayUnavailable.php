<?php

declare(strict_types=1);

namespace app\application\Airplay;

/**
 * 表示 AirPlay companion、输出设备、账号租约或请求当前不可用。
 *
 * reasonCode 是 Web API 依赖的稳定机器码；message 只供服务端诊断，Controller 不得把 OwnTone 响应、
 * 输出 ID、媒体票据或局域网信息原样返回。底层异常只保留类型链，不触发非幂等播放命令自动重试。
 */
final class AirplayUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
