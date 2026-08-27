<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

/**
 * 表示插件业务命令与当前耐久状态冲突，调用者必须刷新权威投影后再决定是否重试。
 *
 * 异常不携带第三方正文、路径、引用或秘密；Controller 统一映射为 HTTP 409。插件只能在 CAS 未命中、
 * 任务已终结、引用已清除或错误分类明确禁止重放时使用，不能把网络暂时失败伪装成状态冲突。
 */
class PhpResourcePluginOperationConflict extends \RuntimeException
{
}
