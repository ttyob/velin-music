<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use InvalidArgumentException;

/**
 * MetadataScrapeCompletionRequest 是核心交给 `metadata_scrape` 插件的最小歌曲补全请求。
 *
 * 该对象刻意只保留用户可理解的作品身份字段，或受边界校验的文件名上下文。请求有两种互斥模式：metadata
 * 模式传递已知的标题、艺人、专辑；filename 模式只传文件名及上两级
 * 目录原文。目录语义、曲号、版本、艺人分隔符和繁简转换全部由插件处理。核心只可额外传递扫描冻结的
 * 毫秒时长，供插件排除同名的 Live、精选或剪辑版；不得传递媒体路径、ISRC、关键词、代理或任何凭据。
 * `taskIdentity` 仅是核心任务与插件审计记录之间的不透明关联键，插件
 * 不得把它交给 Helper、第三方或浏览器。
 *
 * 构造阶段会完成长度、UTF-8 与列表边界校验。无效输入在发起网络或启动 Helper 前失败，且本对象没有
 * 数据库、文件或网络副作用；相同模式和规范化输入构造出的请求在一次调用内保持不可变。
 */
final readonly class MetadataScrapeCompletionRequest
{
    public const MODE_METADATA = 'metadata';
    public const MODE_FILENAME = 'filename';
    private const MAX_TITLE_CHARACTERS = 500;
    private const MAX_ARTISTS = 20;
    private const MAX_ARTIST_CHARACTERS = 300;
    private const MAX_ALBUM_CHARACTERS = 500;

    public string $mode;

    public string $title;

    /** @var list<string> */
    public array $artists;

    public ?string $albumTitle;

    /** @var null|list<string> */
    public ?array $albumArtists;

    public ?string $fileName;
    public ?string $parentDirectory;
    public ?string $grandparentDirectory;

    /** 扫描冻结的毫秒时长；未知时为 null，只参与录音版本校验。 */
    public ?int $durationMs;

    /** 核心逐曲 target 的不透明关联键；只用于复用同一插件任务记录，不能成为查询输入。 */
    public ?string $taskIdentity;

    /**
     * @param list<string> $artists 作品署名，至少一项且不含空白项。
     * @param null|list<string> $albumArtists 专辑署名；未知时必须为 null，不以空列表表达“清空”。
     * @param null|string $taskIdentity 仅用于核心 target 与插件任务记录关联的脱敏键，不参与查询。
     */
    public function __construct(
        string $title,
        array $artists,
        ?string $albumTitle = null,
        ?array $albumArtists = null,
        string $mode = self::MODE_METADATA,
        ?string $fileName = null,
        ?string $parentDirectory = null,
        ?string $grandparentDirectory = null,
        ?string $taskIdentity = null,
        ?int $durationMs = null,
    ) {
        if (!in_array($mode, [self::MODE_METADATA, self::MODE_FILENAME], true)) {
            throw new InvalidArgumentException('METADATA_SCRAPE_REQUEST_MODE_INVALID');
        }
        $this->mode = $mode;
        if ($taskIdentity !== null && preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $taskIdentity) !== 1) {
            throw new InvalidArgumentException('METADATA_SCRAPE_REQUEST_TASK_IDENTITY_INVALID');
        }
        $this->taskIdentity = $taskIdentity;
        if ($durationMs !== null && ($durationMs < 1 || $durationMs > 86_400_000)) {
            throw new InvalidArgumentException('METADATA_SCRAPE_REQUEST_DURATION_INVALID');
        }
        $this->durationMs = $durationMs;
        if ($mode === self::MODE_METADATA) {
            $this->title = self::text($title, self::MAX_TITLE_CHARACTERS, 'TITLE');
            $this->artists = self::texts($artists, self::MAX_ARTISTS, self::MAX_ARTIST_CHARACTERS, 'ARTISTS');
            $this->albumTitle = $albumTitle === null ? null : self::text($albumTitle, self::MAX_ALBUM_CHARACTERS, 'ALBUM_TITLE');
            $this->albumArtists = $albumArtists === null ? null : self::texts($albumArtists, self::MAX_ARTISTS, self::MAX_ARTIST_CHARACTERS, 'ALBUM_ARTISTS');
            $this->fileName = $this->parentDirectory = $this->grandparentDirectory = null;
            return;
        }
        $this->fileName = self::segment($fileName ?? $title, 500, 'FILE_NAME');
        $this->parentDirectory = self::optionalSegment($parentDirectory, 500, 'PARENT_DIRECTORY');
        $this->grandparentDirectory = self::optionalSegment($grandparentDirectory, 500, 'GRANDPARENT_DIRECTORY');
        // filename 模式下这些字段仅为兼容旧持久化边界，不参与插件查询。
        $this->title = $this->fileName;
        $this->artists = [];
        $this->albumTitle = null;
        $this->albumArtists = null;
    }

    /** 规范化单个显示文本，拒绝控制字符、无效 UTF-8 与越界值。 */
    private static function text(string $value, int $maximumCharacters, string $field): string
    {
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximumCharacters) {
            throw new InvalidArgumentException('METADATA_SCRAPE_REQUEST_' . $field . '_INVALID');
        }
        return $value;
    }

    /** @param list<string> $values @return list<string> */
    private static function texts(array $values, int $maximumItems, int $maximumCharacters, string $field): array
    {
        if (!array_is_list($values) || $values === [] || count($values) > $maximumItems) {
            throw new InvalidArgumentException('METADATA_SCRAPE_REQUEST_' . $field . '_INVALID');
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('METADATA_SCRAPE_REQUEST_' . $field . '_INVALID');
            }
            $text = self::text($value, $maximumCharacters, $field);
            $result[mb_strtolower($text, 'UTF-8')] ??= $text;
        }
        return array_values($result);
    }

    private static function segment(string $value, int $maximumCharacters, string $field): string
    {
        if (str_contains($value, '/') || str_contains($value, '\\')) {
            throw new InvalidArgumentException('METADATA_SCRAPE_REQUEST_' . $field . '_INVALID');
        }
        return self::text($value, $maximumCharacters, $field);
    }

    private static function optionalSegment(?string $value, int $maximumCharacters, string $field): ?string
    {
        return $value === null || trim($value) === '' ? null : self::segment($value, $maximumCharacters, $field);
    }

    /** 创建已知元数据模式；空艺人列表在边界处拒绝，避免插件收到不可检索身份。 */
    public static function fromMetadata(string $title, array $artists, ?string $albumTitle = null, ?array $albumArtists = null,
        ?string $taskIdentity = null, ?int $durationMs = null): self
    {
        return new self($title, $artists, $albumTitle, $albumArtists, self::MODE_METADATA,
            taskIdentity: $taskIdentity, durationMs: $durationMs);
    }

    /** 创建文件名模式；目录仅允许单段名称，禁止把相对路径越过插件协议边界。 */
    public static function fromFilename(string $fileName, ?string $parentDirectory, ?string $grandparentDirectory,
        ?string $taskIdentity = null, ?int $durationMs = null): self
    {
        return new self($fileName, [], null, null, self::MODE_FILENAME, $fileName, $parentDirectory, $grandparentDirectory,
            $taskIdentity, $durationMs);
    }
}
