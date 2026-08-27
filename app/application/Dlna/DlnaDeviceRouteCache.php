<?php

declare(strict_types=1);

namespace app\application\Dlna;

/**
 * 定义 Renderer 快速路由令牌的可丢失服务端缓存边界。
 *
 * 路由缓存只用于避免页面刷新或同账号其他标签页控制时重复等待 SSDP，不是设备授权、账号租约或 SSRF
 * 安全边界。实现不得保存明文 UDN、IP 或 Location；读取失败、缓存缺失和存储故障必须等价于未命中，
 * 由调用方重新发现设备。令牌本身仍由 DlnaService 加密、绑定 Renderer 并限制有效期。
 */
interface DlnaDeviceRouteCache
{
    /**
     * 保存一个已经通过服务端校验的加密路由令牌。
     *
     * 相同 Renderer 的重复写入覆盖旧值并重置缓存期限；失败不得阻断当前 DLNA 命令，因为本次请求已经
     * 持有可用路由，后续请求仍可安全回退 SSDP。
     */
    public function put(string $deviceId, string $routeToken): void;

    /** 读取加密令牌；不存在、损坏或缓存暂时不可用均返回 null，不返回任何解密后的网络地址。 */
    public function get(string $deviceId): ?string;

    /** 删除已确认损坏或过期的缓存令牌；重复删除及缓存不可用均视为幂等成功。 */
    public function forget(string $deviceId): void;
}
