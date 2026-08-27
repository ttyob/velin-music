<?php

declare(strict_types=1);

namespace app\application\Account;

use app\application\Preference\UserPreferenceConflict;
use app\application\Preference\UserPreferenceInvalid;
use app\infrastructure\Audit\AuditLogger;
use RuntimeException;
use stdClass;
use support\Db;
use Throwable;

/**
 * 管理当前账号的自定义头像，并为无头像账号生成确定性本地 identicon。
 *
 * 上传只接受 5 MiB 以内 JPEG/PNG/WebP。服务端先检查声明 MIME 与实际图像类型、像素边界，再用 GD
 * 解码、居中裁切、缩放并重新编码为固定 PNG；源 EXIF、文本块、ICC、文件名和其他元数据全部丢弃。
 * 处理完成后才进入短 SQLite 写事务，头像和共享偏好版本原子提交。任何响应、审计和日志都不含原字节。
 */
final class AccountAvatarService
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    private const SIZE = 256;
    private const MAX_SOURCE_DIMENSION = 8192;
    private const MAX_SOURCE_PIXELS = 40_000_000;

    public function __construct(private readonly AuditLogger $auditLogger = new AuditLogger())
    {
    }

    /** 返回自定义 PNG；不存在时根据账号 ULID 生成不访问网络、不落盘的稳定占位图。 */
    public function image(array $actor): AccountAvatarImage
    {
        $userId = $this->userId($actor);
        /** @var stdClass|null $row */
        $row = Db::table('user_avatars')->where('user_id', $userId)->first([
            'png_bytes', 'content_sha256', 'updated_at',
        ]);
        if ($row instanceof stdClass) {
            $bytes = (string) $row->png_bytes;
            $digest = (string) $row->content_sha256;
            if ($bytes !== '' && preg_match('/^[a-f0-9]{64}$/', $digest) === 1
                && hash_equals($digest, hash('sha256', $bytes))) {
                return new AccountAvatarImage(
                    $bytes,
                    '"avatar-' . substr($digest, 0, 32) . '"',
                    strtotime((string) $row->updated_at) ?: 0,
                    true,
                );
            }
            throw new RuntimeException('ACCOUNT_AVATAR_STORAGE_CORRUPT');
        }

        $digest = hash('sha256', 'velin-avatar-v1:' . $userId);
        return new AccountAvatarImage(
            $this->identicon($digest),
            '"avatar-v1-' . substr($digest, 0, 32) . '"',
            0,
            false,
        );
    }

    /**
     * 规范化并保存头像，成功后返回递增的共享偏好版本。
     *
     * @return array{version:int,updatedAt:string,avatarEtag:string}
     */
    public function upload(
        array $actor,
        string $sourceBytes,
        string $declaredMime,
        int $expectedVersion,
        string $requestId,
    ): array {
        if ($expectedVersion < 1 || $sourceBytes === '' || strlen($sourceBytes) > self::MAX_UPLOAD_BYTES) {
            throw new UserPreferenceInvalid('Avatar upload size or version is invalid.');
        }
        $normalizedMime = strtolower(trim(explode(';', $declaredMime, 2)[0]));
        if (!in_array($normalizedMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new UserPreferenceInvalid('Avatar content type is unsupported.');
        }
        $png = $this->normalize($sourceBytes, $normalizedMime);
        $digest = hash('sha256', $png);
        $userId = $this->userId($actor);
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $expectedVersion + 1;
            $changed = Db::table('user_preferences')->where('user_id', $userId)
                ->where('version', $expectedVersion)->update([
                    'version' => $nextVersion,
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new UserPreferenceConflict('个人设置已在其他页面更新，请重新加载后再试。');
            $currentAvatarVersion = (int) (Db::table('user_avatars')->where('user_id', $userId)->value('version') ?? 0);
            Db::table('user_avatars')->updateOrInsert(['user_id' => $userId], [
                'png_bytes' => $png,
                'content_sha256' => $digest,
                'byte_size' => strlen($png),
                'width' => self::SIZE,
                'height' => self::SIZE,
                'version' => $currentAvatarVersion + 1,
                'updated_at' => $now,
            ]);
            $this->auditLogger->record(
                $userId, 'user.avatar.update', 'user_avatar', $userId, 'success', $requestId,
                ['byteSize' => strlen($png), 'version' => $nextVersion],
            );
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) $pdo->exec('ROLLBACK');
            throw $throwable;
        }

        return ['version' => $nextVersion, 'updatedAt' => $now, 'avatarEtag' => '"avatar-' . substr($digest, 0, 32) . '"'];
    }

    /** 删除当前账号自定义头像并回退 identicon；不存在时仍验证版本但保持无副作用。 */
    public function delete(array $actor, int $expectedVersion, string $requestId): array
    {
        if ($expectedVersion < 1) throw new UserPreferenceInvalid('Avatar preference version is invalid.');
        $userId = $this->userId($actor);
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            $exists = Db::table('user_avatars')->where('user_id', $userId)->exists();
            /** @var stdClass|null $preference */
            $preference = Db::table('user_preferences')->where('user_id', $userId)->first(['version']);
            if (!$preference instanceof stdClass || (int) $preference->version !== $expectedVersion) {
                throw new UserPreferenceConflict('个人设置已在其他页面更新，请重新加载后再试。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $expectedVersion;
            if ($exists) {
                $nextVersion++;
                Db::table('user_avatars')->where('user_id', $userId)->delete();
                Db::table('user_preferences')->where('user_id', $userId)->where('version', $expectedVersion)
                    ->update(['version' => $nextVersion, 'updated_at' => $now]);
                $this->auditLogger->record(
                    $userId, 'user.avatar.delete', 'user_avatar', $userId, 'success', $requestId,
                    ['version' => $nextVersion],
                );
            }
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) $pdo->exec('ROLLBACK');
            throw $throwable;
        }
        return ['version' => $nextVersion, 'updatedAt' => $now];
    }

    /** 解码真实 MIME、限制像素内存成本，并将中心正方形缩放为无元数据 PNG。 */
    private function normalize(string $bytes, string $declaredMime): string
    {
        $facts = @getimagesizefromstring($bytes);
        if (!is_array($facts)) throw new UserPreferenceInvalid('Avatar image cannot be decoded.');
        $width = (int) ($facts[0] ?? 0);
        $height = (int) ($facts[1] ?? 0);
        $actualMime = (string) ($facts['mime'] ?? '');
        if ($actualMime !== $declaredMime || $width < 1 || $height < 1
            || $width > self::MAX_SOURCE_DIMENSION || $height > self::MAX_SOURCE_DIMENSION
            || $width * $height > self::MAX_SOURCE_PIXELS) {
            throw new UserPreferenceInvalid('Avatar dimensions or actual content type is invalid.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof \GdImage) throw new UserPreferenceInvalid('Avatar image cannot be decoded.');
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        if (!$target instanceof \GdImage) {
            imagedestroy($source);
            throw new RuntimeException('ACCOUNT_AVATAR_CANVAS_FAILED');
        }
        try {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefill($target, 0, 0, $transparent);
            $side = min($width, $height);
            $sourceX = intdiv($width - $side, 2);
            $sourceY = intdiv($height - $side, 2);
            if (!imagecopyresampled($target, $source, 0, 0, $sourceX, $sourceY, self::SIZE, self::SIZE, $side, $side)) {
                throw new RuntimeException('ACCOUNT_AVATAR_RESAMPLE_FAILED');
            }
            ob_start();
            try {
                if (!imagepng($target, null, 6)) throw new RuntimeException('ACCOUNT_AVATAR_ENCODE_FAILED');
                $png = ob_get_contents();
                if (!is_string($png) || $png === '' || strlen($png) > 1_048_576) {
                    throw new RuntimeException('ACCOUNT_AVATAR_OUTPUT_INVALID');
                }
                return $png;
            } finally { ob_end_clean(); }
        } finally {
            imagedestroy($target);
            imagedestroy($source);
        }
    }

    /** 生成 5x5 对称 identicon，颜色只来自用途分离摘要，不读取邮箱或外部服务。 */
    private function identicon(string $digest): string
    {
        $image = imagecreatetruecolor(128, 128);
        if (!$image instanceof \GdImage) throw new RuntimeException('ACCOUNT_AVATAR_CANVAS_FAILED');
        try {
            $foreground = imagecolorallocate($image, 55 + hexdec(substr($digest, 0, 2)) % 145, 55 + hexdec(substr($digest, 2, 2)) % 145, 55 + hexdec(substr($digest, 4, 2)) % 145);
            imagefill($image, 0, 0, imagecolorallocate($image, 238, 241, 246));
            for ($row = 0; $row < 5; $row++) for ($column = 0; $column < 3; $column++) {
                if ((hexdec(substr($digest, 6 + ($row * 3 + $column) * 2, 2)) & 1) === 0) continue;
                foreach (array_unique([$column, 4 - $column]) as $actual) {
                    imagefilledrectangle($image, 14 + $actual * 20, 14 + $row * 20, 14 + ($actual + 1) * 20 - 3, 14 + ($row + 1) * 20 - 3, $foreground);
                }
            }
            ob_start();
            try {
                if (!imagepng($image, null, 6)) throw new RuntimeException('ACCOUNT_AVATAR_ENCODE_FAILED');
                $bytes = ob_get_contents();
                if (!is_string($bytes) || $bytes === '') throw new RuntimeException('ACCOUNT_AVATAR_OUTPUT_INVALID');
                return $bytes;
            } finally { ob_end_clean(); }
        } finally { imagedestroy($image); }
    }

    /** 账号选择器只能来自复验 Session 中的规范 ULID。 */
    private function userId(array $actor): string
    {
        $userId = (string) ($actor['id'] ?? '');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1) {
            throw new UserPreferenceInvalid('Authenticated user ID is invalid.');
        }
        return $userId;
    }
}
