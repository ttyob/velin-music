<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Theme\ThemeNotFound;
use app\application\Theme\ThemeService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供匿名可读的已发布基础色目录，不暴露操作者、草稿、原始 JSON 或运行时编辑能力。
 *
 * 目录只包含服务端已验证的固定 `{color}` token 和站点默认值；读取无数据库写入副作用。损坏或空目录
 * 关闭失败，客户端继续使用编译期深色基础色，不得把旧明暗 token 当作兼容回退。
 */
final class ThemeController
{
    /** 返回已验证基础色、有效站点默认值和缓存摘要；请求参数不能选择或注入 token。 */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            return JsonResponseFactory::create([
                'data' => (new ThemeService())->publicCatalog(),
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (ThemeNotFound) {
            return JsonResponseFactory::error(
                'THEME_CATALOG_UNAVAILABLE',
                '当前没有可用主题。',
                503,
                $requestId,
            );
        } catch (Throwable $throwable) {
            Log::error('Public theme catalog failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error(
                'THEME_CATALOG_UNAVAILABLE',
                '主题目录暂时不可用。',
                503,
                $requestId,
            );
        }
    }
}
