<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\application\ResourcePlugin\Contract\ExternalPlaylistPluginRegistry;
use app\application\ResourcePlugin\PhpResourcePluginRegistry;

/**
 * 通过资源插件注册表取得公开歌单识别能力。
 *
 * 核心只固定插件 key 和统一异常边界；插件停用、版本不一致或 Hook 缺失时失败关闭，不存在核心渠道
 * 兼容实现。该网关不写数据库、不保存 URL，调用方负责后续匹配和导入事务。
 */
final readonly class PluginPlaylistIdentificationGateway
{
    public function __construct(
        private ExternalPlaylistPluginRegistry $registry = new PhpResourcePluginRegistry(),
        private string $pluginKey = 'metadata-scrape',
    ) {
    }

    /**
     * @throws PlaylistLinkInvalid
     * @throws PlaylistLinkUnavailable 插件未安装、停用或平台请求失败。
     */
    public function identify(string $source, string $link): PlatformPlaylistDocument
    {
        try {
            return $this->registry->playlistIdentification($this->pluginKey)->identifyPlaylist($source, $link);
        } catch (PlaylistLinkInvalid|PlaylistLinkUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new PlaylistLinkUnavailable('playlist_plugin_unavailable', '平台歌单插件不可用。', $exception);
        }
    }

    /** @return list<array{key:string,name:string}> */
    public function sources(): array
    {
        try {
            $declared = $this->registry->playlistIdentification($this->pluginKey)->playlistSources();
            if (!array_is_list($declared) || count($declared) > 64) {
                throw new \RuntimeException('PLAYLIST_SOURCE_DECLARATION_INVALID');
            }
            $result = [];
            foreach ($declared as $source) {
                if (!is_array($source) || array_diff(array_keys($source), ['key', 'name']) !== []
                    || !is_string($source['key'] ?? null) || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $source['key']) !== 1
                    || isset($result[$source['key']]) || !is_string($source['name'] ?? null)
                    || trim($source['name']) === '' || mb_strlen($source['name']) > 100) {
                    throw new \RuntimeException('PLAYLIST_SOURCE_DECLARATION_INVALID');
                }
                $result[$source['key']] = ['key' => $source['key'], 'name' => trim($source['name'])];
            }
            return array_values($result);
        } catch (\Throwable $exception) {
            throw new PlaylistLinkUnavailable('playlist_plugin_unavailable', '平台歌单插件不可用。', $exception);
        }
    }
}
