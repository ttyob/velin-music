<?php

declare(strict_types=1);

namespace app\application\Playback;

use app\application\Auth\UserActorProjector;

/** 复用统一账号投影器，使角色、能力和音乐库授权撤销在后台下载前立即生效。 */
final readonly class LivePlaybackPrefetchActorResolver implements PlaybackPrefetchActorResolver
{
    public function __construct(private UserActorProjector $actors = new UserActorProjector())
    {
    }

    /** @return array<string,mixed>|null */
    public function resolve(string $userId): ?array
    {
        return $this->actors->project($userId);
    }
}
