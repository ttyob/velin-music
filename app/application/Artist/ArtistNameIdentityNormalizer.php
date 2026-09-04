<?php

declare(strict_types=1);

namespace app\application\Artist;

use app\application\Search\SearchTextNormalizer;

/**
 * 为艺人词汇生成保守的显示搜索键和格式等价身份键。
 *
 * 普通空格仍是身份的一部分，`Jay Chou` 不会与 `JayChou` 合并。唯一额外折叠的是 Unicode 标点/符号
 * 与汉字交界处的空格，用于把 `G.E.M. 邓紫棋`、`G.E.M.邓紫棋` 这类纯排版差异识别为同一艺人。
 * 方法只处理内存字符串，不修改显示名、数据库或文件；无效 UTF-8 由上游元数据合同负责拒绝。
 */
final readonly class ArtistNameIdentityNormalizer
{
    public function __construct(private SearchTextNormalizer $search = new SearchTextNormalizer())
    {
    }

    /** 返回仍保留普通词间空格的数据库搜索键。 */
    public function storageKey(string $name): string
    {
        return $this->search->normalize($name);
    }

    /**
     * 返回与显示排版无关的艺人身份键。
     *
     * 只删除“标点/符号 + 空格 + 汉字”或“汉字 + 空格 + 标点/符号”两种边界，失败时保守返回原搜索键。
     */
    public function identityKey(string $name): string
    {
        $storage = $this->storageKey($name);
        $identity = preg_replace(
            '/(?<=[\p{P}\p{S}])\s+(?=\p{Han})|(?<=\p{Han})\s+(?=[\p{P}\p{S}])/u',
            '',
            $storage,
        );

        return is_string($identity) ? $identity : $storage;
    }

    /**
     * 返回旧库与新输入都可命中的有限数据库键集合。
     *
     * 旧版本可能保存有空格或无空格任一形式，因此除原键和紧凑身份键外，再生成一个只在相同边界插入
     * 单空格的兼容键。集合最多三个元素、稳定去重，可直接用于 `whereIn`，不会执行全表模糊扫描。
     *
     * @return list<string>
     */
    public function lookupKeys(string $name): array
    {
        $storage = $this->storageKey($name);
        $identity = $this->identityKey($name);
        $spaced = preg_replace(
            '/(?<=[\p{P}\p{S}])(?=\p{Han})|(?<=\p{Han})(?=[\p{P}\p{S}])/u',
            ' ',
            $identity,
        );
        $keys = array_values(array_unique([$storage, $identity, is_string($spaced) ? $spaced : $identity]));

        return array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));
    }

    /** 判断两个显示名称是否只有受支持的标点/汉字交界排版差异。 */
    public function equivalent(string $left, string $right): bool
    {
        $leftKey = $this->identityKey($left);

        return $leftKey !== '' && hash_equals($leftKey, $this->identityKey($right));
    }
}
