<?php

declare(strict_types=1);

namespace app\application\User;

/** 后台用户编辑校验结果；错误只按固定字段返回，不包含提交正文或目标账号秘密。 */
final readonly class UserUpdateValidationResult
{
    /** @param array<string,list<string>> $errors */
    public function __construct(public ?UserUpdateInput $input, public array $errors)
    {
    }

    /** 成功结果必须同时具有输入对象且没有字段错误。 */
    public function isValid(): bool
    {
        return $this->input instanceof UserUpdateInput && $this->errors === [];
    }
}
