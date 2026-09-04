<?php

declare(strict_types=1);

/**
 * 配置内置 Go 媒体网关与仅回环可见的 Webman 上游。
 *
 * Docker 镜像固定只暴露 `/storage` 与 `/data` 两个受权媒体根，因此默认启用并禁止环境变量扩大范围；
 * 构建产物只从独立 public_path() 公开，不能把媒体根复用为静态根。裸机默认关闭，部署者显式启用时必须
 * 提供逗号分隔的规范媒体父目录。公开端口继续由 VELIN_API_PORT 控制，内部端口固定为 18787 且只绑定
 * 127.0.0.1，不能作为第二个公开 API。关闭网关会恢复 Webman 直接监听公开端口，便于源码开发和不具备
 * Linux Go 二进制的兼容环境。
 */
$container = is_file(base_path('.velin-container'));
$enabledValue = trim((string) getenv('VELIN_MEDIA_GATEWAY_ENABLED'));
$enabled = $enabledValue === ''
    ? $container
    : filter_var($enabledValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
$roots = ['/storage', '/data'];
if (!$container) {
    $rootsValue = trim((string) getenv('VELIN_MEDIA_GATEWAY_ALLOWED_ROOTS'));
    $roots = $rootsValue === '' ? [] : array_values(array_filter(array_map(
        static fn (string $root): string => rtrim(trim($root), DIRECTORY_SEPARATOR),
        explode(',', $rootsValue),
    ), static fn (string $root): bool => $root !== ''));
}

return [
    'enabled' => $enabled,
    'public_port' => max(1, min(65_535, (int) (getenv('VELIN_API_PORT') ?: 8787))),
    'internal_port' => 18_787,
    'binary_path' => (string) (getenv('VELIN_MEDIA_GATEWAY_PATH') ?: base_path('bin/velin-media-gateway')),
    'static_root' => public_path(),
    'allowed_roots' => $roots,
];
