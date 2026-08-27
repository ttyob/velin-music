<?php

declare(strict_types=1);

namespace app\application\Radio;

use RuntimeException;

/** Indicates malformed station metadata was rejected before persistence or network use. */
final class RadioInvalid extends RuntimeException
{
}
