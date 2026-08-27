<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 把一个本地媒体名称解析为供所有外部元数据渠道复用的有序查询关键词。
 *
 * 本服务只生成查询别名，不修改标签、展示标题或最终整理路径。名称先移除可证明无关的
 * 文件编号、扩展名和质量标记，再按“移除明确版本后缀、移除串烧前缀、拆分串烧曲目”
 * 的顺序处理；含噪原名最后作为回退保留。调用方必须把返回顺序视为证据强弱顺序，不能
 * 仅凭较弱的拆分词自动覆盖本地元数据。为避免不受控
 * 标签导致正则和第三方查询膨胀，输入长度、关键词长度、拆分段数和最终数量均有硬上限；
 * 超限或空白输入返回空列表，不产生网络或持久化副作用。
 */
final class MetadataQueryKeywordService
{
    private const MAX_INPUT_CHARACTERS = 240;
    private const MAX_KEYWORD_CHARACTERS = self::MAX_INPUT_CHARACTERS;
    private const MAX_KEYWORDS = 12;
    private const MAX_MEDLEY_PARTS = 8;

    /**
     * 生成去重且按匹配优先级排列的名称关键词。
     *
     * 技术去噪先处理常见音频扩展名、开头曲目号和边界质量标记；无法确定语义的文字原样
     * 保留。拆分仅识别 `+`、全角 `＋` 和两侧有空白的 `/`，因此 `C++`、尾随 `Love+` 与
     * `AC/DC` 不会被拆开；全部为数字的 `1+1` 也按完整标题保留。括号或破折号后缀只有
     * 含明确的 Live、重制、混音、伴奏等版本标记时才会移除，普通副标题不会被猜测。
     * 返回值可安全交给多个渠道查询，但渠道仍须结合艺术家、专辑、时长和稳定外部 ID
     * 独立评分。
     *
     * @return list<string> 去噪完整名优先，随后为查询别名，含噪原名最后作为有界回退。
     */
    public function generate(string $name): array
    {
        $originalName = $this->clean($name);
        if ($originalName === '' || mb_strlen($originalName, 'UTF-8') > self::MAX_INPUT_CHARACTERS) {
            return [];
        }

        $keywords = [];
        $seen = [];
        $name = $this->removeQueryNoise($originalName);
        if ($name === '') {
            $name = $originalName;
        }
        $this->append($keywords, $seen, $name);

        $base = $name;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $stripped = $this->stripVersionSuffix($base);
            if ($stripped === $base) {
                break;
            }
            $base = $stripped;
            $this->append($keywords, $seen, $base);
        }

        $withoutMedleyPrefix = (string) preg_replace(
            '/^\s*(?:medley|mashup|串烧|组曲)\s*[:：-]\s*/iu',
            '',
            $base,
            1,
        );
        $withoutMedleyPrefix = $this->clean($withoutMedleyPrefix);
        if ($withoutMedleyPrefix !== '' && $withoutMedleyPrefix !== $base) {
            $base = $withoutMedleyPrefix;
            $this->append($keywords, $seen, $base);
        }

        foreach ($this->splitMedley($base) as $part) {
            $this->append($keywords, $seen, $part);
            $this->append($keywords, $seen, $this->stripVersionSuffix($part));
        }

        if ($originalName !== $name) {
            $this->append($keywords, $seen, $originalName);
        }

