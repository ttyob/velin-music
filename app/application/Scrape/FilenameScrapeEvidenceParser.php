<?php

declare(strict_types=1);

namespace app\application\Scrape;

use app\application\Search\SearchTextNormalizer;

/**
 * 把扫描器的规范相对路径解析成所有文件名回退入口共用的无路径候选证据。
 *
 * 解析不改变已有音频标签，也不读取音频正文。文件序号、格式、音质和明确版本说明从查询标题中移除；
 * 原文件名仍由库存目录保存。父目录可按“艺人 - 专辑 年份 格式”或“艺人/专辑”提取保守的专辑证据，
 * 但只有无可靠标签的路径模式才允许调用方把首选变体用于可浏览兜底。方向不明确的单个 `A-B` 名称生成
 * 两个有界变体。可选艺人库只把精确命中的一侧排在前面，后续仍须由 Provider 的标题、艺人和阈值共同
 * 确认，不能直接进入 scraped 或 manual 来源层；辅助库故障时行为与未安装完全一致。
 */
final readonly class FilenameScrapeEvidenceParser
{
    /** 解析规则版本会写入快照；任何影响查询变体的规则变化都必须递增，以触发安全重扫。 */
    public const VERSION = 7;

    private ArtistNameLookup $artistNames;

    /**
     * 组合统一关键词、搜索规范化与可降级艺人名查询边界。
     *
     * 默认查询固定只读艺人库；测试可注入纯内存实现。构造函数不访问数据库，parse 中任何辅助库失败
     * 都由查询实现收敛为未命中，不能改变原有回退可用性。
     */
    public function __construct(
        private MetadataQueryKeywordService $keywords = new MetadataQueryKeywordService(),
        private SearchTextNormalizer $normalizer = new SearchTextNormalizer(),
        ?ArtistNameLookup $artistNames = null,
    ) {
        $this->artistNames = $artistNames ?? new ArtistDatabaseNameMatcher();
    }

    /**
     * 从受控库存相对路径生成无路径查询变体，不读取文件或访问网络。
     *
     * 输入必须是扫描器已经限制在音乐库根内的相对路径；超过长度、无法得到标题或解析为空时返回 null。
     * 结果最多两个方向，removed 只记录规则类别而不保存被删除文本。方法不修改展示元数据、标签、数据库
     * 或文件；调用方仍须用真实标签覆盖已知字段，并让 Provider 执行身份门槛与评分。
     *
     * @return array{version:int,variants:list<array{title:string,artists:list<string>,albumTitle:?string,artistRequired:bool}>,removed:list<string>}|null
     */
    public function parse(string $relativePath): ?array
    {
        $path = $this->clean(str_replace('\\', '/', $relativePath));
        $filename = $this->clean(pathinfo($path, PATHINFO_FILENAME));
        if ($filename === '' || mb_strlen($filename, 'UTF-8') > 240) return null;

        $generated = $this->keywords->generate($filename);
        $base = $generated[0] ?? $filename;
        $removed = $base === $filename ? [] : ['track_or_distribution_noise'];
        $hadVersionQualifier = preg_match(
            '/(?:(?<![a-z])(?:live|remix|mix|instrumental|karaoke)(?![a-z])|伴奏|现场|演唱会|混音|无损版)/iu',
            $filename,
        ) === 1;
        [$base, $featuredArtists, $versionRemoved] = $this->stripVersionAndFeaturedArtist($base);
        $versionRemoved = $versionRemoved || ($hadVersionQualifier && $base !== $filename);
        if ($versionRemoved) $removed[] = 'version_qualifier';
        if ($base === '') return null;

        $segments = array_values(array_filter(explode('/', dirname($path)),
            static fn (string $value): bool => $value !== '' && $value !== '.'));
        $album = $segments === [] ? null : $this->clean($segments[count($segments) - 1]);
        $directoryArtist = count($segments) >= 2 ? $this->clean($segments[count($segments) - 2]) : null;
        if ($album !== null) {
            [$album, $albumDirectoryArtist] = $this->albumDirectoryEvidence($album, $directoryArtist);
            if ($directoryArtist === null && $albumDirectoryArtist !== null) {
                $directoryArtist = $albumDirectoryArtist;
            }
        }
        $parts = $this->artistTitleParts($base, $directoryArtist);

        $variants = [];
        if (count($parts) === 2) {
            [$left, $right] = $parts;
            if ($directoryArtist !== null && $this->same($directoryArtist, $right)) {
                $this->appendVariant($variants, $left, [$right, ...$featuredArtists], $album, true);
            } else {
                $leftKnownArtist = $this->artistNames->contains($left);
                $rightKnownArtist = $this->artistNames->contains($right);
                if ($directoryArtist === null && $rightKnownArtist && !$leftKnownArtist) {
                    $this->appendVariant($variants, $left,
                        [...$this->artists($right), ...$featuredArtists], $album, true);
                }
                $artists = $directoryArtist !== null && $this->same($directoryArtist, $left)
                    ? [$directoryArtist] : $this->artists($left);
                $this->appendVariant($variants, $right, [...$artists, ...$featuredArtists], $album, true);
                if ($directoryArtist === null) {
                    $this->appendVariant($variants, $left,
                        [...$this->artists($right), ...$featuredArtists], $album, true);
                }
            }
        } else {
            $artists = $directoryArtist === null ? ['未知艺术家'] : $this->artists($directoryArtist);
            $this->appendVariant(
                $variants,
                $base,
                [...$artists, ...$featuredArtists],
                $album,
                $directoryArtist !== null || $featuredArtists !== [],
            );
        }
        if ($variants === []) return null;

        return ['version' => self::VERSION, 'variants' => array_slice($variants, 0, 2),
            'removed' => array_values(array_unique($removed))];
    }

    /** @return array{string,list<string>,bool} */
    private function stripVersionAndFeaturedArtist(string $title): array
    {
        $removed = false;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $before = $title;
            $title = (string) preg_replace(
                '/\s*[\(\[\{（【]\s*[^\)\]\}）】]{0,80}(?:(?<![a-z])(?:live|remaster(?:ed)?|remix|'
                . 'mix|version|edit|demo|acoustic|instrumental|karaoke|mono|stereo)(?![a-z])|'
                . '伴奏|现场|演唱会|重制|重新灌录|'
                . '混音|纯音乐|不插电|电台编辑|加长版|演出版|无损|flac|mp3|\d{2,4}\s*k(?:b?ps)?)'
                . '[^\)\]\}）】]{0,80}'
                . '[\)\]\}）】]\s*$/iu', '', $title, 1,
            );
            $title = (string) preg_replace(
                '/\s*(?:[-–—]\s*)?(?:live|remaster(?:ed)?|remix|version|edit|demo|acoustic|'
                . 'instrumental|karaoke|伴奏|现场版|演唱会版|重制版|重新灌录|混音版|纯音乐版|'
                . '不插电版|电台编辑|加长版|演出版)\s*$/iu',
                '', $title, 1,
            );
            $title = $this->clean($title);
            if ($title === $before) break;
            $removed = true;
        }
        $featured = [];
        if (preg_match(
            '/^(.*?)\s*[\(\[（【]\s*(?:feat\.?|ft\.?|featuring)\s+(.{1,100}?)\s*[\)\]）】]\s*$/iu',
            $title,
            $match,
        ) === 1 || preg_match('/^(.*?)\s+(?:feat\.?|ft\.?|featuring)\s+(.{1,100})$/iu', $title, $match) === 1) {
            $title = $this->clean($match[1]);
            $featured = $this->artists($match[2]);
        }
        return [$title, $featured, $removed];
    }

    /**
     * 把单个艺人/标题分隔符拆成两段，优先保留标题内部的后续横线。
     *
     * 带空格的半角/全角横线取第一个分隔点，因此 `Artist - Song - Subtitle` 的副标题不会丢失；无空格
     * 半角横线无空格时还必须由艺人目录或艺人库精确确认其中一侧，避免破坏 `Jay-Z`。en/em dash 可无空格拆分。
     * 无法得到两个非空有界部分时返回空数组，调用方把完整文本作为标题并依赖目录艺人证据。
     *
     * @return list<string>
     */
    private function artistTitleParts(string $value, ?string $directoryArtist): array
    {
        $patterns = [
            '/^(.{1,160}?)\s+[-–—]\s+(.{1,240})$/u',
            '/^([^–—]{1,160})[–—]([^–—]{1,240})$/u',
        ];
        if (substr_count($value, '-') === 1
            && preg_match('/^([^-]{1,160})-([^-]{1,240})$/u', $value, $compact) === 1) {
            $directoryConfirms = $directoryArtist !== null
                && ($this->same($directoryArtist, $compact[1]) || $this->same($directoryArtist, $compact[2]));
            $databaseConfirms = ($this->artistNames->contains($this->clean($compact[1]))
                xor $this->artistNames->contains($this->clean($compact[2])));
            if ($directoryConfirms || $databaseConfirms) $patterns[] = '/^([^-]{1,160})-([^-]{1,240})$/u';
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $match) !== 1) continue;
            $parts = array_values(array_filter([$this->clean($match[1]), $this->clean($match[2])], 'strlen'));
            if (count($parts) === 2) return $parts;
        }
        return [];
    }

    /**
     * 从父目录提取保守的专辑名和可选艺人名。
     *
     * 已有上级艺人目录时，它始终优先，本方法只清理专辑目录末尾的年份和完整技术标记。只有单层目录
     * 符合带空格的 `艺人 - 专辑`，且左侧命中艺人库或右侧带有年份/格式噪声时，才把左侧作为艺人；
     * 这样可处理 `王菲 - 浮躁 2024 FLAC`，同时不会把普通 `Love - Story` 专辑任意拆开。纯数字专辑
     * `1989` 在没有其他技术证据时保留。失败时返回清理前目录，不访问文件、网络或业务数据库。
     *
     * @return array{string,?string}
     */
    private function albumDirectoryEvidence(string $directory, ?string $parentArtist): array
    {
        $original = $this->clean($directory);
        $album = $original;
        $inferredArtist = null;
        $canStripYear = $parentArtist !== null;
        if (preg_match('/^(.{1,160}?)\s+[-–—]\s+(.{1,240})$/u', $original, $match) === 1) {
            $left = $this->clean($match[1]);
            $right = $this->clean($match[2]);
            $directoryConfirms = $parentArtist !== null && $this->same($parentArtist, $left);
            $suffixConfirms = $this->hasAlbumDirectorySuffixNoise($right);
            if ($left !== '' && $right !== ''
                && ($directoryConfirms || $suffixConfirms || $this->artistNames->contains($left))) {
                $album = $right;
                $inferredArtist = $directoryConfirms ? $parentArtist : $left;
                $canStripYear = true;
            }
        }

        $technicalRemoved = false;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $cleaned = (string) preg_replace(
                '/\s*(?:[-–—]\s*)?(?:'
                . '[\[\(（【]\s*(?:flac|mp3|m4a|aac|alac|wav|wave|ape|wv|ogg|opus|ds[fd]|'
                . 'dsd(?:64|128|256|512)?|mqa|sacd|lossless|hi[- ]?res|web[- ]?dl|'
                . '(?:16|24|32)[- ]*bit|\d{2,3}(?:\.\d+)?\s*k?hz)(?:[\s,，+_-]+(?:'
                . 'flac|mp3|m4a|aac|alac|wav|wave|ape|wv|ogg|opus|ds[fd]|dsd(?:64|128|256|512)?|'
                . 'mqa|sacd|lossless|hi[- ]?res|web[- ]?dl|(?:16|24|32)[- ]*bit|'
                . '\d{2,3}(?:\.\d+)?\s*k?hz))*\s*[\]\)）】]'
                . '|(?:flac|mp3|m4a|aac|alac|wav|wave|ape|wv|ogg|opus|ds[fd]|'
                . 'dsd(?:64|128|256|512)?|mqa|sacd|lossless|hi[- ]?res|web[- ]?dl)'
                . '(?:\s*(?:分轨|整轨))?|无损(?:音质|版)?|高解析(?:音频)?|分轨|整轨)\s*$/iu',
                '',
                $album,
                1,
            );
            $cleaned = $this->clean($cleaned);
            if ($cleaned === $album) break;
            $album = $cleaned;
            $technicalRemoved = true;
        }
        if (($canStripYear || $technicalRemoved) && preg_match('/^(.*?)\s+(?:19|20)\d{2}\s*$/u', $album, $match) === 1) {
            $album = $this->clean($match[1]);
        }
        if ($album === '') return [$original, null];
        return [$album, $inferredArtist];
    }

    /** 只把父目录末尾的明确年份、格式或分轨标记作为拆分“艺人 - 专辑”的附加证据。 */
    private function hasAlbumDirectorySuffixNoise(string $value): bool
    {
        return preg_match(
            '/(?:\b(?:19|20)\d{2}\b|\b(?:flac|mp3|m4a|aac|alac|wav|wave|ape|wv|ogg|opus|'
            . 'ds[fd]|dsd(?:64|128|256|512)?|mqa|sacd|lossless|hi[- ]?res|web[- ]?dl)\b|'
            . '无损(?:音质|版)?|高解析(?:音频)?|分轨|整轨)\s*$/iu',
            $value,
        ) === 1;
    }

    /** @return list<string> */
    private function artists(string $value): array
    {
        $parts = preg_split(
            '/\s*(?:、|,|，|;|；|&|(?<![\p{L}\p{N}])(?:feat\.?|ft\.?|featuring)(?![\p{L}\p{N}]))\s*/iu',
            $value,
        );
        $result = [];
        foreach (is_array($parts) ? $parts : [] as $part) {
            $part = $this->clean($part);
            if ($part !== '' && !in_array($part, $result, true)) $result[] = $part;
        }
        return $result === [] ? ['未知艺术家'] : array_slice($result, 0, 8);
    }

    /**
     * 追加单个有界查询方向，并保留艺人是否由文件名规则明确解析的来源资格。
     *
     * artistRequired 只表示目录、分隔符或客席艺人语法已经给出艺人证据；未知艺人占位必须传 false。
     * 该标记不会把解析值升级为真实标签，只允许后续文件名刮削在标题同名时拒绝跨艺人候选。
     *
     * @param list<array{title:string,artists:list<string>,albumTitle:?string,artistRequired:bool}> $variants
     */
    private function appendVariant(
        array &$variants,
        string $title,
        array $artists,
        ?string $album,
        bool $artistRequired,
    ): void
    {
        $title = $this->clean($title);
        $artists = array_values(array_unique(array_filter(array_map($this->clean(...), $artists))));
        if ($title === '' || $artists === []) return;
        $candidate = [
            'title' => $title,
            'artists' => $artists,
            'albumTitle' => $album === '' ? null : $album,
            'artistRequired' => $artistRequired,
        ];
        foreach ($variants as $existing) if ($existing === $candidate) return;
        $variants[] = $candidate;
    }

    private function same(string $left, string $right): bool
    {
        $left = $this->normalizer->normalize($left);
        return $left !== '' && $left === $this->normalizer->normalize($right);
    }

    private function clean(string $value): string
    {
        $value = mb_convert_kana($value, 'as', 'UTF-8');
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value);
    }
}
