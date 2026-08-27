<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

/**
 * 表示统一搜索响应中的单个脱敏结果。
 *
 * 插件返回的关联数组在进入 Controller 前必须转换为本对象。投影只保留跨来源稳定的展示字段和随机租约
 * ID，未知字段会被丢弃，因此插件即使误返回 URL、Cookie、平台资源 ID、签名或物理路径，也不会穿过
 * 核心 API 边界。对象不验证租约数据库事实；下载时仍由同一插件实时复验所有权与有效期。
 */
final readonly class ExternalMusicSearchItem
{
    private function __construct(
        private string $pluginKey,
        private string $pluginName,
        private string $resourceType,
        private ?string $leaseId,
        private string $title,
        private ?string $artist,
        private ?string $album,
        private ?int $durationMs,
        private string $source,
        private ?string $quality,
        private ?string $format,
        private ?int $sizeBytes,
        private bool $downloadable,
        private ?string $publishedAt,
        private ?int $seeders,
        private ?int $leechers,
    ) {
    }

    /**
     * 验证并裁剪一个插件搜索条目。
     *
     * 可下载结果必须使用 ULID 作为 actor 绑定租约；不可下载结果不向客户端暴露插件内部 id。title 是
     * 唯一必需的业务文本，source 缺省时回退为插件名称。数值必须非负，所有文本拒绝无效 UTF-8 和控制
     * 字符。任何协议错误都会使当前提供方整体失败，而不会返回一批真假租约混杂的结果。
     *
     * @param array<string,mixed> $item 插件钩子的未信任投影
     * @throws ExternalMusicUnavailable 插件响应不符合统一协议
     */
    public static function fromPluginItem(
        string $pluginKey,
        string $pluginName,
        string $resourceType,
        array $item,
    ): self
    {
        if (!in_array($resourceType, ['track', 'album'], true)
            || (array_key_exists('resourceType', $item) && $item['resourceType'] !== $resourceType)) {
            throw new ExternalMusicUnavailable();
        }
        $downloadable = $item['downloadable'] ?? null;
        if (!is_bool($downloadable)) throw new ExternalMusicUnavailable();
        $leaseId = null;
        if ($downloadable) {
            $id = $item['id'] ?? null;
            if (!is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1) {
                throw new ExternalMusicUnavailable();
            }
            $leaseId = $id;
        }

        $title = self::text($item['title'] ?? null, 500, false);
        $source = self::text($item['source'] ?? $item['indexer'] ?? $pluginName, 160, false);
        $publishedAt = self::text($item['publishedAt'] ?? null, 32, true);
        if ($publishedAt !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $publishedAt) !== 1) {
            throw new ExternalMusicUnavailable();
        }

        return new self(
            $pluginKey,
            $pluginName,
            $resourceType,
            $leaseId,
            $title,
            self::text($item['artist'] ?? null, 300, true),
            self::text($item['album'] ?? null, 300, true),
            self::nonNegativeInteger($item['durationMs'] ?? null),
            $source,
            self::text($item['quality'] ?? null, 40, true),
            self::text($item['format'] ?? null, 24, true),
            self::nonNegativeInteger($item['sizeBytes'] ?? null),
            $downloadable,
            $publishedAt,
            self::nonNegativeInteger($item['seeders'] ?? null),
            self::nonNegativeInteger($item['leechers'] ?? null),
        );
    }

    /**
     * 返回固定字段顺序的 JSON 安全投影。
     *
     * 所有来源都返回相同字段集合，缺失事实使用 null；不附带插件原始数组或 extensions 字段，避免以后
     * 新插件借“扩展信息”绕过核心脱敏合同。
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'pluginKey' => $this->pluginKey,
            'pluginName' => $this->pluginName,
            'resourceType' => $this->resourceType,
            'leaseId' => $this->leaseId,
            'title' => $this->title,
            'artist' => $this->artist,
            'album' => $this->album,
            'durationMs' => $this->durationMs,
            'source' => $this->source,
            'quality' => $this->quality,
            'format' => $this->format,
            'sizeBytes' => $this->sizeBytes,
            'downloadable' => $this->downloadable,
            'publishedAt' => $this->publishedAt,
            'seeders' => $this->seeders,
            'leechers' => $this->leechers,
        ];
    }

    /** 读取并验证插件展示文本；nullable 只允许缺失，不接受空串伪装为有效事实。 */
    private static function text(mixed $value, int $maximum, bool $nullable): ?string
    {
        if ($value === null && $nullable) return null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new ExternalMusicUnavailable();
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) throw new ExternalMusicUnavailable();
        return $value;
    }

    /** 只接受可由 JSON 精确表达的非负整数；null 表示插件没有该事实。 */
    private static function nonNegativeInteger(mixed $value): ?int
    {
        if ($value === null) return null;
        if (!is_int($value) || $value < 0) throw new ExternalMusicUnavailable();
        return $value;
    }
}
