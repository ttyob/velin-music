<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Artist\ArtistNameIdentityNormalizer;
use app\application\Metadata\MetadataFieldStateRepository;
use app\application\Metadata\EntityMetadataStateRepository;
use app\application\ResourcePlugin\Contract\PluginDomainEvent;
use app\application\ResourcePlugin\PluginEventPublisher;
use app\application\Search\SearchTextNormalizer;
use app\infrastructure\Media\FfprobeJsonParser;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 在一个短 SQLite 事务中把规范化探测结果写入稳定媒体实体。
 *
 * 物理路径不会进入目录表；库存行不变时歌曲 ULID 始终稳定。专辑身份在音乐库内按原始扫描候选生成，
 * 人工显示标题不会改变 identity_key。艺术家和专辑字段来源会在关系写入后同步并重新物化，因此扫描
 * 可以刷新 raw/scraped 事实，却不能覆盖已保存的 manual 值或锁定投影。
 */
final class MediaCatalogWriter
{
    /**
     * 注入目录写入所需的共享规范化与字段状态组件。
     *
     * 搜索键必须与查询迁移使用同一规范器；专辑稳定身份仍由本类的保守身份键和版次来源标题决定，
     * 扩大搜索召回绝不能静默改变实体 ID。构造过程不访问数据库、文件或网络。
     */
    public function __construct(
        private readonly SearchTextNormalizer $searchNormalizer = new SearchTextNormalizer(),
        private readonly MetadataFieldStateRepository $fieldStates = new MetadataFieldStateRepository(),
        private readonly EntityMetadataStateRepository $entityFieldStates = new EntityMetadataStateRepository(),
        private readonly AlbumEditionNameNormalizer $albumEditions = new AlbumEditionNameNormalizer(),
        private readonly PluginEventPublisher $pluginEvents = new PluginEventPublisher(),
        private readonly ArtistNameIdentityNormalizer $artistNames = new ArtistNameIdentityNormalizer(),
    ) {
    }

