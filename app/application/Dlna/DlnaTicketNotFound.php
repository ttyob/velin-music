<?php

declare(strict_types=1);

namespace app\application\Dlna;

/** 投放票据不存在、过期、撤销或其实时权限已经失效。 */
final class DlnaTicketNotFound extends \RuntimeException
{
}
