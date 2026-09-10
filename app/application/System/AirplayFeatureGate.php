<?php

declare(strict_types=1);

namespace app\application\System;

/**
 * 隔离 AirPlay 业务编排与系统设置存储，确保任何票据、租约或 OwnTone 调用前都能失败关闭。
 *
 * 生产实现读取版本化数据库设置；测试实现只能替换开关判断，不能绕过 Controller 的实时账号权限。
 */
interface AirplayFeatureGate
{
    /** 设置关闭、缺失或损坏时抛出稳定 AirPlay 领域错误，且不得产生外部副作用。 */
    public function assertEnabled(): void;
}
