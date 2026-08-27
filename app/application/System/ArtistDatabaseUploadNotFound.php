<?php

declare(strict_types=1);

namespace app\application\System;

/**
 * 表示不透明艺人库上传会话不存在、已过期或已结束。
 *
 * 对畸形 ID、未知 ID 与过期 ID 使用同一异常，避免枚举服务端临时文件。该异常不会删除已发布数据库，
 * 客户端应重新读取状态并在需要时创建新会话。
 */
final class ArtistDatabaseUploadNotFound extends ArtistDatabaseException
{
}
