<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use InvalidArgumentException;

/**
 * RecommendationSongIdentity 是推荐插件和核心之间的平台无关歌曲身份。
 *
 * 对象只保存用户可理解的标题、完整艺人集合、可选专辑和毫秒时长。它不接受平台 ID、URL、路径、
 * Cookie、Token 或第三方原始对象，因此可以安全地用于每日推荐、相似歌曲和缺失条目详情查询。构造时
 * 完成 UTF-8、控制字符、数量与长度校验；无效身份会在调用插件或网络前失败，且不会产生数据库或文件
 * 副作用。该身份只用于匹配证据，不能直接证明本地歌曲可见或可播放。
 */
final readonly class RecommendationSongIdentity
{
    public string $title;

    /** @var list<string> */
    public array $artists;

    public ?string $album;

    public ?int $durationMs;

    /**
     * @param list<string> $artists 完整歌曲署名，至少一项，顺序按插件或文件证据保留。
     */
    public function __construct(
        string $title,
        array $artists,
        ?string $album = null,
        ?int $durationMs = null,
    ) {
        $this->title = self::text($title, 500);
        if (!array_is_list($artists) || $artists === [] || count($artists) > 20) {
            throw new InvalidArgumentException('RECOMMENDATION_SONG_IDENTITY_INVALID');
        }
        $normalized = [];
        foreach ($artists as $artist) {
            if (!is_string($artist)) throw new InvalidArgumentException('RECOMMENDATION_SONG_IDENTITY_INVALID');
            $artist = self::text($artist, 300);
            $normalized[mb_strtolower($artist, 'UTF-8')] ??= $artist;
        }
        $this->artists = array_values($normalized);
        $this->album = $album === null || trim($album) === '' ? null : self::text($album, 500);
        if ($durationMs !== null && ($durationMs < 1 || $durationMs > 86_400_000)) {
            throw new InvalidArgumentException('RECOMMENDATION_SONG_IDENTITY_INVALID');
        }
        $this->durationMs = $durationMs;
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        if (array_is_list($value) || array_diff(array_keys($value), ['title', 'artists', 'album', 'durationMs']) !== []
            || !is_string($value['title'] ?? null) || !is_array($value['artists'] ?? null)
            || (array_key_exists('album', $value) && $value['album'] !== null && !is_string($value['album']))
            || (array_key_exists('durationMs', $value) && $value['durationMs'] !== null && !is_int($value['durationMs']))) {
            throw new InvalidArgumentException('RECOMMENDATION_SONG_IDENTITY_INVALID');
        }
        return new self($value['title'], $value['artists'], $value['album'] ?? null, $value['durationMs'] ?? null);
    }

    /** @return array{title:string,artists:list<string>,album:?string,durationMs:?int} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'artists' => $this->artists,
            'album' => $this->album, 'durationMs' => $this->durationMs];
    }

    /** 校验显示文本并拒绝控制字符，避免插件协议或日志出现不可见分隔内容。 */
    private static function text(string $value, int $maximum): string
    {
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('RECOMMENDATION_SONG_IDENTITY_INVALID');
        }
        return $value;
    }
}
