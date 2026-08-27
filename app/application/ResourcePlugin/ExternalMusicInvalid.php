<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use RuntimeException;

/**
 * 表示统一外部音乐 API 的请求结构、标识符或有界字段不符合公开协议。
 *
 * 该异常只描述客户端可修正的 422 条件，不携带搜索词、租约、音乐库 ID 或插件原始异常。抛出前不得调用
 * 插件或产生数据库、网络和文件副作用；Controller 使用固定中文消息响应，不能把异常正文直接回显。
 */
final class ExternalMusicInvalid extends RuntimeException
{
}
