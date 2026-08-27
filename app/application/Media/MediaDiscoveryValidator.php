<?php

declare(strict_types=1);

namespace app\application\Media;

/**
 * 校验歌曲发现 API 的页码与单页条数。
 *
 * 公开查询只接受无符号十进制整数，避免 PHP 的宽松数值转换把小数、指数或带符号文本解释为
 * 合法页码。页码上限限制深分页成本，单页上限限制关联艺术家、封面和个人偏好的批量查询规模；
 * 默认值只在字段缺失或空字符串时使用。校验器不访问数据库，也不静默截断越界输入，失败统一由
 * 控制器映射为 422，因而不会扩大媒体授权范围或产生任何写入副作用。
 */
final class MediaDiscoveryValidator
{
    /** @return array{page: int, pageSize: int} 返回可安全换算为有界 offset 的分页参数。 */
    public function pagination(mixed $page, mixed $pageSize): array
    {
        return [
            'page' => $this->integer($page, 1, 10_000, 1),
            'pageSize' => $this->integer($pageSize, 1, 100, 20),
        ];
    }

    /** 只接受规范整数文本或整数值，拒绝布尔值、浮点数、前导零和隐式类型转换。 */
    private function integer(mixed $value, int $minimum, int $maximum, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new MediaDiscoveryInvalid('Discovery pagination is invalid.');
        }
        if ($integer < $minimum || $integer > $maximum) {
            throw new MediaDiscoveryInvalid('Discovery pagination is outside the supported range.');
        }

        return $integer;
    }
}
