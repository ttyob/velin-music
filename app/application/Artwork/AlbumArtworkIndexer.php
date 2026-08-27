<?php

declare(strict_types=1);

namespace app\application\Artwork;

use stdClass;
use support\Db;

/**
 * 在音乐库扫描期间为专辑选择一张经过验证的本地封面。
 *
 * 同目录 `cover`、`folder`、`front` 和其他有效图片始终优先于音频内嵌图；只有目录没有有效图片时才
 * 探测 attached picture。符号链接、越界文件、主动内容和异常图片均忽略。另一首歌提供的封面只有在
 * 本次扫描仍观察到其源音频时才能阻止当前候选，避免残留引用长期占位。数据库只保存来源身份和图片
 * 摘要，不保存图片二进制；sidecar 不会被写入，内嵌图只物化到私有运行时缓存，源音频不被修改。
 */
final class AlbumArtworkIndexer
{
    public function __construct(
        private readonly LocalArtworkCandidateFinder $finder = new LocalArtworkCandidateFinder(),
        private readonly EmbeddedArtworkSource $embedded = new EmbeddedArtworkExtractor(),
    ) {
    }

    /**
     * 检查一首歌的目录和内嵌图片，并在候选胜出时更新专辑封面引用。
     *
     * 目录候选优先级高于内嵌候选。目录视图或 attached picture 无效时返回 `invalid`，但只有当前库存
     * 文件正是已选来源且本次没有任何可用候选时才删除旧引用；其他目录/歌曲的有效选择会保留到其来源
     * 被本次扫描重新检查。外部进程前后的音频身份复验由 MediaMetadataIndexer 负责。
     */
    public function index(
        string $jobId,
        string $libraryId,
        string $inventoryId,
        string $resolvedRoot,
        string $audioPath,
        string $relativeAudioPath,
    ): AlbumArtworkIndexResult {
        /** @var stdClass|null $song */
        $song = Db::table('media_songs as songs')
            ->join('library_file_inventory as inventory', 'inventory.id', '=', 'songs.inventory_file_id')
            ->where('songs.library_id', $libraryId)
            ->where('songs.inventory_file_id', $inventoryId)->first([
                'songs.id', 'songs.album_id',
                'inventory.device_id as source_device_id', 'inventory.inode as source_inode',
                'inventory.file_size as source_file_size', 'inventory.modified_at as source_modified_at',
            ]);
        if (!$song instanceof stdClass) {
            return new AlbumArtworkIndexResult('not_found');
        }

        $directory = dirname($audioPath);
        $relativeDirectory = dirname(str_replace('\\', '/', $relativeAudioPath));
        $relativeDirectory = $relativeDirectory === '.' ? '' : trim($relativeDirectory, '/');
        $search = $this->finder->find($directory, $resolvedRoot, $relativeDirectory, [
            'cover' => 300,
            'folder' => 200,
            'front' => 100,
        ]);
        if ($search['match'] !== null) {
            $candidate = $search['match'];
            return $this->select(
                $jobId,
                (string) $song->album_id,
                $inventoryId,
                [
                    'sourceFileName' => $candidate['fileName'],
                    'sourceKind' => 'sidecar',
                    'streamIndex' => null,
                    'priority' => $candidate['priority'],
                    'selectionKey' => $candidate['selectionKey'],
                ],
                $candidate['image'],
                $candidate['image'],
            );
        }

        if ($this->currentEmbeddedSelectionIsReusable((string) $song->album_id, $jobId)) {
            return new AlbumArtworkIndexResult('unchanged');
        }

        $embedded = $this->embedded->discover($audioPath);
        if ($embedded['match'] !== null) {
            $stat = @stat($audioPath);
            if (!is_array($stat)
                || (int) $stat['dev'] !== (int) $song->source_device_id
                || (int) $stat['ino'] !== (int) $song->source_inode
                || (int) $stat['size'] !== (int) $song->source_file_size
                || (int) $stat['mtime'] !== (int) $song->source_modified_at
            ) {
                return new AlbumArtworkIndexResult('invalid');
            }
            $image = $embedded['match']['image'];
            return $this->select(
                $jobId,
                (string) $song->album_id,
                $inventoryId,
                [
                    'sourceFileName' => '@embedded',
                    'sourceKind' => 'embedded',
                    'streamIndex' => $embedded['match']['streamIndex'],
                    'priority' => -100,
                    'selectionKey' => 'embedded:' . $inventoryId . ':' . $embedded['match']['streamIndex'],
                ],
                $image,
                [
                    'mtime' => max(0, (int) $stat['mtime']),
                    'dev' => (int) $stat['dev'],
                    'ino' => (int) $stat['ino'],
                ],
            );
        }

        $removed = Db::table('media_album_artworks')->where('album_id', (string) $song->album_id)
            ->where('source_inventory_file_id', $inventoryId)->delete();

        $invalid = $search['hadCandidates'] || $embedded['hadCandidates'];

        return new AlbumArtworkIndexResult($removed > 0 ? 'not_found' : ($invalid ? 'invalid' : 'not_found'));
    }

