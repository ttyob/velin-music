<?php

declare(strict_types=1);

namespace app\application\Recommendation;

/**
 * 表示平台歌单目录、详情或同步暂时不可用。
 *
 * 该异常必须拥有与类名一致的独立 PSR-4 文件，使插件 Helper 在接口尚未预加载时也能直接抛出稳定领域
 * 错误。异常不携带第三方 URL、响应或凭据；调用方可把固定消息映射为公开错误码，并保留上次成功歌单。
 * 构造本身没有数据库或网络副作用，重试与失败冷却由推荐服务和 Worker 决定。
 */
final class PublicPlaylistCatalogUnavailable extends \RuntimeException
{
}
