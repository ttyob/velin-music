<?php

declare(strict_types=1);

namespace app\infrastructure\Media;

use app\application\Library\RemoteLibraryClient;
use app\application\Library\WebDavObject;
use app\application\Library\WebDavRangeProxySession;
use app\application\Media\MediaMetadata;
use app\application\Media\WebDavRemoteMetadataProbe;

/**
 * 使用回环 Range 代理按 FFprobe 实际 seek 读取网络库音频。
 *
 * 本探测器只允许受验证的 Range 请求，绝不创建完整音频临时文件。FFprobe 通常只读取容器头部及少量
 * 尾部索引；具体字节量由格式和服务端 206 行为决定，不能承诺固定大小。代理不可用、服务端忽略
 * Range 或容器不能局部解析时直接失败，由索引器以路径事实建立降级元数据。
 */
final readonly class RangeAwareWebDavMetadataProbe implements WebDavRemoteMetadataProbe
{
    public function __construct(
        private FfprobeMediaProbe $rangeProbe = new FfprobeMediaProbe(),
    ) {
    }

    /**
     * 通过一次性回环地址完成按需探测。
     *
     * 会话始终在 finally 中回收。任何失败保持原错误交给上层降级；本方法没有下载、缓存、重试或写入
     * 副作用，因此同一对象是否再次探测完全由库存签名策略决定。
     */
    public function probe(RemoteLibraryClient $client, WebDavObject $object, string $fallbackTitle): MediaMetadata
    {
        $session = null;
        try {
            $session = $client->openRangeProxy($object);
            return $this->rangeProbe->probeLocalRangeUrl($session->url, $fallbackTitle);
        } finally {
            if ($session instanceof WebDavRangeProxySession) $session->close();
        }
    }
}
