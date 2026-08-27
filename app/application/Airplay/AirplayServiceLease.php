<?php

declare(strict_types=1);

namespace app\application\Airplay;

/**
 * 定义 OwnTone 单队列播放器的账号级全局租约。
 *
 * 所有 AirPlay 输出共享同一队列，租约因此不能按单台音响拆分；同账号可跨标签页续用，不同账号必须在
 * 任何队列或输出副作用之前被拒绝。租约只协调 Velin Music，不约束 Apple Home 等外部控制端。
 */
interface AirplayServiceLease
{
    /** 取得全局租约；返回 true 表示本次新建，供失败补偿判断。 */
    public function acquire(array $actor, bool $refresh = true): bool;

    /** 仅允许当前账号续租；丢失或被其他账号占用时失败关闭。 */
    public function refresh(array $actor): void;

    /** 仅释放当前账号持有的租约；重复释放保持幂等。 */
    public function release(array $actor): void;
}
