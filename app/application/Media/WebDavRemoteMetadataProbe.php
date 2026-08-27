<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Library\RemoteLibraryClient;
use app\application\Library\WebDavObject;

/**
 * 定义 WebDAV 音频元数据探测边界。
 *
 * 实现只允许通过受控 Range 读取 FFprobe 实际需要的片段，禁止无 Range 的完整源音频下载。协议不兼容、
 * Range 被忽略或局部解析失败时必须抛出脱敏领域异常，由索引器按路径事实降级；本边界不得自行重试为
 * 整文件读取。调用方只接收规范元数据，不接触 URL、凭据或部分正文，远端网络 I/O 必须在数据库事务外。
 */
interface WebDavRemoteMetadataProbe
{
    /** 探测一个已经由扫描发现并绑定身份的只读对象，失败只抛出脱敏领域异常。 */
    public function probe(RemoteLibraryClient $client, WebDavObject $object, string $fallbackTitle): MediaMetadata;
}
