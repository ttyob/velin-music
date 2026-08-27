<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use app\application\Metadata\ScrapeAssetFilePublisher;
use app\application\Metadata\ScrapeAssetPublicationFailed;
use stdClass;
use support\Db;

/**
 * 把歌词正文限制在音乐库相邻文件或固定刮削缓存中，并提供统一的按需读取边界。
 *
 * 数据库只保存受控根类型、根内相对定位、文件摘要和解析元数据，不保存歌词正文。写入者必须先在
 * SQLite 事务外调用 `publishForSong()`，文件使用非覆盖原子发布；随后短事务只登记返回的定位事实。
 * 数据库提交失败可能留下无引用的内容寻址缓存，它属于可清理派生文件，不得为了补偿猜测性删除用户
 * 相邻文件。读取每次重新验证库、歌曲、库存、根包含关系、普通文件类型、大小和 SHA-256，再即时解析，
 * 因而数据库记录、缓存命中或已知歌词 ID 都不能绕过文件身份与媒体权限。
 */
final readonly class LyricsFileStore
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(
        private LyricsParser $parser = new LyricsParser(),
        private ScrapeAssetFilePublisher $publisher = new ScrapeAssetFilePublisher(),
    ) {}

    /**
     * 按目标音乐库策略发布一份已验证歌词，并返回可持久化但不可对外投影的文件事实。
     *
     * `purpose=scrape` 在相邻模式使用音频同基名 `.lrc`，让其他播放器可识别；人工编辑和独立导入使用
     * 带内容摘要的 Velin 文件名，避免未经替换确认覆盖已有 sidecar。WebDAV 只能进入 managed_cache。
     * 同摘要重试幂等，不同摘要目标冲突失败，不会覆盖任何现有文件。
     *
     * @return array{storageKind:string,storageLocator:string,contentSha256:string,sourceSizeBytes:int,sourceModifiedAt:int,parsed:ParsedLyrics}
     */
    public function publishForSong(string $songId, string $content, string $purpose = 'scrape'): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1
            || !in_array($purpose, ['scrape', 'manual', 'provider_import'], true)
            || $content === '' || strlen($content) > self::MAX_BYTES) {
            throw new LyricsFileUnavailable('LYRICS_FILE_INPUT_INVALID');
        }
        try {
            $parsed = $this->parser->parse($content);
        } catch (LyricsParseFailed) {
            throw new LyricsFileUnavailable('LYRICS_FILE_PARSE_FAILED');
        }
        /** @var stdClass|null $row */
        $row = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('songs.id', $songId)->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')->where('libraries.status', 'active')
            ->first([
                'songs.id as song_id', 'songs.library_id', 'files.relative_path', 'files.resolved_path',
                'files.device_id', 'files.inode', 'files.file_size', 'files.modified_at',
                'libraries.resolved_root_path', 'libraries.scrape_storage_mode', 'libraries.source_type',
            ]);
        if (!$row instanceof stdClass) throw new LyricsFileUnavailable('LYRICS_FILE_SCOPE_STALE');
        $sha256 = hash('sha256', $content);
        $mode = (string) $row->scrape_storage_mode;
        if ((string) $row->source_type !== 'local') $mode = 'managed_cache';
        if (!in_array($mode, ['managed_cache', 'adjacent'], true)) {
            throw new LyricsFileUnavailable('LYRICS_FILE_MODE_INVALID');
        }
        $audioIdentity = null;
        if ($mode === 'managed_cache') {
            $root = $this->cacheRoot();
            $relative = (string) $row->library_id . '/' . $songId . '/lyrics/' . $sha256 . '.lrc';
            $createParents = true;
        } else {
            if ((string) $row->source_type !== 'local') {
                throw new LyricsFileUnavailable('LYRICS_FILE_SCOPE_STALE');
            }
            $root = rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR);
            $audioRelative = $this->normalizeRelative((string) $row->relative_path);
            $directory = dirname($audioRelative);
            $stem = pathinfo($audioRelative, PATHINFO_FILENAME);
            $suffix = $purpose === 'scrape' ? '' : '.velin-' . substr($sha256, 0, 16);
            $relative = ($directory === '.' ? '' : $directory . '/') . $stem . $suffix . '.lrc';
            $createParents = false;
            $audioIdentity = [
                'relativePath' => $audioRelative,
                'device' => (int) $row->device_id,
                'inode' => (int) $row->inode,
                'size' => (int) $row->file_size,
                'modifiedAt' => (int) $row->modified_at,
            ];
        }
        try {
            $published = $this->publisher->publish(
                $root, $relative, $content, $sha256, $createParents, $audioIdentity,
            );
        } catch (ScrapeAssetPublicationFailed $failure) {
            throw new LyricsFileUnavailable($failure->errorCode);
        }
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $published);
        $stat = @stat($path);
        if (!is_array($stat)) throw new LyricsFileUnavailable('LYRICS_FILE_VERIFY_FAILED');
        return [
            'storageKind' => $mode,
            'storageLocator' => $published,
            'contentSha256' => $sha256,
            'sourceSizeBytes' => (int) $stat['size'],
            'sourceModifiedAt' => max(0, (int) $stat['mtime']),
            'parsed' => $parsed,
        ];
    }

    /**
     * 从数据库索引解析并读取歌词文件；返回正文只存在于当前调用栈。
     *
     * 调用者仍负责歌曲级授权。本方法重新关联歌曲、库存和活动库，拒绝失效记录、WebDAV 相邻定位、
     * 软链接、根外路径、超过 1 MiB、大小或摘要漂移。文件改变后必须由扫描/刮削重新建立索引，不能
     * 静默读取与数据库证据不一致的字节。
     */
    public function readById(string $lyricId): ParsedLyrics
    {
        return $this->parser->parse($this->bytesById($lyricId));
    }

    /** 返回经完整文件身份复验的原始歌词字节，供写回序列化前重新解析。 */
    public function bytesById(string $lyricId): string
    {
        /** @var stdClass|null $row */
        $row = Db::table('media_lyrics as lyrics')
            ->join('media_songs as songs', 'songs.id', '=', 'lyrics.song_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('lyrics.id', $lyricId)->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')->where('libraries.status', 'active')
            ->first([
                'lyrics.song_id', 'lyrics.storage_kind', 'lyrics.storage_locator', 'lyrics.content_sha256',
                'lyrics.source_size_bytes', 'lyrics.source_modified_at', 'songs.library_id',
                'libraries.resolved_root_path', 'libraries.source_type',
            ]);
        if (!$row instanceof stdClass) throw new LyricsFileUnavailable('LYRICS_FILE_NOT_FOUND');
        $locator = $this->normalizeRelative((string) $row->storage_locator);
        $kind = (string) $row->storage_kind;
        if ($kind === 'managed_cache') {
            $expectedPrefix = (string) $row->library_id . '/' . (string) $row->song_id . '/lyrics/';
            if (!str_starts_with($locator, $expectedPrefix)) {
                throw new LyricsFileUnavailable('LYRICS_FILE_PATH_INVALID');
            }
            $root = $this->cacheRoot();
        } elseif ($kind === 'adjacent' && (string) $row->source_type === 'local') {
            $root = rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR);
        } else {
            throw new LyricsFileUnavailable('LYRICS_FILE_MODE_INVALID');
        }
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $locator);
        $real = realpath($path);
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $stat = @lstat($path);
        if ($real !== $path || !str_starts_with($real ?: '', $prefix) || is_link($path)
            || !is_file($path) || !is_readable($path) || !is_array($stat)
            || (int) ($stat['size'] ?? -1) < 1 || (int) ($stat['size'] ?? 0) > self::MAX_BYTES
            || ($row->source_size_bytes !== null && (int) $row->source_size_bytes !== (int) $stat['size'])) {
            throw new LyricsFileUnavailable('LYRICS_FILE_IDENTITY_STALE');
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || !hash_equals((string) $row->content_sha256, hash('sha256', $bytes))) {
            throw new LyricsFileUnavailable('LYRICS_FILE_CONTENT_STALE');
        }
        return $bytes;
    }

    /** 返回固定缓存根；根必须真实、非链接、可写，且路径不能由请求或数据库覆盖。 */
    private function cacheRoot(): string
    {
        $configured = rtrim((string) (getenv('VELIN_SCRAPE_CACHE_PATH') ?: '/media/cache/scrape'), DIRECTORY_SEPARATOR);
        $root = realpath($configured);
        if ($root === false || $root !== $configured || is_link($configured) || !is_dir($root) || !is_writable($root)) {
            throw new LyricsFileUnavailable('LYRICS_CACHE_ROOT_UNAVAILABLE');
        }
        return $root;
    }

    /** 只接受无空段、无点段和无控制字符的根内相对定位。 */
    private function normalizeRelative(string $relative): string
    {
        $normalized = str_replace('\\', '/', $relative);
        if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, "\0")) {
            throw new LyricsFileUnavailable('LYRICS_FILE_PATH_INVALID');
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                throw new LyricsFileUnavailable('LYRICS_FILE_PATH_INVALID');
            }
        }
        return $normalized;
    }
}
