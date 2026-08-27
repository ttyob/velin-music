<?php

declare(strict_types=1);

namespace app\application\Scrape;

use app\application\ResourcePlugin\Contract\ExternalMetadataScrapePluginRegistry;
use app\application\ResourcePlugin\Contract\MetadataScrapeCompletionRequest;
use app\application\ResourcePlugin\Contract\MetadataScrapeCompletionResult;
use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use Throwable;

/**
 * PluginMetadataEnrichmentProvider 把唯一的歌曲元数据插件接入核心审批与发布链路。
 *
 * 当前只识别稳定 key `metadata-scrape`，避免多个已安装插件被隐式排序后改变歌曲结果。插件只收到已知
 * 文件元数据或未解析的文件名上下文，且其 `matched` 结论不会再和核心或其他渠道候选比较；本类只把该
 * 最终结论适配回既有审批与发布链路，并只生成一条插件级结果。插件不存在、停用、协议失败或 temporary
 * unavailable 时，核心只返回扫描阶段冻结的文件元数据候选，并保留 unavailable 状态供 Worker 重试或
 * 失败收口。文件名上下文只可作为插件请求输入，不能由核心解析成本地候选；attemptIdentity 只被转换为
 * 插件内部任务关联键，不会进入 Helper。核心不会在这些分支调用核心音乐平台 Helper 或第三方网络。本类不写数据库。
 */
