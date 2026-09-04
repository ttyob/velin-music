<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * RecommendationPluginRegistry 是核心取得推荐插件的最小可替换边界。
 *
 * 实现每次取 Hook 都必须复验安装、启用、待重启/卸载、manifest capability、PHP 接口与插件数据库
 * 版本。推荐服务不能仅凭 capability 字符串动态调用插件，也不能因某个插件失败扩大用户媒体可见范围。
 */
interface RecommendationPluginRegistry
{
    /** @throws \app\application\ResourcePlugin\PhpResourcePluginInvalid */
    public function recommendation(string $key): PluginRecommendationProviderHook;
}
