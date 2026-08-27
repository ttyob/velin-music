<?php

declare(strict_types=1);

namespace app\application\Playback;

use RuntimeException;

/**
 * 表示播放接口收到不受支持或来自错误认证边界的单次音质覆盖。
 *
 * 目前只有经轮换 App access token 认证的客户端可以提交 `original|standard`；Cookie Web 与 PAT 省略参数
 * 后继续使用账号偏好。异常不保存原始查询值，必须在媒体输入、转码槽和 FFmpeg 启动前映射为稳定 422，
 * 防止畸形枚举进入编码器参数或通过日志形成不受控内容。
 */
final class PlaybackQualityRequestInvalid extends RuntimeException
{
}
