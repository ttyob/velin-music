<?php

declare(strict_types=1);

namespace app\application\Playlist;

/** 公开歌单解析器启动、网络请求或协议失败；调用方应保持无歌单写入并允许重试。 */
final class PlaylistLinkUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'provider_unavailable',
        string $message = 'Playlist link is unavailable.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
