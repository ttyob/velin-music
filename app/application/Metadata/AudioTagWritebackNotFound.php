<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/** 合并对象不存在、不可用和失权，防止通过方案或歌曲 ID 枚举其他音乐库。 */
final class AudioTagWritebackNotFound extends RuntimeException {}
