<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * PluginArtistArtworkLookupGateway 限定艺人资料图只能由元数据插件提供。
 *
 * 端口保留代表歌曲身份约束，但核心没有实现类，因而不能在插件缺失、停用或异常时成为回退。实现不得
 * 读取核心固定渠道设置；第三方 URL 仍只能在当前调用栈内短暂存在，并
 * 必须经过调用方的 CDN、DNS、图片签名、尺寸和许可校验后才能冻结。
 */
interface PluginArtistArtworkLookupGateway extends ArtistArtworkLookupGateway
{
}
