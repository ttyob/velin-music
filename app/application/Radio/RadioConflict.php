<?php

declare(strict_types=1);

namespace app\application\Radio;

use RuntimeException;

/** Signals an optimistic station version changed before a Web mutation committed. */
final class RadioConflict extends RuntimeException
{
}
