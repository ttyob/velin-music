<?php

declare(strict_types=1);

namespace app\application\Recommendation;

use app\application\ResourcePlugin\Contract\ExternalPlaylistPluginRegistry;
use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use app\application\Playlist\PlatformPlaylistDocument;

/**
 * 把 metadata-scrape 插件的歌单同步 Hook 适配为核心推荐领域网关。
 *
 * provider 显示目录、公开榜单请求和远端同步都由插件完成；核心只接收脱敏条目并负责匹配、事务、版本锁
 * 和审计。插件状态异常统一转成稳定不可用错误，不能退回核心 Helper。
 */
final readonly class PluginPlaylistCatalogGateway implements PublicPlaylistProviderCatalogGateway
{
    public function __construct(
        private ExternalPlaylistPluginRegistry $registry = new PhpResourcePluginRegistry(),
        private string $pluginKey = 'metadata-scrape',
    ) {
    }

    /** @return list<array{key:string,name:string,loginRequired:bool}> */
    public function providers(): array
    {
        try {
            return $this->registry->playlistSync($this->pluginKey)->playlistProviders();
        } catch (\Throwable $exception) {
            throw new PublicPlaylistCatalogUnavailable('PUBLIC_PLAYLIST_PLUGIN_UNAVAILABLE', previous: $exception);
        }
    }

    /** @return list<array{key:string,title:string,description:string,entries:list<array{title:string,artists:list<string>,album:?string,durationMs:?int}>}> */
    public function catalog(string $provider): array
    {
        try {
            return $this->registry->playlistSync($this->pluginKey)->playlistCatalog($provider);
        } catch (PublicPlaylistCatalogUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new PublicPlaylistCatalogUnavailable('PUBLIC_PLAYLIST_PLUGIN_UNAVAILABLE', previous: $exception);
        }
    }

    /**
     * 供同步服务按目录 key 读取最新远端文档；该方法不执行核心持久化。
     */
    public function sync(string $provider, string $playlistKey): PlatformPlaylistDocument
    {
        try {
            return $this->registry->playlistSync($this->pluginKey)->syncPlaylist($provider, $playlistKey);
        } catch (PublicPlaylistCatalogUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new PublicPlaylistCatalogUnavailable('PUBLIC_PLAYLIST_PLUGIN_UNAVAILABLE', previous: $exception);
        }
    }
}
