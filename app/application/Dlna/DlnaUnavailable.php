<?php

declare(strict_types=1);

namespace app\application\Dlna;

/**
 * 表示 DLNA helper、部署配置、Renderer、账号占用协调或短期票据当前不可用。
 *
 * reasonCode 是 HTTP、CLI 与 Web 客户端共同依赖的稳定机器错误码；message 只用于服务端诊断，不能把
 * Redis、SOAP 或局域网地址原文直接返回客户端。previous 保留底层故障链供受控日志诊断，不改变对外
 * 错误分类，也不会触发自动重试非幂等 Renderer 命令。
 */
final class DlnaUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        ?\Throwable $previous = null,
    )
    {
        parent::__construct($message, 0, $previous);
    }
}
