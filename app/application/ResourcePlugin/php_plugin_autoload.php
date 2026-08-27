<?php

declare(strict_types=1);

/**
 * 为后台安装的 PHP 插件提供受限 PSR-4 自动加载。
 *
 * Composer 的 authoritative classmap 在镜像构建时看不到后来上传的类，因此这里只处理
 * `plugin\<安全 namespace key>\...`。目录 key 中的 `-` 确定性映射为 PHP namespace 的 `_`，反向定位
 * 时只接受已存在且恰好匹配该映射的活动插件目录。类名逐段限制为 PHP 标识符，最终 realpath 必须留在
 * 固定插件根，且目标必须是非链接普通 `.php` 文件；其他命名空间立即交还 Composer，不扫描嵌套目录，
 * 也不从数据库或请求读取类路径。
 */
spl_autoload_register(static function (string $class): void {
    if (preg_match('/^plugin\\\\([a-z0-9][a-z0-9_]{1,47})\\\\((?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*)$/D',
        $class, $matches) !== 1) return;
    $root = realpath(base_path('plugin'));
    if (!is_string($root)) return;
    $directoryKey = null;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $candidate = basename($directory);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $candidate) === 1
            && str_replace('-', '_', $candidate) === $matches[1]) {
            if ($directoryKey !== null) return;
            $directoryKey = $candidate;
        }
    }
    if ($directoryKey === null) return;
    $path = $root . '/' . $directoryKey . '/' . str_replace('\\', '/', $matches[2]) . '.php';
    $real = realpath($path);
    if (!is_string($real) || is_link($path) || !is_file($real)
        || !str_starts_with($real, $root . '/' . $directoryKey . '/')) return;
    require_once $real;
}, true, true);
