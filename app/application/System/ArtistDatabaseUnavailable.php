<?php

declare(strict_types=1);

namespace app\application\System;

/**
 * 表示固定艺人库目录无法满足安全写入不变量。
 *
 * 可能由目录权限、空间预留、锁、fsync、回滚硬链接或 rename 失败触发。调用方可稍后重试，但不得改用
 * 任意临时目录或环境变量绕过；已经发布的数据库在可补偿失败中保持原状。
 */
final class ArtistDatabaseUnavailable extends ArtistDatabaseException
{
}
