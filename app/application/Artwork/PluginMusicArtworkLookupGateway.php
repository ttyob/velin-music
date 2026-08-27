<?php

declare(strict_types=1);

namespace app\application\Artwork;

use app\application\Scrape\MusicSourceLookupGateway;

/**
 * PluginMusicArtworkLookupGateway 限定歌曲与专辑封面只能由元数据插件提供。
 *
 * 该标记端口故意继承历史查询形状，以复用后续身份与图片安全校验；核心没有实现类，不能装配插件外
 * 回退。调用方只传稳定插件键，不读取核心渠道目录或代理；插件失败返回空结果，由图片任务按无候选
 * 或可重试失败独立收口。
 */
interface PluginMusicArtworkLookupGateway extends MusicSourceLookupGateway
{
}
