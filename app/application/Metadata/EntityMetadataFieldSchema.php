<?php

declare(strict_types=1);

namespace app\application\Metadata;

use DateTimeImmutable;
use Throwable;

/**
 * 定义艺术家和专辑实体可维护字段、类型、空值与外部 ID 白名单。
 *
 * 共享实体字段与单曲覆盖必须分开：单曲的 `album` 或 `albumArtists` 只影响该歌曲覆盖层，本类接受的
 * 命令会显式修改整个艺术家或专辑。所有输入在进入 JSON 和目录表前规范化，不接受路径、图片、歌词、
 * 任意第三方响应或未声明扩展键。
 */
final class EntityMetadataFieldSchema
{
    /** @return list<string> 返回类型固定字段顺序，供详情、命令数量上限和前端稳定渲染。 */
    public function fields(string $type): array
    {
        return match ($type) {
            'artist' => ['name', 'sortName', 'externalIds'],
            'album' => ['title', 'sortTitle', 'albumArtists', 'releaseDate', 'discTotal', 'externalIds'],
            default => throw new MediaMetadataInvalid('不支持的元数据实体类型。'),
        };
    }

    /**
     * 严格规范化一个实体字段。
     *
     * 名称和标题不可为空；列表去重并保留顺序；日期只接受年、年月或完整日期；外部 ID 按实体类型
     * 限定，防止把歌曲 ISRC 写到专辑或艺术家。失败不产生任何数据库副作用。
     */
    public function normalize(string $type, string $field, mixed $value): mixed
    {
        if (!in_array($field, $this->fields($type), true)) throw new MediaMetadataInvalid('不支持的实体元数据字段。');
        if ($field === 'albumArtists') return $this->names($value);
        if ($field === 'discTotal') {
            if ($value === null) return null;
            if (!is_int($value) || $value < 0 || $value > 9999) throw new MediaMetadataInvalid('专辑碟数无效。');
            return $value;
        }
        if ($field === 'externalIds') return $this->externalIds($type, $value);
        if ($value === null || $value === '') {
            if (in_array($field, ['name', 'title'], true)) throw new MediaMetadataInvalid('实体名称或标题不能为空。');
            return null;
        }
        if (!is_string($value)) throw new MediaMetadataInvalid('实体文本字段类型无效。');
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $maximum = $field === 'title' ? 500 : 255;
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum) throw new MediaMetadataInvalid('实体文本字段长度无效。');
        if ($field === 'releaseDate') $this->assertDate($value);
        return $value;
    }

    /** 名称/标题不允许清空；其余字段返回各自可验证的显式空值。 */
    public function emptyValue(string $type, string $field): mixed
    {
        if (!in_array($field, $this->fields($type), true)) throw new MediaMetadataInvalid('不支持的实体元数据字段。');
        if (in_array($field, ['name', 'title'], true)) throw new MediaMetadataInvalid('该实体字段不能清空。');
        return in_array($field, ['albumArtists', 'externalIds'], true) ? [] : null;
    }

    /** @return list<string> */
    private function names(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 64) {
            throw new MediaMetadataInvalid('专辑艺术家列表无效。');
        }
        $result = [];
        foreach ($value as $name) {
            if (!is_string($name)) throw new MediaMetadataInvalid('专辑艺术家类型无效。');
            $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 255) throw new MediaMetadataInvalid('专辑艺术家名称无效。');
            $result[mb_strtolower($name, 'UTF-8')] ??= $name;
        }
        return array_values($result);
    }

    /** @return array<string,string> */
    private function externalIds(string $type, mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) throw new MediaMetadataInvalid('实体外部 ID 无效。');
        $allowed = $type === 'artist' ? ['musicbrainzArtistId'] : ['musicbrainzReleaseId', 'musicbrainzReleaseGroupId'];
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !in_array($key, $allowed, true) || !is_string($item)) {
                throw new MediaMetadataInvalid('实体外部 ID 键或值无效。');
            }
            $item = trim($item);
            if ($item === '' || strlen($item) > 255 || preg_match('/[\x00-\x1F\x7F]/', $item) === 1) {
                throw new MediaMetadataInvalid('实体外部 ID 值无效。');
            }
            $result[$key] = $item;
        }
        ksort($result);
        return $result;
    }

    /** 验证规范发行日期；年月额外校验月份范围，完整日期交给日历实现。 */
    private function assertDate(string $value): void
    {
        if (preg_match('/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/', $value) !== 1) {
            throw new MediaMetadataInvalid('发行日期格式无效。');
        }
        if (strlen($value) === 7 && ((int) substr($value, 5, 2) < 1 || (int) substr($value, 5, 2) > 12)) {
            throw new MediaMetadataInvalid('发行月份无效。');
        }
        if (strlen($value) !== 10) return;
        try {
            if ((new DateTimeImmutable($value))->format('Y-m-d') !== $value) throw new MediaMetadataInvalid('发行日期无效。');
        } catch (MediaMetadataInvalid $error) {
            throw $error;
        } catch (Throwable) {
            throw new MediaMetadataInvalid('发行日期无效。');
        }
    }
}
