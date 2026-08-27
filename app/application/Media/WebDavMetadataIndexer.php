<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Artwork\AlbumArtworkIndexResult;
use app\application\Library\RemoteLibraryClient;
use app\application\Library\RemoteLibraryUnavailable;
use app\application\Library\WebDavObject;
use app\application\Lyrics\EmbeddedLyricsExportResult;
use app\application\ResourcePlugin\PluginPublishedMediaMetadata;
use app\application\Scan\ScanFileResultRecorder;
use app\infrastructure\Media\RangeAwareWebDavMetadataProbe;
use stdClass;
use support\Db;

/**
 * 在扫描 Worker 中为 WebDAV 与 OneDrive 音频建立元数据目录。
 *
 * `filename_only` 只使用目录发现已经取得的路径、扩展名和对象事实，完全不启动 FFprobe 或音频 Range；
 * 核心统一发布账本若能以路径、大小和发现 ETag 证明对象仍是插件上传的同一文件，则可接管插件上传前
 * 已写入并校验的受限标签快照。该快照不是目录猜测，外部替换对象后会随身份不匹配立即失效。
 * `range_probe` 才把一次性 `127.0.0.1` 代理 URL 交给 FFprobe；扫描仍绝不完整下载源音频。Range 不可用
 * 或容器不能局部解析时，使用文件名、上级目录和扩展名建立可播放的降级目录记录。
 *
 * 扫描不再为了 SHA-256 强制读取整首音频，字节哈希与声学指纹保持 pending；远端直放也不会顺带补算
 * 完整字节哈希，网络库声学指纹仍等待专用任务。元数据签名同时包含库模式，因此模式切换会让目标
 * 安全重建；相同模式下，失败降级记录不会在定时扫描中反复探测。每首目录写入仍由
 * MediaCatalogWriter 短事务完成，网络 I/O 不在事务内。
 */
final class WebDavMetadataIndexer
{
    public function __construct(
        private readonly WebDavRemoteMetadataProbe $probe = new RangeAwareWebDavMetadataProbe(),
        private readonly MediaCatalogWriter $catalog = new MediaCatalogWriter(),
        private readonly ScanFileResultRecorder $scanResults = new ScanFileResultRecorder(),
    ) {
    }

