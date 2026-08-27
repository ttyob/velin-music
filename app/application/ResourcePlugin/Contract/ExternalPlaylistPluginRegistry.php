<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalPlaylistPluginRegistry 是核心获取歌单插件能力的最小注册表。
 *
 * 实现必须在每次取 Hook 时复验活动标记、manifest capability、PHP 接口和插件数据库版本；核心不根据
 * 平台名称猜测实现，也不在插件缺失时回退旧核心 Helper。
 */
interface ExternalPlaylistPluginRegistry
{
    /** @throws \app\application\ResourcePlugin\PhpResourcePluginInvalid */
    public function playlistIdentification(string $key): ExternalPlaylistIdentificationHook;

    /** @throws \app\application\ResourcePlugin\PhpResourcePluginInvalid */
    public function playlistSync(string $key): ExternalPlaylistSyncHook;
}
