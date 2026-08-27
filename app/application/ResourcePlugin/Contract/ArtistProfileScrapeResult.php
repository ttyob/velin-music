<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ArtistProfileScrapeResult 是艺人资料插件返回的脱敏最终结论。
 *
 * 插件内部完成第三方请求、身份复验和字段解析；核心只接收可写入业务表的通用资料，不接收候选列表、
 * 请求 URL、原始响应或平台私有诊断。unavailable 表示插件或必需 MusicBrainz 服务暂时不可用，
 * unmatched 表示请求正常但没有唯一身份；两者必须由核心任务状态机区别处理。
 */
final readonly class ArtistProfileScrapeResult
{
    public const MATCHED = 'matched';
    public const UNMATCHED = 'unmatched';
    public const UNAVAILABLE = 'unavailable';

    /** @var self::MATCHED|self::UNMATCHED|self::UNAVAILABLE */
    public string $status;

    /** @var null|array<string,mixed> */
    public ?array $profile;

    public function __construct(string $status, ?array $profile = null)
    {
        if (!in_array($status, [self::MATCHED, self::UNMATCHED, self::UNAVAILABLE], true)) {
            throw new \InvalidArgumentException('ARTIST_PROFILE_RESULT_STATUS_INVALID');
        }
        if ($status === self::MATCHED && $profile === null) {
            throw new \InvalidArgumentException('ARTIST_PROFILE_RESULT_PROFILE_REQUIRED');
        }
        if ($status !== self::MATCHED && $profile !== null) {
            throw new \InvalidArgumentException('ARTIST_PROFILE_RESULT_PROFILE_UNEXPECTED');
        }
        $this->status = $status;
        $this->profile = $profile;
    }
}