    /**
     * @param callable(int,int,int):void $checkpoint
     * @return array{parsedFiles:int,failedFiles:int,updatedFiles:int}
     */
    public function index(
        string $jobId,
        string $libraryId,
        RemoteLibraryClient $client,
        string $remoteMetadataMode,
        callable $checkpoint,
    ): array {
        if (!in_array($remoteMetadataMode, ['filename_only', 'range_probe'], true)) {
            throw new MediaProbeFailed('REMOTE_METADATA_MODE_INVALID', '网络音乐库元数据模式无效。');
        }
        /** @var list<stdClass> $rows */
        $rows = Db::table('library_file_inventory')->where('library_id', $libraryId)
            ->where('last_seen_scan_job_id', $jobId)->where('status', 'available')
            ->orderBy('relative_path')->get([
                'id', 'relative_path', 'extension', 'file_size', 'modified_at', 'remote_etag',
                'metadata_status', 'metadata_signature',
            ])->all();
        $parsed = 0;
        $failed = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $checkpoint($parsed, $failed, $updated);
            $publication = $remoteMetadataMode === 'filename_only'
                ? $this->trustedPublication($libraryId, $row) : null;
            $signature = $this->signature($row, $remoteMetadataMode, $publication['signature'] ?? null);
            if ((string) $row->metadata_status === 'ready'
                && hash_equals((string) $row->metadata_signature, $signature)) {
                $this->recordSuccess($jobId, $row, 'unchanged', false, false);
                continue;
            }
            try {
                $rawMetadataAuthoritative = true;
                $object = new WebDavObject(
                    (string) $row->relative_path,
                    false,
                    (int) $row->file_size,
                    (int) $row->modified_at,
                    (string) $row->remote_etag,
                );
                $probeFailure = null;
                if ($remoteMetadataMode === 'filename_only') {
                    if ($publication !== null) {
                        $rawMetadata = $publication['metadata'];
                    } else {
                        $rawMetadataAuthoritative = false;
                        $rawMetadata = $this->pathMetadata(
                            (string) $row->relative_path,
                            (string) $row->extension,
                            'filename_only',
                        );
                    }
                } else {
                    try {
                        $rawMetadata = $this->probe->probe(
                            $client,
                            $object,
                            pathinfo((string) $row->relative_path, PATHINFO_FILENAME),
                        );
                    } catch (RemoteLibraryUnavailable|MediaProbeFailed $failure) {
                        $probeFailure = $failure;
                        $rawMetadataAuthoritative = false;
                        $rawMetadata = $this->pathMetadata(
                            (string) $row->relative_path,
                            (string) $row->extension,
                            'range_probe',
                            $failure->errorCode,
                        );
                    }
                }
                $metadataUpdated = $this->catalog->write(
                    $libraryId,
                    (string) $row->id,
                    $jobId,
                    (string) $row->relative_path,
                    $signature,
                    $rawMetadata,
                    $rawMetadata,
                    null,
                    $rawMetadataAuthoritative,
                );
                if ($metadataUpdated) ++$updated;
                if ($probeFailure instanceof RemoteLibraryUnavailable || $probeFailure instanceof MediaProbeFailed) {
                    // 降级元数据已经以当前对象签名提交为 ready；这里只把局部探测失败写入本次扫描报告。
                    // 不能调用 catalog->recordFailure，否则会把 ready 改回 failed 并导致每轮扫描重复请求。
                    $this->scanResults->recordFailure(
                        $jobId,
                        (string) $row->id,
                        (string) $row->relative_path,
                        $probeFailure->errorCode,
                        '远端音频仅按路径建立索引，技术元数据暂不可用。',
                    );
                    ++$failed;
                } else {
                    $this->recordSuccess($jobId, $row, 'indexed', true, $metadataUpdated);
                    ++$parsed;
                }
            } catch (MediaProbeFailed $failure) {
                $this->recordFailure($jobId, $row, $failure);
                ++$failed;
            }
        }
        $checkpoint($parsed, $failed, $updated);
        return ['parsedFiles' => $parsed, 'failedFiles' => $failed, 'updatedFiles' => $updated];
    }

    /**
     * 网络对象签名包含 ETag、读取模式与可选发布快照，使对象替换、模式切换或历史账本回填都能重建。
     *
     * publicationSignature 只由已经通过对象身份复验的账本行生成；未匹配时保持旧签名形状，避免普通
     * filename_only 文件因数据库存在无关发布记录而反复索引。
     */
    private function signature(stdClass $row, string $remoteMetadataMode, ?string $publicationSignature): string
    {
        return hash('sha256', $remoteMetadataMode . ':metadata-scan-v2:' . (int) $row->file_size . ':'
            . (int) $row->modified_at . ':' . (string) $row->remote_etag . ':' . ($publicationSignature ?? ''));
    }

    /**
     * 查找与当前库存对象严格绑定的插件发布元数据。
     *
     * 新账本必须让 discovery_etag 与目录发现 ETag 完全一致。迁移前 OneDrive/WebDAV 发布行没有该列值，
     * 但它们的 remote_version 可由路径、大小、源 SHA-256 和库存 ETag确定性重算，因此允许一次兼容恢复；
     * Google Drive 旧版本包含未持久化对象 ID，无法重算时失败关闭。任何列缺失、JSON 损坏、大小漂移、
     * 非 published 状态或多个路径事实异常都返回 null，随后只使用 basename 临时投影。本方法只读数据库，
     * 不访问远端正文，也不修改发布账本。
     *
     * @return array{metadata:MediaMetadata,signature:string}|null
     */
    private function trustedPublication(string $libraryId, stdClass $inventory): ?array
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('plugin_media_publications')
            || !$schema->hasColumn('plugin_media_publications', 'metadata_json')
            || !$schema->hasColumn('plugin_media_publications', 'discovery_etag')) return null;
        /** @var stdClass|null $row */
        $row = Db::table('plugin_media_publications')->where('library_id', $libraryId)
            ->where('relative_path', (string) $inventory->relative_path)
            ->where('status', 'published')->where('size_bytes', (int) $inventory->file_size)
            ->whereNotNull('metadata_json')->first([
                'id', 'source_type', 'relative_path', 'size_bytes', 'sha256', 'remote_version',
                'discovery_etag', 'metadata_json', 'updated_at',
            ]);
        if (!$row instanceof stdClass || !is_string($row->metadata_json)
            || !is_string($row->remote_version) || !is_string($row->sha256)) return null;
        $inventoryEtag = (string) $inventory->remote_etag;
        $identityMatches = is_string($row->discovery_etag) && $row->discovery_etag !== ''
            ? hash_equals((string) $row->discovery_etag, $inventoryEtag)
            : in_array((string) $row->source_type, ['onedrive', 'webdav'], true)
                && hash_equals((string) $row->remote_version, hash('sha256',
                    (string) $row->relative_path . "\0" . (int) $row->size_bytes . "\0"
                    . (string) $row->sha256 . "\0" . $inventoryEtag));
        if (!$identityMatches) return null;
        $metadata = PluginPublishedMediaMetadata::fromJson((string) $row->metadata_json);
        if ($metadata === null) return null;
        return [
            'metadata' => $metadata->toMediaMetadata((string) $inventory->extension),
            'signature' => hash('sha256', (string) $row->id . "\0" . (string) $row->updated_at
                . "\0" . (string) $row->metadata_json),
        ];
    }

    /**
     * 以已经校验过的远端路径事实创建最小可浏览元数据。
     *
     * relativePath 来自受根边界保护的目录发现，extension 来自扫描白名单；核心只使用文件 basename 作为
     * 临时可浏览标题，目录语义和艺人/专辑推断留给刮削插件。该兜底不具备真实标签资格，后续任务按
     * filename 模式复验。时长使用目录模型允许的未知值 0，编码保持 null，容器仅记录扩展名。原始快照
     * 保存模式以及可选稳定错误码，供诊断区分主动跳过与 Range 失败降级，不保存 URL、路径或响应正文。
     * 本方法不读取远端正文，也不修改对象。
     */
    private function pathMetadata(
        string $relativePath,
        string $extension,
        string $remoteMetadataMode,
        ?string $errorCode = null,
    ): MediaMetadata
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);
        $title = trim(pathinfo($normalizedPath, PATHINFO_FILENAME));
        $albumTitle = '单曲';
        $artists = ['未知艺术家'];
        $diagnostic = [
            'remoteMetadataMode' => $remoteMetadataMode,
            'remoteProbe' => $errorCode === null ? 'skipped' : 'degraded',
        ];
        if ($errorCode !== null) $diagnostic['errorCode'] = $errorCode;
        return new MediaMetadata(
            title: $title === '' ? '未知曲目' : $title,
            sortTitle: null,
            artists: $artists,
            albumArtists: $artists,
            albumTitle: $albumTitle === '' ? '单曲' : $albumTitle,
            albumSortTitle: null,
            hasTaggedAlbum: false,
            trackNumber: null,
            trackTotal: null,
            discNumber: null,
            discTotal: null,
            genres: [],
            releaseDate: null,
            releaseYear: null,
            composer: null,
            comment: null,
            bpm: null,
            isrc: null,
            musicbrainzTrackId: null,
            musicbrainzArtistId: null,
            musicbrainzReleaseId: null,
            musicbrainzReleaseGroupId: null,
            durationMs: 0,
            codecName: null,
            containerName: strtolower($extension),
            bitrate: null,
            bitDepth: null,
            sampleRate: null,
            channels: null,
            replaygainTrackGain: null,
            replaygainTrackPeak: null,
            replaygainAlbumGain: null,
            replaygainAlbumPeak: null,
            rawTags: ['velin' => $diagnostic],
        );
    }

    private function recordSuccess(
        string $jobId,
        stdClass $row,
        string $result,
        bool $parsed,
        bool $updated,
    ): void {
        $this->scanResults->recordSuccess(
            $jobId,
            (string) $row->id,
            (string) $row->relative_path,
            $result,
            $parsed,
            $updated,
            new EmbeddedLyricsExportResult(false, 'disabled'),
            new AlbumArtworkIndexResult('not_found'),
        );
    }

    private function recordFailure(string $jobId, stdClass $row, MediaProbeFailed $failure): void
    {
        $this->catalog->recordFailure((string) $row->id, $jobId, $failure);
        $this->scanResults->recordFailure(
            $jobId,
            (string) $row->id,
            (string) $row->relative_path,
            $failure->errorCode,
            $failure->getMessage(),
        );
    }

}
