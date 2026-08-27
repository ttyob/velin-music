<?php

declare(strict_types=1);

namespace app\application\System;

use InvalidArgumentException;

/**
 * 统一解析服务公开 Origin 与 Session Cookie 的 HTTPS 策略。
 *
 * `VELIN_PUBLIC_URL` 是新部署唯一需要了解的公开地址变量；旧的 `VELIN_DLNA_PUBLIC_URL` 仅在新变量
 * 为空时回退，保证升级不会立即中断已有 DLNA。公开地址只允许无凭据、无路径、无查询参数和无片段的
 * HTTP(S) Origin，且永远不从请求 Host 或代理头推导，避免攻击者把短期媒体票据指向非预期主机。
 * 本类只读取进程环境且不缓存结果，因此测试和长驻 Worker 重载配置时没有持久化或回滚副作用。
 */
final class PublicUrlConfig
{
    private const MAXIMUM_ORIGIN_BYTES = 512;

    /**
     * 返回经过规范化的显式公开 Origin；未配置时返回 null，非法配置抛出异常并由功能边界失败关闭。
     *
     * 新变量始终优先，即使旧变量有效也不能掩盖新变量的配置错误。仅当新变量为空字符串或未定义时才
     * 读取旧变量；尾部唯一 `/` 会被移除，除此之外不重写主机、端口或大小写，避免改变证书和代理语义。
     */
    public static function publicOrigin(): ?string
    {
        $value = trim((string) getenv('VELIN_PUBLIC_URL'));
        if ($value === '') {
            $value = trim((string) getenv('VELIN_DLNA_PUBLIC_URL'));
        }
        return $value === '' ? null : self::normalizeOrigin($value);
    }

    /**
     * 返回可交给附近接收器的显式 HTTP(S) Origin；未配置时返回 null。
     *
     * 手机不代理媒体，Renderer 会直接读取该地址，所以可达性和 HTTP 明文网络风险由部署者负责。这里
     * 不改写 scheme，也不回退请求 Host；配置语法错误仍抛出异常，让调用方按不可用而不是误签票据。
     * 允许 HTTP 是为了兼容只支持局域网明文取流的传统 DLNA Renderer，票据的短期限、随机秘密和每次
     * 读取时的实时授权复验保持不变，但 HTTP 无法防止同一网络中的被动监听者截获票据。
     */
    public static function externalPlaybackOrigin(): ?string
    {
        return self::publicOrigin();
    }

    /**
     * 决定 Web Session Cookie 是否带 Secure。
     *
     * 显式非空的旧 `VELIN_SESSION_SECURE` 保持最高优先级，便于特殊代理拓扑兼容；未显式设置时仅根据
     * 可信公开 Origin 的 HTTPS scheme 自动启用。非法布尔值或非法公开地址安全收敛为 false，不会让
     * 配置加载阶段抛错导致服务无法启动，也不会根据单次请求在不同 Worker 间产生不一致 Cookie。
     */
    public static function sessionCookieSecure(): bool
    {
        $override = trim((string) getenv('VELIN_SESSION_SECURE'));
        if ($override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        }
        try {
            $origin = self::publicOrigin();
            return $origin !== null
                && strtolower((string) parse_url($origin, PHP_URL_SCHEME)) === 'https';
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * 校验任意内部或显式 Origin 并移除尾部 `/`。
     *
     * 调用方必须提供完整 HTTP(S) Origin。失败不执行网络访问或状态修改；服务端 DLNA 会把异常映射为
     * 稳定的部署错误，附近投放会关闭 capability。该方法也供自动发现地址复验，以维持同一语法边界。
     */
    public static function normalizeOrigin(string $value): string
    {
        $value = trim($value);
        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if ($value === '' || strlen($value) > self::MAXIMUM_ORIGIN_BYTES || !is_array($parts)
            || !in_array($scheme, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')) {
            throw new InvalidArgumentException('Public URL must be an HTTP(S) Origin.');
        }
        return rtrim($value, '/');
    }
}
