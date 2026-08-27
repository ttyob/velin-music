<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * 定义内置查询器的艺人资料图专用端口。
 *
 * 该端口与歌曲元数据查询分离，避免把代表歌曲的专辑图误当成艺人肖像。调用方只传递已由实体授权
 * 边界冻结的艺人名和代表歌曲描述，不传文件路径、平台 ID、URL 或凭据；实现必须按输入渠道顺序返回
 * 有界结果，单渠道失败不得阻断其他渠道。第三方 URL 只允许在当前 Worker 调用栈内短暂存在，后续必须
 * 经固定 CDN、DNS、图片签名和尺寸校验后冻结，不能进入业务数据库、审计或浏览器投影。
 */
interface ArtistArtworkLookupGateway
{
    /**
     * 查询真实艺人资料图，并用同平台代表歌曲关系约束同名艺人。
     *
     * @param array{title:string,artists:list<string>,album:?string,durationMs:?int} $representativeSong
     * @param list<string> $sources 固定内置平台键，顺序即管理员配置的优先级。
     * @return list<array<string,mixed>> 每个平台恰好一条脱敏前的进程内结果。
     */
    public function lookup(string $artist, array $representativeSong, array $sources): array;
}
