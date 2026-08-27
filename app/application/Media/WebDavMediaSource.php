<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Library\RemoteLibraryClient;
use app\application\Library\RemoteLibraryUnavailable;
use app\application\Library\WebDavObject;

/**
 * 以受复验 WebDAV 对象实现按需远端读取；本类本身不创建完整本地缓存。
 *
 * 每个范围 GET 都携带 If-Match（真实 ETag 可用时），并由 WebDavClient 复验 206、Content-Range、总大小
 * 和响应 ETag。同步 HTTP Handler 的单次读取限制为 1 MiB，避免开放区间在返回前吞下整首音频。FFmpeg
 * 仅获得一次性回环 URL；远端地址、路径、用户名和密码不进入 argv、日志或响应。
 */
final readonly class WebDavMediaSource implements MediaReadableSource
{
    public function __construct(
        private RemoteLibraryClient $client,
        private WebDavObject $object,
    ) {
    }

    /** 远端源没有可安全交给 withFile 的本地路径。 */
    public function localPath(): ?string
    {
        return null;
    }

    /**
     * 读取一个最多 1 MiB 的远端范围并要求正文长度与已验证 Content-Range 完全一致。
     *
     * @throws MediaStreamUnavailable 网络、版本漂移、协议不兼容或短读；异常不含远端定位信息。
     */
    public function readRange(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 1 || $length > 1_048_576 || $offset >= $this->object->size) {
            throw new MediaStreamUnavailable(strtoupper($this->client->sourceType()) . '_RANGE_INVALID');
        }
        $end = min($this->object->size - 1, $offset + $length - 1);
        try {
            $response = $this->client->openRange($this->object, $offset, $end);
            $expected = $response->length();
            $bytes = '';
            while (!$response->body->eof() && strlen($bytes) < $expected) {
                $chunk = $response->body->read(min(65_536, $expected - strlen($bytes)));
                if ($chunk === '') break;
                $bytes .= $chunk;
            }
            $response->body->close();
            if (strlen($bytes) !== $expected) {
                throw new MediaStreamUnavailable(strtoupper($this->client->sourceType()) . '_RANGE_SHORT_READ');
            }
            return $bytes;
        } catch (MediaStreamUnavailable $failure) {
            throw $failure;
        } catch (RemoteLibraryUnavailable) {
            throw new MediaStreamUnavailable(strtoupper($this->client->sourceType()) . '_SOURCE_UNAVAILABLE');
        }
    }

    /**
     * 启动只服务该不可变对象的一次性回环代理，租约关闭时终止代理及活动上游请求。
     *
     * 转码启用连续下游响应，使 FFmpeg 的一个开放 Range 可以跨越多个 1 MiB WebDAV 上游区间；这不会
     * 创建完整缓存，每段仍独立携带 If-Match 并复验远端身份。扫描探测不使用此模式，以保留按需 seek。
     */
    public function openTranscodeInput(): MediaInputLease
    {
        try {
            $session = $this->client->openRangeProxy($this->object, true);
            return new MediaInputLease($session->url, $session);
        } catch (RemoteLibraryUnavailable) {
            throw new MediaStreamUnavailable(strtoupper($this->client->sourceType()) . '_SOURCE_UNAVAILABLE');
        }
    }
}
