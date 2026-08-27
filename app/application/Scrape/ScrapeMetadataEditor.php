<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 校验并应用发现审核弹窗提交的最终元数据字段。
 *
 * 编辑只产生新的数据库候选快照，不写音频标签。允许字段固定在代码中，未知字段失败关闭；
 * 技术事实如时长、编码和文件身份不可由浏览器修改。调用者必须在实时音乐库权限和候选
 * 版本锁下持久化结果，并用新候选重新生成目标路径。失败抛出稳定的验证异常且无副作用。
 */
final class ScrapeMetadataEditor
{
    /**
     * 应用一个非空的字段补丁并返回人工来源候选。
     *
     * @param array<string,mixed> $patch 只含白名单业务字段，不含路径、来源、置信度或外部响应。
     * @throws ScrapeAdminInvalid 字段未知、类型错误、越界或编辑后缺少必要标题/艺术家/专辑。
     */
    public function apply(ScrapeMetadataCandidate $current, array $patch): ScrapeMetadataCandidate
    {
        if ($patch === [] || array_diff(array_keys($patch), ScrapeMetadataFieldSources::FIELDS) !== []) {
            throw new ScrapeAdminInvalid('最终信息包含未知字段或没有修改内容。');
        }
        $metadata = $current->metadata;
        foreach ($patch as $field => $value) {
            $metadata[$field] = match ($field) {
                'title', 'albumTitle' => $this->requiredString($value, 300, $field),
                'artists', 'albumArtists' => $this->stringList($value, 16, 200, false, $field),
                'genres' => $this->stringList($value, 32, 100, true, $field),
                'composer' => $this->nullableString($value, 300, $field),
                'releaseDate' => $this->releaseDate($value),
                'releaseYear' => $this->boundedInteger($value, 1000, 9999, true, $field),
                'trackNumber', 'trackTotal' => $this->boundedInteger($value, 1, 9999, true, $field),
                'discNumber', 'discTotal' => $this->boundedInteger($value, 1, 999, true, $field),
                'isrc' => $this->isrc($value),
                default => throw new ScrapeAdminInvalid('最终信息字段无效。'),
            };
        }
        if (!is_string($metadata['title'] ?? null) || trim($metadata['title']) === ''
            || !is_string($metadata['albumTitle'] ?? null) || trim($metadata['albumTitle']) === ''
            || !is_array($metadata['artists'] ?? null) || $metadata['artists'] === []
            || !is_array($metadata['albumArtists'] ?? null) || $metadata['albumArtists'] === []) {
            throw new ScrapeAdminInvalid('最终信息缺少标题、艺术家、专辑艺术家或专辑。');
        }
        if (is_int($metadata['trackNumber'] ?? null) && is_int($metadata['trackTotal'] ?? null)
            && $metadata['trackNumber'] > $metadata['trackTotal']) {
            throw new ScrapeAdminInvalid('曲目号不能大于曲目总数。');
        }
        if (is_int($metadata['discNumber'] ?? null) && is_int($metadata['discTotal'] ?? null)
            && $metadata['discNumber'] > $metadata['discTotal']) {
            throw new ScrapeAdminInvalid('碟号不能大于碟片总数。');
        }

        return new ScrapeMetadataCandidate(
            $metadata,
            100,
            'manual',
            array_values(array_unique([...$current->evidence, 'manual_edit'])),
        );
    }

    /**
     * 返回规范化后发生变化的白名单字段名，供脱敏审计和字段来源更新使用。
     *
     * 比较只覆盖审核可编辑字段，不返回前后值；方法无副作用，调用者仍须把候选、来源映射和审计
     * 放在同一版本锁事务中提交。
     *
     * @return list<string>
     */
    public function changedFields(ScrapeMetadataCandidate $before, ScrapeMetadataCandidate $after): array
    {
        return array_values(array_filter(
            ScrapeMetadataFieldSources::FIELDS,
            static fn (string $field): bool => ($before->metadata[$field] ?? null) !== ($after->metadata[$field] ?? null),
        ));
    }

    private function requiredString(mixed $value, int $max, string $field): string
    {
        if (!is_string($value)) throw new ScrapeAdminInvalid($field . ' 字段类型无效。');
        $value = $this->clean($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max) throw new ScrapeAdminInvalid($field . ' 字段长度无效。');
        return $value;
    }

    private function nullableString(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || $value === '') return null;
        return $this->requiredString($value, $max, $field);
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $maxItems, int $maxLength, bool $allowEmpty, string $field): array
    {
        if (!is_array($value) || count($value) > $maxItems) throw new ScrapeAdminInvalid($field . ' 列表无效。');
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) throw new ScrapeAdminInvalid($field . ' 列表项类型无效。');
            $item = $this->clean($item);
            if ($item === '' || mb_strlen($item, 'UTF-8') > $maxLength) throw new ScrapeAdminInvalid($field . ' 列表项长度无效。');
            $result[] = $item;
        }
        $result = array_values(array_unique($result));
        if (!$allowEmpty && $result === []) throw new ScrapeAdminInvalid($field . ' 不能为空。');
        return $result;
    }

    private function boundedInteger(mixed $value, int $min, int $max, bool $nullable, string $field): ?int
    {
        if ($nullable && ($value === null || $value === '')) return null;
        if (!is_int($value) || $value < $min || $value > $max) throw new ScrapeAdminInvalid($field . ' 数值无效。');
        return $value;
    }

    private function releaseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^(?:1\d{3}|2\d{3})(?:-(?:0[1-9]|1[0-2])(?:-(?:0[1-9]|[12]\d|3[01]))?)?$/', $value) !== 1) {
            throw new ScrapeAdminInvalid('发行日期格式无效。');
        }
        return $value;
    }

    private function isrc(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw new ScrapeAdminInvalid('ISRC 格式无效。');
        $value = strtoupper(str_replace(['-', ' '], '', $value));
        if (preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $value) !== 1) throw new ScrapeAdminInvalid('ISRC 格式无效。');
        return $value;
    }

    private function clean(string $value): string
    {
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
