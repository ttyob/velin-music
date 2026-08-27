<?php

declare(strict_types=1);

namespace app\application\Transcode;

use app\application\Media\MediaInputLease;

/**
 * 只供 HTTP supervisor 消费的不可变、已完整校验 FFmpeg 执行计划。
 *
 * command 只能是服务端固定 codec/format 白名单构造的位置参数数组，不能是 shell 字符串。input 持有已经
 * 实时授权和身份复验的本地路径或一次性 WebDAV 回环代理；runner 必须在缓存命中、成功、失败、取消和超时
 * 路径关闭租约，不能记录 locator 或 stderr。`spoolBeforeSend` 以启动延迟换取精确 Content-Length，否则
 * 使用 HTTP chunk framing 增量发送。
 *
 * `persistentCacheKey` 只由内部授权的接收器精确长度请求设置，是媒体 ETag 与去地址化转码计划的一向摘要，
 * 不包含路径、WebDAV URL、票据、账号或设备信息。非空时输出可进入有界可重建缓存；`cacheOnly` 仅发布
 * 完整缓存并返回 204。任一模式都不代表播放完成，也不缓存 WebDAV 原始音频。
 */
final readonly class TranscodePlan
{
    /**
     * @param list<string> $command Fixed executable plus individually escaped-by-proc_open arguments.
     */
    public function __construct(
        public array $command,
        public string $contentType,
        public string $downloadName,
        public string $requestId,
        public bool $spoolBeforeSend,
        public int $timeoutSeconds,
        public int $maxOutputBytes,
        public TranscodeLease $lease,
        public MediaInputLease $input,
        /** `true` 仅改变响应下载语义，不允许调用方注入任意 Content-Disposition。 */
        public bool $attachment = false,
        /** 接收器精确长度派生缓存的 64 位十六进制内容地址；null 表示响应结束后删除 spool。 */
        public ?string $persistentCacheKey = null,
        /** 预热请求只发布缓存并返回 204，不把派生音频字节发送给发起预热的浏览器。 */
        public bool $cacheOnly = false,
        /** 完整派生输出的稳定强 ETag；只允许服务端内部内容摘要，普通实时流保持 null。 */
        public ?string $responseEtag = null,
    ) {
    }
}
