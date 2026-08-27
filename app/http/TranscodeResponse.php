<?php

declare(strict_types=1);

namespace app\http;

use app\application\Transcode\TranscodePlan;
use support\Response;

/**
 * Internal response marker transferring a validated plan from Controller dispatch to Http::send.
 *
 * Webman must never serialize this object as a normal body: the custom HTTP process recognizes it
 * and hands it to the non-blocking FFmpeg supervisor. If dispatch aborts before that handoff, the
 * plan-owned lease destructor releases capacity automatically.
 */
final class TranscodeResponse extends Response
{
    /**
     * 响应在 supervisor 启动或完成 FFmpeg 前没有公开 body。
     *
     * deliveryTicketId 只由 DLNA Controller 从已解析票据注入，普通 Web/Subsonic 转码保持 null；该内部 ID
     * 不进入响应头或日志，仅让精确长度发送器记录可丢失的 socket 投递进度。
     */
    public function __construct(
        public readonly TranscodePlan $plan,
        public readonly ?string $deliveryTicketId = null,
    )
    {
        parent::__construct(200);
    }
}