    /**
     * 在短事务中原子替换一首歌曲投影及全部标签派生关系。
     *
     * 调用前必须在事务外完成 FFprobe 和 Provider 请求；libraryId、inventoryId 与 relativePath 已由
     * 扫描 Worker 绑定到同一授权音乐库。本方法写歌曲、艺术家、专辑、流派、标签快照和库存状态，
     * 任一数据库失败全部回滚。相同 inventoryId 重试更新原歌曲 ID，不创建第二个公开对象。
     *
     * 专辑自动显示名会去掉明确版次后缀，但 identity_source_title 保留版次来源用于后续归并；该行为
     * 不回写音频文件。人工标题由实体字段状态继续优先，扫描不能覆盖。
     * 文件名查询证据在进入事务前按统一版本化规则生成，只写入无路径标签快照；它不能改变本次目录投影、
     * raw/scraped/manual 字段来源或音频文件。真实身份标签完整时不生成该证据。
     * `$rawMetadataAuthoritative=false` 仅供完全没有读取音频标签的远端占位调用：目录仍以 basename 可见，
     * 但占位不会取得 raw 标签优先级，后续可靠插件结果可接管投影。该模式不得用于成功的 FFprobe 结果。
     *
     * @param bool $rawMetadataAuthoritative rawMetadata 是否来自实际音频标签读取
     * @throws JsonException 规范化标签无法编码为 JSON
     * @return bool 更新既有歌曲时为 true，首次创建时为 false
     */
    public function write(
        string $libraryId,
        string $inventoryId,
        string $scanJobId,
        string $relativePath,
        string $signature,
        MediaMetadata $metadata,
        ?MediaMetadata $rawMetadata = null,
        ?array $scrapedMetadata = null,
        bool $rawMetadataAuthoritative = true,
    ): bool {
        // 快照只保存原始标签；文件名和目录语义由刮削插件在任务边界解析。
        $snapshotRawTags = $this->withoutLyricsBodies(($rawMetadata ?? $metadata)->rawTags);
        [$updated, $songId] = Db::transaction(function () use (
            $inventoryId,
            $libraryId,
            $metadata,
            $relativePath,
            $scanJobId,
            $signature,
            $rawMetadata,
            $scrapedMetadata,
            $snapshotRawTags,
            $rawMetadataAuthoritative,
        ): array {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            /** @var stdClass|null $existing */
            $existing = Db::table('media_songs')->where('inventory_file_id', $inventoryId)->first(['id', 'album_id']);
            $songId = $existing instanceof stdClass ? (string) $existing->id : (string) new Ulid();
            $oldAlbumId = $existing instanceof stdClass ? (string) $existing->album_id : null;

            $artistIds = [];
            $artistSources = [];
            foreach ($metadata->artists as $position => $name) {
                $artistId = $this->artistId($name, $position === 0 ? $metadata->musicbrainzArtistId : null, $now);
                $artistIds[] = $artistId;
                $artistSources[$artistId] ??= $this->artistSources(
                    $name,
                    $rawMetadata?->artists[$position] ?? $name,
                    is_array($scrapedMetadata['artists'] ?? null) ? ($scrapedMetadata['artists'][$position] ?? null) : null,
                    $position === 0 ? ($rawMetadata?->musicbrainzArtistId ?? $metadata->musicbrainzArtistId) : null,
                    $position === 0 && is_string($scrapedMetadata['musicbrainzArtistId'] ?? null)
                        ? $scrapedMetadata['musicbrainzArtistId'] : null,
                );
            }
            $albumArtistIds = [];
            foreach ($metadata->albumArtists as $position => $name) {
                $artistId = $this->artistId($name, $position === 0 ? $metadata->musicbrainzArtistId : null, $now);
                $albumArtistIds[] = $artistId;
                $artistSources[$artistId] ??= $this->artistSources(
                    $name,
                    $rawMetadata?->albumArtists[$position] ?? $name,
                    is_array($scrapedMetadata['albumArtists'] ?? null)
                        ? ($scrapedMetadata['albumArtists'][$position] ?? null) : null,
                    $position === 0 ? ($rawMetadata?->musicbrainzArtistId ?? $metadata->musicbrainzArtistId) : null,
                    $position === 0 && is_string($scrapedMetadata['musicbrainzArtistId'] ?? null)
                        ? $scrapedMetadata['musicbrainzArtistId'] : null,
                );
            }
            $albumIdentityKeys = $this->albumIdentityKeys($metadata, $relativePath);
            $albumId = $this->albumId(
                $libraryId,
                $albumIdentityKeys,
                $metadata,
                $albumArtistIds,
                $now,
            );
            $rawAlbumTitle = $this->canonicalAlbumDisplayTitle(
                $albumId,
                ($rawMetadata ?? $metadata)->albumTitle,
            );

            $songValues = [
                'library_id' => $libraryId,
                'inventory_file_id' => $inventoryId,
                'album_id' => $albumId,
                'title' => $metadata->title,
                'normalized_title' => $this->searchNormalizer->normalize($metadata->title),
                'sort_title' => $metadata->sortTitle,
                'track_number' => $metadata->trackNumber,
                'track_total' => $metadata->trackTotal,
                'disc_number' => $metadata->discNumber,
                'disc_total' => $metadata->discTotal,
                'release_date' => $metadata->releaseDate,
                'release_year' => $metadata->releaseYear,
                'composer' => $metadata->composer,
                'comment' => $metadata->comment,
                'bpm' => $metadata->bpm,
                'isrc' => $metadata->isrc,
                'musicbrainz_track_id' => $metadata->musicbrainzTrackId,
                'duration_ms' => $metadata->durationMs,
                'codec_name' => $metadata->codecName,
                'container_name' => $metadata->containerName,
                'bitrate' => $metadata->bitrate,
                'bit_depth' => $metadata->bitDepth,
                'sample_rate' => $metadata->sampleRate,
                'channels' => $metadata->channels,
                'replaygain_track_gain' => $metadata->replaygainTrackGain,
                'replaygain_track_peak' => $metadata->replaygainTrackPeak,
                'replaygain_album_gain' => $metadata->replaygainAlbumGain,
                'replaygain_album_peak' => $metadata->replaygainAlbumPeak,
                'updated_at' => $now,
            ];
            if ($existing instanceof stdClass) {
                Db::table('media_songs')->where('id', $songId)->update($songValues);
                Db::table('media_song_artists')->where('song_id', $songId)->delete();
                Db::table('media_song_genres')->where('song_id', $songId)->delete();
            } else {
                Db::table('media_songs')->insert(['id' => $songId, 'created_at' => $now] + $songValues);
            }

            foreach ($artistIds as $position => $artistId) {
                Db::table('media_song_artists')->insert([
                    'song_id' => $songId,
                    'artist_id' => $artistId,
                    'role' => $position === 0 ? 'primary' : 'featured',
                    'position' => $position,
                ]);
            }
            foreach ($albumArtistIds as $position => $artistId) {
                Db::table('media_album_artists')->insertOrIgnore([
                    'album_id' => $albumId,
                    'artist_id' => $artistId,
                    'position' => $position,
                ]);
            }
            // 实体来源必须在署名关系建好后初始化；否则新专辑无法得到可靠的 albumArtists 原始快照。
            if ($rawMetadataAuthoritative) {
                foreach ($artistSources as $artistId => $sources) {
                    $this->entityFieldStates->syncFromScan('artist', $artistId, $sources['raw'], $sources['scraped'], $now);
                }
                $this->entityFieldStates->syncFromScan(
                    'album',
                    $albumId,
                    $this->albumRawFields($rawMetadata ?? $metadata, $rawAlbumTitle),
                    $this->albumScrapedFields($scrapedMetadata, $rawAlbumTitle),
                    $now,
                );
            } else {
                // 文件名占位没有实体 raw 资格；只建立缺失状态并重放既有 effective，避免后续扫描清掉
                // 已保存的专辑/艺人 scraped 或 manual 投影。
                foreach (array_keys($artistSources) as $artistId) {
                    $this->entityFieldStates->ensure('artist', $artistId, $now);
                    $this->entityFieldStates->materialize('artist', $artistId, $now);
                }
                $this->entityFieldStates->ensure('album', $albumId, $now);
                $this->entityFieldStates->materialize('album', $albumId, $now);
            }
            foreach ($metadata->genres as $position => $genre) {
                Db::table('media_song_genres')->insert([
                    'song_id' => $songId,
                    'genre_id' => $this->genreId($genre, $now),
                    'position' => $position,
                ]);
            }

            $snapshot = [
                'scan_job_id' => $scanJobId,
                'parser_version' => FfprobeJsonParser::PARSER_VERSION,
                'raw_tags_json' => json_encode(
                    $this->withoutLyricsBodies($snapshotRawTags),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
                'probed_at' => $now,
                'updated_at' => $now,
            ];
            if (Db::table('media_tag_snapshots')->where('song_id', $songId)->exists()) {
                Db::table('media_tag_snapshots')->where('song_id', $songId)->update($snapshot);
            } else {
                Db::table('media_tag_snapshots')->insert(['song_id' => $songId] + $snapshot);
            }
            // 覆盖层保存探测原始值与刮削值的独立来源；完全未读取标签的远端降级只提交可浏览占位。
            if ($rawMetadataAuthoritative) {
                $this->fieldStates->syncFromScan($songId, $rawMetadata ?? $metadata, $scrapedMetadata, $now);
            } else {
                $this->fieldStates->syncFromUntrustedFallback($songId, $metadata, $now);
            }
            Db::table('library_file_inventory')->where('id', $inventoryId)->update([
                'metadata_status' => 'ready',
                'metadata_signature' => $signature,
                'last_metadata_scan_job_id' => $scanJobId,
                'metadata_error_code' => null,
                'metadata_error_message' => null,
                'updated_at' => $now,
            ]);

            $this->refreshAlbumStats($albumId, $now);
            if ($oldAlbumId !== null && $oldAlbumId !== $albumId) {
                $this->refreshAlbumStats($oldAlbumId, $now);
            }

            return [$existing instanceof stdClass, $songId];
        });
        // Redis 通知位于 SQLite 提交之后；失败只丢失可重建扩展通知，不能把已完成的媒体索引回滚。
        $this->pluginEvents->publish(
            PluginDomainEvent::MEDIA_INDEXED,
            'song',
            $songId,
            payload: [
                'libraryId' => $libraryId,
                'scanJobId' => $scanJobId,
                'change' => $updated ? 'updated' : 'created',
            ],
        );
        return $updated;
    }

    /**
     * 在保存 FFprobe 标签快照前移除所有已知歌词正文键。
     *
     * 完整 `rawTags` 仍可在当前扫描调用栈中交给 `EmbeddedLyricsExporter` 生成受控 `.lrc`，但数据库快照
     * 只能保留重新解析描述元数据所需的非正文标签。键比较不区分大小写，并覆盖带语言后缀的
     * `lyrics_*`；未知结构保持原样，避免清理无关标签。该纯函数无文件或数据库副作用。
     *
     * @param array<string,mixed> $rawTags
     * @return array<string,mixed>
     */
    private function withoutLyricsBodies(array $rawTags): array
    {
        foreach (['format', 'audioStream'] as $scope) {
            if (!is_array($rawTags[$scope] ?? null)) continue;
            foreach (array_keys($rawTags[$scope]) as $key) {
                if (!is_string($key)) continue;
                $normalized = strtolower($key);
                if (in_array($normalized, [
                    'lyrics', 'unsyncedlyrics', 'unsynchronizedlyrics', 'syncedlyrics', 'uslt', 'sylt',
                ], true) || str_starts_with($normalized, 'lyrics_')) {
                    unset($rawTags[$scope][$key]);
                }
            }
        }
        return $rawTags;
    }

    /** 记录脱敏的单文件失败；库存离开可用状态后，旧歌曲会从授权浏览查询中隐藏。 */
    public function recordFailure(string $inventoryId, string $scanJobId, MediaProbeFailed $failure): void
    {
        Db::table('library_file_inventory')->where('id', $inventoryId)->update([
            'metadata_status' => 'failed',
            'last_metadata_scan_job_id' => $scanJobId,
            'metadata_error_code' => $failure->errorCode,
            'metadata_error_message' => $failure->getMessage(),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * 返回或创建规范艺术家 ID。
     *
     * 先按有限兼容键查找，再按字段状态保存的 raw 名称找回已人工改名实体。第二步是防重复关键边界：
     * 扫描器不能因为管理员改了显示名，就把仍携带旧标签的歌曲拆到一个新艺术家。兼容查询与新建位于
     * 同一 SQLite 写事务，跨 Worker 写入由数据库锁和 busy timeout 串行化；精确同键竞争还会由
     * normalized_name 唯一约束使整首歌曲写入回滚。方法不执行模糊全表扫描，也不改写已有显示名。
     */
    private function artistId(string $name, ?string $musicbrainzId, string $now): string
    {
        $normalized = $this->artistNames->storageKey($name);
        $existing = Db::table('media_artists')->whereIn('normalized_name', $this->artistNames->lookupKeys($name))
            ->orderBy('created_at')->orderBy('id')->value('id');
        if (is_string($existing)) {
            return $existing;
        }
        $existing = $this->entityFieldStates->artistIdByRawName($name);
        if (is_string($existing)) return $existing;
        $id = (string) new Ulid();
        Db::table('media_artists')->insert([
            'id' => $id,
            'name' => $name,
            'normalized_name' => $normalized,
            'sort_name' => null,
            'musicbrainz_artist_id' => $musicbrainzId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** @return array{raw:array<string,mixed>,scraped:array<string,mixed>} */
    private function artistSources(
        string $effectiveName,
        string $rawName,
        mixed $scrapedName,
        ?string $rawMusicbrainzId,
        ?string $scrapedMusicbrainzId,
    ): array {
        $raw = ['name' => $rawName, 'sortName' => null, 'externalIds' => array_filter([
            'musicbrainzArtistId' => $rawMusicbrainzId,
        ], static fn (?string $value): bool => $value !== null)];
        $scraped = [];
        if (is_string($scrapedName) && trim($scrapedName) !== '') $scraped['name'] = $scrapedName;
        elseif ($effectiveName !== $rawName) $scraped['name'] = $effectiveName;
        if ($scrapedMusicbrainzId !== null) {
            $scraped['externalIds'] = ['musicbrainzArtistId' => $scrapedMusicbrainzId];
        }
        return ['raw' => $raw, 'scraped' => $scraped];
    }

    /** @return array<string,mixed> 把文件原始标签转换为专辑实体的完整 raw 字段集合。 */
    private function albumRawFields(MediaMetadata $metadata, string $canonicalTitle): array
    {
        return [
            'title' => $canonicalTitle,
            'sortTitle' => $metadata->albumSortTitle,
            'albumArtists' => $metadata->albumArtists,
            'releaseDate' => $metadata->releaseDate,
            'discTotal' => $metadata->discTotal,
            'externalIds' => array_filter([
                'musicbrainzReleaseId' => $metadata->musicbrainzReleaseId,
                'musicbrainzReleaseGroupId' => $metadata->musicbrainzReleaseGroupId,
            ], static fn (?string $value): bool => $value !== null),
        ];
    }

    /**
     * 把已确认第三方候选转换为专辑实体的稀疏 scraped 字段。
     *
     * 缺失键保持缺失而不是写 null，避免第三方没有返回某项时错误清空文件原始事实。输入已由刮削候选
     * 协议校验；这里仍只提取实体白名单字段，不保存完整远端响应。
     *
     * @return array<string,mixed>
     */
    private function albumScrapedFields(?array $candidate, string $canonicalTitle): array
    {
        if ($candidate === null) return [];
        $fields = [];
        foreach (['albumTitle' => 'title', 'albumArtists' => 'albumArtists', 'releaseDate' => 'releaseDate',
            'discTotal' => 'discTotal'] as $source => $target) {
            if (array_key_exists($source, $candidate) && $candidate[$source] !== null && $candidate[$source] !== '') {
                $fields[$target] = $candidate[$source];
            }
        }
        if (is_string($fields['title'] ?? null)
            && $this->albumEditions->equivalent($canonicalTitle, $fields['title'])) {
            $fields['title'] = $this->albumEditions->preferredDisplayTitle($canonicalTitle, $fields['title']);
        }
        $external = [];
        foreach (['musicbrainzReleaseId', 'musicbrainzReleaseGroupId'] as $key) {
            if (is_string($candidate[$key] ?? null) && trim($candidate[$key]) !== '') $external[$key] = trim($candidate[$key]);
        }
        if ($external !== []) $fields['externalIds'] = $external;
        return $fields;
    }

    /**
     * 返回或创建音乐库内的稳定专辑实体。
     *
     * release-group ID 是最强作品身份；缺失时仍保留旧的精确 identity_key，并只在同库、同主要专辑
     * 艺术家且至少一侧带明确白名单版次词时尝试基础标题复用。这样“七里香”和“七里香（珍藏版）”
     * 可以归并，而两个都没有版次标记的同名不同年份发行不会被自动合并。候选不唯一且无法由年份消歧
     * 时退回精确身份，新建比误合并安全。方法位于歌曲写事务内，不删除历史实体或迁移个人关系。
     *
     * @param non-empty-list<string> $identityKeys 首项是当前输入的精确键，其余项只覆盖艺人标点/汉字边界空格差异
     * @param list<string> $albumArtistIds 已按标签顺序解析的稳定艺术家 ID；首项用于限制主要署名
     */
    private function albumId(
        string $libraryId,
        array $identityKeys,
        MediaMetadata $metadata,
        array $albumArtistIds,
        string $now,
    ): string
    {
        $identityKey = $identityKeys[0];
        $existing = $this->editionCompatibleAlbumId($libraryId, $identityKeys, $metadata, $albumArtistIds);
        if (!is_string($existing)) {
            $exact = Db::table('media_albums')->where('library_id', $libraryId)
                ->whereIn('identity_key', $identityKeys)->orderBy('created_at')->orderBy('id')->value('id');
            $existing = is_string($exact) ? $exact : null;
        }
        if (is_string($existing)) {
            /** @var stdClass|null $current */
            $current = Db::table('media_albums')->where('id', $existing)
                ->first(['title', 'identity_source_title']);
            $currentSourceTitle = is_string($current?->identity_source_title ?? null)
                && trim((string) $current->identity_source_title) !== ''
                ? (string) $current->identity_source_title
                : (is_string($current?->title ?? null) ? (string) $current->title : $metadata->albumTitle);
            $identitySourceTitle = $this->albumEditions->preferredDisplayTitle(
                $currentSourceTitle,
                $metadata->albumTitle,
            );
            $displayTitle = $this->automaticAlbumDisplayTitle($identitySourceTitle);
            Db::table('media_albums')->where('id', $existing)->update([
                'title' => $displayTitle,
                'normalized_title' => $this->searchNormalizer->normalize($displayTitle),
                'identity_source_title' => $identitySourceTitle,
                'sort_title' => $metadata->albumSortTitle,
                'release_date' => $metadata->releaseDate,
                'release_year' => $metadata->releaseYear,
                'disc_total' => $metadata->discTotal,
                'musicbrainz_release_id' => $metadata->musicbrainzReleaseId,
                'musicbrainz_release_group_id' => $metadata->musicbrainzReleaseGroupId,
                'updated_at' => $now,
            ]);

            return $existing;
        }
        $id = (string) new Ulid();
        $displayTitle = $this->automaticAlbumDisplayTitle($metadata->albumTitle);
        Db::table('media_albums')->insert([
            'id' => $id,
            'library_id' => $libraryId,
            'identity_key' => $identityKey,
            'title' => $displayTitle,
            'normalized_title' => $this->searchNormalizer->normalize($displayTitle),
            'identity_source_title' => $metadata->albumTitle,
            'sort_title' => $metadata->albumSortTitle,
            'release_date' => $metadata->releaseDate,
            'release_year' => $metadata->releaseYear,
            'disc_total' => $metadata->discTotal,
            'musicbrainz_release_id' => $metadata->musicbrainzReleaseId,
            'musicbrainz_release_group_id' => $metadata->musicbrainzReleaseGroupId,
            'song_count' => 0,
            'duration_ms' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * 在主要专辑艺术家范围内解析版次等价实体。
     *
     * 带版次标题会优先选择已存在的基础标题，即使旧版精确 identity_key 已经指向另一个重复实体，
     * 从而让后续重扫逐首收敛到基础专辑。基础标题本身先使用精确键，只有精确实体不存在时才接纳唯一的
     * 版次候选。历史重复实体及其收藏、封面、分享不能在扫描事务中直接删除，须交给可审计合并流程。
     *
     * @param non-empty-list<string> $identityKeys
     * @param list<string> $albumArtistIds
     */
    private function editionCompatibleAlbumId(
        string $libraryId,
        array $identityKeys,
        MediaMetadata $metadata,
        array $albumArtistIds,
    ): ?string {
        $primaryArtistId = $albumArtistIds[0] ?? null;
        if (!is_string($primaryArtistId)) return null;
        /** @var list<stdClass> $candidates */
        $candidates = Db::table('media_albums as albums')
            ->join('media_album_artists as credits', function ($join): void {
                $join->on('credits.album_id', '=', 'albums.id')->where('credits.position', '=', 0);
            })
            ->leftJoin('media_album_metadata_field_states as title_state', function ($join): void {
                $join->on('title_state.album_id', '=', 'albums.id')->where('title_state.field_key', '=', 'title');
            })
            ->where('albums.library_id', $libraryId)
            ->where('credits.artist_id', $primaryArtistId)
            ->orderBy('albums.created_at')
            ->get([
                'albums.id', 'albums.identity_key', 'albums.title', 'albums.identity_source_title',
                'albums.release_year',
                'albums.musicbrainz_release_id', 'albums.musicbrainz_release_group_id',
                'title_state.raw_value_json as raw_title_json',
            ])->all();

        $releaseGroupId = $this->normalizeExternalId($metadata->musicbrainzReleaseGroupId);
        if ($releaseGroupId !== null) {
            foreach ($candidates as $candidate) {
                if ($this->normalizeExternalId($candidate->musicbrainz_release_group_id) === $releaseGroupId) {
                    return (string) $candidate->id;
                }
            }
        }
        $releaseId = $this->normalizeExternalId($metadata->musicbrainzReleaseId);
        if ($releaseId !== null) {
            foreach ($candidates as $candidate) {
                if ($this->normalizeExternalId($candidate->musicbrainz_release_id) === $releaseId) {
                    return (string) $candidate->id;
                }
            }
        }

        $incomingHasEdition = $this->albumEditions->hasEditionQualifier($metadata->albumTitle);
        $equivalent = array_values(array_filter(
            $candidates,
            fn (stdClass $candidate): bool => $this->albumEditions->equivalent(
                $metadata->albumTitle,
                $this->candidateSourceTitle($candidate),
            ),
        ));
        if ($incomingHasEdition) {
            $baseCandidates = array_values(array_filter(
                $equivalent,
                fn (stdClass $candidate): bool => !$this->albumEditions->hasEditionQualifier(
                    $this->candidateSourceTitle($candidate),
                ),
            ));
            $base = $this->unambiguousAlbumCandidate($baseCandidates, $metadata->releaseYear);
            if ($base instanceof stdClass) return (string) $base->id;
            if (count($equivalent) === 1) return (string) $equivalent[0]->id;
        }

        foreach ($candidates as $candidate) {
            foreach ($identityKeys as $identityKey) {
                if (hash_equals((string) $candidate->identity_key, $identityKey)) return (string) $candidate->id;
            }
        }
        if (!$incomingHasEdition) {
            $editionCandidates = array_values(array_filter(
                $equivalent,
                fn (stdClass $candidate): bool => $this->albumEditions->hasEditionQualifier(
                    $this->candidateSourceTitle($candidate),
                ),
            ));
            $edition = $this->unambiguousAlbumCandidate($editionCandidates, $metadata->releaseYear);
            if ($edition instanceof stdClass) return (string) $edition->id;
        }

        return null;
    }

    /**
     * 生成当前专辑精确身份键及旧艺人排版形式的有限兼容键。
     *
     * 旧 identity_key 把专辑艺人的原始空格写进哈希；因此艺人实体已复用后，`G.E.M. 邓紫棋` 与
     * `G.E.M.邓紫棋` 仍可能命中不同专辑。这里仅对每个署名使用 ArtistNameIdentityNormalizer 给出的
     * 最多三个精确形式做笛卡尔组合，标题、年份、标签来源和目录身份完全不变。超过 64 个组合时停止
     * 扩张并保留已生成键，避免恶意多艺人标签放大事务查询；首项始终是当前输入键，可安全用于新建。
     *
     * @return non-empty-list<string>
     */
    private function albumIdentityKeys(MediaMetadata $metadata, string $relativePath): array
    {
        $artistCombinations = [''];
        foreach ($metadata->albumArtists as $name) {
            $next = [];
            foreach ($artistCombinations as $prefix) {
                foreach ($this->artistNames->lookupKeys($name) as $key) {
                    $next[] = $prefix === '' ? $key : $prefix . '|' . $key;
                    if (count($next) >= 64) break 2;
                }
            }
            $artistCombinations = array_values(array_unique($next));
        }
        if ($artistCombinations === []) {
            $artistCombinations = [''];
        }

        $title = $this->normalize($metadata->albumTitle);
        $year = (string) ($metadata->releaseYear ?? 0);
        $source = $metadata->hasTaggedAlbum
            ? 'tagged'
            : 'directory:' . $this->normalize(dirname($relativePath));
        $keys = [];
        foreach ($artistCombinations as $artists) {
            $keys[] = hash('sha256', implode("\n", [$title, $artists, $year, $source]));
        }

        return array_values(array_unique($keys));
    }

    /** 候选唯一时直接使用；多个同基础标题实体只有唯一同年项可消歧，否则拒绝自动归并。 */
    private function unambiguousAlbumCandidate(array $candidates, ?int $releaseYear): ?stdClass
    {
        if (count($candidates) === 1) return $candidates[0];
        if ($releaseYear === null) return null;
        $sameYear = array_values(array_filter(
            $candidates,
            static fn (stdClass $candidate): bool => (int) $candidate->release_year === $releaseYear,
        ));

        return count($sameYear) === 1 ? $sameYear[0] : null;
    }

    /** 从实体字段状态读取未被人工改名污染的扫描标题；损坏或旧实体回退当前目录投影。 */
    private function candidateSourceTitle(stdClass $candidate): string
    {
        if (is_string($candidate->identity_source_title ?? null)
            && trim((string) $candidate->identity_source_title) !== '') {
            return (string) $candidate->identity_source_title;
        }
        if (is_string($candidate->raw_title_json ?? null)) {
            try {
                $raw = json_decode($candidate->raw_title_json, true, 8, JSON_THROW_ON_ERROR);
                if (is_string($raw) && trim($raw) !== '') return $raw;
            } catch (JsonException) {
                // 损坏状态不能放宽实体匹配；退回当前标题后仍须通过完整等价检查。
            }
        }

        return (string) $candidate->title;
    }

    /**
     * 为共享专辑字段状态选择稳定的自动来源标题。
     *
     * 多首歌曲可能分别携带基础名和版次名，字段状态却是专辑级单值。这里优先保留基础标题，避免扫描
     * 顺序改变页面展示；人工值仍由 EntityMetadataStateRepository 独立保存并拥有更高优先级。
     */
    private function canonicalAlbumDisplayTitle(string $albumId, string $incoming): string
    {
        $rawJson = Db::table('media_album_metadata_field_states')->where('album_id', $albumId)
            ->where('field_key', 'title')->value('raw_value_json');
        $existing = Db::table('media_albums')->where('id', $albumId)->value('title');
        if (is_string($rawJson)) {
            try {
                $decoded = json_decode($rawJson, true, 8, JSON_THROW_ON_ERROR);
                if (is_string($decoded) && trim($decoded) !== '') $existing = $decoded;
            } catch (JsonException) {
                // 状态损坏会由元数据维护流程报告；扫描标题选择保持保守回退。
            }
        }

        return is_string($existing)
            ? $this->automaticAlbumDisplayTitle(
                $this->albumEditions->preferredDisplayTitle($existing, $incoming),
            )
            : $this->automaticAlbumDisplayTitle($incoming);
    }

    /** 自动来源只展示基础专辑名；原始版次身份由 identity_source_title 独立保存。 */
    private function automaticAlbumDisplayTitle(string $title): string
    {
        return $this->albumEditions->hasEditionQualifier($title)
            ? $this->albumEditions->baseName($title)
            : $title;
    }

    /** 外部 ID 只做大小写和空白统一；空值不得参与强身份匹配。 */
    private function normalizeExternalId(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $value = trim($value);

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** 返回或创建大小写规范化的流派词汇；调用方必须已处于歌曲写事务。 */
    private function genreId(string $name, string $now): string
    {
        $normalized = $this->normalize($name);
        $existing = Db::table('media_genres')->where('normalized_name', $normalized)->value('id');
        if (is_string($existing)) {
            return $existing;
        }
        $id = (string) new Ulid();
        Db::table('media_genres')->insert([
            'id' => $id,
            'name' => $name,
            'normalized_name' => $normalized,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** 按当前可用且解析成功的歌曲重算专辑派生总数；不读取媒体文件。 */
    private function refreshAlbumStats(string $albumId, string $now): void
    {
        /** @var stdClass|null $stats */
        $stats = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('songs.album_id', $albumId)
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')
            ->first([Db::raw('COUNT(songs.id) AS song_count'), Db::raw('COALESCE(SUM(songs.duration_ms), 0) AS duration_ms')]);
        Db::table('media_albums')->where('id', $albumId)->update([
            'song_count' => (int) ($stats?->song_count ?? 0),
            'duration_ms' => (int) ($stats?->duration_ms ?? 0),
            'updated_at' => $now,
        ]);
    }

    /** 生成不依赖 SQLite/MySQL 排序规则的保守身份文本。 */
    private function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
