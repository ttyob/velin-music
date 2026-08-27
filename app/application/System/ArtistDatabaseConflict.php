<?php

declare(strict_types=1);

namespace app\application\System;

/**
 * 表示艺人库命令与当前上传状态冲突。
 *
 * 常见原因是已有唯一活动上传、分块偏移过期或完成前字节尚未收齐。调用方应重新读取服务端状态；异常
 * 本身不清理临时文件、不替换已发布数据库，也不表示请求可以用猜测偏移自动重放。
 */
final class ArtistDatabaseConflict extends ArtistDatabaseException
{
}
