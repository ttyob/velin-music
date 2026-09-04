<?php

declare(strict_types=1);

namespace app\application\Artwork;

use RuntimeException;
use stdClass;
use support\Db;

/**
 * 以 SHA-256 为键保存封面正文，并兼容尚未创建去重表的缩减测试或滚动升级 schema。
 *
 * 图片解码、裁剪和编码必须在调用本类前完成；本类只在现有短事务中校验并写入一个最大 8 MiB 的 WebP。
 * 同一摘要再次写入时，字节、MIME、尺寸和长度必须全部一致，否则失败关闭，不能把理论哈希碰撞或历史
 * 损坏当作幂等成功。新 schema 的候选行只保存空 BLOB 和摘要；旧 schema 继续内联正文，保证单元测试
 * 缩减表与滚动升级期间不会因缺表中断。候选删除后的孤儿回收由迁移触发器负责，不触碰媒体文件。
 */
final class ArtworkBlobStore
{
    /**
     * 保存已规范化图片并返回候选表应写入的兼容 BLOB。
     *
     * @return string 新 schema 返回空字节，旧 schema 返回原正文。
     */
    public function put(string $bytes, string $mimeType, int $width, int $height, string $sha256): string
    {
        if ($bytes === '' || strlen($bytes) > 8_388_608 || $mimeType !== 'image/webp'
            || $width < 1 || $width > 1600 || $height < 1 || $height > 1600
            || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('ARTWORK_BLOB_INVALID');
        }
        if (!Db::connection()->getSchemaBuilder()->hasTable('media_artwork_blobs')) {
            return $bytes;
        }

        /** @var stdClass|null $existing */
        $existing = Db::table('media_artwork_blobs')->where('content_sha256', $sha256)->first();
        if (!$existing instanceof stdClass) {
            Db::table('media_artwork_blobs')->insert([
                'content_sha256' => $sha256,
                'image_bytes' => $bytes,
                'byte_size' => strlen($bytes),
                'width' => $width,
                'height' => $height,
                'mime_type' => $mimeType,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            return '';
        }
        if ((string) $existing->mime_type !== $mimeType || (int) $existing->byte_size !== strlen($bytes)
            || (int) $existing->width !== $width || (int) $existing->height !== $height
            || !hash_equals($bytes, (string) $existing->image_bytes)) {
            throw new RuntimeException('ARTWORK_BLOB_IDENTITY_MISMATCH');
        }
        return '';
    }

    /**
     * 读取候选正文并复验摘要与长度。
     *
     * 旧候选的非空内联 BLOB 优先使用；新候选必须从去重表取得同摘要正文。缺行、长度不符或摘要不符
     * 都表示备份/迁移或存储已损坏，调用方应转换为自身稳定错误码，不能返回部分图片。
     */
    public function bytes(stdClass $candidate): string
    {
        $bytes = (string) ($candidate->image_bytes ?? '');
        $digest = (string) ($candidate->content_sha256 ?? '');
        $expectedSize = (int) ($candidate->byte_size ?? 0);
        if ($bytes === '' && Db::connection()->getSchemaBuilder()->hasTable('media_artwork_blobs')) {
            /** @var stdClass|null $blob */
            $blob = Db::table('media_artwork_blobs')->where('content_sha256', $digest)
                ->first(['image_bytes', 'byte_size', 'mime_type']);
            if (!$blob instanceof stdClass || (int) $blob->byte_size !== $expectedSize
                || (string) $blob->mime_type !== (string) ($candidate->mime_type ?? '')) {
                throw new RuntimeException('ARTWORK_BLOB_MISSING');
            }
            $bytes = (string) $blob->image_bytes;
        }
        if ($bytes === '' || strlen($bytes) !== $expectedSize || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals($digest, hash('sha256', $bytes))) {
            throw new RuntimeException('ARTWORK_BLOB_CORRUPT');
        }
        return $bytes;
    }
}
