<?php

declare(strict_types=1);

namespace app\application\Scrape;

use JsonException;

/**
 * 管理刮削最终描述字段到冻结渠道键的可持久化来源映射。
 *
 * 来源映射只覆盖审核界面允许人工编辑或逐字段选择的描述字段，不包含时长、编码、路径和文件身份。
 * 历史记录没有映射时，使用发现当前渠道为每个字段生成一致回退；损坏 JSON 失败关闭，不能因为展示
 * 需要而伪造字段来源。该对象只处理有界数组和 JSON，不访问数据库、媒体文件或外部 Provider。
 */
final class ScrapeMetadataFieldSources
{
    /** 审核层允许逐字段选择的稳定字段集合；协议扩展必须同时更新编辑器、前端类型和契约测试。 */
    public const FIELDS = [
        'title', 'artists', 'albumArtists', 'albumTitle', 'trackNumber', 'trackTotal',
        'discNumber', 'discTotal', 'genres', 'releaseDate', 'releaseYear', 'composer', 'isrc',
    ];

    /**
     * 为一个完整候选建立单渠道来源映射。
     *
     * 即使可空字段当前为 null，也保存其渠道：null 本身可能是操作者选择的有效“清空”结果。渠道键
     * 必须使用代码内稳定格式，避免任意文本进入审计、响应或后续 SQL 筛选。
     *
     * @param array<string,mixed> $metadata 已通过候选 schema 校验的规范元数据。
     * @return array<string,string>
     */
    public static function forChannel(array $metadata, string $channelKey): array
    {
        self::assertChannelKey($channelKey);
        $sources = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $metadata)) {
                $sources[$field] = $channelKey;
            }
        }
        return $sources;
    }

    /**
     * 解析数据库来源映射，并为迁移前记录提供确定回退。
     *
     * 非空 JSON 必须是只含已知字段和安全渠道键的对象；未知字段、缺失字符串或超出数量全部拒绝。
     * 空值表示历史记录而非损坏，此时以当前最终渠道生成映射。调用者可捕获异常把整个发现视为不可
     * 编辑，但不得静默采用部分损坏映射。
     *
     * @param array<string,mixed> $metadata 当前最终候选的规范元数据。
     * @return array<string,string>
     * @throws JsonException JSON 语法无效。
     * @throws ScrapeMetadataInvalid 映射字段或渠道键不符合协议。
     */
    public static function fromJson(?string $json, array $metadata, string $fallbackChannel): array
    {
        if ($json === null || trim($json) === '') {
            return self::forChannel($metadata, $fallbackChannel);
        }
        $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || count($payload) > count(self::FIELDS)
            || array_diff(array_keys($payload), self::FIELDS) !== []) {
            throw new ScrapeMetadataInvalid('刮削字段来源快照无效。');
        }
        $sources = [];
        foreach ($payload as $field => $channelKey) {
            if (!is_string($field) || !is_string($channelKey)) {
                throw new ScrapeMetadataInvalid('刮削字段来源快照类型无效。');
            }
            if (!self::isChannelKey($channelKey)) {
                throw new ScrapeMetadataInvalid('刮削字段来源渠道标识无效。');
            }
            $sources[$field] = $channelKey;
        }
        return $sources;
    }

    /**
     * 把一组已校验字段选择合并到当前来源映射。
     *
     * 输入只表达来源决策，不包含字段值。真实值必须由调用者从同一发现的冻结渠道快照读取并校验；
     * 本方法没有副作用，空选择由上层拒绝，避免产生无意义版本递增。
     *
     * @param array<string,string> $current
     * @param array<string,string> $selections
     * @return array<string,string>
     */
    public static function apply(array $current, array $selections): array
    {
        foreach ($selections as $field => $channelKey) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new ScrapeAdminInvalid('字段来源选择包含未知字段。');
            }
            self::assertChannelKey($channelKey);
            $current[$field] = $channelKey;
        }
        return $current;
    }

    /**
     * 编码有界来源映射用于数据库持久化。
     *
     * JSON 不包含元数据值或第三方响应，适合进入发现事实和安全管理响应；编码失败向上传播，调用方
     * 的数据库事务必须整体回滚。
     *
     * @param array<string,string> $sources
     */
    public static function toJson(array $sources): string
    {
        return json_encode($sources, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** 校验代码内稳定渠道键；非法值在任何数据库写入前失败关闭。 */
    public static function assertChannelKey(string $channelKey): void
    {
        if (!self::isChannelKey($channelKey)) {
            throw new ScrapeAdminInvalid('元数据渠道标识无效。');
        }
    }

    /** 判断渠道键是否满足协议格式，不产生异常或其他副作用。 */
    private static function isChannelKey(string $channelKey): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $channelKey) === 1;
    }
}
