<?php

declare(strict_types=1);

namespace app\application\Theme;

use JsonException;
use stdClass;
use support\Db;

/**
 * 提供用户端只读预置基础色目录和账号偏好回退。
 *
 * 主题 token 只由版本化迁移发布，运行时不再接受自定义 CSS、颜色或主题生命周期命令。所有对外读取
 * 都同时限定 `builtin` 与 `published`，因此历史自定义行在退役迁移执行前也不会越过 API 边界。
 * 后台主题预览和站点默认设置已经退役，匿名及无效偏好固定优先回退到 `velin`；本服务不提供任何
 * 管理员写入、引用人数或对比度诊断接口。
 */
final class ThemeService
{
    public function __construct(
        private readonly ThemeTokenValidator $tokenValidator = new ThemeTokenValidator(),
    ) {
    }

    /**
     * 返回经过完整 token 校验的已发布预置主题和可用默认值。
     *
     * 单条损坏记录会被隔离；若全部损坏则关闭失败，让前端继续使用编译期兜底主题。该方法只读，
     * 不修复数据，也不因无效默认设置产生隐式写入。
     *
     * @return array{themes: list<array<string, mixed>>, defaultThemeId: string, catalogVersion: string}
     */
    public function publicCatalog(): array
    {
        $themes = [];
        $rows = Db::table('themes')->where('kind', 'builtin')->where('status', 'published')
            ->whereNull('deleted_at')->orderBy('name')->get();
        foreach ($rows as $row) {
            try {
                $theme = $this->mapTheme($row);
                $this->tokenValidator->validate($theme['tokens'], true);
                $themes[] = $theme;
            } catch (ThemeInvalid|JsonException) {
                // 损坏的展示配置不能进入匿名目录；迁移或人工修复后会在下次读取恢复。
            }
        }
        if ($themes === []) {
            throw new ThemeNotFound('No published built-in theme is available.');
        }

        $ids = array_column($themes, 'id');
        $default = in_array('velin', $ids, true) ? 'velin' : (string) $ids[0];
        $versions = array_map(static fn (array $theme): string => $theme['id'] . ':' . $theme['version'], $themes);

        return [
            'themes' => $themes,
            'defaultThemeId' => $default,
            'catalogVersion' => hash('sha256', implode('|', [...$versions, 'default:' . $default])),
        ];
    }

    /** 返回有效预置主题偏好；无效或已退役自定义 ID 只回退，不执行数据库写入。 */
    public function resolvePreference(?string $requestedThemeId): array
    {
        $catalog = $this->publicCatalog();
        $ids = array_column($catalog['themes'], 'id');
        if ($requestedThemeId !== null && in_array($requestedThemeId, $ids, true)) {
            return ['themeId' => $requestedThemeId, 'themeFallbackFrom' => null];
        }

        return ['themeId' => $catalog['defaultThemeId'], 'themeFallbackFrom' => $requestedThemeId];
    }

    /** 用户偏好写入只能选择当前已发布的预置主题。 */
    public function isPublished(string $themeId): bool
    {
        return Db::table('themes')->where('id', $themeId)->where('kind', 'builtin')
            ->where('status', 'published')->whereNull('deleted_at')->exists();
    }

    /** @return array<string, mixed> 将持久行映射为固定 API 字段，不暴露操作者和原始 JSON。 */
    private function mapTheme(stdClass $row): array
    {
        $tokens = $this->decodeTokens((string) $row->tokens_json);
        $theme = [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'kind' => 'builtin',
            'status' => 'published',
            'tokens' => $tokens,
            'version' => (int) $row->version,
            'updatedAt' => (string) $row->updated_at,
        ];
        return $theme;
    }

    /** JSON 必须解码为对象形关联数组，损坏值关闭失败且不会被自动改写。 */
    private function decodeTokens(string $json): array
    {
        $tokens = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($tokens) || array_is_list($tokens)) {
            throw new ThemeInvalid('Persisted theme tokens are invalid.');
        }
        return $tokens;
    }

}
