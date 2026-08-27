<?php

declare(strict_types=1);

namespace app\application\Theme;

/** Signals that a theme command or semantic token set failed the closed validation contract. */
final class ThemeInvalid extends \RuntimeException
{
}
