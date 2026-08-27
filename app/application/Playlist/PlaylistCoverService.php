<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\infrastructure\Audit\AuditLogger;
use RuntimeException;
use stdClass;
use support\Db;
use Throwable;

/**
 * 管理歌单所有者上传的自定义封面，并复用歌单可见性与乐观锁边界。
 *
 * 上传最多 5 MiB，只接受声明和真实类型一致的 JPEG/PNG/WebP。服务端在事务外完成解码、居中正方形
 * 裁切、缩放和 WebP 重编码，丢弃原文件名、EXIF、ICC、文本块和压缩流；事务内重新校验所有者与
 * expectedVersion，并原子更新封面、歌单版本和脱敏审计。私有歌单仅所有者可读，server 歌单允许已
 * 登录且具有 play 能力的用户读取，但所有写入仍只允许所有者。失败不会留下文件或部分数据库状态。
 */
final class PlaylistCoverService
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    private const SIZE = 800;
    private const MAX_SOURCE_DIMENSION = 8192;
    private const MAX_SOURCE_PIXELS = 40_000_000;
    private const MAX_OUTPUT_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly AuditLogger $auditLogger = new AuditLogger())
    {
    }

    /**
     * 返回当前账号可读取的自定义 WebP，并支持内容摘要条件缓存。
     *
     * 不存在封面、私有歌单失权和非法 ID 使用同一 PlaylistNotFound，防止通过图片端点枚举私有对象。
     * 存储摘要或长度不一致视为服务端损坏并失败关闭，不回退到未经验证的 BLOB。
     */
    public function image(array $actor, string $playlistId): PlaylistCoverImage
    {
        $this->assertPlaylistId($playlistId);
        $userId = $this->userId($actor);
        /** @var stdClass|null $row */
        $row = Db::table('playlists as playlists')
            ->join('playlist_covers as covers', 'covers.playlist_id', '=', 'playlists.id')
            ->where('playlists.id', $playlistId)
            ->where(function ($visibility) use ($userId): void {
                $visibility->where('playlists.owner_user_id', $userId)
                    ->orWhere('playlists.visibility', 'server');
            })->first([
                'covers.webp_bytes', 'covers.content_sha256', 'covers.byte_size', 'covers.updated_at',
            ]);
        if (!$row instanceof stdClass) {
            throw new PlaylistNotFound('Playlist cover not found.');
        }
        $bytes = (string) $row->webp_bytes;
        $digest = (string) $row->content_sha256;
        if ($bytes === '' || strlen($bytes) !== (int) $row->byte_size
            || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1
            || !hash_equals($digest, hash('sha256', $bytes))) {
            throw new RuntimeException('PLAYLIST_COVER_STORAGE_CORRUPT');
        }

        return new PlaylistCoverImage(
            $bytes,
            '"playlist-cover-' . substr($digest, 0, 32) . '"',
            strtotime((string) $row->updated_at) ?: 0,
        );
    }

    /**
     * 规范化并替换所有者封面，同时递增共享歌单版本。
     *
     * @return array{version:int,updatedAt:string,coverUrl:string} 提交后的版本和带内容缓存键的同源 URL。
     * @throws PlaylistCoverInvalid 图片输入或输出违反固定边界。
     * @throws PlaylistNotFound 歌单不存在、ID 非法或调用者不是所有者。
     * @throws PlaylistConflict expectedVersion 已过期；封面、版本和审计全部回滚。
     */
    public function upload(
        array $actor,
        string $playlistId,
        string $sourceBytes,
        string $declaredMime,
        int $expectedVersion,
        string $requestId,
        bool $allowSystem = false,
    ): array {
        if ($expectedVersion < 1 || $sourceBytes === '' || strlen($sourceBytes) > self::MAX_UPLOAD_BYTES) {
            throw new PlaylistCoverInvalid('Playlist cover size or version is invalid.');
        }
        $normalizedMime = strtolower(trim(explode(';', $declaredMime, 2)[0]));
        if (!in_array($normalizedMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new PlaylistCoverInvalid('Playlist cover content type is unsupported.');
        }
        $webp = $this->normalize($sourceBytes, $normalizedMime);
        $digest = hash('sha256', $webp);
        $userId = $this->userId($actor);
        $this->assertPlaylistId($playlistId);
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            $row = $this->writableRow($playlistId, $userId, $allowSystem);
            if ((int) $row->version !== $expectedVersion) {
                throw new PlaylistConflict('播放列表已在其他页面更新，请重新加载。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $expectedVersion + 1;
            Db::table('playlist_covers')->updateOrInsert(['playlist_id' => $playlistId], [
                'webp_bytes' => $webp,
                'content_sha256' => $digest,
                'byte_size' => strlen($webp),
                'width' => self::SIZE,
                'height' => self::SIZE,
                'updated_by' => $userId,
                'updated_at' => $now,
            ]);
            $changed = Db::table('playlists')->where('id', $playlistId)->where('version', $expectedVersion)
                ->update(['version' => $nextVersion, 'updated_at' => $now]);
            if ($changed !== 1) {
                throw new PlaylistConflict('播放列表已在其他页面更新，请重新加载。');
            }
            $this->auditLogger->record(
                $userId,
                'playlist.cover.update',
                'playlist',
                $playlistId,
                'success',
                $requestId,
                ['byteSize' => strlen($webp), 'version' => $nextVersion],
            );
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return [
            'version' => $nextVersion,
            'updatedAt' => $now,
            'coverUrl' => $this->coverUrl($playlistId, $digest),
        ];
    }

    /**
     * 删除所有者自定义封面并回退客户端占位图。
     *
     * 即使当前没有封面也会复验所有者和版本，但保持幂等且不递增版本；实际删除时封面、歌单版本和
     * 审计在同一事务提交。删除只影响数据库 BLOB，不修改歌曲、音乐库封面或媒体文件。
     *
     * @return array{version:int,updatedAt:string,coverUrl:null}
     */
    public function delete(
        array $actor,
        string $playlistId,
        int $expectedVersion,
        string $requestId,
        bool $allowSystem = false,
    ): array {
        if ($expectedVersion < 1) {
            throw new PlaylistCoverInvalid('Playlist cover version is invalid.');
        }
        $userId = $this->userId($actor);
        $this->assertPlaylistId($playlistId);
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            $row = $this->writableRow($playlistId, $userId, $allowSystem);
            if ((int) $row->version !== $expectedVersion) {
                throw new PlaylistConflict('播放列表已在其他页面更新，请重新加载。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $expectedVersion;
            if (Db::table('playlist_covers')->where('playlist_id', $playlistId)->exists()) {
                Db::table('playlist_covers')->where('playlist_id', $playlistId)->delete();
                $nextVersion++;
                $changed = Db::table('playlists')->where('id', $playlistId)->where('version', $expectedVersion)
                    ->update(['version' => $nextVersion, 'updated_at' => $now]);
                if ($changed !== 1) {
                    throw new PlaylistConflict('播放列表已在其他页面更新，请重新加载。');
                }
                $this->auditLogger->record(
                    $userId,
                    'playlist.cover.delete',
                    'playlist',
                    $playlistId,
                    'success',
                    $requestId,
                    ['version' => $nextVersion],
                );
            }
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return ['version' => $nextVersion, 'updatedAt' => $now, 'coverUrl' => null];
    }

    /**
     * 返回事务内可写歌单头。
     *
     * 普通入口仍只允许当前所有者写 user 歌单；管理 Controller 在验证 `manage_system` 后才传入
     * allowSystem，此时只接受 scope=system 且不依赖历史 owner，避免管理员账号变更后系统封面失管。
     * 两条路径都在同一个 BEGIN IMMEDIATE 内复验 scope 和版本，失败不会留下 BLOB 或版本增量。
     */
    private function writableRow(string $playlistId, string $userId, bool $allowSystem): stdClass
    {
        /** @var stdClass|null $row */
        $columns = ['id', 'version'];
        if (Db::connection()->getSchemaBuilder()->hasColumn('playlists', 'scope')) $columns[] = 'scope';
        $query = Db::table('playlists')->where('id', $playlistId);
        if (!$allowSystem) $query->where('owner_user_id', $userId);
        $row = $query->first($columns);
        if (!$row instanceof stdClass) {
            throw new PlaylistNotFound('Playlist not found.');
        }
        $scope = (string) ($row->scope ?? 'user');
        if ((!$allowSystem && $scope !== 'user') || ($allowSystem && $scope !== 'system')) {
            throw new PlaylistNotFound('Playlist not found.');
        }

        return $row;
    }

    /** 解码真实 MIME、限制像素内存成本，并将中心正方形重新编码为固定 WebP。 */
    private function normalize(string $bytes, string $declaredMime): string
    {
        $facts = @getimagesizefromstring($bytes);
        if (!is_array($facts)) {
            throw new PlaylistCoverInvalid('Playlist cover cannot be decoded.');
        }
        $width = (int) ($facts[0] ?? 0);
        $height = (int) ($facts[1] ?? 0);
        $actualMime = (string) ($facts['mime'] ?? '');
        if ($actualMime !== $declaredMime || $width < 1 || $height < 1
            || $width > self::MAX_SOURCE_DIMENSION || $height > self::MAX_SOURCE_DIMENSION
            || $width * $height > self::MAX_SOURCE_PIXELS) {
            throw new PlaylistCoverInvalid('Playlist cover dimensions or actual content type is invalid.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof \GdImage) {
            throw new PlaylistCoverInvalid('Playlist cover cannot be decoded.');
        }
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        if (!$target instanceof \GdImage) {
            imagedestroy($source);
            throw new RuntimeException('PLAYLIST_COVER_CANVAS_FAILED');
        }
        try {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefill($target, 0, 0, $transparent);
            $side = min($width, $height);
            $sourceX = intdiv($width - $side, 2);
            $sourceY = intdiv($height - $side, 2);
            if (!imagecopyresampled(
                $target,
                $source,
                0,
                0,
                $sourceX,
                $sourceY,
                self::SIZE,
                self::SIZE,
                $side,
                $side,
            )) {
                throw new RuntimeException('PLAYLIST_COVER_RESAMPLE_FAILED');
            }
            ob_start();
            try {
                if (!imagewebp($target, null, 85)) {
                    throw new RuntimeException('PLAYLIST_COVER_ENCODE_FAILED');
                }
                $webp = ob_get_contents();
                if (!is_string($webp) || $webp === '' || strlen($webp) > self::MAX_OUTPUT_BYTES) {
                    throw new RuntimeException('PLAYLIST_COVER_OUTPUT_INVALID');
                }

                return $webp;
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($target);
            imagedestroy($source);
        }
    }

    /** 认证主体只能提供规范 ULID，避免空 ID 或非字符串值进入权限查询。 */
    private function userId(array $actor): string
    {
        $userId = (string) ($actor['id'] ?? '');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1) {
            throw new PlaylistCoverInvalid('Authenticated user ID is invalid.');
        }

        return $userId;
    }

    /** 歌单选择器在任何存在性查询前只接受规范 ULID。 */
    private function assertPlaylistId(string $playlistId): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $playlistId) !== 1) {
            throw new PlaylistNotFound('Playlist not found.');
        }
    }

    /** 只暴露同源不透明歌单 ID 和内容摘要前缀，不包含路径或所有者。 */
    private function coverUrl(string $playlistId, string $digest): string
    {
        return '/api/v1/playlists/' . rawurlencode($playlistId) . '/cover?v=' . substr($digest, 0, 16);
    }
}
