<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\http\JsonResponseFactory;
use app\http\RequestContext;
use RuntimeException;
use support\Request;
use support\Response;

/** 标记账号已登录但默认音乐库尚未完成配置。 */
final class LibrarySetupRequired extends RuntimeException
{
    /** 普通 API 在默认库完成前统一返回可识别状态，避免把未初始化实例当作普通 500。 */
    public function render(Request $request): Response
    {
        return JsonResponseFactory::error(
            'SETUP_LIBRARY_REQUIRED',
            '请先由管理员设置默认音乐库。',
            423,
            RequestContext::requestId(),
        );
    }
}
