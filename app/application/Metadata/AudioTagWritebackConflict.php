<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/** 表示字段、文件身份、库版本或不可变方案在预览后变化；调用方必须重新创建方案。 */
final class AudioTagWritebackConflict extends RuntimeException {}
