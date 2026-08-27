<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Artwork\AlbumArtworkIndexer;
use app\application\Artwork\ArtistArtworkIndexer;
use app\application\Lyrics\SidecarLyricsIndexer;
use app\application\Lyrics\EmbeddedLyricsExporter;
use app\application\Scan\ScanFileResultRecorder;
use app\infrastructure\Media\FfprobeMediaProbe;
use JsonException;
use stdClass;
use support\Db;

/**
 * 编排一次本地音乐库扫描中已经发现文件的元数据阶段。
 *
 * FFprobe 前后都会复验真实路径、普通文件身份、大小和修改时间，阻止发现后替换符号链接或文件内容。
 * 单文件媒体错误写入扫描明细后继续，数据库错误则向上抛出，让持久任务重试而不伪造成功。文件名查询
 * 证据只在标签缺失时由目录写入边界生成，解析规则版本进入库存签名，升级后下次扫描会重新探测并提交
 * 快照；扫描不修改音频文件，也不让文件名猜测覆盖真实标签。
 */
final class MediaMetadataIndexer
{
    public function __construct(
        private readonly MediaProbe $probe = new FfprobeMediaProbe(),
        private readonly MediaCatalogWriter $catalog = new MediaCatalogWriter(),
        private readonly SidecarLyricsIndexer $lyrics = new SidecarLyricsIndexer(),
        private readonly EmbeddedLyricsExporter $embeddedLyrics = new EmbeddedLyricsExporter(),
        private readonly AlbumArtworkIndexer $artwork = new AlbumArtworkIndexer(),
        private readonly ArtistArtworkIndexer $artistArtwork = new ArtistArtworkIndexer(),
        private readonly ScanFileResultRecorder $scanResults = new ScanFileResultRecorder(),
        private readonly AudioDuplicateEvidenceIndexer $duplicateEvidence = new AudioDuplicateEvidenceIndexer(),
    ) {
    }

