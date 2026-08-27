<?php

declare(strict_types=1);

namespace app\application\Playback;

/** 后台预缓存使用的实时账号投影边界；不得返回任务创建时冻结的旧权限。 */
interface PlaybackPrefetchActorResolver
{
    /** @return array<string,mixed>|null 活动账号实时投影；停用、删除或到期返回 null。 */
    public function resolve(string $userId): ?array;
}
