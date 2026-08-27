<?php

declare(strict_types=1);

namespace app\application\Scrape;

use app\application\Media\MediaMetadata;

/**
 * 在统一扫描边界为缺少可靠身份标签的媒体生成文件名查询证据。
 *
 * 本类只扩充将要持久化的安全标签快照，不修改 MediaMetadata、展示字段或音频文件。`filename_only` 与
 * Range 失败记录没有可信标签，因此完整采用共享解析器；本地或 Range 探测成功时，只有 title、artist、
 * album 任一标签缺失才生成证据，并用仍然存在的真实标签覆盖对应解析值。这样所有入口共享同一套文件名
 * 去噪和方向规则，同时保证文件名猜测永远不能降级已有标签。返回值不含路径，失败时保留原快照。
 */
final readonly class FilenameScrapeEvidenceEnricher
{
    public function __construct(
        private FilenameScrapeEvidenceParser $parser = new FilenameScrapeEvidenceParser(),
    ) {
    }

    /**
     * 生成可由逐曲任务冻结的无路径查询变体。
     *
     * relativePath 必须来自已受音乐库根约束的库存行；方法只在内存中处理，最多保留两个变体。FFprobe
     * 快照必须包含 format 或 audioStream 域，路径降级快照必须包含受控 velin.remoteProbe 标记；其他
     * 未知历史结构原样返回，避免把测试夹具或旧数据误判为无标签媒体。可靠标签完整时会主动移除同一
     * 快照中的陈旧文件名证据，保证重新扫描可撤销降级状态。
     *
     * @return array<string,mixed> 可安全 JSON 编码的标签快照，不包含相对路径或原文件名
     */
    public function enrich(MediaMetadata $metadata, string $relativePath): array
    {
        $rawTags = $metadata->rawTags;
        $velin = is_array($rawTags['velin'] ?? null) ? $rawTags['velin'] : [];
        $pathOnly = in_array($velin['remoteProbe'] ?? null, ['skipped', 'degraded'], true);
        $hasProbeScopes = array_key_exists('format', $rawTags) || array_key_exists('audioStream', $rawTags);
        if (!$pathOnly && !$hasProbeScopes) return $rawTags;

        $taggedTitle = $this->hasTag($rawTags, 'title');
        $taggedArtist = $this->hasTag($rawTags, 'artist');
        $taggedAlbum = $this->hasTag($rawTags, 'album');
        if (!$pathOnly && $taggedTitle && $taggedArtist && $taggedAlbum) {
            unset($velin['filenameScrapeEvidence']);
            if ($velin === []) unset($rawTags['velin']);
            else $rawTags['velin'] = $velin;
            return $rawTags;
        }

        $evidence = $this->parser->parse($relativePath);
        if ($evidence === null) return $rawTags;
        $variants = [];
        foreach ($evidence['variants'] as $variant) {
            if ($taggedTitle) $variant['title'] = $metadata->title;
            if ($taggedArtist) {
                $variant['artists'] = $metadata->artists;
                // 可信标签继续沿用普通元数据评分；不能借一次文件名字段回退把标签艺人升级为硬门槛。
                $variant['artistRequired'] = false;
            }
            if ($taggedAlbum) $variant['albumTitle'] = $metadata->albumTitle;
            if (!in_array($variant, $variants, true)) $variants[] = $variant;
        }
        if ($variants === []) return $rawTags;

        $evidence['variants'] = array_slice($variants, 0, 2);
        $velin['filenameScrapeEvidence'] = $evidence;
        $rawTags['velin'] = $velin;
        return $rawTags;
    }

    /** 已规范化的 FFprobe 标签只接受非空标量，未知嵌套结构不能获得可信标签资格。 */
    private function hasTag(array $rawTags, string $key): bool
    {
        foreach (['format', 'audioStream'] as $scope) {
            $value = is_array($rawTags[$scope] ?? null) ? ($rawTags[$scope][$key] ?? null) : null;
            if ((is_string($value) || is_numeric($value)) && trim((string) $value) !== '') return true;
        }
        return false;
    }
}
