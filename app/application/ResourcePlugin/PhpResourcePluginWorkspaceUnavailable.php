<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use RuntimeException;

/**
 * PHP 插件工作目录无法按核心所有权和路径隔离规则分配。
 *
 * 异常只携带稳定错误码，不暴露宿主物理路径。调用插件应把它转换为自己的领域错误；核心不得在失败后
 * 回退到音乐库、插件包目录或系统临时目录，否则会绕过媒体发布与卸载清理边界。
 */
final class PhpResourcePluginWorkspaceUnavailable extends RuntimeException
{
}
