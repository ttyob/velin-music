<?php

declare(strict_types=1);

namespace app\application\Playback;

use RuntimeException;

/**
 * 表示 Web 播放请求中的受限控制参数不符合协议。
 *
 * 该异常只承载已经去敏的客户端输入错误，不包含媒体路径、FFmpeg 参数或账号信息。
 * Controller 必须把它映射为稳定的 422 响应；失败不得启动转码进程、取得并发槽或读取媒体字节。
 */
final class WebPlaybackRequestInvalid extends RuntimeException
{
}
