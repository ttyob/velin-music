<?php

declare(strict_types=1);

namespace app\application\Artwork;

use app\application\Metadata\EntityMediaMetadataService;
use app\application\Metadata\MediaMetadataNotFound;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 管理歌曲、艺术家或专辑的手工封面候选和选择覆盖，不修改音乐库图片或扫描派生事实。
 *
 * 所有入口先复用实体元数据服务验证 `edit_metadata` 之外的全部库 manage 范围。上传正文只在内存中
 * 解码一次，按万分比裁剪框重采样为固定上限 WebP，从而移除 EXIF、ICC、文本块和原文件名。候选字节
 * 随 SQLite 在线备份保存；选择覆盖使用版本 CAS，恢复只删除覆盖并立即回到扫描器的本地/内嵌来源。
 */
final class ArtworkAdminService
{
    public const MAX_UPLOAD_BYTES = ArtworkCandidateImageNormalizer::MAX_INPUT_BYTES;

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ArtworkCandidateImageNormalizer $normalizer = new ArtworkCandidateImageNormalizer(),
        private readonly ArtworkBlobStore $blobs = new ArtworkBlobStore(),
    ) {}

    /** 返回指定实体/库的自动来源摘要、手工候选和当前版本化选择，不返回图片字节。 */
    public function detail(array $actor, string $type, string $entityId, string $libraryId): array
    {
        $scope = $this->scope($actor, $type, $entityId, $libraryId);
        $idColumn = $type . '_id';
        /** @var list<stdClass> $rows */
        $rows = Db::table('media_manual_artwork_candidates')->where($idColumn, $entityId)
            ->where('library_id', $scope['library']['id'])->orderByDesc('created_at')->limit(20)
            ->get(['id', 'mime_type', 'byte_size', 'width', 'height', 'content_sha256', 'origin_kind',
                'provider_key', 'attribution_required', 'attribution_text', 'attribution_url', 'created_at'])->all();
        /** @var stdClass|null $selection */
        $selection = Db::table('media_artwork_selection_overrides')->where($idColumn, $entityId)
            ->where('library_id', $scope['library']['id'])->first(['candidate_id', 'version', 'updated_at']);
        $automatic = $this->automatic($type, $entityId, $scope['library']['id']);

        return [
            'type' => $type,
            'entity' => ['id' => $entityId, 'name' => $scope['name']],
            'library' => $scope['library'],
            'automatic' => $automatic instanceof stdClass ? [
                'source' => (string) $automatic->source_kind,
                'mimeType' => (string) $automatic->mime_type,
                'width' => (int) $automatic->width,
                'height' => (int) $automatic->height,
                'updatedAt' => (string) $automatic->updated_at,
                'imageUrl' => match ($type) {
                    'song' => '/api/v1/songs/' . $entityId . '/cover',
                    'album' => '/api/v1/albums/' . $entityId . '/cover',
                    default => '/api/v1/artists/' . $entityId . '/image',
                },
            ] : null,
            'selection' => [
                'mode' => $selection instanceof stdClass ? 'manual' : 'automatic',
                'candidateId' => $selection instanceof stdClass ? (string) $selection->candidate_id : null,
                'version' => $selection instanceof stdClass ? (int) $selection->version : 0,
                'updatedAt' => $selection instanceof stdClass ? (string) $selection->updated_at : null,
            ],
            'candidates' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'mimeType' => (string) $row->mime_type,
                'byteSize' => (int) $row->byte_size,
                'width' => (int) $row->width,
                'height' => (int) $row->height,
                'origin' => (string) $row->origin_kind,
                'providerKey' => $row->provider_key === null ? null : (string) $row->provider_key,
                'attribution' => [
                    'required' => (int) $row->attribution_required === 1,
                    'text' => (string) $row->attribution_text,
                    'url' => $row->attribution_url === null ? null : (string) $row->attribution_url,
                ],
                'etag' => '"manual-' . substr((string) $row->content_sha256, 0, 32) . '"',
                'imageUrl' => '/api/v1/admin/artworks/candidates/' . (string) $row->id . '/image',
                'createdAt' => (string) $row->created_at,
            ], $rows),
            'policy' => ['maxUploadBytes' => self::MAX_UPLOAD_BYTES, 'outputSize' => ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                'sourceFilesModified' => false, 'selectionUsesVersionLock' => true],
        ];
    }

    /**
     * 校验真实图片和裁剪框，生成不含源元数据的正方形 WebP 候选。
     *
     * 裁剪坐标使用 0..10000 万分比并相对源图；宽高至少为 1，且右/下边界不得越界。写库前完成全部
     * 解码和编码，避免在 SQLite 事务持锁期间进行高成本图像处理。上传不会自动改变当前选择。
     *
     * @param array{x:int,y:int,width:int,height:int} $crop
     */
    public function upload(array $actor, string $type, string $entityId, string $libraryId, string $bytes,
        string $declaredMime, array $crop, string $requestId): array
    {
        $scope = $this->scope($actor, $type, $entityId, $libraryId);
        if ($bytes === '' || strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new ArtworkAdminInvalid('图片大小无效。');
        }
        $mime = strtolower(trim(explode(';', $declaredMime, 2)[0]));
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ArtworkAdminInvalid('图片类型无效。');
        }
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!isset($crop[$key]) || !is_int($crop[$key])) throw new ArtworkAdminInvalid('裁剪框无效。');
        }
        if ($crop['x'] < 0 || $crop['y'] < 0 || $crop['width'] < 1 || $crop['height'] < 1
            || $crop['x'] + $crop['width'] > 10_000 || $crop['y'] + $crop['height'] > 10_000) {
            throw new ArtworkAdminInvalid('裁剪框越界。');
        }
        $normalized = $this->normalizer->crop($bytes, $mime, $crop);
        $id = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $crop, $entityId, $id, $normalized, $requestId, $scope, $type, $now): void {
            $digest = hash('sha256', $normalized);
            $candidateBytes = $this->blobs->put(
                $normalized,
                'image/webp',
                ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                $digest,
            );
            Db::table('media_manual_artwork_candidates')->insert([
                'id' => $id, 'song_id' => $type === 'song' ? $entityId : null,
                'album_id' => $type === 'album' ? $entityId : null,
                'artist_id' => $type === 'artist' ? $entityId : null, 'library_id' => $scope['library']['id'],
                'created_by' => (string) $actor['id'], 'mime_type' => 'image/webp',
                'image_bytes' => $candidateBytes, 'byte_size' => strlen($normalized),
                'width' => ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                'height' => ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                'content_sha256' => $digest, 'crop_x' => $crop['x'], 'crop_y' => $crop['y'],
                'crop_width' => $crop['width'], 'crop_height' => $crop['height'], 'created_at' => $now,
            ]);
            $this->audit->record((string) $actor['id'], 'artwork.candidate.upload', $type, $entityId,
                'success', $requestId, ['candidateId' => $id, 'libraryId' => $scope['library']['id'],
                    'byteSize' => strlen($normalized)]);
        });
        return $this->detail($actor, $type, $entityId, $libraryId);
    }

    /** 以当前选择版本 CAS 选中一个同实体、同库候选；重复选择也推进版本并留下审计。 */
    public function select(array $actor, string $type, string $entityId, string $libraryId, string $candidateId,
        int $expectedVersion, string $requestId): array
    {
        $scope = $this->scope($actor, $type, $entityId, $libraryId);
        $this->ulid($candidateId);
        if ($expectedVersion < 0) throw new ArtworkAdminInvalid('选择版本无效。');
        $idColumn = $type . '_id';
        $candidate = Db::table('media_manual_artwork_candidates')->where('id', $candidateId)
            ->where($idColumn, $entityId)->where('library_id', $scope['library']['id'])->exists();
        if (!$candidate) throw new ArtworkAdminConflict('候选已不存在或作用域已变化。');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $candidateId, $entityId, $expectedVersion, $idColumn,
            $requestId, $scope, $type, $now): void {
            $query = Db::table('media_artwork_selection_overrides')->where($idColumn, $entityId)
                ->where('library_id', $scope['library']['id']);
            /** @var stdClass|null $current */
            $current = $query->first(['id', 'version']);
            if (!$current instanceof stdClass) {
                if ($expectedVersion !== 0) throw new ArtworkAdminConflict('封面选择已变化。');
                Db::table('media_artwork_selection_overrides')->insert([
                    'id' => (string) new Ulid(), 'song_id' => $type === 'song' ? $entityId : null,
                    'album_id' => $type === 'album' ? $entityId : null,
                    'artist_id' => $type === 'artist' ? $entityId : null, 'library_id' => $scope['library']['id'],
                    'candidate_id' => $candidateId, 'version' => 1, 'updated_by' => (string) $actor['id'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $changed = Db::table('media_artwork_selection_overrides')->where('id', (string) $current->id)
                    ->where('version', $expectedVersion)->update(['candidate_id' => $candidateId,
                        'version' => Db::raw('version + 1'), 'updated_by' => (string) $actor['id'], 'updated_at' => $now]);
                if ($changed !== 1) throw new ArtworkAdminConflict('封面选择已变化。');
            }
            $this->audit->record((string) $actor['id'], 'artwork.selection.set', $type, $entityId,
                'success', $requestId, ['candidateId' => $candidateId, 'libraryId' => $scope['library']['id']]);
        });
        return $this->detail($actor, $type, $entityId, $libraryId);
    }

    /** 删除当前选择覆盖并恢复扫描优先级；候选保留，源图片和媒体文件始终不变。 */
    public function restore(array $actor, string $type, string $entityId, string $libraryId, int $expectedVersion,
        string $requestId): array
    {
        $scope = $this->scope($actor, $type, $entityId, $libraryId);
        if ($expectedVersion < 1) throw new ArtworkAdminInvalid('选择版本无效。');
        $idColumn = $type . '_id';
        Db::transaction(function () use ($actor, $entityId, $expectedVersion, $idColumn, $requestId, $scope, $type): void {
            $changed = Db::table('media_artwork_selection_overrides')->where($idColumn, $entityId)
                ->where('library_id', $scope['library']['id'])->where('version', $expectedVersion)->delete();
            if ($changed !== 1) throw new ArtworkAdminConflict('封面选择已变化。');
            $this->audit->record((string) $actor['id'], 'artwork.selection.restore', $type, $entityId,
                'success', $requestId, ['libraryId' => $scope['library']['id']]);
        });
        return $this->detail($actor, $type, $entityId, $libraryId);
    }

    /** 返回已重新授权候选的私有字节和摘要，不通过候选 ID 绕过实体管理范围。 */
    public function image(array $actor, string $candidateId): array
    {
        $this->ulid($candidateId);
        /** @var stdClass|null $row */
        $row = Db::table('media_manual_artwork_candidates')->where('id', $candidateId)->first();
        if (!$row instanceof stdClass) throw new ArtworkAdminNotFound('封面候选不存在。');
        $type = $this->rowType($row);
        $entityId = (string) ($row->song_id ?? $row->album_id ?? $row->artist_id);
        $this->scope($actor, $type, $entityId, (string) $row->library_id);
        try {
            $bytes = $this->blobs->bytes($row);
        } catch (\RuntimeException) {
            throw new ArtworkAdminConflict('封面候选存储校验失败。');
        }
        return ['bytes' => $bytes, 'mimeType' => (string) $row->mime_type,
            'etag' => '"manual-' . substr((string) $row->content_sha256, 0, 32) . '"'];
    }

    /** @return array{name:string,library:array{id:string,name:string}} */
    private function scope(array $actor, string $type, string $entityId, string $libraryId): array
    {
        $this->ulid($entityId);
        $this->ulid($libraryId);
        if (!in_array($type, ['song', 'artist', 'album'], true)) throw new ArtworkAdminInvalid('实体类型无效。');
        if ($type === 'song') return $this->songScope($actor, $entityId, $libraryId);
        try {
            $detail = (new EntityMediaMetadataService())->detail($actor, $type, $entityId);
        } catch (MediaMetadataNotFound) {
            throw new ArtworkAdminNotFound('实体不存在或不可管理。');
        }
        foreach ($detail['libraries'] as $library) {
            if (($library['id'] ?? null) === $libraryId) return ['name' => (string) $detail['name'], 'library' => $library];
        }
        throw new ArtworkAdminNotFound('实体不存在或不可管理。');
    }

    /**
     * 校验歌曲只属于请求库且调用者保有 manage 范围。
     *
     * 本地上传不要求歌曲已经具备可用于在线匹配的完整元数据，因此不能复用 Provider 证据服务；但
     * 歌曲、库存文件和音乐库仍必须可用。超级管理员沿用全库管理语义，普通账号必须持有该库 manage。
     * 该查询只读取目录事实，不初始化元数据状态，也不暴露文件路径。
     *
     * @return array{name:string,library:array{id:string,name:string}}
     */
    private function songScope(array $actor, string $songId, string $libraryId): array
    {
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $managed = false;
            foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
                if (is_array($library) && ($library['id'] ?? null) === $libraryId
                    && ($library['accessLevel'] ?? null) === 'manage') $managed = true;
            }
            if (!$managed) throw new ArtworkAdminNotFound('实体不存在或不可管理。');
        }
        /** @var stdClass|null $row */
        $row = Db::table('media_songs as songs')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('songs.id', $songId)->where('songs.library_id', $libraryId)
            ->where('libraries.status', 'active')->where('files.status', 'available')
            ->first(['songs.title', 'libraries.id as library_id', 'libraries.name as library_name']);
        if (!$row instanceof stdClass) throw new ArtworkAdminNotFound('实体不存在或不可管理。');
        return ['name' => (string) $row->title,
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name]];
    }

    /**
     * 返回恢复自动时实际会使用的来源摘要。
     *
     * 歌曲没有扫描派生图片表，其自动来源是所属专辑当前选择；若专辑也无手工选择，再回退本地扫描
     * 封面。这里只返回尺寸和同源 URL，不读取 BLOB。歌曲独立选择在调用本方法前另行读取，因此不会
     * 被误标成自动来源。
     */
    private function automatic(string $type, string $entityId, string $libraryId): ?stdClass
    {
        if ($type === 'artist') {
            return Db::table('media_artist_artworks')->where('artist_id', $entityId)->where('library_id', $libraryId)
                ->first(['source_kind', 'mime_type', 'width', 'height', 'updated_at']);
        }
        $albumId = $type === 'album' ? $entityId
            : Db::table('media_songs')->where('id', $entityId)->where('library_id', $libraryId)->value('album_id');
        if (!is_string($albumId)) return null;
        /** @var stdClass|null $manual */
        $manual = Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', 'candidates.id', '=', 'selections.candidate_id')
            ->where('selections.album_id', $albumId)->where('selections.library_id', $libraryId)
            ->first(['candidates.mime_type', 'candidates.width', 'candidates.height', 'selections.updated_at']);
        if ($manual instanceof stdClass) {
            $manual->source_kind = 'album_manual';
            return $manual;
        }
        return Db::table('media_album_artworks')->where('album_id', $albumId)
            ->first(['source_kind', 'mime_type', 'width', 'height', 'updated_at']);
    }

    /** 迁移约束保证三个实体列恰好一个非空；旧滚动升级行仍按 album/artist 兼容解析。 */
    private function rowType(stdClass $row): string
    {
        if (property_exists($row, 'song_id') && $row->song_id !== null) return 'song';
        return $row->album_id === null ? 'artist' : 'album';
    }

    /** 对外部实体、库和候选标识执行规范 ULID 校验，不在错误中回显输入。 */
    private function ulid(string $value): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) throw new ArtworkAdminInvalid('对象标识无效。');
    }
}
