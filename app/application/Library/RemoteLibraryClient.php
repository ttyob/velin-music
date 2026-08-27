<?php

declare(strict_types=1);

namespace app\application\Library;

/**
 * 统一只读网络音乐库的最小协议边界。
 *
 * 实现只能访问创建时固定的远端根，所有目录结果必须规范化为根内相对路径；对象读取必须绑定扫描观察到
 * 的 ETag、大小和修改时间，并以最多 1 MiB 的区间流交付。认证秘密不得出现在 URL、argv、日志或异常
 * 消息。所有方法会执行外部网络 I/O，调用方不得在 SQLite 写事务内调用；失败抛出脱敏的
 * RemoteLibraryUnavailable，且不得返回部分可信目录供缺失校准继续运行。
 */
interface RemoteLibraryClient
{
    /** 返回稳定来源键，仅允许 `webdav|onedrive|google_drive`。 */
    public function sourceType(): string;

    /** 验证认证和配置根当前可读取；成功不承诺后续网络持续可用。 */
    public function assertConnection(): void;

    /** @return list<WebDavObject> 包含请求目录自身及其直接子对象。 */
    public function listDirectory(string $relativeDirectory): array;

    /** 打开经过状态码、区间、总大小和对象版本复验的单段读取流。 */
    public function openRange(WebDavObject $object, int $start, ?int $end = null): WebDavRangeResponse;

    /** 启动只服务单个对象的一次性回环代理；调用方必须关闭返回会话。 */
    public function openRangeProxy(WebDavObject $object, bool $continuousResponse = false): WebDavRangeProxySession;
}
