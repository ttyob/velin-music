<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * 定义音频内嵌封面的受限发现与物化边界。
 *
 * 实现只能读取已经由扫描器或授权服务验证过的绝对音频路径，不得修改音频标签。发现结果必须经过
 * 实际图片签名、大小、尺寸和像素限制校验；物化方法还必须核对索引时保存的摘要，防止缓存缺失后从
 * 已被替换的音频中返回另一张图片。实现产生的文件只能位于私有运行时缓存，不能写入音乐库。
 */
interface EmbeddedArtworkSource
{
    /**
     * 探测并提取第一个受支持的 attached picture。
     *
     * `hadCandidates` 区分“没有内嵌图”和“存在但格式无效”，供扫描详情解释结果。失败不抛出媒体级
     * 异常，而是返回无匹配；调用方仍需在外部进程前后重验音频文件身份。
     *
     * @return array{
     *   match: array{
     *     streamIndex: int,
     *     image: array{mime: string, width: int, height: int, size: int, mtime: int, dev: int, ino: int, sha256: string}
     *   }|null,
     *   hadCandidates: bool
     * }
     */
    public function discover(string $audioPath): array;

    /**
     * 返回与已保存身份完全一致的私有缓存文件，缓存丢失时允许从同一音频流重新生成。
     *
     * 只有流序号、MIME、字节数和 SHA-256 全部一致才成功；否则抛出路径无关异常。该方法不承担用户
     * 授权，必须在调用前完成专辑可见性和源音频身份复验。
     *
     * @throws EmbeddedArtworkExtractionFailed 缓存、外部进程或图片身份不满足约束
     */
    public function materialize(
        string $audioPath,
        int $streamIndex,
        string $expectedMime,
        int $expectedSize,
        string $expectedSha256,
    ): string;
}
