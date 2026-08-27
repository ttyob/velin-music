<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Library\WebDavRangeProxySession;

/**
 * 持有 FFmpeg 输入定位符及其可选远端代理生命周期。
 *
 * locator 只进入固定 argv 的 `-i` 参数，不进入日志或响应。远端会话关闭会终止未完成 Range 请求；本地
 * 租约 close 为幂等空操作。所有权从转码协商转移给 TranscodePlan，再由 FfmpegTranscodeRunner 回收。
 */
final class MediaInputLease
{
    private bool $closed = false;

    public function __construct(
        public readonly string $locator,
        private readonly ?WebDavRangeProxySession $session = null,
    ) {
    }

    /** 幂等关闭远端代理；不删除本地源文件或修改 WebDAV 对象。 */
    public function close(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        $this->session?->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
