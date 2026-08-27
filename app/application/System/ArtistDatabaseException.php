<?php

declare(strict_types=1);

namespace app\application\System;

use RuntimeException;

/**
 * 艺人辅助库上传领域错误的稳定基类。
 *
 * 异常消息只供服务内部诊断，Controller 必须按子类映射固定错误码，不能把物理路径、SQLite 错误或
 * 客户端文件名回显给浏览器。该异常不表示业务数据库事务失败，也不会改变当前已发布艺人库。
 */
class ArtistDatabaseException extends RuntimeException
{
}
