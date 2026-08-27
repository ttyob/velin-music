<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Media\MediaMetadata;
use app\application\Search\SearchTextNormalizer;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 维护歌曲字段来源状态，并把可安全物化的有效值同步回现有目录投影。
 *
 * 本类不自行开启事务：扫描写入器和管理员命令必须把来源状态、目录投影和审计放在同一个短事务中。
 * 它不访问媒体文件、不调用网络，也不接收路径。标题、编号、日期、贡献者、外部 ID、艺术家和流派
 * 可以直接物化；专辑及专辑艺术家涉及共享实体身份，必须交给实体来源仓储处理，不能悄悄改名影响同专辑
 * 其他歌曲。后续专辑实体编辑复用同一字段审计，而不是在单曲命令中破坏共享关系。
 */
final class MetadataFieldStateRepository
{
    public function __construct(
        private readonly MetadataFieldSchema $schema = new MetadataFieldSchema(),
        private readonly SearchTextNormalizer $normalizer = new SearchTextNormalizer(),
        private readonly EntityMetadataStateRepository $entityStates = new EntityMetadataStateRepository(),
    ) {
    }

    /**
     * 扫描成功后刷新原始来源，同时保留逐曲刮削任务已经写入的来源、手工值和锁定语义。
     *
     * `$scraped` 非空只用于旧扫描调用方在同一事务首次提交候选；传入 null 表示本次扫描没有新的
     * 刮削事实，而不是删除现有 scraped 层。单目录模型的逐曲任务独立写入该层，后续重扫若把 null
     * 解释成清空会导致管理员已确认的结果无提示丢失。
     *
     * @param array<string,mixed>|null $scraped 本次扫描同时确认的稀疏候选；null 表示保留现有来源。
     * @throws JsonException JSON 编码失败时让整个目录写入回滚，不能留下半个来源快照。
     */
    public function syncFromScan(
        string $songId,
        MediaMetadata $raw,
        ?array $scraped,
        string $now,
    ): void {
        $rawFields = $this->schema->fromMediaMetadata($raw);
        $scrapedFields = $scraped === null ? [] : $this->schema->fromScrapeCandidate($scraped);
        foreach (MetadataFieldSchema::FIELDS as $field) {
            /** @var stdClass|null $existing */
            $existing = Db::table('media_metadata_field_states')
                ->where('song_id', $songId)->where('field_key', $field)->first();
            $rawValue = $this->schema->normalize($field, $rawFields[$field]);
            $scrapedSupplied = $scraped !== null;
            $scrapedPresent = array_key_exists($field, $scrapedFields);
            $scrapedValue = $scrapedPresent ? $scrapedFields[$field] : null;
            if (!$existing instanceof stdClass) {
                $rawPresent = $this->valuePresent($rawValue, $field);
                $effective = $rawPresent || !$scrapedPresent ? $rawValue : $scrapedValue;
                Db::table('media_metadata_field_states')->insert([
                    'song_id' => $songId,
                    'field_key' => $field,
                    'raw_value_json' => $this->encode($rawValue),
                    'scraped_value_json' => $scrapedPresent ? $this->encode($scrapedValue) : null,
                    'manual_value_json' => null,
                    'effective_value_json' => $this->encode($effective),
                    'effective_source' => $rawPresent || !$scrapedPresent ? 'raw' : 'scraped',
                    'is_locked' => 0,
                    'version' => 1,
                    'source_updated_at' => $now,
                    'manual_updated_at' => null,
                    'updated_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                continue;
            }

            $values = [
                'raw_value_json' => $this->encode($rawValue),
                // 自动来源变化同样推进字段版本，使已打开的管理员表单不能覆盖新的扫描事实。
                'version' => Db::raw('version + 1'),
                'source_updated_at' => $now,
                'updated_at' => $now,
            ];
            // 已锁定字段保留当前自动来源和值。未锁定字段才接受候选出现或消失带来的回退。
            if ((int) $existing->is_locked === 0) {
                if ($scrapedSupplied) {
                    $values['scraped_value_json'] = $scrapedPresent ? $this->encode($scrapedValue) : null;
                }
                if ($existing->manual_value_json !== null) {
                    $values['effective_value_json'] = (string) $existing->manual_value_json;
                    $values['effective_source'] = 'manual';
                } elseif ($this->valuePresent($rawValue, $field)) {
                    $values['effective_value_json'] = $this->encode($rawValue);
                    $values['effective_source'] = 'raw';
                } elseif (!$scrapedSupplied && $existing->scraped_value_json !== null) {
                    $values['effective_value_json'] = (string) $existing->scraped_value_json;
                    $values['effective_source'] = 'scraped';
                } else {
                    $values['effective_value_json'] = $this->encode($scrapedPresent ? $scrapedValue : $rawValue);
                    $values['effective_source'] = $scrapedPresent ? 'scraped' : 'raw';
                }
            }
            Db::table('media_metadata_field_states')->where('song_id', $songId)->where('field_key', $field)
                ->update($values);
        }
        $this->materialize($songId);
    }

    /**
     * 同步一个仅用于建立可浏览目录的文件名占位，而不把它提升为真实音频标签。
     *
     * `filename_only` 和远端 Range 失败都没有读取到音频描述标签；调用方仍需用 basename、未知艺人和
     * 单曲占位创建可播放目录，但这些值只能在没有 manual/scraped 时作为临时 effective 投影。raw 层
     * 统一保存 JSON null，使后续插件候选能够取得 scraped 优先级。已有手工值、锁定值和 scraped 值
     * 必须保留，重复同步相同占位不推进版本。方法位于调用方的目录写事务内，不访问网络或媒体文件，
     * 最后重新物化歌曲投影；任一字段写入失败由外层事务整体回滚。
     */
    public function syncFromUntrustedFallback(string $songId, MediaMetadata $fallback, string $now): void
    {
        $fallbackFields = $this->schema->fromMediaMetadata($fallback);
        $nullJson = $this->encode(null);
        foreach (MetadataFieldSchema::FIELDS as $field) {
            $fallbackValue = $this->schema->normalize($field, $fallbackFields[$field]);
            $fallbackJson = $this->encode($fallbackValue);
            /** @var stdClass|null $existing */
            $existing = Db::table('media_metadata_field_states')
                ->where('song_id', $songId)->where('field_key', $field)->first();
            if (!$existing instanceof stdClass) {
                Db::table('media_metadata_field_states')->insert([
                    'song_id' => $songId,
                    'field_key' => $field,
                    'raw_value_json' => $nullJson,
                    'scraped_value_json' => null,
                    'manual_value_json' => null,
                    'effective_value_json' => $fallbackJson,
                    'effective_source' => 'raw',
                    'is_locked' => 0,
                    'version' => 1,
                    'source_updated_at' => $now,
                    'manual_updated_at' => null,
                    'updated_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                continue;
            }

            $effectiveJson = (string) $existing->effective_value_json;
            $effectiveSource = (string) $existing->effective_source;
            if ((int) $existing->is_locked === 0) {
                if ($existing->manual_value_json !== null) {
                    $effectiveJson = (string) $existing->manual_value_json;
                    $effectiveSource = 'manual';
                } elseif ($existing->scraped_value_json !== null) {
                    $effectiveJson = (string) $existing->scraped_value_json;
                    $effectiveSource = 'scraped';
                } else {
                    $effectiveJson = $fallbackJson;
                    $effectiveSource = 'raw';
                }
            }
            if ((string) $existing->raw_value_json === $nullJson
                && (string) $existing->effective_value_json === $effectiveJson
                && (string) $existing->effective_source === $effectiveSource) {
                continue;
            }
            $changed = Db::table('media_metadata_field_states')->where('song_id', $songId)->where('field_key', $field)
                ->where('version', (int) $existing->version)->update([
                    'raw_value_json' => $nullJson,
                    'effective_value_json' => $effectiveJson,
                    'effective_source' => $effectiveSource,
                    'version' => Db::raw('version + 1'),
                    'source_updated_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                throw new MediaMetadataConflict('歌曲字段在远端占位同步期间发生变化。');
            }
        }
        $this->materialize($songId);
    }

    /**
     * 只应用一次可靠第三方候选，不刷新扫描得到的 raw 层。
     *
     * 调用方必须位于已经复核账号、音乐库授权和歌曲证据的短事务中。候选只更新明确提供的字段：
     * 手工值继续作为有效值，锁定字段连自动来源版本也保持不变，未锁定且没有手工值的字段才切换到
     * scraped。这样管理员主动同步不会把当前目录投影误当成新的原始标签，也不会清掉平台未返回的
     * 旧字段。方法最后只物化数据库目录，不读取或修改音频文件。
     *
     * @param array<string,mixed> $candidate 已通过 ScrapeMetadataCandidate 约束的 metadata map
     */
    public function applyScrapedCandidate(string $songId, array $candidate, string $now): void
    {
        $scrapedFields = $this->schema->fromScrapeCandidate($candidate);
        if ($scrapedFields === []) throw new MediaMetadataInvalid('刮削候选没有可应用字段。');
        $this->ensureSong($songId, $now);
        foreach ($scrapedFields as $field => $value) {
            /** @var stdClass|null $existing */
            $existing = Db::table('media_metadata_field_states')
                ->where('song_id', $songId)->where('field_key', $field)->first();
            if (!$existing instanceof stdClass || (int) $existing->is_locked === 1) continue;
            $values = [
                'scraped_value_json' => $this->encode($value),
                'version' => Db::raw('version + 1'),
                'source_updated_at' => $now,
                'updated_at' => $now,
            ];
            // 三方数据只能填补原始字段缺失；原始值即使质量一般，也不能被自动来源静默替换。
            if ($existing->manual_value_json === null
                && !$this->valuePresent($this->decode((string) $existing->raw_value_json), $field)) {
                $values['effective_value_json'] = $this->encode($value);
                $values['effective_source'] = 'scraped';
            }
            Db::table('media_metadata_field_states')->where('song_id', $songId)
                ->where('field_key', $field)->where('version', (int) $existing->version)->update($values);
        }
        $this->materialize($songId);
    }

    /**
     * 为迁移前歌曲惰性建立字段状态；读取当前目录只是初始化来源，不会改变投影或版本。
     *
     * @return array<string,array<string,mixed>> 以字段键索引的解码状态。
     */
    public function ensureSong(string $songId, string $now): array
    {
        $current = $this->currentCatalogValues($songId);
        if ($current === null) throw new MediaMetadataNotFound('歌曲不存在或不可管理。');
        foreach (MetadataFieldSchema::FIELDS as $field) {
            if (Db::table('media_metadata_field_states')->where('song_id', $songId)->where('field_key', $field)->exists()) continue;
            $value = $this->schema->normalize($field, $current[$field]);
            Db::table('media_metadata_field_states')->insertOrIgnore([
                'song_id' => $songId, 'field_key' => $field,
                'raw_value_json' => $this->encode($value), 'scraped_value_json' => null,
                'manual_value_json' => null, 'effective_value_json' => $this->encode($value),
                'effective_source' => 'raw', 'is_locked' => 0, 'version' => 1,
                'source_updated_at' => $now, 'manual_updated_at' => null, 'updated_by' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return $this->states($songId);
    }

    /** @return array<string,array<string,mixed>> */
    public function states(string $songId): array
    {
        $result = [];
        /** @var list<stdClass> $rows */
        $rows = Db::table('media_metadata_field_states')->where('song_id', $songId)
            ->orderBy('field_key')->get()->all();
        foreach ($rows as $row) {
            $result[(string) $row->field_key] = [
                'field' => (string) $row->field_key,
                'raw' => $this->decode((string) $row->raw_value_json),
                'scraped' => $row->scraped_value_json === null ? null : $this->decode((string) $row->scraped_value_json),
                'manual' => $row->manual_value_json === null ? null : $this->decode((string) $row->manual_value_json),
                'effective' => $this->decode((string) $row->effective_value_json),
                'source' => (string) $row->effective_source,
                'locked' => (int) $row->is_locked === 1,
                'version' => (int) $row->version,
                'sourceUpdatedAt' => (string) $row->source_updated_at,
                'manualUpdatedAt' => $row->manual_updated_at === null ? null : (string) $row->manual_updated_at,
                'updatedAt' => (string) $row->updated_at,
            ];
        }
        return $result;
    }

    /**
     * 无副作用读取当前字段快照，供批量方案冻结版本和生成示例差异。
     *
     * 迁移前尚无状态的字段以 `version=0` 表示，并直接使用目录当前值；Worker 只有在歌曲 updated_at
     * 仍等于方案快照时才允许把 version 0 初始化为正式状态。该方法不写库，打开批量预览不会改变
     * 歌曲更新时间、来源或审计。
     *
     * @return array{fields:array<string,array<string,mixed>>,songUpdatedAt:string}
     */
    public function snapshotSong(string $songId): array
    {
        $catalog = $this->currentCatalogValues($songId);
        if ($catalog === null) throw new MediaMetadataNotFound('歌曲不存在或不可管理。');
        $persisted = $this->states($songId);
        $fields = [];
        foreach (MetadataFieldSchema::FIELDS as $field) {
            if (isset($persisted[$field])) {
                $fields[$field] = $persisted[$field];
                continue;
            }
            $value = $this->schema->normalize($field, $catalog[$field]);
            $fields[$field] = [
                'field' => $field, 'raw' => $value, 'scraped' => null, 'manual' => null,
                'effective' => $value, 'source' => 'raw', 'locked' => false, 'version' => 0,
                'sourceUpdatedAt' => $catalog['_updatedAt'], 'manualUpdatedAt' => null,
                'updatedAt' => $catalog['_updatedAt'],
            ];
        }
        return ['fields' => $fields, 'songUpdatedAt' => (string) $catalog['_updatedAt']];
    }

    /** 将字段状态写回现有目录投影；调用方必须已完成版本校验并位于事务内。 */
    public function materialize(string $songId): void
    {
        $states = $this->states($songId);
        if ($states === []) return;
        $value = static fn (string $field): mixed => $states[$field]['effective'] ?? null;
        $releaseDate = $value('releaseDate');
        $externalIds = is_array($value('externalIds')) ? $value('externalIds') : [];
        $contributors = is_array($value('contributors')) ? $value('contributors') : [];
        Db::table('media_songs')->where('id', $songId)->update([
            'title' => (string) $value('title'),
            'normalized_title' => $this->normalizer->normalize((string) $value('title')),
            'sort_title' => $value('sortTitle'),
            'track_number' => $value('trackNumber'), 'track_total' => $value('trackTotal'),
            'disc_number' => $value('discNumber'), 'disc_total' => $value('discTotal'),
            'release_date' => $releaseDate,
            'release_year' => is_string($releaseDate) && preg_match('/^(\d{4})/', $releaseDate, $match) === 1 ? (int) $match[1] : null,
            'composer' => $contributors[0] ?? null,
            'isrc' => $externalIds['isrc'] ?? null,
            'musicbrainz_track_id' => $externalIds['musicbrainzTrackId'] ?? null,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $artists = $value('artists');
        if (is_array($artists) && $artists !== []) $this->replaceArtists($songId, $artists);
        $genres = $value('genres');
        if (is_array($genres)) $this->replaceGenres($songId, $genres);
    }

    /** @return array<string,mixed>|null */
    private function currentCatalogValues(string $songId): ?array
    {
        /** @var stdClass|null $song */
        $song = Db::table('media_songs as songs')->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->where('songs.id', $songId)->first([
                'songs.title', 'songs.sort_title', 'songs.track_number', 'songs.track_total',
                'songs.disc_number', 'songs.disc_total', 'songs.release_date', 'songs.composer',
                'songs.isrc', 'songs.musicbrainz_track_id', 'albums.title as album_title',
                'albums.musicbrainz_release_id', 'albums.musicbrainz_release_group_id', 'songs.updated_at',
            ]);
        if (!$song instanceof stdClass) return null;
        $artists = Db::table('media_song_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->where('links.song_id', $songId)->orderBy('links.position')->pluck('artists.name')->map('strval')->all();
        $albumArtists = Db::table('media_album_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->join('media_songs as songs', 'songs.album_id', '=', 'links.album_id')->where('songs.id', $songId)
            ->orderBy('links.position')->pluck('artists.name')->map('strval')->all();
        $genres = Db::table('media_song_genres as links')->join('media_genres as genres', 'genres.id', '=', 'links.genre_id')
            ->where('links.song_id', $songId)->orderBy('links.position')->pluck('genres.name')->map('strval')->all();
        return [
            'title' => (string) $song->title, 'sortTitle' => $song->sort_title === null ? null : (string) $song->sort_title,
            'artists' => $artists ?: ['未知艺术家'], 'albumArtists' => $albumArtists ?: ($artists ?: ['未知艺术家']),
            'album' => (string) $song->album_title, 'trackNumber' => $this->nullableInt($song->track_number),
            'trackTotal' => $this->nullableInt($song->track_total), 'discNumber' => $this->nullableInt($song->disc_number),
            'discTotal' => $this->nullableInt($song->disc_total),
            'releaseDate' => $song->release_date === null ? null : (string) $song->release_date,
            'genres' => $genres, 'moods' => [],
            'contributors' => $song->composer === null ? [] : [(string) $song->composer],
            'label' => null, 'edition' => null, 'explicit' => null,
            'externalIds' => array_filter([
                'isrc' => $song->isrc === null ? null : (string) $song->isrc,
                'musicbrainzTrackId' => $song->musicbrainz_track_id === null ? null : (string) $song->musicbrainz_track_id,
                'musicbrainzReleaseId' => $song->musicbrainz_release_id === null ? null : (string) $song->musicbrainz_release_id,
                'musicbrainzReleaseGroupId' => $song->musicbrainz_release_group_id === null ? null : (string) $song->musicbrainz_release_group_id,
            ], static fn (?string $item): bool => $item !== null),
            '_updatedAt' => (string) $song->updated_at,
        ];
    }

    /** @param list<string> $names */
    private function replaceArtists(string $songId, array $names): void
    {
        Db::table('media_song_artists')->where('song_id', $songId)->delete();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($names as $position => $name) {
            $normalized = $this->normalizer->normalize($name);
            $artistId = Db::table('media_artists')->where('normalized_name', $normalized)->value('id');
            // 人工改名后当前规范名不再匹配文件标签；复用实体 raw 名称，避免扫描物化生成重复艺术家。
            if (!is_string($artistId)) $artistId = $this->entityStates->artistIdByRawName($name);
            if (!is_string($artistId)) {
                $artistId = (string) new Ulid();
                Db::table('media_artists')->insert([
                    'id' => $artistId, 'name' => $name, 'normalized_name' => $normalized,
                    'sort_name' => null, 'musicbrainz_artist_id' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            Db::table('media_song_artists')->insert([
                'song_id' => $songId, 'artist_id' => $artistId,
                'role' => $position === 0 ? 'primary' : 'featured', 'position' => $position,
            ]);
        }
    }

    /** @param list<string> $names */
    private function replaceGenres(string $songId, array $names): void
    {
        Db::table('media_song_genres')->where('song_id', $songId)->delete();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($names as $position => $name) {
            $normalized = $this->normalizer->normalize($name);
            $genreId = Db::table('media_genres')->where('normalized_name', $normalized)->value('id');
            if (!is_string($genreId)) {
                $genreId = (string) new Ulid();
                Db::table('media_genres')->insert([
                    'id' => $genreId, 'name' => $name, 'normalized_name' => $normalized,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            Db::table('media_song_genres')->insert(['song_id' => $songId, 'genre_id' => $genreId, 'position' => $position]);
        }
    }

    /** 使用抛异常 JSON 保证状态表永远可以完整解码。 */
    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decode(string $json): mixed
    {
        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    /** 判断字段是否确实有可展示值；已知扫描回退值不阻止三方补全。 */
    private function valuePresent(mixed $value, string $field): bool
    {
        if ($value === null || $value === '' || $value === []) return false;
        if (in_array($field, ['artists', 'albumArtists'], true) && is_array($value)) {
            return !(count($value) === 1 && in_array($value[0], ['未知艺术家', 'Unknown Artist'], true));
        }
        return !($field === 'album' && in_array($value, ['单曲', 'Unknown Album'], true));
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
