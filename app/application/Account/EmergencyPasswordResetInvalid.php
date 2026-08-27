<?php

declare(strict_types=1);

namespace app\application\Account;

use InvalidArgumentException;

/**
 * 表示本机应急改密命令的目标账号、密码或强确认条件不满足公开契约。
 *
 * 该异常刻意不区分账号不存在、已删除和密码格式错误等细节，避免终端自动化把内部账号状态当作
 * 可枚举接口；异常消息绝不能包含用户名、密码、哈希或 Subsonic 密文。
 */
final class EmergencyPasswordResetInvalid extends InvalidArgumentException
{
}
