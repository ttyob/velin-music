<?php

declare(strict_types=1);

namespace app\application\Artist;

use RuntimeException;

/**
 * 表示艺人资料任务在插件结果校验、身份 CAS 或业务事务边界上的稳定失败。
 *
 * 该异常不承载第三方正文、URL 或网络细节；reasonCode 只用于任务退避和脱敏日志，retryable 由核心
 * 状态机解释。第三方请求本身完全属于插件，不应在核心重新实现远程错误分类。
 */
final class ArtistProfileTaskFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly bool $retryable,
    ) {
        parent::__construct($reasonCode);
    }
}
