<?php

declare(strict_types=1);

namespace app\application\Playlist;

/** 公开歌单链接不符合固定平台、HTTPS、主机和长度约束；不会产生网络或持久化副作用。 */
final class PlaylistLinkInvalid extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode = 'invalid_playlist_link')
    {
        parent::__construct('Playlist link is invalid.');
    }
}