        return array_slice($keywords, 0, self::MAX_KEYWORDS);
    }

    /**
     * 移除只影响文件分发、不属于作品名称的有界技术噪声。
     *
     * 扩展名、明确曲目编号、首尾括号质量块和末尾破折号质量块在同一有界循环中处理，因此站点块外层
     * 包住曲号、末尾又叠加多个规格块时仍可逐层收敛。括号内容必须完整命中技术白名单才会删除，因此
     * `(Live)`、`(Chapter One)`、`[Explicit]`、`[HQ]` 等语义或宽泛标签会保留。艺术家前缀、年份和地点
     * 在仅有一个字符串时无法可靠判定，故本方法绝不猜测。最多循环六次，防止恶意标签制造无界处理；
     * 只在内存中返回查询文本，无文件或数据库副作用。
     */
    private function removeQueryNoise(string $name): string
    {
        $value = $name;
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $before = $value;
            $value = (string) preg_replace(
                '/\.(?:flac|mp3|m4a|aac|alac|wav|wave|ape|wv|ogg|opus|dsf|dff)\s*$/iu',
                '',
                $value,
                1,
            );
            // 音乐资源下载器曾把任务 ULID 末 8 位写成“ [xxxxxxxx]”后缀。严格限定空格、方括号和
            // Crockford ULID 字符，避免误删 [Explicit]、[Live] 或标题本身的普通八字符方括号内容。
            $value = (string) preg_replace(
                '/\s+\[[0-9A-HJKMNP-TV-Z]{8}\]\s*$/iu',
                '',
                $value,
                1,
            );
            $value = $this->removeTrackPrefix($value);
            if (preg_match('/^\s*[\[\(\{（【〔]\s*([^\]\)\}）】〕]{1,80})\s*[\]\)\}）】〕]\s*/u', $value, $match) === 1
                && $this->isTechnicalNoise($match[1])) {
                $value = substr($value, strlen($match[0]));
            }
            if (preg_match('/\s*[\[\(\{（【〔]\s*([^\]\)\}）】〕]{1,80})\s*[\]\)\}）】〕]\s*$/u', $value, $match) === 1
                && $this->isTechnicalNoise($match[1])) {
                $value = substr($value, 0, -strlen($match[0]));
            }
            if (preg_match('/^(.*?)\s+[-–—]\s+([^\r\n]{1,80})$/u', $value, $match) === 1
                && $this->isTechnicalNoise($match[2])) {
                $value = $match[1];
            }
            if ($value === $before) {
                break;
            }
            $value = $this->trimNoiseSeparators($value);
        }

        return $this->clean($value);
    }

    /**
     * 删除具有明确编号语法的文件名前缀，普通数字标题和四位年份保持不变。
     *
     * 无文字标记的数字必须带句点、横线、冒号或顿号等分隔符；只有 `Track`、`No.`、`曲目`、`音轨`、
     * `#`、`第…首` 等明确标记，以及 `[01] Song` 形式才允许使用空格分隔。该规则每轮最多消费一个前缀，
     * 外层循环可继续处理其前后的站点或质量块，不会无界回溯。
     */
    private function removeTrackPrefix(string $value): string
    {
        return (string) preg_replace(
            '/^\s*(?:'
            . '(?:(?:cd|disc|disk)\s*\d{1,2}\s*[-_. ]\s*)?'
            . '(?:(?:track|no\.?)\s*)?'
            . '(?:\[\s*\d{1,3}\s*\]|\(\s*\d{1,3}\s*\)|\d{1,3}(?:[-_.]\d{1,3})?)'
            . '\s*[-–—._:：、]\s*'
            . '|(?:(?:track|no\.?|曲目|音轨|#)\s*|第\s*)\d{1,3}\s*(?:首|曲)?'
            . '(?:\s*[-–—._:：、\)]\s*|\s+)'
            . '|[\[\(（【]\s*\d{1,3}\s*[\]\)）】]\s+'
            . ')/iu',
            '',
            $value,
            1,
        );
    }

    /**
     * 判断一个边界片段是否完全属于可丢弃的格式、质量或发布载体说明。
     *
     * 白名单覆盖明确容器、位深/采样率、DSD/MQA、声道、流媒体发布载体和中英文母带标记，但故意不
     * 包含宽泛的 `HQ`、年份、CD/Vinyl、Live 和 Explicit；这些词可能区分正式版本。域名只在独立
     * 边界块中识别，不能借此删除标题主体。
     */
    private function isTechnicalNoise(string $value): bool
    {
        $value = mb_strtolower($this->clean($value), 'UTF-8');
        if ($value === '' || mb_strlen($value, 'UTF-8') > 80) {
            return false;
        }
        if (preg_match(
            '/^(?:https?:\/\/)?(?:www\.)?[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?'
            . '\.(?:com|net|org|cn|cc|tv|me|io|co|top|xyz)(?:\/\S*)?$/iu',
            $value,
        ) === 1) {
            return true;
        }

        $remaining = (string) preg_replace(
            '/(?:\bflac\b|\bmp3\b|\bm4a\b|\baac\b|\balac\b|\bwav(?:e)?\b|\bape\b|'
            . '\bopus\b|\bogg\b|\bwv\b|\bds[fd]\b|\bdsd(?:64|128|256|512)?\b|\bcue\b|'
            . '\bmqa\b|\bsacd\b|\bpcm\b|\bdts(?:-hd)?\b|\bdolby(?:\s+atmos)?\b|\bspatial\s+audio\b|'
            . '\blossless\b|\bhi[- ]?res\b|\bweb[- ]?dl\b|'
            . '\bweb[- ]?rip\b|\bofficial\s+(?:audio|video)\b|\blyrics?\s+video\b|'
            . '\b(?:16|24|32)[- ]*bit\b|\b\d{2,4}\s*k(?:b?ps)?\b|'
            . '\b\d{2,3}(?:\.\d+)?\s*k?hz\b|\b(?:720|1080|1440|2160)p\b|\b4k\b|'
            . '\b(?:16|24|32)\s*[-_\/]\s*\d{2,3}(?:\.\d+)?\b|'
            . '\b(?:16|24|32)\s*b(?:it)?\s*[-_\/]\s*\d{2,3}(?:\.\d+)?\s*k\b|'
            . '\b(?:mono|stereo|\d(?:\.\d)?\s*ch|(?:2|5|7)\.1)\b|'
            . '\b(?:qobuz|tidal|deezer|itunes|apple\s+music|amazon\s+music|spotify)\b|'
            . '无损(?:音质|版)?|高解析(?:音频)?|高保真|数字母带|母带级|臻品母带|超清母带|'
            . '杜比全景声|空间音频|官方(?:音频|视频)|歌词版视频)/iu',
            ' ',
            $value,
        );
        $remaining = (string) preg_replace('/[\s|,，;；:：\/＋+_.-]+/u', '', $remaining);
        return $remaining === '';
    }

    /** Removes separators exposed only because an adjacent technical-noise block was deleted. */
    private function trimNoiseSeparators(string $value): string
    {
        $value = (string) preg_replace('/^(?:\s*[-–—._]\s*)+/u', '', $value);
        return (string) preg_replace('/(?:\s*[-–—._]\s*)+$/u', '', $value);
    }

    /**
     * 移除一个明确的末尾版本说明；无法证明是版本说明时原样返回。
     *
     * 每次只移除一层，使调用方能保留中间别名并控制最大迭代次数。该方法不会处理名称
     * 中间的括号，也不会把年份、地点或普通副标题单独视为可删除证据。
     */
    private function stripVersionSuffix(string $name): string
    {
        if (preg_match('/^(.*?)\s*[\(\[（【]\s*([^\)\]）】]{1,80})\s*[\)\]）】]\s*$/u', $name, $match) === 1
            && $this->isVersionQualifier($match[2])) {
            return $this->clean($match[1]);
        }

        if (preg_match('/^(.*?)\s+[-–—]\s+([^\r\n]{1,80})$/u', $name, $match) === 1
            && $this->isVersionQualifier($match[2])) {
            return $this->clean($match[1]);
        }

        return $name;
    }

    /** Returns true only for a bounded suffix containing an explicit version/performance marker. */
    private function isVersionQualifier(string $suffix): bool
    {
        return preg_match(
            '/(?:\blive\b|\bremaster(?:ed)?\b|\bversion\b|\bedit\b|\bmix\b|\bdemo\b|'
            . '\bacoustic\b|\binstrumental\b|\bkaraoke\b|\bmono\b|\bstereo\b|'
            . '现场(?:版)?|演唱会(?:版)?|重制(?:版)?|重新灌录|混音(?:版)?|伴奏(?:版)?|'
            . '纯音乐(?:版)?|不插电(?:版)?|电台(?:编辑|版)|加长(?:版)?|演出(?:版)?)/iu',
            $suffix,
        ) === 1;
    }

    /**
     * 拆分具有明确连接符的串烧名称，同时保守排除常见合法标题。
     *
     * 任一空段、连续加号、过多段或纯数字算式都会让本次拆分整体失效，避免输出残缺词。
     * 斜杠必须两侧都有空白，全角/半角无空白斜杠均保留给 `AC/DC` 一类正式名称。
     *
     * @return list<string>
     */
    private function splitMedley(string $name): array
    {
        if (str_contains($name, '++') || str_contains($name, '＋＋')) {
            return [];
        }

        $parts = preg_split('/\s*(?:\+|＋)\s*|\s+[\/／]\s+/u', $name);
        if (!is_array($parts) || count($parts) < 2 || count($parts) > self::MAX_MEDLEY_PARTS) {
            return [];
        }

        $cleaned = array_map(fn (string $part): string => $this->clean($part), $parts);
        if (in_array('', $cleaned, true)) {
            return [];
        }

        $numericParts = array_filter(
            $cleaned,
            static fn (string $part): bool => preg_match('/^\d+(?:\.\d+)?$/', $part) === 1,
        );
        return count($numericParts) === count($cleaned) ? [] : array_values($cleaned);
    }

    /**
     * 追加一个有界关键词，并以大小写和空白归一后的键去重。
     *
     * 标点不参与去重，因为不同标点可能是第三方搜索索引中的有效证据。达到数量上限后
     * 静默忽略更弱别名，保证任何渠道的单次查询规模稳定。
     *
     * @param list<string> $keywords
     * @param array<string, true> $seen
     */
    private function append(array &$keywords, array &$seen, string $keyword): void
    {
        $keyword = $this->clean($keyword);
        if ($keyword === '' || count($keywords) >= self::MAX_KEYWORDS
            || mb_strlen($keyword, 'UTF-8') > self::MAX_KEYWORD_CHARACTERS) {
            return;
        }

        $dedupeKey = mb_strtolower($keyword, 'UTF-8');
        if (isset($seen[$dedupeKey])) {
            return;
        }
        $seen[$dedupeKey] = true;
        $keywords[] = $keyword;
    }

    /** Normalizes control/spacing differences without changing meaningful punctuation or Unicode. */
    private function clean(string $value): string
    {
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
