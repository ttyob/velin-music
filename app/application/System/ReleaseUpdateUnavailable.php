<?php

declare(strict_types=1);

namespace app\application\System;

use RuntimeException;

/** 官方版本源不可用或返回了不符合发布合同的数据。 */
final class ReleaseUpdateUnavailable extends RuntimeException
{
}
