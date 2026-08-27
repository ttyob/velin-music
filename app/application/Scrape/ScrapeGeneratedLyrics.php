<?php

declare(strict_types=1);

namespace app\application\Scrape;

use app\application\Lyrics\LyricsParseFailed;
use app\application\Lyrics\LyricsParser;
use JsonException;

/**
 * 表示一次刮削确认所冻结的同名 LRC 内容。
 *
 * 正文必须是可解析、非空、最多 1 MiB 的 UTF-8 歌词；source 只能是固定旧平台键或已经安装、在包内完成
 * 来源取舍的 `metadata-scrape` 插件键。对象不包含平台资源 ID、URL 或认证信息，可安全进入发现和任务
 * 快照。SHA-256 在恢复时复验，防止数据库损坏后写入文件。
 */
final readonly class ScrapeGeneratedLyrics
{
    private const SOURCES = ['netease', 'qq', 'kugou', 'kuwo', 'migu', 'soda', 'lrclib', 'metadata-scrape'];

    /**
     * 构造并解析歌词，拒绝空白、二进制、无效编码和超限正文。
     * 构造没有文件或数据库副作用；解析失败统一转换为刮削元数据错误，不回显歌词内容。
     */
    public function __construct(public string $source, public string $lrc)
    {
        if (!in_array($source, self::SOURCES, true) || $lrc === '' || strlen($lrc) > 1_048_576
            || !mb_check_encoding($lrc, 'UTF-8')) {
            throw new ScrapeMetadataInvalid('刮削歌词快照无效。');
        }
        try {
            (new LyricsParser())->parse($lrc);
        } catch (LyricsParseFailed $exception) {
            throw new ScrapeMetadataInvalid('刮削歌词无法解析。', previous: $exception);
        }
    }

    /** 编码确定性快照；相同来源与正文产生相同摘要。 */
    public function toJson(): string
    {
        return json_encode([
            'version' => 1,
            'source' => $this->source,
            'lrc' => $this->lrc,
            'sha256' => hash('sha256', $this->lrc),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 恢复任务快照并复验正文摘要。
     *
     * @throws JsonException JSON 损坏。
     * @throws ScrapeMetadataInvalid 字段、正文或摘要不符合当前协议。
     */
    public static function fromJson(string $json): self
    {
        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || !is_string($value['source'] ?? null)
            || !is_string($value['lrc'] ?? null) || !is_string($value['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $value['sha256']) !== 1
            || !hash_equals($value['sha256'], hash('sha256', $value['lrc']))) {
            throw new ScrapeMetadataInvalid('刮削歌词快照损坏。');
        }
        return new self($value['source'], $value['lrc']);
    }
}
