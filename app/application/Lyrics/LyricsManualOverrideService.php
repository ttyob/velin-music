<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 管理以受控歌词文件为正文来源的人工歌词覆盖，不执行音频标签写回。
 *
 * Controller 先校验 `edit_metadata`，本服务再校验歌曲所属库的实时 read/manage grant。正文先在事务外
 * 原子发布到音乐库配置的相邻目录或独立缓存，短事务仅登记受控相对定位、摘要和解析事实；正文不进入
 * 数据库、审计、错误、任务、Outbox 或日志。
 *
 * 保存使用歌词版本作为 CAS；并发旧版本完整失败，不覆盖较新正文。SQLite 写事务只包含一行歌词和
 * 一条审计记录，不在事务中访问文件或外部服务。未来 MySQL 继续使用唯一来源定位和受影响行数实现
 * 相同乐观锁，不依赖 SQLite upsert 或 JSON 函数。
 */
final class LyricsManualOverrideService
{
    private const MAX_LINES = 10_000;
    private const MAX_LINE_BYTES = 4_096;
    private const MAX_WORDS = 50_000;

    public function __construct(
        private readonly LyricsDocumentSerializer $serializer = new LyricsDocumentSerializer(),
        private readonly LyricsAdminQueryService $query = new LyricsAdminQueryService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly LyricsFileStore $files = new LyricsFileStore(),
    ) {
    }

