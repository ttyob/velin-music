<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/**
 * 表示专辑资料任务在插件结果校验、实体身份 CAS 或业务事务边界上的稳定失败。
 *
 * reasonCode 只允许固定错误码，不能携带第三方正文、URL 或 SQL；retryable 由任务状态机解释。实体
 * 身份变化属于不可重试的旧结果，插件临时不可用才允许按任务退避重试。
 */
final class AlbumMetadataTaskFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly bool $retryable,
    ) {
        parent::__construct($reasonCode);
    }
}
