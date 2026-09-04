<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * PluginAdminPageHook 允许插件只提供一个受核心保护的后台管理页面。
 *
 * 插件必须同时在 manifest 声明 `admin_page` 并提供经过 realpath、MIME 与同源 CSP 校验的 adminPage；
 * 本接口不开放动态路由，也不授予搜索、下载、文件或数据库能力。页面的每次资源请求仍由核心实时验证
 * Cookie Session、`manage_system`、插件启用状态和数据库版本，禁用或待卸载后立即停止访问。
 */
interface PluginAdminPageHook extends PhpResourcePlugin
{
}
