<?php

declare(strict_types=1);

namespace app\application\Recommendation;

use app\application\Artist\ArtistNameIdentityNormalizer;
use app\application\Media\MediaDetailNotFound;
use app\application\Media\MediaQueryService;
use app\application\ResourcePlugin\Contract\RecommendationPluginRegistry;
use app\application\ResourcePlugin\Contract\RecommendationSongIdentity;
use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use app\application\Search\SearchTextNormalizer;
use InvalidArgumentException;
use stdClass;
use support\Db;
use Throwable;

/**
 * PluginRecommendationService 聚合推荐插件结果与当前账号可见的本地媒体。
 *
 * 插件只提供平台无关歌曲/艺人身份，不能授予本地对象权限。本服务对所有插件结果重新构造受限 DTO，
 * 再以标题、艺人、可选专辑和时长匹配本地候选，最终必须经过 MediaQueryService 的活动库、可用文件、
 * 元数据成功和实时 grant 过滤。插件失败只把 `providerAvailable` 置为 false；每日、相似歌曲和相似艺人
 * 继续返回本地算法结果，推荐歌单返回空列表。所有方法只读，不写推荐缓存、播放历史或歌单。
 */
final readonly class PluginRecommendationService
{
    private const PLUGIN_KEY = 'metadata-scrape';
    private const MAX_LIMIT = 100;

    public function __construct(
        private RecommendationPluginRegistry $plugins = new PhpResourcePluginRegistry(),
        private MediaQueryService $media = new MediaQueryService(),
        private ArtistNameIdentityNormalizer $artistNames = new ArtistNameIdentityNormalizer(),
    ) {
    }

    /**
     * 返回有界每日推荐歌曲；插件命中排在本地稳定每日随机结果前，并按本地歌曲 ID 去重。
     *
     * @param array<string,mixed> $actor 已通过 `play` capability 的认证主体。
     * @return array{songs:list<array<string,mixed>>,providerAvailable:bool}
     */
    public function daily(array $actor, int $limit): array
    {
        $limit = $this->limit($limit);
        $local = $this->media->randomRecommendationSongs($actor, 1, $limit)['songs'];
        $seeds = [];
        foreach (array_slice($this->media->frequentlyPlayedSongPage($actor, 1, min(10, $limit))['songs'], 0, 10) as $item) {
            if (is_array($item['song'] ?? null)) $seeds[] = $this->identityFromSong($item['song']);
        }
        [$external, $available] = $this->pluginCall(
            fn (): array => $this->plugins->recommendation(self::PLUGIN_KEY)->dailyRecommendations($seeds, $limit),
        );
        return ['songs' => $this->mergeSongs($this->matchSongs($actor, $this->identities($external, $limit)), $local, $limit),
            'providerAvailable' => $available];
    }

    /**
     * 返回一首已授权歌曲的相似歌曲；种子详情先由核心证明可见，插件不能用输入 ID 绕过该边界。
     *
     * @return array{songs:list<array<string,mixed>>,providerAvailable:bool}
     * @throws MediaDetailNotFound 种子不存在、不可播放或当前账号无权访问。
     */
    public function similarSongs(array $actor, string $songId, int $limit): array
    {
        $limit = $this->limit($limit);
        $seedSong = $this->media->songDetail($actor, $songId)['song'];
        $seed = $this->identityFromSong($seedSong);
        [$external, $available] = $this->pluginCall(
            fn (): array => $this->plugins->recommendation(self::PLUGIN_KEY)->similarSongs($seed, $limit),
        );
        $pluginSongs = array_values(array_filter($this->matchSongs($actor, $this->identities($external, $limit)),
            static fn (array $song): bool => ($song['id'] ?? null) !== ($seedSong['id'] ?? null)));
        $local = $this->media->similarSongs($actor, (string) $seedSong['id'], $limit);
        return ['songs' => $this->mergeSongs($pluginSongs, $local, $limit), 'providerAvailable' => $available];
    }

    /**
     * 返回一个已授权艺人的相似艺人；插件名称只作为候选，最终艺人摘要仍由核心可见歌曲关系生成。
     *
     * @return array{artists:list<array<string,mixed>>,providerAvailable:bool}
     */
    public function similarArtists(array $actor, string $artistId, int $limit): array
    {
        $limit = min(50, $this->limit($limit));
        $seed = $this->media->artistDetail($actor, $artistId)['artist'];
        [$external, $available] = $this->pluginCall(
            fn (): array => $this->plugins->recommendation(self::PLUGIN_KEY)
                ->similarArtists((string) $seed['name'], $limit),
        );
        $pluginArtists = $this->matchArtists($actor, $this->artistNames($external, $limit), $artistId);
        $local = $this->media->similarArtists($actor, $artistId, $limit);
        $artists = [];
        foreach ([...$pluginArtists, ...$local] as $artist) {
            if (!is_string($artist['id'] ?? null)) continue;
            $artists[$artist['id']] ??= $artist;
            if (count($artists) >= $limit) break;
        }
        return ['artists' => array_values($artists), 'providerAvailable' => $available];
    }

    /**
     * 返回插件推荐歌单；未入库条目保留安全 identity，已匹配条目额外携带授权后的 `song`。
     *
     * @return array{playlists:list<array<string,mixed>>,providerAvailable:bool}
     */
    public function playlists(array $actor, int $limit): array
    {
        $limit = min(20, $this->limit($limit));
        [$raw, $available] = $this->pluginCall(
            fn (): array => $this->plugins->recommendation(self::PLUGIN_KEY)->recommendedPlaylists($limit),
        );
        if (!$available) return ['playlists' => [], 'providerAvailable' => false];
        $playlists = [];
        foreach (array_slice($raw, 0, $limit) as $value) {
            if (!is_array($value) || array_is_list($value)
                || array_diff(array_keys($value), ['key', 'title', 'description', 'songs']) !== []
                || !is_string($value['key'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $value['key']) !== 1
                || !$this->text($value['title'] ?? null, 500) || !is_string($value['description'] ?? null)
                || mb_strlen($value['description'], 'UTF-8') > 1000 || !is_array($value['songs'] ?? null)) {
                continue;
            }
            $identities = $this->identities($value['songs'], 100);
            $matched = $this->matchedSongMap($actor, $identities);
            $entries = [];
            foreach ($identities as $position => $identity) {
                $entry = ['identity' => $identity->toArray()];
                if (isset($matched[$position])) $entry['song'] = $matched[$position];
                $entries[] = $entry;
            }
            if ($entries === []) continue;
            $playlists[] = ['key' => $value['key'], 'title' => trim($value['title']),
                'description' => trim($value['description']), 'entries' => $entries,
                'matchedCount' => count($matched), 'totalCount' => count($entries)];
        }
        return ['playlists' => $playlists, 'providerAvailable' => true];
    }

    /**
     * 查询未入库歌曲的描述详情；返回仅包含固定通用字段，不触发下载、刮削任务或资源发布。
     *
     * @return array{detail:?array<string,mixed>,providerAvailable:bool}
     */
    public function missingSongDetail(RecommendationSongIdentity $identity): array
    {
        [$raw, $available] = $this->pluginCall(
            fn (): ?array => $this->plugins->recommendation(self::PLUGIN_KEY)->missingSongDetail($identity),
        );
        if (!$available || $raw === null) return ['detail' => null, 'providerAvailable' => $available];
        try {
            return ['detail' => $this->detail($raw), 'providerAvailable' => true];
        } catch (Throwable) {
            return ['detail' => null, 'providerAvailable' => false];
        }
    }

    /** @return array{mixed,bool} */
    private function pluginCall(callable $callback): array
    {
        try {
            return [$callback(), true];
        } catch (Throwable) {
            return [[], false];
        }
    }

    /** @param mixed $values @return list<RecommendationSongIdentity> */
    private function identities(mixed $values, int $limit): array
    {
        if (!is_array($values) || !array_is_list($values)) return [];
        $result = [];
        foreach (array_slice($values, 0, $limit) as $value) {
            try {
                $identity = $value instanceof RecommendationSongIdentity ? $value
                    : (is_array($value) ? RecommendationSongIdentity::fromArray($value)
                        : throw new InvalidArgumentException());
                $key = hash('sha256', json_encode($identity->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $result[$key] ??= $identity;
            } catch (Throwable) {
                continue;
            }
        }
        return array_values($result);
    }

    /** @param list<RecommendationSongIdentity> $identities @return list<array<string,mixed>> */
    private function matchSongs(array $actor, array $identities): array
    {
        return array_values($this->matchedSongMap($actor, $identities));
    }

    /**
     * @param list<RecommendationSongIdentity> $identities
     * @return array<int,array<string,mixed>> 按输入位置索引的唯一授权歌曲。
     */
    private function matchedSongMap(array $actor, array $identities): array
    {
        $normalizer = new SearchTextNormalizer();
        $result = [];
        foreach ($identities as $position => $identity) {
            $artists = [];
            foreach ($identity->artists as $artist) $artists = [...$artists, ...$this->artistNames->lookupKeys($artist)];
            $artists = array_values(array_unique($artists));
            /** @var list<stdClass> $rows */
            $rows = Db::table('media_songs as songs')
                ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
                ->join('media_song_artists as credits', 'credits.song_id', '=', 'songs.id')
                ->join('media_artists as artists', 'artists.id', '=', 'credits.artist_id')
                ->where('songs.normalized_title', $normalizer->normalize($identity->title))
                ->whereIn('artists.normalized_name', $artists)->distinct()->limit(20)
                ->get(['songs.id', 'songs.duration_ms', 'albums.normalized_title'])->all();
            $authorized = $this->media->songsByIds($actor,
                array_map(static fn (stdClass $row): string => (string) $row->id, $rows));
            $ranked = [];
            foreach ($rows as $row) {
                if (!isset($authorized[(string) $row->id])) continue;
                $score = 1;
                if ($identity->album !== null
                    && (string) $row->normalized_title === $normalizer->normalize($identity->album)) $score += 2;
                if ($identity->durationMs !== null && abs((int) $row->duration_ms - $identity->durationMs) <= 3_000) $score++;
                $ranked[] = ['score' => $score, 'song' => $authorized[(string) $row->id]];
            }
            usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
                ?: strcmp((string) $a['song']['id'], (string) $b['song']['id']));
            if ($ranked !== [] && (!isset($ranked[1]) || $ranked[0]['score'] > $ranked[1]['score'])) {
                $result[$position] = $ranked[0]['song'];
            }
        }
        return $result;
    }

    /** @param list<array<string,mixed>> ...$groups @return list<array<string,mixed>> */
    private function mergeSongs(array $first, array $second, int $limit): array
    {
        $songs = [];
        foreach ([...$first, ...$second] as $song) {
            if (!is_string($song['id'] ?? null)) continue;
            $songs[$song['id']] ??= $song;
            if (count($songs) >= $limit) break;
        }
        return array_values($songs);
    }

    /** @param mixed $values @return list<string> */
    private function artistNames(mixed $values, int $limit): array
    {
        if (!is_array($values) || !array_is_list($values)) return [];
        $names = [];
        foreach (array_slice($values, 0, $limit) as $value) {
            if (!is_array($value) || array_keys($value) !== ['name'] || !$this->text($value['name'] ?? null, 300)) continue;
            $names[mb_strtolower(trim($value['name']), 'UTF-8')] ??= trim($value['name']);
        }
        return array_values($names);
    }

    /** @param list<string> $names @return list<array<string,mixed>> */
    private function matchArtists(array $actor, array $names, string $seedId): array
    {
        if ($names === []) return [];
        $keys = [];
        foreach ($names as $name) $keys = [...$keys, ...$this->artistNames->lookupKeys($name)];
        $rows = Db::table('media_artists')->whereIn('normalized_name', array_values(array_unique($keys)))
            ->orderBy('name')->limit(100)->get(['id'])->all();
        $result = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            if ($id === $seedId) continue;
            try {
                $result[] = $this->media->artistDetail($actor, $id)['artist'];
            } catch (MediaDetailNotFound) {
                continue;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $song */
    private function identityFromSong(array $song): RecommendationSongIdentity
    {
        $artists = array_values(array_filter(array_map(static fn (mixed $artist): mixed =>
            is_array($artist) ? ($artist['name'] ?? null) : null, $song['artists'] ?? []), 'is_string'));
        return new RecommendationSongIdentity((string) $song['title'], $artists,
            is_string($song['album']['title'] ?? null) ? $song['album']['title'] : null,
            is_int($song['durationMs'] ?? null) ? $song['durationMs'] : null);
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function detail(array $raw): array
    {
        $allowed = ['title', 'artists', 'albumTitle', 'albumArtists', 'releaseDate', 'genres', 'composer', 'isrc',
            'durationMs', 'hasLyrics', 'hasArtwork'];
        if (array_is_list($raw) || array_diff(array_keys($raw), $allowed) !== []
            || !is_string($raw['title'] ?? null) || !is_array($raw['artists'] ?? null)) {
            throw new InvalidArgumentException('RECOMMENDATION_DETAIL_INVALID');
        }
        $identity = new RecommendationSongIdentity($raw['title'], $raw['artists'],
            is_string($raw['albumTitle'] ?? null) ? $raw['albumTitle'] : null,
            is_int($raw['durationMs'] ?? null) ? $raw['durationMs'] : null);
        $result = ['title' => $identity->title, 'artists' => $identity->artists,
            'albumTitle' => $identity->album, 'durationMs' => $identity->durationMs];
        foreach (['albumArtists' => 20, 'genres' => 16] as $field => $maximum) {
            if (!isset($raw[$field]) || !is_array($raw[$field]) || !array_is_list($raw[$field])
                || count($raw[$field]) > $maximum) continue;
            $items = [];
            foreach ($raw[$field] as $item) if ($this->text($item, 300)) $items[] = trim($item);
            if ($items !== []) $result[$field] = array_values(array_unique($items));
        }
        foreach (['composer' => 300, 'isrc' => 32] as $field => $maximum) {
            if ($this->text($raw[$field] ?? null, $maximum)) $result[$field] = trim($raw[$field]);
        }
        if (is_string($raw['releaseDate'] ?? null)
            && preg_match('/^(?:1\d{3}|2\d{3})(?:-(?:0[1-9]|1[0-2])(?:-(?:0[1-9]|[12]\d|3[01]))?)?$/D', $raw['releaseDate']) === 1) {
            $result['releaseDate'] = $raw['releaseDate'];
        }
        $result['hasLyrics'] = ($raw['hasLyrics'] ?? false) === true;
        $result['hasArtwork'] = ($raw['hasArtwork'] ?? false) === true;
        return $result;
    }

    private function limit(int $limit): int
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) throw new InvalidArgumentException('RECOMMENDATION_LIMIT_INVALID');
        return $limit;
    }

    private function text(mixed $value, int $maximum): bool
    {
        return is_string($value) && trim($value) !== '' && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= $maximum
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 1;
    }
}
