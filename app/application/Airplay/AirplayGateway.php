<?php

declare(strict_types=1);

namespace app\application\Airplay;

/**
 * 隔离 Webman 与 OwnTone 29.3 JSON API 的协议边界。
 *
 * 实现只能连接部署内固定回环地址，并把外部 JSON 校验成封闭投影；业务服务不得接触 OwnTone URL、任意
 * HTTP 方法或原始响应。OwnTone 是单队列播放器，选择一个输出会取消其他输出，调用方必须先取得全局租约。
 */
interface AirplayGateway
{
    /** @return list<array{id:string,name:string,selected:bool,volume:int,requiresAuth:bool}> */
    public function outputs(): array;

    /** 只启用指定 AirPlay 输出；设备不存在或需要配对时失败且不启动播放。 */
    public function selectOutput(string $outputId): void;

    /** 用服务端生成的短期媒体 URL 原子替换 OwnTone 队列并开始播放。 */
    public function playUrl(string $url): void;

    /** @return array{state:string,positionMs:int,durationMs:int,volume:int} */
    public function status(string $outputId): array;

    /** 执行固定控制动作；value 仅用于 seek 毫秒位置或 volume 百分比。 */
    public function control(string $outputId, string $operation, ?int $value = null): void;
}
