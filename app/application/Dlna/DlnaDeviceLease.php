<?php

declare(strict_types=1);

namespace app\application\Dlna;

/**
 * 定义共享 DLNA Renderer 的按账号短租约边界。
 *
 * 实现必须以账号而非 Session/标签页为所有者，使同一账号可在多个入口继续控制；不同账号不得静默覆盖
 * 当前 URI。租约只协调 Velin Music 发出的命令，无法阻止电视、厂商 App 等外部 DMC 直接控制设备。
 */
interface DlnaDeviceLease
{
    /**
     * 取得或校验一个 Renderer 租约。
     *
     * refresh=false 用于 status：已有同账号租约只校验不续期，调用方确认设备仍非 STOPPED 后再 refresh；
     * 空闲设备仍会创建初始租约。返回 true 表示本次新建，便于后续命令失败时只回滚自己新建的占用。
     *
     * @param array<string,mixed> $actor 已通过实时 play/cast 授权的账号
     * @throws DlnaUnavailable 设备被其他账号占用或协调存储不可用
     */
    public function acquire(array $actor, string $deviceId, bool $refresh = true): bool;

    /** 仅当当前账号仍是所有者时续期；租约丢失或被占用均失败关闭。 */
    public function refresh(array $actor, string $deviceId): void;

    /** 仅删除当前账号持有的租约；重复释放和租约已过期均视为幂等成功。 */
    public function release(array $actor, string $deviceId): void;
}