final readonly class PluginMetadataEnrichmentProvider implements MetadataEnrichmentProvider
{
    private const PLUGIN_KEY = 'metadata-scrape';

    public function __construct(private ExternalMetadataScrapePluginRegistry $plugins = new PhpResourcePluginRegistry())
    {
    }

    public function enrich(
        ScrapeMetadataCandidate $candidate,
        array $queryKeywords,
        ?string $attemptIdentity = null,
        ?array $selectedSources = null,
    ): MetadataEnrichmentResult {
        if ($selectedSources !== null && !in_array(self::PLUGIN_KEY, $selectedSources, true)) {
            return $this->localOnly($candidate, true);
        }
        foreach ($this->plugins->list() as $plugin) {
            if (($plugin['key'] ?? null) !== self::PLUGIN_KEY
                || ($plugin['valid'] ?? false) !== true
                || ($plugin['enabled'] ?? true) !== true
                || ($plugin['databaseInstalled'] ?? false) !== true
                || !in_array('metadata_scrape', $plugin['capabilities'] ?? [], true)) {
                continue;
            }
            try {
                $result = $this->plugins->metadataScrape(self::PLUGIN_KEY)
                    ->scrapeMetadata($this->request($candidate, $attemptIdentity));
                if ($result->status === MetadataScrapeCompletionResult::UNAVAILABLE) break;
                return $this->adapt($candidate, $result);
            } catch (Throwable) {
                // 插件故障不允许扩大到核心第三方查询；本轮只保留文件候选，Worker 会按统一租约策略重试。
                break;
            }
        }
        return $this->localOnly($candidate, true);
    }

    /**
     * 在歌曲插件不可用或未被当前确认选择时收口本地结果。
     *
     * 本地候选只来自扫描阶段已经读取并冻结的音频标签与技术事实；未解析文件名上下文即使随插件请求
     * 冻结，也不能在失败分支成为 raw、scraped 或 local 值。本方法不读取网络、不启动 Helper、不生成
     * 歌词或第三方封面，也不把本地值伪装成外部匹配。unavailable 渠道只用于让 Worker 区分“插件暂时
     * 不可用”和“插件明确无匹配”，不会携带异常正文或外部响应。
     */
    private function localOnly(ScrapeMetadataCandidate $candidate, bool $pluginUnavailable): MetadataEnrichmentResult
    {
        $candidate = $this->fileMetadataCandidate($candidate);
        $channels = [new MetadataProviderResult(
            'local', '本地文件元数据', 'matched', $candidate,
        )];
        if ($pluginUnavailable) {
            $channels[] = new MetadataProviderResult(
                self::PLUGIN_KEY, '元数据刮削插件', 'unavailable', null,
            );
        }
        return new MetadataEnrichmentResult($candidate, $channels);
    }

    /** 将本地候选中允许公开给插件的两种输入冻结为通用请求。 */
    private function request(ScrapeMetadataCandidate $candidate, ?string $taskIdentity): MetadataScrapeCompletionRequest
    {
        $metadata = $candidate->metadata;
        if (($metadata['scrapeInputMode'] ?? null) === MetadataScrapeCompletionRequest::MODE_FILENAME
            && is_array($metadata['filenameContext'] ?? null)) {
            $context = $metadata['filenameContext'];
            return MetadataScrapeCompletionRequest::fromFilename(
                (string) ($context['fileName'] ?? ''),
                is_string($context['parentDirectory'] ?? null) ? $context['parentDirectory'] : null,
                is_string($context['grandparentDirectory'] ?? null) ? $context['grandparentDirectory'] : null,
                $taskIdentity,
            );
        }
        return MetadataScrapeCompletionRequest::fromMetadata(
            (string) ($metadata['title'] ?? ''),
            is_array($metadata['artists'] ?? null) ? array_values($metadata['artists']) : [],
            is_string($metadata['albumTitle'] ?? null) && trim($metadata['albumTitle']) !== '' ? $metadata['albumTitle'] : null,
            is_array($metadata['albumArtists'] ?? null) && $metadata['albumArtists'] !== []
                ? array_values($metadata['albumArtists']) : null,
            $taskIdentity,
        );
    }

    /**
     * 将插件唯一 final result 映射到历史 Worker 使用的结果对象。
     *
     * 元数据只覆盖插件明确提供的通用字段，本地时长等技术事实保持不变；字段 evidence 仅记录已经越过
     * 插件边界的实际字段，供审核层显示来源。歌词和封面仍由核心对象做格式与下载前校验，构造失败只丢弃
     * 相应派生资源，不能推翻已可信的描述元数据。
     */
    private function adapt(ScrapeMetadataCandidate $local, MetadataScrapeCompletionResult $result): MetadataEnrichmentResult
    {
        if (!$this->resultMatchesFrozenIdentity($local, $result)) {
            return new MetadataEnrichmentResult($this->fileMetadataCandidate($local), [
                new MetadataProviderResult(self::PLUGIN_KEY, '元数据刮削插件', 'unmatched', null),
            ]);
        }
        $local = $this->fileMetadataCandidate($local);
        if ($result->status === MetadataScrapeCompletionResult::UNMATCHED) {
            return new MetadataEnrichmentResult($local, [
                new MetadataProviderResult(self::PLUGIN_KEY, '元数据刮削插件', 'unmatched', null),
            ]);
        }
        $metadata = $local->metadata;
        $provided = [];
        foreach ($result->metadata ?? [] as $field => $value) {
            $metadata[$field] = $value;
            $provided[] = 'music_source_field_' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
        }
        if (isset($metadata['releaseDate']) && is_string($metadata['releaseDate'])) {
            $metadata['releaseYear'] = (int) substr($metadata['releaseDate'], 0, 4);
            $provided[] = 'music_source_field_release_year';
        }
        $final = new ScrapeMetadataCandidate($metadata, $result->confidence ?? 0, self::PLUGIN_KEY,
            array_values(array_unique(array_merge($provided, ['plugin_finalized']))));
        $lyricsCandidates = [];
        foreach ($result->lyricsCandidates as $body) {
            try {
                $lyricsCandidates[] = new ScrapeGeneratedLyrics(self::PLUGIN_KEY, $body);
            } catch (ScrapeMetadataInvalid) {
                // 单个渠道正文不可解析时继续尝试同身份的下一渠道，不能让坏资源推翻可信描述元数据。
            }
        }
        $lyrics = $lyricsCandidates[0] ?? null;
        $artwork = $this->artworkCandidates($result->artworkUrls, $final->confidence);
        $albumArtwork = $this->artworkCandidates($result->albumArtworkUrls, $final->confidence);
        $fieldSources = [];
        foreach ($provided as $evidence) {
            $field = substr($evidence, strlen('music_source_field_'));
            $fieldSources[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))))] = self::PLUGIN_KEY;
        }
        return new MetadataEnrichmentResult(
            $final,
            [
                new MetadataProviderResult(self::PLUGIN_KEY, '元数据刮削插件', 'matched', $final, null, $lyrics !== null, $artwork !== []),
            ],
            $lyrics,
            $lyricsCandidates,
            $fieldSources,
            $artwork,
            $albumArtwork,
        );
    }

    /**
     * 复验插件最终结论与冻结请求身份是否一致。
     *
     * 插件负责实际文件名解析和第三方候选排序，但自动审批不能只信任一个恰好达到阈值的分数。当路径同时
     * 提供 `艺人/专辑/文件` 三层事实时，最终标题、完整艺人集合和专辑必须与这些冻结片段严格一致；
     * metadata 模式则以扫描阶段冻结的标题、完整艺人集合、可选专辑和可选专辑艺人为准。这样远程发布
     * 快照从 filename_only 升级为可信 raw 后，`Sazablue, 周深.` 之类仅部分包含已知艺人的结果仍会收口
     * 为 unmatched。文件名两层以下没有完整目录证据时不在核心猜测语义。比较只折叠大小写、全半角和
     * 空白，不删除标点或别名，避免把不同艺人再次合并。本方法只检查内存 DTO，不记录第三方字段或修改任务。
     */
    private function resultMatchesFrozenIdentity(
        ScrapeMetadataCandidate $local,
        MetadataScrapeCompletionResult $result,
    ): bool {
        $metadata = $local->metadata;
        if ($result->status !== MetadataScrapeCompletionResult::MATCHED
            || !is_array($result->metadata)) return true;
        if (($metadata['scrapeInputMode'] ?? null) !== MetadataScrapeCompletionRequest::MODE_FILENAME) {
            $expectedTitle = is_string($metadata['title'] ?? null) ? $metadata['title'] : '';
            $expectedArtists = is_array($metadata['artists'] ?? null)
                ? array_values(array_filter($metadata['artists'], 'is_string')) : [];
            $actualTitle = is_string($result->metadata['title'] ?? null) ? $result->metadata['title'] : '';
            $actualArtists = is_array($result->metadata['artists'] ?? null)
                ? array_values(array_filter($result->metadata['artists'], 'is_string')) : [];
            if ($expectedTitle !== '' && $this->identityText($actualTitle) !== $this->identityText($expectedTitle)) {
                return false;
            }
            if ($expectedArtists !== [] && $this->identitySet($actualArtists) !== $this->identitySet($expectedArtists)) {
                return false;
            }
            $expectedAlbum = is_string($metadata['albumTitle'] ?? null) ? trim($metadata['albumTitle']) : '';
            if ($expectedAlbum !== '') {
                $actualAlbum = is_string($result->metadata['albumTitle'] ?? null)
                    ? $result->metadata['albumTitle'] : '';
                if ($this->identityText($actualAlbum) !== $this->identityText($expectedAlbum)) return false;
            }
            $expectedAlbumArtists = is_array($metadata['albumArtists'] ?? null)
                ? array_values(array_filter($metadata['albumArtists'], 'is_string')) : [];
            if ($expectedAlbumArtists !== [] && is_array($result->metadata['albumArtists'] ?? null)) {
                $actualAlbumArtists = array_values(array_filter($result->metadata['albumArtists'], 'is_string'));
                if ($this->identitySet($actualAlbumArtists) !== $this->identitySet($expectedAlbumArtists)) return false;
            }
            return true;
        }
        if (!is_array($metadata['filenameContext'] ?? null)) return true;
        $context = $metadata['filenameContext'];
        $file = is_string($context['fileName'] ?? null)
            ? trim(pathinfo((string) $context['fileName'], PATHINFO_FILENAME)) : '';
        $album = is_string($context['parentDirectory'] ?? null) ? trim($context['parentDirectory']) : '';
        $artist = is_string($context['grandparentDirectory'] ?? null) ? trim($context['grandparentDirectory']) : '';
        if ($file === '' || $album === '' || $artist === '') return true;
        $file = trim((string) preg_replace('/^\s*(?:\d{1,4}[ ._-]+|[A-Z]?\d{1,3}[ ._-]+)(?=\S)/iu', '', $file));
        $title = is_string($result->metadata['title'] ?? null) ? $result->metadata['title'] : '';
        $resultAlbum = is_string($result->metadata['albumTitle'] ?? null) ? $result->metadata['albumTitle'] : '';
        $resultArtists = is_array($result->metadata['artists'] ?? null)
            ? array_values(array_filter($result->metadata['artists'], 'is_string')) : [];
        $expectedArtists = preg_split('/\s*(?:、|,|，|;|；|&)\s*/u', $artist) ?: [];
        return $this->identityText($title) === $this->identityText($file)
            && $this->identityText($resultAlbum) === $this->identityText($album)
            && $this->identitySet($resultArtists) === $this->identitySet($expectedArtists);
    }

    /** @param list<string> $values @return list<string> */
    private function identitySet(array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map($this->identityText(...), $values))));
        sort($values, SORT_STRING);
        return $values;
    }

    /** 冻结身份比较只规范字符宽度、大小写和连续空白，标点必须保留。 */
    private function identityText(string $value): string
    {
        $value = mb_convert_kana($value, 'as', 'UTF-8');
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    /**
     * 把插件已按同一作品排序的瞬时 URL 转换为核心下载候选。
     *
     * 本方法不访问网络；URL 会在 Worker 中逐项通过 HostPolicy、公开 DNS、HTTPS 和图片签名校验。单项
     * 构造失败只丢弃该项，顺序保持不变，且地址不会进入数据库、日志或审计。
     *
     * @param list<string> $urls
     * @return list<ScrapeGeneratedArtwork>
     */
    private function artworkCandidates(array $urls, int $confidence): array
    {
        $result = [];
        foreach ($urls as $url) {
            try {
                $result[] = new ScrapeGeneratedArtwork(self::PLUGIN_KEY, $url, $confidence);
            } catch (ScrapeMetadataInvalid) {
                // 保留后续候选的回退机会。
            }
        }
        return $result;
    }

    /**
     * 从插件调用载体中移除只允许出站的文件名上下文。
     *
     * Worker 为 filename 模式临时把三个受限名称片段附在候选载体上，供 request() 构造插件协议；这些
     * 片段不是 FFprobe 或标签读取结果，不能随 local/final candidate 返回、持久化或取得字段来源资格。
     * 本方法只复制内存数组，不修改冻结任务证据；重复调用结果相同且没有数据库、文件或网络副作用。
     */
    private function fileMetadataCandidate(ScrapeMetadataCandidate $candidate): ScrapeMetadataCandidate
    {
        $metadata = $candidate->metadata;
        unset($metadata['scrapeInputMode'], $metadata['filenameContext']);
        return new ScrapeMetadataCandidate(
            $metadata,
            $candidate->confidence,
            $candidate->source,
            $candidate->evidence,
        );
    }
}
