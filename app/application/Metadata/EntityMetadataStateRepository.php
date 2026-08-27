<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Search\SearchTextNormalizer;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 保存艺术家/专辑字段来源，并把有效值安全物化回共享目录实体。
 *
 * 调用者负责事务与对象授权。本仓储不访问文件或网络；扫描器可更新 raw/scraped，管理员服务可更新
 * manual。自动来源只有真实变化时才推进版本，避免同专辑多首歌曲重复扫描制造无意义冲突。艺术家名称
 * 物化会检查全局规范名唯一性；专辑艺术家替换会复用或创建规范词汇，但不会改变歌曲艺术家关系。
 */
final class EntityMetadataStateRepository
{
    public function __construct(
        private readonly EntityMetadataFieldSchema $schema = new EntityMetadataFieldSchema(),
        private readonly SearchTextNormalizer $normalizer = new SearchTextNormalizer(),
    ) {
    }

    /**
     * 为迁移前实体惰性创建原始字段状态。
     *
     * 初始化读取当前目录投影，不触发扫描或生成管理员审计。若并发初始化，复合主键和 insertOrIgnore
     * 保证只有一份状态；随后重新读取完整字段集合。
     *
     * @return array<string,array<string,mixed>>
     */
    public function ensure(string $type, string $entityId, string $now): array
    {
        $current = $this->currentValues($type, $entityId);
        if ($current === null) throw new MediaMetadataNotFound('元数据实体不存在。');
        [$table, $idColumn] = $this->storage($type);
        foreach ($this->schema->fields($type) as $field) {
            if (Db::table($table)->where($idColumn, $entityId)->where('field_key', $field)->exists()) continue;
            $value = $this->schema->normalize($type, $field, $current[$field]);
            Db::table($table)->insertOrIgnore([
                $idColumn => $entityId, 'field_key' => $field,
                'raw_value_json' => $this->encode($value), 'scraped_value_json' => null,
                'manual_value_json' => null, 'effective_value_json' => $this->encode($value),
                'effective_source' => 'raw', 'is_locked' => 0, 'version' => 1,
                'source_updated_at' => $now, 'manual_updated_at' => null, 'updated_by' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return $this->states($type, $entityId);
    }

    /**
     * 从一次已确认扫描同步完整 raw 与稀疏 scraped 来源。
     *
     * `raw` 必须包含类型全部字段；`scraped` 只含第三方实际提供的字段，缺失表示该来源消失。锁定字段
     * 仍更新两份来源事实和版本，但保持 effective 不变；未锁定字段按 manual > raw > scraped 重算。
     * 调用结束会重新物化实体，确保扫描不能覆盖人工值。
     *
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $scraped
     */
    public function syncFromScan(string $type, string $entityId, array $raw, array $scraped, string $now): void
    {
        [$table, $idColumn] = $this->storage($type);
        $this->ensure($type, $entityId, $now);
        foreach ($this->schema->fields($type) as $field) {
            if (!array_key_exists($field, $raw)) throw new MediaMetadataInvalid('扫描实体原始字段不完整。');
            $rawValue = $this->schema->normalize($type, $field, $raw[$field]);
            $scrapedPresent = array_key_exists($field, $scraped);
            $scrapedValue = $scrapedPresent ? $this->schema->normalize($type, $field, $scraped[$field]) : null;
            /** @var stdClass|null $existing */
            $existing = Db::table($table)->where($idColumn, $entityId)->where('field_key', $field)->first();
            if (!$existing instanceof stdClass) throw new MediaMetadataConflict('实体字段状态不存在。');
            $rawJson = $this->encode($rawValue);
            $scrapedJson = $scrapedPresent ? $this->encode($scrapedValue) : null;
            if ((string) $existing->raw_value_json === $rawJson
                && ($existing->scraped_value_json === null ? null : (string) $existing->scraped_value_json) === $scrapedJson) {
                continue;
            }
            $values = [
                'raw_value_json' => $rawJson, 'scraped_value_json' => $scrapedJson,
                'version' => Db::raw('version + 1'), 'source_updated_at' => $now, 'updated_at' => $now,
            ];
            if ((int) $existing->is_locked === 0) {
                if ($existing->manual_value_json !== null) {
                    $values['effective_value_json'] = (string) $existing->manual_value_json;
                    $values['effective_source'] = 'manual';
                } else {
                    $rawPresent = $this->valuePresent($rawValue, $field);
                    $values['effective_value_json'] = $rawPresent || $scrapedJson === null ? $rawJson : $scrapedJson;
                    $values['effective_source'] = $rawPresent || $scrapedJson === null ? 'raw' : 'scraped';
                }
            }
            Db::table($table)->where($idColumn, $entityId)->where('field_key', $field)->update($values);
        }
        $this->materialize($type, $entityId, $now);
    }

    /**
     * 应用一次已验证的稀疏第三方实体字段。
     *
     * 专辑属于共享实体，歌曲刮削必须先保存歌曲来源，再通过本方法按字段补齐专辑信息。手工值和已有
     * 原始值只记录第三方事实但保持有效投影不变；只有字段确实为空时才把 scraped 提升为 effective，
     * 并在同一调用内物化专辑关系。调用方负责外层授权、歌曲证据和事务。
     * @param array<string,mixed> $candidate 只包含专辑实体白名单字段
     */
    public function applyScrapedCandidate(string $type, string $entityId, array $candidate, string $now): void
    {
        [$table, $idColumn] = $this->storage($type);
        // 滚动升级期间旧测试库或旧进程可能尚未安装共享实体状态表；歌曲刮削仍可安全完成，专辑补全下次重试。
        if (!Db::connection()->getSchemaBuilder()->hasTable($table)) return;
        $this->ensure($type, $entityId, $now);
        $fields = [];
        foreach ($candidate as $field => $value) {
            if (in_array($field, $this->schema->fields($type), true)) $fields[$field] = $this->schema->normalize($type, $field, $value);
        }
        foreach ($fields as $field => $value) {
            /** @var stdClass|null $existing */
            $existing = Db::table($table)->where($idColumn, $entityId)->where('field_key', $field)->first();
            if (!$existing instanceof stdClass || (int) $existing->is_locked === 1) continue;
            $values = ['scraped_value_json' => $this->encode($value), 'version' => Db::raw('version + 1'),
                'source_updated_at' => $now, 'updated_at' => $now];
            if ($existing->manual_value_json === null
                && !$this->valuePresent($this->decode((string) $existing->raw_value_json), $field)) {
                $values['effective_value_json'] = $this->encode($value);
                $values['effective_source'] = 'scraped';
            }
            Db::table($table)->where($idColumn, $entityId)->where('field_key', $field)
                ->where('version', (int) $existing->version)->update($values);
        }
        $this->materialize($type, $entityId, $now);
    }

    /** @return array<string,array<string,mixed>> 返回解码后的安全字段状态，不包含路径或标签原始映射。 */
    public function states(string $type, string $entityId): array
    {
        [$table, $idColumn] = $this->storage($type);
        $result = [];
        /** @var list<stdClass> $rows */
        $rows = Db::table($table)->where($idColumn, $entityId)->orderBy('field_key')->get()->all();
        foreach ($rows as $row) {
            $result[(string) $row->field_key] = [
                'field' => (string) $row->field_key,
                'raw' => $this->decode((string) $row->raw_value_json),
                'scraped' => $row->scraped_value_json === null ? null : $this->decode((string) $row->scraped_value_json),
                'manual' => $row->manual_value_json === null ? null : $this->decode((string) $row->manual_value_json),
                'effective' => $this->decode((string) $row->effective_value_json),
                'source' => (string) $row->effective_source, 'locked' => (int) $row->is_locked === 1,
                'version' => (int) $row->version, 'sourceUpdatedAt' => (string) $row->source_updated_at,
                'manualUpdatedAt' => $row->manual_updated_at === null ? null : (string) $row->manual_updated_at,
                'updatedAt' => (string) $row->updated_at,
            ];
        }
        return $result;
    }

    /**
     * 将当前有效状态写回共享实体表。
     *
     * 艺术家规范名冲突表示已有另一个稳定实体，必须要求管理员使用合并工作流；这里抛 409 而不自动
     * 合并。专辑 identity_key 不随显示标题改变，它继续把后续扫描定位到同一稳定专辑。关系替换和实体
     * 字段更新必须由上层事务一起提交。
     */
    public function materialize(string $type, string $entityId, string $now): void
    {
        $states = $this->states($type, $entityId);
        if ($states === []) return;
        $value = static fn (string $field): mixed => $states[$field]['effective'] ?? null;
        $external = is_array($value('externalIds')) ? $value('externalIds') : [];
        if ($type === 'artist') {
            $name = (string) $value('name');
            $normalized = $this->normalizer->normalize($name);
            if (Db::table('media_artists')->where('normalized_name', $normalized)->where('id', '!=', $entityId)->exists()) {
                throw new MediaMetadataConflict('艺术家名称已由其他实体使用，请使用合并工作流。');
            }
            Db::table('media_artists')->where('id', $entityId)->update([
                'name' => $name, 'normalized_name' => $normalized, 'sort_name' => $value('sortName'),
                'musicbrainz_artist_id' => $external['musicbrainzArtistId'] ?? null, 'updated_at' => $now,
            ]);
            return;
        }
        $releaseDate = $value('releaseDate');
        Db::table('media_albums')->where('id', $entityId)->update([
            'title' => (string) $value('title'),
            'normalized_title' => $this->normalizer->normalize((string) $value('title')),
            'sort_title' => $value('sortTitle'), 'release_date' => $releaseDate,
            'release_year' => is_string($releaseDate) && preg_match('/^(\d{4})/', $releaseDate, $match) === 1
                ? (int) $match[1] : null,
            'disc_total' => $value('discTotal'),
            'musicbrainz_release_id' => $external['musicbrainzReleaseId'] ?? null,
            'musicbrainz_release_group_id' => $external['musicbrainzReleaseGroupId'] ?? null,
            'updated_at' => $now,
        ]);
        $artists = $value('albumArtists');
        if (is_array($artists) && $artists !== []) $this->replaceAlbumArtists($entityId, $artists, $now);
    }

    /**
     * 通过字段状态保留的原始名称找到已被人工改名的艺术家。
     *
     * 扫描器只在当前 normalized_name 未命中时调用；JSON 比较使用本仓储的规范编码，返回值仍由调用方
     * 在同一事务内复验。这样旧标签不会因管理员修改显示名而新建重复艺人。
     */
    public function artistIdByRawName(string $name): ?string
    {
        $raw = $this->encode($this->schema->normalize('artist', 'name', $name));
        $id = Db::table('media_artist_metadata_field_states')->where('field_key', 'name')
            ->where('raw_value_json', $raw)->value('artist_id');
        return is_string($id) ? $id : null;
    }

    /** @return array<string,mixed>|null */
    private function currentValues(string $type, string $entityId): ?array
    {
        if ($type === 'artist') {
            /** @var stdClass|null $row */
            $row = Db::table('media_artists')->where('id', $entityId)->first();
            if (!$row instanceof stdClass) return null;
            return [
                'name' => (string) $row->name, 'sortName' => $row->sort_name === null ? null : (string) $row->sort_name,
                'externalIds' => array_filter(['musicbrainzArtistId' => $row->musicbrainz_artist_id === null
                    ? null : (string) $row->musicbrainz_artist_id], static fn (?string $item): bool => $item !== null),
            ];
        }
        if ($type !== 'album') throw new MediaMetadataInvalid('不支持的元数据实体类型。');
        /** @var stdClass|null $row */
        $row = Db::table('media_albums')->where('id', $entityId)->first();
        if (!$row instanceof stdClass) return null;
        $artists = Db::table('media_album_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->where('links.album_id', $entityId)->orderBy('links.position')->pluck('artists.name')->map('strval')->all();
        return [
            'title' => (string) $row->title, 'sortTitle' => $row->sort_title === null ? null : (string) $row->sort_title,
            'albumArtists' => $artists ?: ['未知艺术家'],
            'releaseDate' => $row->release_date === null ? null : (string) $row->release_date,
            'discTotal' => $row->disc_total === null ? null : (int) $row->disc_total,
            'externalIds' => array_filter([
                'musicbrainzReleaseId' => $row->musicbrainz_release_id === null ? null : (string) $row->musicbrainz_release_id,
                'musicbrainzReleaseGroupId' => $row->musicbrainz_release_group_id === null ? null : (string) $row->musicbrainz_release_group_id,
            ], static fn (?string $item): bool => $item !== null),
        ];
    }

    /** @param list<string> $names */
    private function replaceAlbumArtists(string $albumId, array $names, string $now): void
    {
        Db::table('media_album_artists')->where('album_id', $albumId)->delete();
        foreach ($names as $position => $name) {
            $normalized = $this->normalizer->normalize($name);
            $artistId = Db::table('media_artists')->where('normalized_name', $normalized)->value('id');
            if (!is_string($artistId)) $artistId = $this->artistIdByRawName($name);
            if (!is_string($artistId)) {
                $artistId = (string) new Ulid();
                Db::table('media_artists')->insert([
                    'id' => $artistId, 'name' => $name, 'normalized_name' => $normalized, 'sort_name' => null,
                    'musicbrainz_artist_id' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            Db::table('media_album_artists')->insert([
                'album_id' => $albumId, 'artist_id' => $artistId, 'position' => $position,
            ]);
        }
    }

    /** @return array{0:string,1:string} */
    private function storage(string $type): array
    {
        return match ($type) {
            'artist' => ['media_artist_metadata_field_states', 'artist_id'],
            'album' => ['media_album_metadata_field_states', 'album_id'],
            default => throw new MediaMetadataInvalid('不支持的元数据实体类型。'),
        };
    }

    /** 使用统一选项编码值，保证比较与原始名称回查都具有确定字节表示。 */
    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 解码仅处理由本仓储写入的数据；损坏会抛出 JsonException 并回滚当前命令。 */
    private function decode(string $json): mixed
    {
        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    /** 空值和扫描占位名称不能阻止专辑/艺人实体的第三方补全。 */
    private function valuePresent(mixed $value, string $field): bool
    {
        if ($value === null || $value === '' || $value === []) return false;
        if (in_array($field, ['albumArtists', 'name'], true) && is_array($value)) {
            return !(count($value) === 1 && in_array($value[0], ['未知艺术家', 'Unknown Artist'], true));
        }
        return !($field === 'title' && in_array($value, ['单曲', 'Unknown Album'], true));
    }
}