    /**
     * 创建或按版本更新一个语言的人工覆盖。
     *
     * `expectedLyricId/expectedVersion` 必须同时为空或同时提供：为空只允许首次创建，提供时按歌词 ID 和
     * 当前正版本更新，因此语言修正也不会失去并发身份。普通、逐行和逐字歌词均按 UTF-8 与有界结构
     * 规范化；同步时间必须严格递增、不重叠且不超过已知歌曲时长。逐字词项还必须按顺序定位到行文本，
     * 防止公开增强歌词返回错误字节偏移。人工覆盖使用固定高优先级，但不删除其他来源版本。
     *
     * @param list<mixed> $lines 来自严格 JSON 请求的结构化行，正文不得由调用方记录。
     * @param array<string,mixed> $actor 已通过全局能力校验的当前身份快照。
     * @return array<string,mixed> 保存后的单曲对照投影。
     * @throws LyricsWritebackConflict 乐观版本或同语言创建状态发生变化。
     */
    public function save(
        string $songId,
        string $language,
        string $kind,
        array $lines,
        ?string $expectedLyricId,
        ?int $expectedVersion,
        array $actor,
        string $requestId,
    ): array {
        $this->requireUlid($songId, '歌曲标识无效。');
        $this->validateLanguage($language);
        if ($expectedLyricId !== null) {
            $this->requireUlid($expectedLyricId, '人工歌词标识无效。');
        }
        if (!in_array($kind, ['plain', 'line', 'word'], true)) {
            throw new LyricsWritebackInvalid('人工歌词类型无效。');
        }
        if ($expectedVersion !== null && $expectedVersion < 1) {
            throw new LyricsWritebackInvalid('人工歌词版本无效。');
        }
        if (($expectedLyricId === null) !== ($expectedVersion === null)) {
            throw new LyricsWritebackInvalid('人工歌词并发身份无效。');
        }
        $song = $this->findScopedSong($songId, $actor);
        $normalized = $this->normalizeLines($kind, $lines, (int) $song->duration_ms);
        $bytes = $this->serializer->serialize($kind, $normalized);
        try {
            $file = $this->files->publishForSong($songId, $bytes, 'manual');
        } catch (LyricsFileUnavailable $error) {
            throw new LyricsWritebackInvalid('歌词文件无法安全发布。', previous: $error);
        }
        $locator = hash('sha256', 'manual-override:' . $songId . ':' . strtolower($language));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $lyricId = '';
        $nextVersion = 1;

        try {
            Db::transaction(function () use (
                $actor,
                $expectedLyricId,
                $expectedVersion,
                $kind,
                $language,
                $file,
                $locator,
                $normalized,
                $now,
                $requestId,
                $song,
                $songId,
                &$lyricId,
                &$nextVersion,
            ): void {
                $currentSong = $this->findScopedSong($songId, $actor);
                if ((int) $currentSong->duration_ms !== (int) $song->duration_ms) {
                    throw new LyricsWritebackConflict('歌曲时长已变化，请刷新后重新校验时间轴。');
                }
                if ($expectedLyricId !== null && $expectedVersion !== null) {
                    /** @var stdClass|null $existing */
                    $existing = Db::table('media_lyrics')->where('id', $expectedLyricId)
                        ->where('song_id', $songId)->where('source_kind', 'manual')
                        ->first(['id', 'version']);
                    if (!$existing instanceof stdClass || (int) $existing->version !== $expectedVersion) {
                        throw new LyricsWritebackConflict('人工歌词版本已变化，请刷新后重试。');
                    }
                    $lyricId = (string) $existing->id;
                    $nextVersion = $expectedVersion + 1;
                    $changed = Db::table('media_lyrics')->where('id', $lyricId)
                        ->where('song_id', $songId)->where('source_kind', 'manual')
                        ->where('version', $expectedVersion)->update([
                            'language' => $language,
                            'source_locator_digest' => $locator,
                            'lyric_kind' => $kind,
                            'source_format' => $this->sourceFormat($kind),
                            'storage_kind' => $file['storageKind'],
                            'storage_locator' => $file['storageLocator'],
                            'content_sha256' => $file['contentSha256'],
                            'source_size_bytes' => $file['sourceSizeBytes'],
                            'source_modified_at' => $file['sourceModifiedAt'],
                            'version' => $nextVersion,
                            'updated_at' => $now,
                        ]);
                    if ($changed !== 1) {
                        throw new LyricsWritebackConflict('人工歌词版本已变化，请刷新后重试。');
                    }
                } else {
                    $occupied = Db::table('media_lyrics')->where('song_id', $songId)
                        ->where('source_kind', 'manual')->where('source_locator_digest', $locator)->exists();
                    if ($occupied) {
                        throw new LyricsWritebackConflict('同语言人工歌词已存在，请刷新后重试。');
                    }
                    $lyricId = (string) new Ulid();
                    Db::table('media_lyrics')->insert([
                        'id' => $lyricId,
                        'song_id' => $songId,
                        'source_kind' => 'manual',
                        'source_locator_digest' => $locator,
                        'language' => $language,
                        'lyric_kind' => $kind,
                        'source_format' => $this->sourceFormat($kind),
                        'storage_kind' => $file['storageKind'],
                        'storage_locator' => $file['storageLocator'],
                        'content_sha256' => $file['contentSha256'],
                        'priority' => 1000,
                        'match_score' => null,
                        'license_policy' => 'local_controlled',
                        'source_size_bytes' => $file['sourceSizeBytes'],
                        'source_modified_at' => $file['sourceModifiedAt'],
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                $this->audit->record(
                    (string) $actor['id'],
                    'lyrics.manual.save',
                    'media_lyric',
                    $lyricId,
                    'success',
                    $requestId,
                    [
                        'songId' => $songId,
                        'libraryId' => (string) $currentSong->library_id,
                        'language' => $language,
                        'kind' => $kind,
                        'lineCount' => count($normalized),
                        'version' => $nextVersion,
                    ],
                );
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new LyricsWritebackConflict('同语言人工歌词已存在，请刷新后重试。', previous: $exception);
            }
            throw $exception;
        }

        return $this->query->song($songId, $actor);
    }

    /**
     * 按版本清除一个人工覆盖并恢复原来源优先级链。
     *
     * 仅允许删除当前歌曲的 `manual` 行；本地、内嵌和 Provider 版本永不受影响。删除与无正文审计在同一
     * 事务提交，旧版本或撤权完整失败。该操作只删除数据库覆盖，不删除 sidecar，也不回滚此前单独确认
     * 的文件写回。
     *
     * @param array<string,mixed> $actor 当前身份快照。
     * @return array<string,mixed> 清除后的单曲对照投影。
     */
    public function clear(
        string $songId,
        string $lyricId,
        int $expectedVersion,
        array $actor,
        string $requestId,
    ): array {
        $this->requireUlid($songId, '歌曲标识无效。');
        $this->requireUlid($lyricId, '歌词标识无效。');
        if ($expectedVersion < 1) {
            throw new LyricsWritebackInvalid('人工歌词版本无效。');
        }
        $this->findScopedSong($songId, $actor);
        Db::transaction(function () use ($actor, $expectedVersion, $lyricId, $requestId, $songId): void {
            $currentSong = $this->findScopedSong($songId, $actor);
            $deleted = Db::table('media_lyrics')->where('id', $lyricId)->where('song_id', $songId)
                ->where('source_kind', 'manual')->where('version', $expectedVersion)->delete();
            if ($deleted !== 1) {
                throw new LyricsWritebackConflict('人工歌词版本已变化或已被清除，请刷新后重试。');
            }
            $this->audit->record(
                (string) $actor['id'],
                'lyrics.manual.clear',
                'media_lyric',
                $lyricId,
                'success',
                $requestId,
                [
                    'songId' => $songId,
                    'libraryId' => (string) $currentSong->library_id,
                    'version' => $expectedVersion,
                ],
            );
        });

        return $this->query->song($songId, $actor);
    }

    /**
     * 返回当前可用歌曲的最小内部事实，并应用当前请求身份的库范围。
     *
     * 调用者必须先通过全局 `edit_metadata` 校验；这里继续要求歌曲可用、元数据 ready、库 active 且有
     * read/manage grant。任何条件不满足都按同一 not found 失败，既不泄露对象存在性也没有写副作用。
     */
    private function findScopedSong(string $songId, array $actor): stdClass
    {
        $query = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('songs.id', $songId)->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')->where('libraries.status', 'active');
        $this->scope($query, $actor, 'songs.library_id');
        /** @var stdClass|null $row */
        $row = $query->first(['songs.id', 'songs.library_id', 'songs.duration_ms']);
        if (!$row instanceof stdClass) {
            throw new LyricsWritebackNotFound('歌曲不存在或不可管理。');
        }

        return $row;
    }

    /**
     * 规范化并验证编辑器结构，失败错误不回显任何正文。
     *
     * @param list<mixed> $lines
     * @return list<array<string,mixed>>
     */
    private function normalizeLines(string $kind, array $lines, int $durationMs): array
    {
        if ($lines === [] || count($lines) > self::MAX_LINES || !array_is_list($lines)) {
            throw new LyricsWritebackInvalid('人工歌词行结构无效。');
        }
        $normalized = [];
        $previousStart = -1;
        $previousEnd = -1;
        $wordCount = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new LyricsWritebackInvalid('人工歌词行结构无效。');
            }
            $keys = array_keys($line);
            sort($keys);
            $expectedKeys = $kind === 'word' ? ['endMs', 'startMs', 'text', 'words'] : ['startMs', 'text'];
            if ($keys !== $expectedKeys || !is_string($line['text'])) {
                throw new LyricsWritebackInvalid('人工歌词行结构无效。');
            }
            $text = str_replace(["\r\n", "\r", "\n"], ' ', $line['text']);
            if ($text === '' || !mb_check_encoding($text, 'UTF-8') || strlen($text) > self::MAX_LINE_BYTES) {
                throw new LyricsWritebackInvalid('人工歌词行文本无效。');
            }
            $startMs = $line['startMs'];
            if ($kind === 'plain') {
                if ($startMs !== null) {
                    throw new LyricsWritebackInvalid('普通人工歌词不能包含时间戳。');
                }
            } elseif (!is_int($startMs) || $startMs < 0 || $startMs <= $previousStart
                || ($durationMs > 0 && $startMs > $durationMs)) {
                throw new LyricsWritebackInvalid('人工歌词时间轴无效。');
            } else {
                $previousStart = $startMs;
            }
            if ($kind !== 'word') {
                $normalized[] = ['startMs' => $startMs, 'text' => $text];
                continue;
            }

            $endMs = $line['endMs'];
            $words = $line['words'];
            if (!is_int($endMs) || $endMs <= $startMs || ($durationMs > 0 && $endMs > $durationMs)
                || $startMs < $previousEnd || !is_array($words) || !array_is_list($words) || $words === []) {
                throw new LyricsWritebackInvalid('逐字歌词行时间轴无效。');
            }
            $normalizedWords = [];
            $previousWordEnd = $startMs;
            $textCursor = 0;
            foreach ($words as $word) {
                ++$wordCount;
                if ($wordCount > self::MAX_WORDS || !is_array($word)) {
                    throw new LyricsWritebackInvalid('逐字歌词词项结构无效。');
                }
                $wordKeys = array_keys($word);
                sort($wordKeys);
                if ($wordKeys !== ['endMs', 'startMs', 'text'] || !is_string($word['text'])) {
                    throw new LyricsWritebackInvalid('逐字歌词词项结构无效。');
                }
                $wordText = str_replace(["\r\n", "\r", "\n"], ' ', $word['text']);
                $wordStart = $word['startMs'];
                $wordEnd = $word['endMs'];
                $position = $wordText === '' ? false : strpos($text, $wordText, $textCursor);
                if ($wordText === '' || !mb_check_encoding($wordText, 'UTF-8')
                    || strlen($wordText) > self::MAX_LINE_BYTES || !is_int($wordStart) || !is_int($wordEnd)
                    || $wordStart < $startMs || $wordStart < $previousWordEnd || $wordEnd <= $wordStart
                    || $wordEnd > $endMs || $position === false) {
                    throw new LyricsWritebackInvalid('逐字歌词词项时间轴或文本定位无效。');
                }
                $normalizedWords[] = ['startMs' => $wordStart, 'endMs' => $wordEnd, 'text' => $wordText];
                $previousWordEnd = $wordEnd;
                $textCursor = $position + strlen($wordText);
            }
            $previousEnd = $endMs;
            $normalized[] = ['startMs' => $startMs, 'endMs' => $endMs, 'text' => $text,
                'words' => $normalizedWords];
        }

        return $normalized;
    }

    /** 为数据库版本标记实际结构来源；逐字结构不冒充可直接导出的普通 LRC。 */
    private function sourceFormat(string $kind): string
    {
        return match ($kind) {
            'plain' => 'txt',
            'line' => 'lrc',
            'word' => 'enhanced-json',
            default => throw new LyricsWritebackInvalid('人工歌词类型无效。'),
        };
    }

    /**
     * 应用与管理查询、sidecar 写回一致的 read/manage 对象范围。
     *
     * actor 是 AuthorizationService 在当前请求中生成的身份快照；事务内会再次调用本范围检查，避免在
     * 写前漏掉对象状态变化。非超级管理员没有任何合法库 ID 时使用不可能值失败关闭，禁止省略 where。
     */
    private function scope(Builder $query, array $actor, string $column): void
    {
        if (($actor['isSuperAdmin'] ?? false) === true) {
            return;
        }
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && is_string($library['id'] ?? null)
                && in_array($library['accessLevel'] ?? null, ['read', 'manage'], true)) {
                $ids[] = $library['id'];
            }
        }
        $query->whereIn($column, array_values(array_unique($ids)) ?: ['']);
    }

    /**
     * 只接受可用于稳定 manual 唯一身份的 BCP 47 子集。
     *
     * `und` 表示未知语言；其余值拒绝空段、下划线和过长子标签。失败发生在序列化与事务前，错误不回显
     * 正文，未来若扩展完整 BCP 47 必须同步唯一定位规范和契约测试。
     */
    private function validateLanguage(string $language): void
    {
        if ($language !== 'und'
            && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) !== 1) {
            throw new LyricsWritebackInvalid('人工歌词语言无效。');
        }
    }

    /**
     * 在查询前拒绝畸形 ULID，避免畸形标识绕过对象等值条件。
     *
     * 该校验只验证协议形状，不证明对象存在或授权；后续范围查询统一处理不存在与失权且不产生副作用。
     */
    private function requireUlid(string $value, string $message): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new LyricsWritebackInvalid($message);
        }
    }
}
