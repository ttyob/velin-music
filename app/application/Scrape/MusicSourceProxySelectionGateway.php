<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 提供固定数据源到命名代理 profile 的选择，不包含代理地址或凭据。
 *
 * 该端口与渠道启停目录分离，使测试和替代目录可以保持直连默认；生产实现只能从 `music_sources`
 * 固定九行读取布尔值，不能根据浏览器参数、环境代理或第三方响应临时改变路由。
 */
interface MusicSourceProxySelectionGateway
{
    /**
     * 返回已启用数据源到代理 profile ID 的映射；未出现的渠道保持直连。
     *
     * capability 的语义与 MusicSourceCatalogGateway 相同；该方法不保证全局代理已启用，运行时仍需读取
     * 独立版本化系统设置并在不可用时失败关闭。
     *
     * @return array<string,string>
     */
    public function proxyProfileIdsBySource(?string $capability = null): array;
}
