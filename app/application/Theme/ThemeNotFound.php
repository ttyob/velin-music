<?php

declare(strict_types=1);

namespace app\application\Theme;

/** Merges absent, soft-deleted, and visibility-filtered theme resources at the API boundary. */
final class ThemeNotFound extends \RuntimeException
{
}
