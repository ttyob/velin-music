<?php

declare(strict_types=1);

namespace app\application\User;

use RuntimeException;

/**
 * 表示全局系统限制拒绝了一个新动作。
 *
 * 仅携带固定原因码和整数用量，Controller 可以安全返回 current/maximum；对象 ID、路径、策略行和
 * 管理员备注不进入异常，避免日志或协议响应泄露资源信息。类名为兼容既有 Controller 错误映射暂时
 * 保留；它不再读取或表达任何用户级配置。
 */
final class UserRuntimeLimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $current,
        public readonly int $maximum,
    ) {
        parent::__construct($reasonCode);
    }
}
