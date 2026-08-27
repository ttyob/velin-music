<?php

declare(strict_types=1);

namespace app\application\System;

/**
 * 表示上传输入或 SQLite 内容不符合艺人库契约。
 *
 * 包括扩展名、大小、分块摘要、SQLite magic、完整性、必需表列与最低行数失败。完成阶段抛出时，服务
 * 会清理本次临时文件并保留旧库；Controller 只能返回稳定 422，不能回显底层 SQLite 消息。
 */
final class ArtistDatabaseInvalid extends ArtistDatabaseException
{
}
