<?php

declare(strict_types=1);

namespace app\application\Theme;

/**
 * 校验固定深色界面可使用的单一基础色及其关键 WCAG 对比度。
 *
 * 主题只允许 `{color: "#RRGGBB"}`，不再接收明暗模式、中性色、效果、CSS 函数、URL、选择器或其他
 * 可执行字符串。返回值仍是数据，调用方只能把 `color` 映射到固定 CSS 自定义属性，数据库键不能决定
 * 任意样式属性。基础色必须同时能在黑色背景上作为状态色，并承载白色按钮文字。
 */
final class ThemeTokenValidator
{
    /**
     * 规范化基础色并计算固定深色布局所需的两组对比度。
     *
     * 基础色对黑色背景至少 3:1，白色文字对基础色至少 4.5:1。静态检查不能覆盖所有组件组合，发布仍需
     * 浏览器视觉回归；`$requireAccessible=false` 只允许管理诊断读取失败报告，不允许把失败颜色发布给
     * 用户。缺键、多键、格式错误或不达标时抛出 `ThemeInvalid`，不自动修正为近似颜色。
     *
     * @param array<string, mixed> $tokens 从持久 JSON 解码的主题对象。
     * @return array{tokens: array{color:string}, contrast: array{passed: bool, checks: list<array<string, mixed>>}}
     * @throws ThemeInvalid token 结构、颜色格式或发布对比度不符合固定契约。
     */
    public function validate(array $tokens, bool $requireAccessible): array
    {
        if (array_keys($tokens) !== ['color']) {
            throw new ThemeInvalid('Theme tokens must contain only color.');
        }
        $color = $tokens['color'] ?? null;
        if (!is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
            throw new ThemeInvalid('Theme color must be a six-digit hexadecimal color.');
        }
        $normalized = ['color' => strtoupper($color)];
        $checks = [];
        foreach ([
            ['accent-on-background', $normalized['color'], '#000000', 3.0],
            ['text-on-accent', '#FFFFFF', $normalized['color'], 4.5],
        ] as [$usage, $foreground, $background, $minimum]) {
            $ratio = $this->contrastRatio($foreground, $background);
            $checks[] = [
                'usage' => $usage,
                'ratio' => round($ratio, 2),
                'minimum' => $minimum,
                'passed' => $ratio >= $minimum,
            ];
        }
        $passed = !in_array(false, array_column($checks, 'passed'), true);
        if ($requireAccessible && !$passed) {
            throw new ThemeInvalid('Theme contrast does not meet the publication threshold.');
        }

        return ['tokens' => $normalized, 'contrast' => ['passed' => $passed, 'checks' => $checks]];
    }

    /** 返回两个已验证不透明 sRGB 颜色的 WCAG 2.x 对比度。 */
    private function contrastRatio(string $left, string $right): float
    {
        $light = max($this->luminance($left), $this->luminance($right));
        $dark = min($this->luminance($left), $this->luminance($right));

        return ($light + 0.05) / ($dark + 0.05);
    }

    /** 使用 WCAG sRGB 转换曲线把 `#RRGGBB` 转为相对亮度。 */
    private function luminance(string $color): float
    {
        $channels = [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))];
        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;
            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
