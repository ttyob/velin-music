<?php

declare(strict_types=1);

namespace app\application\Media;

/**
 * 定义从已经授权且完成身份复验的音频提取规范元数据的只读边界。
 *
 * 扫描 Worker 与播放缺失技术事实补全均可调用。调用方必须在执行前后确认本地规范路径仍属于当前库，
 * 并在写库时再次使用库存身份 CAS。实现可以启动有界子进程，但不得写标签、修改文件、泄露路径，或把
 * 本地入口扩展为任意 URL；失败只抛出脱敏稳定错误，由调用方决定降级或冷却。
 */
interface MediaProbe
{
    /**
     * 探测一个规范、普通且可读的本地文件并返回无路径元数据。
     *
     * @param string $absolutePath 仅服务端可见的规范路径，禁止写入日志或响应。
     * @param string $fallbackTitle 只在标签缺少标题时使用的不含扩展名文件名。
     * @throws MediaProbeFailed 输入、超时、输出上限、JSON 或音频流校验失败。
     */
    public function probe(string $absolutePath, string $fallbackTitle): MediaMetadata;
}
