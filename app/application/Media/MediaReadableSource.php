<?php

declare(strict_types=1);

namespace app\application\Media;

/**
 * 统一描述已经通过授权和运行时身份复验的本地或远端音频读取源。
 *
 * 本地源可把规范绝对路径交给 Workerman/FFmpeg；远端源必须隐藏 URL 与凭据，只允许按有界字节区间
 * 读取，或生成生命周期受控的回环 FFmpeg 输入。完整远端源只能由独立下一首预缓存 Worker 在固定有界
 * runtime 中原子物化；普通请求和该接口实现不得自行创建无界副本。
 */
interface MediaReadableSource
{
    /** 本地源返回已复验绝对路径；远端源返回 NULL，调用方不得伪造路径回退。 */
    public function localPath(): ?string;

    /**
     * 从指定偏移读取至多 `$length` 字节。
     *
     * 偏移和长度必须位于 PlayableMedia 声明的对象大小内，单次长度不得超过 1 MiB。返回空字符串只允许
     * 表示失败并应抛出异常，不能被调用方解释为提前 EOF。
     */
    public function readRange(int $offset, int $length): string;

    /**
     * 为一个受监督 FFmpeg 子进程创建输入定位符。
     *
     * 本地输入只包装规范路径；远端输入启动一次性回环 Range 代理。返回租约必须由 runner 在成功、失败、
     * 超时或客户端断开后关闭，析构仅作为异常补偿。
     */
    public function openTranscodeInput(): MediaInputLease;
}