    /**
     * 判断本次扫描已经观察到的内嵌选择是否可以无外部进程复用。
     *
     * 增量扫描会为同一专辑的每首歌调用索引器；若已选 attached picture 的源库存仍由当前 Job 观察到，
     * 且库存 dev/inode/mtime 与封面快照一致，就没有必要为后续歌曲再次启动 FFprobe/FFmpeg。图片私有
     * 缓存即使被清理，也可在授权读取时按保存的流序号和摘要重建。任何身份不一致都返回 false，让正常
     * 探测重新建立引用，而不是相信旧缓存。
     */
    private function currentEmbeddedSelectionIsReusable(string $albumId, string $jobId): bool
    {
        /** @var stdClass|null $row */
        $row = Db::table('media_album_artworks as artwork')
            ->join('library_file_inventory as source', 'source.id', '=', 'artwork.source_inventory_file_id')
            ->where('artwork.album_id', $albumId)
            ->where('artwork.source_kind', 'embedded')
            ->where('source.status', 'available')
            ->where('source.last_seen_scan_job_id', $jobId)
            ->first([
                'artwork.embedded_stream_index', 'artwork.device_id', 'artwork.inode', 'artwork.modified_at',
                'source.device_id as source_device_id', 'source.inode as source_inode',
                'source.modified_at as source_modified_at',
            ]);

        return $row instanceof stdClass
            && $row->embedded_stream_index !== null
            && (int) $row->embedded_stream_index >= 0
            && (int) $row->device_id === (int) $row->source_device_id
            && (int) $row->inode === (int) $row->source_inode
            && (int) $row->modified_at === (int) $row->source_modified_at;
    }

    /**
     * 按固定优先级和稳定键选择候选，并返回公开投影是否发生变化。
     *
     * 同目录任意有效图片的优先级都高于内嵌图；同优先级时 sidecar fallback 比较 mtime 后再比较稳定键，
     * 其他候选直接比较稳定键。只有来源在本次扫描仍然存在时才允许其胜出。写入包含来源音频/图片身份，
     * 后续读取会再次核对，更新失败由外层扫描事务重试且不会触碰源文件。
     *
     * @param array{sourceFileName: string, sourceKind: string, streamIndex: int|null, priority: int, selectionKey: string} $candidate
     * @param array{mime: string, width: int, height: int, size: int, mtime: int, dev: int, ino: int, sha256: string} $image
     * @param array{mtime: int, dev: int, ino: int} $sourceIdentity
     */
    private function select(
        string $jobId,
        string $albumId,
        string $inventoryId,
        array $candidate,
        array $image,
        array $sourceIdentity,
    ): AlbumArtworkIndexResult {
        /** @var stdClass|null $existing */
        $existing = Db::table('media_album_artworks as artwork')
            ->leftJoin('library_file_inventory as source', 'source.id', '=', 'artwork.source_inventory_file_id')
            ->where('artwork.album_id', $albumId)
            ->first([
                'artwork.*', 'source.last_seen_scan_job_id as source_last_seen_scan_job_id',
            ]);
        if ($existing instanceof stdClass && (string) $existing->source_inventory_file_id !== $inventoryId) {
            $sourceIsCurrent = (string) ($existing->source_last_seen_scan_job_id ?? '') === $jobId;
            $samePriority = (int) $existing->selection_priority === $candidate['priority'];
            $fallbackWins = $samePriority && $candidate['priority'] === 0
                && ((int) $existing->modified_at > $image['mtime']
                    || ((int) $existing->modified_at === $image['mtime']
                        && (string) $existing->selection_key <= $candidate['selectionKey']));
            $deterministicWins = $samePriority && $candidate['priority'] !== 0
                && (string) $existing->selection_key <= $candidate['selectionKey'];
            $existingWins = $sourceIsCurrent && ((int) $existing->selection_priority > $candidate['priority']
                || $fallbackWins || $deterministicWins);
            if ($existingWins) {
                return new AlbumArtworkIndexResult('unchanged');
            }
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $values = [
            'source_inventory_file_id' => $inventoryId,
            'source_file_name' => $candidate['sourceFileName'],
            'source_kind' => $candidate['sourceKind'],
            'embedded_stream_index' => $candidate['streamIndex'],
            'mime_type' => $image['mime'],
            'width' => $image['width'],
            'height' => $image['height'],
            'file_size' => $image['size'],
            'modified_at' => $sourceIdentity['mtime'],
            'device_id' => $sourceIdentity['dev'],
            'inode' => $sourceIdentity['ino'],
            'content_sha256' => $image['sha256'],
            'selection_priority' => $candidate['priority'],
            'selection_key' => $candidate['selectionKey'],
            'updated_at' => $now,
        ];
        $unchanged = $existing instanceof stdClass
            && (string) $existing->source_inventory_file_id === $inventoryId
            && (string) $existing->source_file_name === $candidate['sourceFileName']
            && (string) $existing->source_kind === $candidate['sourceKind']
            && ($existing->embedded_stream_index === null ? null : (int) $existing->embedded_stream_index) === $candidate['streamIndex']
            && (string) $existing->content_sha256 === $image['sha256']
            && (int) $existing->file_size === $image['size']
            && (int) $existing->modified_at === $sourceIdentity['mtime']
            && (int) $existing->device_id === $sourceIdentity['dev']
            && (int) $existing->inode === $sourceIdentity['ino'];
        if ($unchanged) {
            return new AlbumArtworkIndexResult('unchanged');
        }
        if ($existing instanceof stdClass) {
            Db::table('media_album_artworks')->where('album_id', $albumId)->update($values);
        } else {
            Db::table('media_album_artworks')->insert(['album_id' => $albumId, 'created_at' => $now] + $values);
        }

        return new AlbumArtworkIndexResult('indexed');
    }

}
