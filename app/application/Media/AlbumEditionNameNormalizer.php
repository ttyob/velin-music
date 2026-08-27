<?php

declare(strict_types=1);

namespace app\application\Media;

/**
 * 识别不会改变专辑作品身份的明确发行版说明。
 *
 * 该服务只折叠位于标题末尾的白名单版次词，用于解决同一基础专辑因“珍藏版、豪华版、周年版、
 * 重制版”等标签差异被拆成多个实体的问题。Live、原声、Demo、Acoustic、Instrumental、卷号、碟号、
 * EP/Single 等会改变内容集合或作品语义的文字不在白名单内，调用方不得再做包含或模糊匹配。
 *
 * 本类不修改文件标签和展示标题，也不访问数据库。空标题或删除版次后为空的标题不会被判为等价；
 * 相同输入始终产生相同结果，因而可同时供扫描身份判定和第三方封面准入复用。
 */
final readonly class AlbumEditionNameNormalizer
{
    private const QUALIFIER = '(?:(?:第?\s*\d{1,3}\s*周年)(?:\s*(?:珍藏|典藏|纪念|豪华|特别|限量|重制|复刻))?\s*(?:版|版本)?'
        . '|(?:珍藏|典藏|纪念|豪华|特别|限量|重制|复刻|周年)\s*(?:版|版本)'
        . '|(?:\d{1,3}(?:st|nd|rd|th)\s+)?anniversary(?:\s+edition)?'
        . '|(?:deluxe|special|limited|expanded)\s*(?:edition)?'
        . '|collector(?:\x27s|\x{2019}s)?\s+edition'
        . '|(?:(?:19|20)\d{2}\s+)?remaster(?:ed)?(?:\s+edition)?)';

    /**
     * 返回移除明确末尾版次说明后的基础标题。
     *
     * 最多处理三层连续后缀，既覆盖“（10周年纪念版）（Remastered）”，也避免损坏或恶意超长输入
     * 造成无界循环。只有完整括号后缀或由空白/分隔符引出的独立后缀会被移除，标题正文中的同名词
     * 不受影响；删除后遗留的分隔符会一并清理。方法不移除开头年份，因为“1989”等数字可能就是作品名。
     */
    public function baseName(string $title): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $title));
        for ($attempt = 0; $attempt < 3 && $value !== ''; ++$attempt) {
            $next = (string) preg_replace(
                '/\s*[\(（\[【]\s*(?:' . self::QUALIFIER . ')\s*[\)）\]】]\s*$/iu',
                '',
                $value,
                1,
            );
            if ($next === $value) {
                $next = (string) preg_replace(
                    '/(?:\s+|\s*[-_\x{2013}\x{2014}：:]\s*)(?:' . self::QUALIFIER . ')\s*$/iu',
                    '',
                    $value,
                    1,
                );
            }
            $next = trim((string) preg_replace('/\s*[-_\x{2013}\x{2014}：:]\s*$/u', '', $next));
            if ($next === $value || $next === '') break;
            $value = $next;
        }

        return $value;
    }

    /** 判断标题是否实际包含可折叠版次后缀；空标题和无法安全删除的文字返回 false。 */
    public function hasEditionQualifier(string $title): bool
    {
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', $title));
        $base = $this->baseName($title);

        return $base !== '' && $base !== $trimmed;
    }

    /**
     * 判断两个标题在仅忽略白名单版次后是否相同。
     *
     * 比较只折叠 Unicode 大小写和连续空白，保留 `+`、`&`、数字及其他可能属于作品名的符号，避免
     * 把 `C++` 与 `C` 之类的真实不同标题误合并。调用方仍须额外约束音乐库和主要专辑艺术家。
     */
    public function equivalent(string $left, string $right): bool
    {
        $leftKey = $this->baseIdentityKey($left);
        $rightKey = $this->baseIdentityKey($right);

        return $leftKey !== '' && hash_equals($leftKey, $rightKey);
    }

    /**
     * 返回基础标题的确定比较键，供同库、同主要专辑艺术家范围内建立候选分组。
     *
     * 此键不是全局专辑 ID，调用方禁止脱离音乐库和艺术家约束使用；返回空字符串表示输入不能形成
     * 安全身份。键保留语义符号，因此可以在 PHP 中分组而不依赖 SQLite/MySQL 的排序规则差异。
     */
    public function baseIdentityKey(string $title): string
    {
        return $this->comparisonKey($this->baseName($title));
    }

    /**
     * 在两个等价标题中选择稳定展示标题。
     *
     * 基础标题优先于带版次后缀的标题，防止同一专辑按不同文件顺序扫描时页面标题来回变化；若两侧
     * 都有或都没有版次说明，则保留已存在标题。返回值只用于目录投影，不会回写音频文件。
     */
    public function preferredDisplayTitle(string $existing, string $incoming): string
    {
        if (!$this->equivalent($existing, $incoming)) return $incoming;
        if ($this->hasEditionQualifier($existing) && !$this->hasEditionQualifier($incoming)) return $incoming;

        return $existing;
    }

    /** 生成不依赖数据库排序规则的保守比较键；符号保留，只有大小写与空白被统一。 */
    private function comparisonKey(string $title): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $title));

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
