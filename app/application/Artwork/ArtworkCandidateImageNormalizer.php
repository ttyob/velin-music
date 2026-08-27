<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * 把不受信上传或 Provider 图片规范化为固定 1000 x 1000 WebP 候选。
 *
 * 输入最多 20 MiB，只接受 JPEG/PNG/WebP、单边不超过 12000 且总像素不超过 8000 万。实现先验证
 * 声明 MIME 与真实 MIME，再完整解码、按万分比裁剪并重新编码；EXIF、ICC、文本块、原文件名及原始
 * 压缩流都不会进入结果。裁剪区域始终按原始宽高比重采样到正方形：Provider 图片先居中裁成正方形，
 * 手工上传则使用管理员确认的裁剪框，因此不会直接拉伸原图。所有高成本处理必须在数据库事务外调用；
 * 失败不产生文件或数据库副作用。
 */
final class ArtworkCandidateImageNormalizer
{
    public const MAX_INPUT_BYTES = 20 * 1024 * 1024;
    public const OUTPUT_SIZE = 1000;
    private const MAX_SOURCE_DIMENSION = 12_000;
    private const MAX_SOURCE_PIXELS = 80_000_000;
    private const MAX_OUTPUT_BYTES = 8 * 1024 * 1024;

    /**
     * 按浏览器提交的万分比框规范化图片。
     *
     * 裁剪框必须完全位于 0..10000 坐标空间，宽高至少为 1。浏览器预览不具权威性，本方法会基于真实
     * 解码尺寸重新换算像素边界，再在框内取居中的最大正方形，确保异常或旧客户端提交非正方形框时
     * 也只会缩小裁剪范围而不会拉伸内容；PHP/GD 资源在成功和失败路径都会释放。
     *
     * @param array{x:int,y:int,width:int,height:int} $crop
     */
    public function crop(string $bytes, string $declaredMime, array $crop): string
    {
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!isset($crop[$key]) || !is_int($crop[$key])) throw new ArtworkAdminInvalid('裁剪框无效。');
        }
        if ($crop['x'] < 0 || $crop['y'] < 0 || $crop['width'] < 1 || $crop['height'] < 1
            || $crop['x'] + $crop['width'] > 10_000 || $crop['y'] + $crop['height'] > 10_000) {
            throw new ArtworkAdminInvalid('裁剪框越界。');
        }
        return $this->normalize($bytes, $declaredMime, $crop);
    }

    /**
     * 对 Provider 原图使用居中正方形裁剪。
     *
     * 图片摘要在调用本方法前由 Provider Worker 复验；本方法仍重新读取真实 MIME 和尺寸，避免摘要与
     * 实际解码器视图不一致。非正方形图片从长边等量裁去，绝不拉伸人物或封面比例。
     */
    public function centered(string $bytes, string $declaredMime): string
    {
        [$width, $height] = $this->facts($bytes, $declaredMime);
        if ($width === $height) return $this->normalize($bytes, $declaredMime, ['x' => 0, 'y' => 0, 'width' => 10_000, 'height' => 10_000]);
        if ($width > $height) {
            $cropWidth = max(1, (int) round(10_000 * $height / $width));
            return $this->normalize($bytes, $declaredMime, ['x' => intdiv(10_000 - $cropWidth, 2), 'y' => 0,
                'width' => $cropWidth, 'height' => 10_000]);
        }
        $cropHeight = max(1, (int) round(10_000 * $width / $height));
        return $this->normalize($bytes, $declaredMime, ['x' => 0, 'y' => intdiv(10_000 - $cropHeight, 2),
            'width' => 10_000, 'height' => $cropHeight]);
    }

    /** @param array{x:int,y:int,width:int,height:int} $crop */
    private function normalize(string $bytes, string $declaredMime, array $crop): string
    {
        [$width, $height] = $this->facts($bytes, $declaredMime);
        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof \GdImage) throw new ArtworkAdminInvalid('图片无法完整解码。');
        $target = imagecreatetruecolor(self::OUTPUT_SIZE, self::OUTPUT_SIZE);
        if (!$target instanceof \GdImage) {
            imagedestroy($source);
            throw new ArtworkAdminConflict('图片画布创建失败。');
        }
        try {
            $x = min($width - 1, (int) floor($width * $crop['x'] / 10_000));
            $y = min($height - 1, (int) floor($height * $crop['y'] / 10_000));
            $cropWidth = max(1, min($width - $x, (int) ceil($width * $crop['width'] / 10_000)));
            $cropHeight = max(1, min($height - $y, (int) ceil($height * $crop['height'] / 10_000)));
            // 万分比换算和客户端误差可能产生非正方形像素框；只在已确认区域内部居中收窄，既不越界，
            // 也不扩大管理员未选择的内容。正方形源区域再等比重采样到正方形画布，因而不会发生拉伸。
            if ($cropWidth > $cropHeight) {
                $x += intdiv($cropWidth - $cropHeight, 2);
                $cropWidth = $cropHeight;
            } elseif ($cropHeight > $cropWidth) {
                $y += intdiv($cropHeight - $cropWidth, 2);
                $cropHeight = $cropWidth;
            }
            if (!imagecopyresampled($target, $source, 0, 0, $x, $y, self::OUTPUT_SIZE, self::OUTPUT_SIZE,
                $cropWidth, $cropHeight)) {
                throw new ArtworkAdminConflict('图片裁剪失败。');
            }
            ob_start();
            try {
                if (!imagewebp($target, null, 90)) throw new ArtworkAdminConflict('图片编码失败。');
                $result = ob_get_contents();
                if (!is_string($result) || $result === '' || strlen($result) > self::MAX_OUTPUT_BYTES) {
                    throw new ArtworkAdminConflict('规范化图片大小无效。');
                }
                return $result;
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($target);
            imagedestroy($source);
        }
    }

    /** @return array{int,int} 验证字节上限、真实 MIME、尺寸和像素预算。 */
    private function facts(string $bytes, string $declaredMime): array
    {
        $mime = strtolower(trim(explode(';', $declaredMime, 2)[0]));
        if ($bytes === '' || strlen($bytes) > self::MAX_INPUT_BYTES
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ArtworkAdminInvalid('图片大小或类型无效。');
        }
        $facts = @getimagesizefromstring($bytes);
        if (!is_array($facts) || (string) ($facts['mime'] ?? '') !== $mime) {
            throw new ArtworkAdminInvalid('图片内容类型无效。');
        }
        $width = (int) ($facts[0] ?? 0);
        $height = (int) ($facts[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_SOURCE_DIMENSION
            || $height > self::MAX_SOURCE_DIMENSION || $width * $height > self::MAX_SOURCE_PIXELS) {
            throw new ArtworkAdminInvalid('图片像素尺寸无效。');
        }
        return [$width, $height];
    }
}
