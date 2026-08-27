<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

/**
 * 提供后台唯一的插件目录投影。
 *
 * Velin 只保留管理员上传的受信 PHP 插件模型；插件自己的配置、搜索、下载、API 与 Worker 都随包交付。
 * 本服务不再发现或启停外部二进制来源，也不把两套互不兼容的安全边界混在同一后台响应中。
 */
final readonly class ResourcePluginManager
{
    public function __construct(private PhpResourcePluginRegistry $registry = new PhpResourcePluginRegistry())
    {
    }

    /** @return array{plugins:list<array<string,mixed>>} */
    public function list(): array
    {
        return ['plugins' => $this->registry->list()];
    }
}
