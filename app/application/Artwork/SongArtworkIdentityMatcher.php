<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * 对歌曲封面执行独立于专辑名的硬身份匹配。
 *
 * 综合分数只能说明候选相似，不能单独阻止同名翻唱或错误版本封面。本匹配器要求规范化标题完全一致，
 * 且本地与平台署名艺术家至少一项完全一致；规范化只忽略大小写、空白和标点，不删除 Live、Remix、
 * 伴奏等有区分意义的词。空标题、空艺术家或类型损坏均返回 false，不抛出包含第三方正文的异常，也不
 * 产生网络、数据库或文件副作用。
 */
final readonly class SongArtworkIdentityMatcher
{
    /**
     * @param array{title:mixed,artists:mixed} $query 本地冻结歌曲证据。
     * @param array<string,mixed> $candidate 已通过查询协议白名单校验的平台候选。
     */
    public function matches(array $query, array $candidate): bool
    {
        if (!is_string($query['title'] ?? null) || !is_array($query['artists'] ?? null)
            || !is_string($candidate['title'] ?? null) || !is_array($candidate['artists'] ?? null)) return false;
        $title = $this->normalize($query['title']);
        if ($title === '' || !hash_equals($title, $this->normalize($candidate['title']))) return false;
        $expected = array_values(array_filter(array_map(
            fn (mixed $artist): string => is_string($artist) ? $this->normalize($artist) : '',
            $query['artists'],
        )));
        if ($expected === []) return false;
        foreach ($candidate['artists'] as $artist) {
            if (is_string($artist) && in_array($this->normalize($artist), $expected, true)) return true;
        }
        return false;
    }

    /** 只移除不构成录音身份的大小写、空白和标点差异。 */
    private function normalize(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', trim($value)), 'UTF-8');
    }
}
