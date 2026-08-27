<?php

declare(strict_types=1);

namespace app\application\Media;

/**
 * 为 HTTP 播放与后台预缓存提供同一套实时媒体解析边界。
 *
 * 实现必须重新验证账号的音乐库范围和媒体身份，不能把任务入队时的歌曲可见性当作长期授权。返回对象只在
 * 当前调用内使用，路径、远端坐标和凭据不得持久化到任务表、日志或公开响应。
 */
interface MediaStreamResolver
{
    /**
     * @param array<string,mixed> $actor 当前时刻重新构造的活动账号投影。
     * @throws MediaStreamNotFound 歌曲不存在或已经离开账号授权范围。
     * @throws MediaStreamUnavailable 文件或远端对象身份无法继续证明。
     */
    public function resolve(array $actor, string $songId): PlayableMedia;
}