    /**
     * 索引本任务发现的全部可用库存文件，并跳过签名与当前解析版本都未变化的成功项。
     *
     * checkpoint 在文件边界接收已解析、失败和更新数量，可抛出异常协作取消；当前文件尚未提交时取消
     * 不会留下目录半状态。每首文件的目录写入使用独立短事务，已经提交的前序文件不会因后序失败回滚。
     *
     * @param callable(int, int, int): void $checkpoint
     * @return array{parsedFiles: int, failedFiles: int, updatedFiles: int}
     */
    public function index(string $jobId, string $libraryId, string $resolvedRoot, callable $checkpoint): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('library_file_inventory')
            ->where('library_id', $libraryId)
            ->where('last_seen_scan_job_id', $jobId)
            ->where('status', 'available')
            ->orderBy('relative_path')
            ->get([
                'id', 'relative_path', 'resolved_path', 'device_id', 'inode', 'file_size', 'modified_at',
                'metadata_status', 'metadata_signature', 'byte_hash_status', 'byte_sha256',
                'acoustic_fingerprint_status', 'duplicate_evidence_signature',
            ])->all();
        $parsed = 0;
        $failed = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $checkpoint($parsed, $failed, $updated);
            try {
                $absolutePath = $this->verifyFile($row, $resolvedRoot);
                // 重复证据属于扫描期派生数据，必须在任何 unchanged 快速返回之前补齐；服务内部再次
                // 复验文件身份，失败不会把旧摘要错误绑定到当前库存行。
                $this->duplicateEvidence->index($row, $absolutePath);
                // 文件名查询规则版本属于目录快照语义；升级后即使音频未变化也必须重新生成安全证据。
                $signature = 'metadata-scan-v2:'
                    . (int) $row->file_size . ':' . (int) $row->modified_at;
                if ((string) $row->metadata_status === 'ready' && (string) $row->metadata_signature === $signature) {
                    // Stored raw tags allow a newly enabled exporter to run without reprobeing audio.
                    $export = $this->embeddedLyrics->export(
                        $this->rawTagsForInventory((string) $row->id),
                        $absolutePath,
                        $resolvedRoot,
                    );
                    // Sidecars have their own signature and must update even when audio is unchanged.
                    $this->lyrics->index(
                        $libraryId,
                        (string) $row->id,
                        $resolvedRoot,
                        $absolutePath,
                        (string) $row->relative_path,
                    );
                    $artwork = $this->artwork->index(
                        $jobId,
                        $libraryId,
                        (string) $row->id,
                        $resolvedRoot,
                        $absolutePath,
                        (string) $row->relative_path,
                    );
                    $this->artistArtwork->index(
                        $jobId,
                        $libraryId,
                        (string) $row->id,
                        $resolvedRoot,
                        $absolutePath,
                        (string) $row->relative_path,
                    );
                    $this->scanResults->recordSuccess(
                        $jobId,
                        (string) $row->id,
                        (string) $row->relative_path,
                        'unchanged',
                        false,
                        false,
                        $export,
                        $artwork,
                    );
                    continue;
                }
                $rawMetadata = $this->probe->probe(
                    $absolutePath,
                    pathinfo((string) $row->relative_path, PATHINFO_FILENAME),
                );
                $this->verifyFile($row, $resolvedRoot);
                // 新单目录模型的刮削值由逐曲任务直接写入字段来源层。扫描只提交真实文件标签，避免
                // 重新扫描时读取已经删除的目录对历史并把旧快照再次覆盖到当前媒体事实。
                $metadataUpdated = $this->catalog->write(
                    $libraryId,
                    (string) $row->id,
                    $jobId,
                    (string) $row->relative_path,
                    $signature,
                    $rawMetadata,
                    $rawMetadata,
                    null,
                );
                if ($metadataUpdated) {
                    ++$updated;
                }
                $export = $this->embeddedLyrics->export($rawMetadata->rawTags, $absolutePath, $resolvedRoot);
                $this->lyrics->index(
                    $libraryId,
                    (string) $row->id,
                    $resolvedRoot,
                    $absolutePath,
                    (string) $row->relative_path,
                );
                $artwork = $this->artwork->index(
                    $jobId,
                    $libraryId,
                    (string) $row->id,
                    $resolvedRoot,
                    $absolutePath,
                    (string) $row->relative_path,
                );
                $this->artistArtwork->index(
                    $jobId,
                    $libraryId,
                    (string) $row->id,
                    $resolvedRoot,
                    $absolutePath,
                    (string) $row->relative_path,
                );
                $this->scanResults->recordSuccess(
                    $jobId,
                    (string) $row->id,
                    (string) $row->relative_path,
                    'indexed',
                    true,
                    $metadataUpdated,
                    $export,
                    $artwork,
                );
                ++$parsed;
            } catch (MediaProbeFailed $failure) {
                $this->catalog->recordFailure((string) $row->id, $jobId, $failure);
                $this->scanResults->recordFailure(
                    $jobId,
                    (string) $row->id,
                    (string) $row->relative_path,
                    $failure->errorCode,
                    $failure->getMessage(),
                );
                ++$failed;
            }
        }
        $checkpoint($parsed, $failed, $updated);

        return ['parsedFiles' => $parsed, 'failedFiles' => $failed, 'updatedFiles' => $updated];
    }

    /**
     * Loads the latest bounded raw tag snapshot for an unchanged inventory row.
     *
     * Invalid historic JSON is treated as no embedded lyrics. It must not turn an otherwise valid
     * unchanged song into a scan failure; a later parser-version bump can force a clean reprobe.
     *
     * @return array<string, mixed>
     */
    private function rawTagsForInventory(string $inventoryId): array
    {
        $json = Db::table('media_tag_snapshots as snapshots')
            ->join('media_songs as songs', 'songs.id', '=', 'snapshots.song_id')
            ->where('songs.inventory_file_id', $inventoryId)
            ->value('snapshots.raw_tags_json');
        if (!is_string($json)) {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Revalidates a discovered file against its immutable library root and discovery stat tuple.
     *
     * realpath resolves every symlink. Equality with the stored canonical path and a root-boundary
     * prefix check prevent link replacement and prefix-confusion escapes. A changed size or mtime is
     * deferred to the next scan instead of indexing bytes that discovery did not observe.
     */
    private function verifyFile(stdClass $row, string $resolvedRoot): string
    {
        $resolved = realpath((string) $row->resolved_path);
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolved === false
            || $resolved !== (string) $row->resolved_path
            || !str_starts_with($resolved, $rootPrefix)
            || !is_file($resolved)
            || !is_readable($resolved)
        ) {
            throw new MediaProbeFailed('MEDIA_PATH_CHANGED', '文件路径在扫描期间发生变化。');
        }
        $stat = @stat($resolved);
        if (!is_array($stat)
            || (int) $stat['size'] !== (int) $row->file_size
            || (int) $stat['mtime'] !== (int) $row->modified_at
        ) {
            throw new MediaProbeFailed('MEDIA_FILE_CHANGED', '文件内容在扫描期间发生变化。');
        }

        return $resolved;
    }
}
