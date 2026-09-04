<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Artist\ArtistNameIdentityNormalizer;
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
 * 可以直接物化。歌曲级专辑和发行日期是明确例外：有效值可在文件元数据与可靠 scraped 值之间切换；
 * 专辑变化必须只迁移当前歌曲到独立或已存在的目标专辑，禁止直接改名当前共享专辑并影响同合辑的其他
 * 歌曲。切回文件元数据时优先找回扫描创建的原专辑实体，不能只改字段状态而留下错误 album_id。专辑
 * 实体后续补全仍复用实体来源仓储；本仓储不迁移个人收藏、封面或其他歌曲关系。
 */
final class MetadataFieldStateRepository
{
    public function __construct(
        private readonly MetadataFieldSchema $schema = new MetadataFieldSchema(),
        private readonly SearchTextNormalizer $normalizer = new SearchTextNormalizer(),
        private readonly EntityMetadataStateRepository $entityStates = new EntityMetadataStateRepository(),
        private readonly MetadataScrapePolicyService $policy = new MetadataScrapePolicyService(),
        private readonly ArtistNameIdentityNormalizer $artistNames = new ArtistNameIdentityNormalizer(),
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
                $scrapedWins = $scrapedPresent && ($this->policy->providerOverridesMetadata($field) || !$rawPresent);
                $effective = $scrapedWins ? $scrapedValue : $rawValue;
                Db::table('media_metadata_field_states')->insert([
                    'song_id' => $songId,
                    'field_key' => $field,
                    'raw_value_json' => $this->encode($rawValue),
                    'scraped_value_json' => $scrapedPresent ? $this->encode($scrapedValue) : null,
                    'manual_value_json' => null,
                    'effective_value_json' => $this->encode($this->policy->effectiveValue($field, $effective)),
                    'effective_source' => $scrapedWins ? 'scraped' : 'raw',
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
                } elseif (($scrapedSupplied ? ($scrapedPresent ? $this->encode($scrapedValue) : null)
                    : $existing->scraped_value_json) !== null
                    && ($this->policy->providerOverridesMetadata($field) || !$this->valuePresent($rawValue, $field))) {
                    $selected = $scrapedSupplied ? $scrapedValue
                        : $this->decode((string) $existing->scraped_value_json);
                    $values['effective_value_json'] = $this->encode($this->policy->effectiveValue($field, $selected));
                    $values['effective_source'] = 'scraped';
                } elseif ($this->valuePresent($rawValue, $field)) {
                    $values['effective_value_json'] = $this->encode($this->policy->effectiveValue($field, $rawValue));
                    $values['effective_source'] = 'raw';
                } else {
                    $selected = $scrapedPresent ? $scrapedValue : $rawValue;
                    $values['effective_value_json'] = $this->encode($this->policy->effectiveValue($field, $selected));
                    $values['effective_source'] = $scrapedPresent ? 'scraped' : 'raw';
                }
            }
            Db::table('media_metadata_field_states')->where('song_id', $songId)->where('field_key', $field)
                ->update($values);
        }
        // 扫描器已按版次、目录占位和原始标签规则确定 album_id；这里不能用字段层再次拆分该身份。
        $this->materialize($songId, false);
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
            $fallbackJson = $this->encode($this->policy->effectiveValue($field, $fallbackValue));
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
        // 远端占位同样由目录写入器确定临时专辑；等待可靠刮削后再允许重投影关系。
        $this->materialize($songId, false);
    }

    /**
     * 只应用一次可靠第三方候选，不刷新扫描得到的 raw 层。
     *
     * 调用方必须位于已经复核账号、音乐库授权和歌曲证据的短事务中。候选只更新明确提供的字段：
     * 手工值继续作为有效值，锁定字段连自动来源版本也保持不变；未锁定且没有手工值的字段按当前策略
     * 在 raw/scraped 间重新选择并再次执行业务文本投影。重新计算不能只在 scraped 获胜时发生，否则管理员
     * 开启繁转简后，以文件元数据优先的重新刮削仍会沿用开关保存前的繁体 effective 值。raw/scraped
     * 快照保持原文，方法最后只物化数据库目录，不读取或修改音频文件。
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
            if ($existing->manual_value_json === null) {
                $rawValue = $this->decode((string) $existing->raw_value_json);
                $scrapedWins = $this->policy->providerOverridesMetadata($field)
                    || !$this->valuePresent($rawValue, $field);
                $selected = $scrapedWins ? $value : $rawValue;
                $values['effective_value_json'] = $this->encode($this->policy->effectiveValue($field, $selected));
                $values['effective_source'] = $scrapedWins ? 'scraped' : 'raw';
            }
            Db::table('media_metadata_field_states')->where('song_id', $songId)
                ->where('field_key', $field)->where('version', (int) $existing->version)->update($values);
        }
        $this->materialize($songId);
    }

    /**
     * 依据当前全局策略重算一首历史歌曲的未锁定自动来源，并同步目录及专辑关系。
     *
     * 调用方必须已经完成 `manage_system + edit_metadata` 授权，并在短事务内调用。方法只读取状态表中已
     * 持久化的 raw/scraped 快照，不接受候选参数、不访问网络或媒体文件；manual 或 locked 字段保持原值和
     * 版本。自动来源只有 effective/source 确实变化时才 CAS 推进版本，重复执行幂等。最后仍会物化一次，
     * 因为历史字段可能早已是正确 raw 值但 album_id 仍指向旧插件专辑；迁移失败由外层事务整体回滚。
     *
     * @return int 实际改变有效值或来源的字段数，不包含仅修复目录关系的情况
     */
    public function reprojectAutomaticValues(string $songId, string $now): int
    {
        $this->ensureSong($songId, $now);
        $changedFields = 0;
        /** @var list<stdClass> $rows */
        $rows = Db::table('media_metadata_field_states')->where('song_id', $songId)
            ->orderBy('field_key')->get()->all();
        foreach ($rows as $row) {
            if ((int) $row->is_locked === 1 || $row->manual_value_json !== null) continue;
            $field = (string) $row->field_key;
            $raw = $this->decode((string) $row->raw_value_json);
            $scraped = $row->scraped_value_json === null ? null : $this->decode((string) $row->scraped_value_json);
            $scrapedWins = $row->scraped_value_json !== null
                && ($this->policy->providerOverridesMetadata($field) || !$this->valuePresent($raw, $field));
            $source = $scrapedWins ? 'scraped' : 'raw';
            $effectiveJson = $this->encode($this->policy->effectiveValue($field, $scrapedWins ? $scraped : $raw));
            if ((string) $row->effective_source === $source
                && (string) $row->effective_value_json === $effectiveJson) continue;
            $changed = Db::table('media_metadata_field_states')->where('song_id', $songId)
                ->where('field_key', $field)->where('version', (int) $row->version)->update([
                    'effective_value_json' => $effectiveJson, 'effective_source' => $source,
                    'version' => Db::raw('version + 1'), 'source_updated_at' => $now, 'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new MediaMetadataConflict('歌曲字段在策略重投影期间发生变化。');
            ++$changedFields;
        }
        $this->materialize($songId);
        return $changedFields;
    }

    /**
     * 把管理员从历史任务中明确选择的插件字段设为当前有效值并锁定来源。
     *
     * 候选值必须由上层从已保存且重新校验的 `ScrapeMetadataCandidate` 取出，不能来自浏览器。调用方还须
     * 在同一短事务内完成 `edit_metadata`、`run_scrape`、实时音乐库 manage 授权和候选归属复验。本方法
     * 只接受字段状态键对应的期望版本；任一字段已锁定、存在 manual 值或版本变化都会让整笔事务失败，
     * 不会清除人工事实或部分应用。
     *
     * 明确“使用数据”会把 `effective_source` 保持为 scraped 并设置来源锁。锁是必要的不变量，否则后续
     * 扫描会按默认 raw 优先级立即撤销管理员选择。相同候选已是锁定 scraped 有效值时视为幂等成功，
     * 即使浏览器携带旧版本也不会重复推进版本。方法只修改数据库投影，不写音频标签或派生文件。
     *
     * @param array<string,mixed> $candidate 服务端恢复的稀疏插件候选
     * @param array<string,int> $expectedVersions 以歌曲字段状态键索引的乐观锁版本
     */
    public function selectStoredScrapedCandidate(
        string $songId,
        array $candidate,
        array $expectedVersions,
        string $now,
    ): void {
        $scrapedFields = $this->schema->fromScrapeCandidate($candidate);
        $scrapedKeys = array_keys($scrapedFields);
        $expectedKeys = array_keys($expectedVersions);
        sort($scrapedKeys);
        sort($expectedKeys);
        if ($scrapedFields === [] || $scrapedKeys !== $expectedKeys) {
            throw new MediaMetadataInvalid('历史刮削字段版本结构无效。');
        }
        $this->ensureSong($songId, $now);
        foreach ($scrapedFields as $field => $value) {
            $expectedVersion = $expectedVersions[$field] ?? null;
            if (!is_int($expectedVersion) || $expectedVersion < 1) {
                throw new MediaMetadataInvalid('历史刮削字段版本无效。');
            }
            /** @var stdClass|null $existing */
            $existing = Db::table('media_metadata_field_states')
                ->where('song_id', $songId)->where('field_key', $field)->first();
            if (!$existing instanceof stdClass) throw new MediaMetadataConflict('歌曲字段状态不存在。');
            $valueJson = $this->encode($value);
            $effectiveJson = $this->encode($this->policy->effectiveValue($field, $value));
            $alreadySelected = (int) $existing->is_locked === 1
                && $existing->manual_value_json === null
                && (string) $existing->effective_source === 'scraped'
                && (string) $existing->effective_value_json === $effectiveJson
                && (string) $existing->scraped_value_json === $valueJson;
            if ($alreadySelected) continue;
            if ((int) $existing->is_locked === 1 || $existing->manual_value_json !== null
                || (int) $existing->version !== $expectedVersion) {
                throw new MediaMetadataConflict('歌曲字段已锁定、存在手工值或版本已经变化。');
            }
            $changed = Db::table('media_metadata_field_states')->where('song_id', $songId)
                ->where('field_key', $field)->where('version', $expectedVersion)->where('is_locked', 0)
                ->whereNull('manual_value_json')->update([
                    'scraped_value_json' => $valueJson,
                    'effective_value_json' => $effectiveJson,
                    'effective_source' => 'scraped',
                    'is_locked' => 1,
                    'version' => Db::raw('version + 1'),
                    'source_updated_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new MediaMetadataConflict('歌曲字段在应用刮削数据时发生变化。');
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
                'manual_value_json' => null,
                'effective_value_json' => $this->encode($this->policy->effectiveValue($field, $value)),
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

    /**
     * 将字段状态写回现有目录投影；调用方必须已完成版本校验并位于事务内。
     *
     * 普通字段直接更新歌曲行和从属关系。`album` 的有效来源为 raw 或 scraped 时都要解析专辑归属：
     * scraped 以音乐库、规范标题、完整专辑艺术家、发行年和 release ID 形成稳定身份；raw 则优先复用
     * 扫描器的原始标签身份键及实体 raw 字段。相同结果重试会复用实体，不同结果只移动当前歌曲并重算
     * 新旧聚合，绝不改名原共享专辑。创建/迁移任一步失败由调用方事务整体回滚，原歌曲归属仍保持不变。
     * 扫描调用传 `reconcileRawAlbum=false`，因为扫描器已经完成版次归并，但 scraped 有效来源仍需在扫描
     * 重置 album_id 后恢复；刮削/策略重放使用默认 true，允许从插件专辑安全切回文件专辑。
     *
     * @param bool $reconcileRawAlbum 是否允许按 raw 来源重投影歌曲专辑关系
     */
    public function materialize(string $songId, bool $reconcileRawAlbum = true): void
    {
        $states = $this->states($songId);
        if ($states === []) return;
        $value = static fn (string $field): mixed => $states[$field]['effective'] ?? null;
        $releaseDate = $value('releaseDate');
        $externalIds = is_array($value('externalIds')) ? $value('externalIds') : [];
        $contributors = is_array($value('contributors')) ? $value('contributors') : [];
        $now = gmdate('Y-m-d\TH:i:s\Z');
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
            'updated_at' => $now,
        ]);
        $artists = $value('artists');
        if (is_array($artists) && $artists !== []) $this->replaceArtists($songId, $artists);
        $genres = $value('genres');
        if (is_array($genres)) $this->replaceGenres($songId, $genres);
        $this->materializeEffectiveAlbum($songId, $states, $now, $reconcileRawAlbum);
    }

    /**
     * 把当前歌曲迁移到当前有效来源对应的专辑实体，同时保持共享专辑和媒体文件不变。
     *
     * 前置条件是生产专辑表已具备稳定身份列，且调用者位于短写事务。滚动升级或精简测试库缺少这些列时
     * 保留字段状态但跳过关系迁移，避免半升级节点写出不完整实体。raw 来源先按扫描时的确定性 identity_key
     * 和专辑实体 raw 标题找回原实体；scraped 来源继续按 release ID 和插件身份键查找。并发创建由
     * `(library_id, identity_key)` 唯一约束与 `insertOrIgnore` 收敛。旧专辑即使变空也不在这里删除，清理由
     * 独立可审计流程负责。manual/locked 专辑不在此自动迁移，仍由管理员元数据工作流维护实体关系。
     *
     * @param array<string,array<string,mixed>> $states 当前歌曲的完整字段状态
     */
    private function materializeEffectiveAlbum(
        string $songId,
        array $states,
        string $now,
        bool $reconcileRawAlbum,
    ): void
    {
        $album = $states['album'] ?? null;
        $source = is_array($album) ? ($album['source'] ?? null) : null;
        if (!in_array($source, ['raw', 'scraped'], true)
            || ($source === 'raw' && !$reconcileRawAlbum)
            || !is_string($album['effective'] ?? null) || trim($album['effective']) === '') {
            return;
        }
        $schema = Db::connection()->getSchemaBuilder();
        foreach (['library_id', 'identity_key', 'identity_source_title', 'normalized_title', 'song_count',
            'duration_ms', 'created_at', 'updated_at'] as $column) {
            if (!$schema->hasColumn('media_albums', $column)) return;
        }

        /** @var stdClass|null $song */
        $song = Db::table('media_songs')->where('id', $songId)->first(['library_id', 'album_id']);
        if (!$song instanceof stdClass) throw new MediaMetadataNotFound('歌曲不存在或不可管理。');
        $title = trim((string) $album['effective']);
        $sourceTitle = is_string($album[$source] ?? null) && trim((string) $album[$source]) !== ''
            ? trim((string) $album[$source]) : $title;
        $sourceArtists = $states['albumArtists'][$source] ?? null;
        $albumArtists = is_array($sourceArtists)
            ? $this->policy->effectiveValue('albumArtists', $sourceArtists)
            : ($states['albumArtists']['effective'] ?? []);
        if (!is_array($albumArtists) || $albumArtists === []) {
            $albumArtists = Db::table('media_album_artists as links')
                ->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
                ->where('links.album_id', (string) $song->album_id)->orderBy('links.position')
                ->pluck('artists.name')->map('strval')->all();
        }
        if ($albumArtists === []) $albumArtists = ['未知艺术家'];
        $artistIds = $this->resolveAlbumArtists($albumArtists, $now);
        // 身份字段必须来自与专辑标题相同的来源层，避免把文件合辑日期混进插件专辑或反向污染 raw 身份。
        $releaseDate = $states['releaseDate'][$source] ?? null;
        $releaseYear = is_string($releaseDate) && preg_match('/^(\d{4})/', $releaseDate, $match) === 1
            ? (int) $match[1] : null;
        $external = is_array($states['externalIds'][$source] ?? null)
            ? $states['externalIds'][$source] : [];
        $releaseId = $this->normalizedExternalId($external['musicbrainzReleaseId'] ?? null);
        $releaseGroupId = $this->normalizedExternalId($external['musicbrainzReleaseGroupId'] ?? null);
        $identityKey = $source === 'raw'
            ? $this->rawAlbumIdentityKey($sourceTitle, $sourceArtists, $releaseYear)
            : 'scraped:' . hash('sha256', json_encode([
                $this->albumIdentityTitle($title), $artistIds, $releaseYear, $releaseId, $releaseGroupId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $targetId = $source === 'raw' ? $this->matchingRawAlbumId(
            (string) $song->library_id,
            $sourceTitle,
            $artistIds,
            $releaseYear,
            $identityKey,
        ) : $this->matchingAlbumId(
            (string) $song->library_id,
            (string) $song->album_id,
            $title,
            $artistIds,
            $releaseYear,
            $releaseId,
            $releaseGroupId,
            $identityKey,
        );
        if ($targetId === null) {
            $createdId = (string) new Ulid();
            Db::table('media_albums')->insertOrIgnore([
                'id' => $createdId, 'library_id' => (string) $song->library_id, 'identity_key' => $identityKey,
                'identity_source_title' => $sourceTitle, 'title' => $title,
                'normalized_title' => $this->normalizer->normalize($title), 'sort_title' => null,
                'release_date' => is_string($releaseDate) ? $releaseDate : null, 'release_year' => $releaseYear,
                'disc_total' => $states['discTotal'][$source] ?? null,
                'musicbrainz_release_id' => $releaseId, 'musicbrainz_release_group_id' => $releaseGroupId,
                'song_count' => 0, 'duration_ms' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $resolved = Db::table('media_albums')->where('library_id', (string) $song->library_id)
                ->where('identity_key', $identityKey)->value('id');
            if (!is_string($resolved)) throw new MediaMetadataConflict('刮削专辑实体创建失败。');
            $targetId = $resolved;
        }
        foreach ($artistIds as $position => $artistId) {
            Db::table('media_album_artists')->insertOrIgnore([
                'album_id' => $targetId, 'artist_id' => $artistId, 'position' => $position,
            ]);
        }
        if (hash_equals((string) $song->album_id, $targetId)) return;

        $changed = Db::table('media_songs')->where('id', $songId)
            ->where('album_id', (string) $song->album_id)->update([
            'album_id' => $targetId, 'updated_at' => $now,
        ]);
        if ($changed !== 1) throw new MediaMetadataConflict('歌曲专辑归属已经变化。');
        $this->refreshAlbumStats((string) $song->album_id, $now);
        $this->refreshAlbumStats($targetId, $now);
    }

    /**
     * 生成与 `MediaCatalogWriter` 完全一致的文件标签专辑身份键。
     *
     * 扫描器使用原始标题、原始有序专辑艺人、发行年和固定 `tagged` 标记，因此这里不能使用已经繁转简的
     * effective 文本，否则无法找回策略切换前建立的实体。缺少原始艺人列表时返回一个不会命中扫描实体的
     * 保守键，随后仍可通过 raw 字段状态匹配；新建时该键由唯一约束保证并发幂等。
     *
     * @param mixed $artists 文件标签中的原始有序专辑艺人列表
     */
    private function rawAlbumIdentityKey(string $title, mixed $artists, ?int $releaseYear): string
    {
        $names = is_array($artists) ? array_values(array_filter($artists, 'is_string')) : [];
        return hash('sha256', implode("\n", [
            $this->scanIdentityText($title),
            implode('|', array_map(fn (string $name): string => $this->scanIdentityText($name), $names)),
            (string) ($releaseYear ?? 0),
            'tagged',
        ]));
    }

    /**
     * 找回扫描创建的原始专辑实体，避免策略从三方数据切回文件元数据后遗留错误 album_id。
     *
     * 精确 identity_key 是首选且天然包含库、标题、完整署名和年份。历史实体可能因旧版本或标签修正而没有
     * 当前键，此时仅在同库、raw 标题完全相同、完整有序艺术家 ID 相同的候选中选择；多个候选必须再由
     * raw 发行年唯一消歧，否则失败关闭并让调用方创建独立实体，不能把歌曲猜回错误专辑。查询只读取业务
     * 数据，迁移和聚合更新仍由调用方同一事务完成。
     *
     * @param list<string> $artistIds
     */
    private function matchingRawAlbumId(
        string $libraryId,
        string $rawTitle,
        array $artistIds,
        ?int $releaseYear,
        string $identityKey,
    ): ?string {
        $exact = Db::table('media_albums')->where('library_id', $libraryId)
            ->where('identity_key', $identityKey)->value('id');
        if (is_string($exact)) return $exact;

        if (!Db::connection()->getSchemaBuilder()->hasTable('media_album_metadata_field_states')) return null;
        $rawTitleJson = $this->encode($rawTitle);
        $candidateIds = Db::table('media_albums as albums')
            ->join('media_album_metadata_field_states as states', function ($join): void {
                $join->on('states.album_id', '=', 'albums.id')->where('states.field_key', '=', 'title');
            })
            ->where('albums.library_id', $libraryId)->where('states.raw_value_json', $rawTitleJson)
            ->orderBy('albums.created_at')->orderBy('albums.id')->pluck('albums.id')
            ->map('strval')->all();
        $compatible = array_values(array_filter(
            $candidateIds,
            fn (string $albumId): bool => $this->albumArtistIds($albumId) === $artistIds,
        ));
        if (count($compatible) === 1) return $compatible[0];
        if ($releaseYear === null || $compatible === []) return null;

        $yearMatches = [];
        foreach ($compatible as $albumId) {
            $rawDateJson = Db::table('media_album_metadata_field_states')->where('album_id', $albumId)
                ->where('field_key', 'releaseDate')->value('raw_value_json');
            if (!is_string($rawDateJson)) continue;
            $rawDate = $this->decode($rawDateJson);
            if (is_string($rawDate) && preg_match('/^(\d{4})/', $rawDate, $match) === 1
                && (int) $match[1] === $releaseYear) {
                $yearMatches[] = $albumId;
            }
        }
        return count($yearMatches) === 1 ? $yearMatches[0] : null;
    }

    /** 与扫描器保持字节级一致的 trim、空白折叠和小写规则；只用于身份，不改变展示文本。 */
    private function scanIdentityText(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** @param list<string> $names @return list<string> */
    private function resolveAlbumArtists(array $names, string $now): array
    {
        $ids = [];
        foreach ($names as $name) {
            $normalized = $this->artistNames->storageKey($name);
            $artistId = Db::table('media_artists')->whereIn('normalized_name', $this->artistNames->lookupKeys($name))
                ->orderBy('created_at')->orderBy('id')->value('id');
            if (!is_string($artistId)) $artistId = $this->entityStates->artistIdByRawName($name);
            if (!is_string($artistId)) {
                $artistId = (string) new Ulid();
                Db::table('media_artists')->insert([
                    'id' => $artistId, 'name' => $name, 'normalized_name' => $normalized, 'sort_name' => null,
                    'musicbrainz_artist_id' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $ids[] = $artistId;
        }
        return $ids;
    }

    /**
     * 解析可复用专辑；当前实体或唯一候选的标题与完整署名一致时优先复用。
     *
     * 逐曲插件可能分别从录音、歌曲和发行版来源补字段，发行日期并不总与最终 albumTitle 属于同一个
     * 专辑候选。因此年份只能辅助生成最后的确定性键，不能仅因每首歌年份不同就把同标题、同完整署名
     * 拆成“一首一个专辑”。末尾 `Disc N` 的方括号/圆括号仅是标签格式差异，匹配时折叠括号但保留
     * 碟号本身；明确且已落库的 MusicBrainz release ID 冲突仍拒绝复用。方法只读目录关系，不修改
     * 字段状态；并发创建最终仍由 identity_key 唯一约束收敛。
     *
     * @param list<string> $artistIds
     */
    private function matchingAlbumId(
        string $libraryId,
        string $currentAlbumId,
        string $title,
        array $artistIds,
        ?int $releaseYear,
        ?string $releaseId,
        ?string $releaseGroupId,
        string $identityKey,
    ): ?string {
        if ($this->albumMatches($currentAlbumId, $title, $artistIds, $releaseId, $releaseGroupId)) {
            return $currentAlbumId;
        }
        foreach ([
            'musicbrainz_release_group_id' => $releaseGroupId,
            'musicbrainz_release_id' => $releaseId,
        ] as $column => $externalId) {
            if ($externalId === null) continue;
            $id = Db::table('media_albums')->where('library_id', $libraryId)->whereRaw(
                'LOWER(' . $column . ') = ?', [$externalId],
            )->value('id');
            if (is_string($id)) return $id;
        }
        $titleKeys = array_values(array_unique([
            $this->normalizer->normalize($title),
            $this->albumIdentityTitle($title),
        ]));
        /** @var list<stdClass> $sameTitle */
        $sameTitle = Db::table('media_albums')->where('library_id', $libraryId)
            ->whereIn('normalized_title', $titleKeys)->orderBy('created_at')->orderBy('id')
            ->get(['id', 'title', 'musicbrainz_release_id', 'musicbrainz_release_group_id'])->all();
        $compatible = [];
        foreach ($sameTitle as $candidate) {
            if ($this->albumIdentityTitle((string) $candidate->title) !== $this->albumIdentityTitle($title)
                || $this->albumArtistIds((string) $candidate->id) !== $artistIds
                || $this->externalIdConflicts($candidate->musicbrainz_release_id, $releaseId)
                || $this->externalIdConflicts($candidate->musicbrainz_release_group_id, $releaseGroupId)) {
                continue;
            }
            $compatible[] = (string) $candidate->id;
        }
        if (count($compatible) === 1) return $compatible[0];
        $id = Db::table('media_albums')->where('library_id', $libraryId)->where('identity_key', $identityKey)->value('id');
        return is_string($id) ? $id : null;
    }

    /** @param list<string> $artistIds */
    private function albumMatches(
        string $albumId,
        string $title,
        array $artistIds,
        ?string $releaseId,
        ?string $releaseGroupId,
    ): bool
    {
        /** @var stdClass|null $album */
        $album = Db::table('media_albums')->where('id', $albumId)->first([
            'title', 'musicbrainz_release_id', 'musicbrainz_release_group_id',
        ]);
        if (!$album instanceof stdClass
            || $this->albumIdentityTitle((string) $album->title) !== $this->albumIdentityTitle($title)
            || $this->externalIdConflicts($album->musicbrainz_release_id, $releaseId)
            || $this->externalIdConflicts($album->musicbrainz_release_group_id, $releaseGroupId)) {
            return false;
        }
        return $this->albumArtistIds($albumId) === $artistIds;
    }

    /** @return list<string> 完整有序署名用于专辑身份比较，不能只比较主艺人。 */
    private function albumArtistIds(string $albumId): array
    {
        return Db::table('media_album_artists')->where('album_id', $albumId)
            ->orderBy('position')->pluck('artist_id')->map('strval')->all();
    }

    /** 候选和既有实体都声明了不同 release ID 时才构成强冲突；单侧缺失不能制造重复专辑。 */
    private function externalIdConflicts(mixed $existing, ?string $incoming): bool
    {
        $normalized = $this->normalizedExternalId($existing);
        return $normalized !== null && $incoming !== null && !hash_equals($normalized, $incoming);
    }

    /**
     * 生成专辑标题的保守关系身份键。
     *
     * 只把标题末尾 `[Disc 1]`、`(Disc 1)`、`【CD 1】` 统一为无括号形式，保留数字和 Disc/CD 文字；
     * 因此不同碟号仍是不同实体，不会把盒装合集的多张碟静默合并。其它符号和版次说明原样保留，最终
     * 再使用全局搜索规范化处理大小写、简繁和空白。该键不用于展示或回写文件标签。
     */
    private function albumIdentityTitle(string $title): string
    {
        $value = preg_replace(
            '/\s*[\[\(（【]\s*((?:disc|cd)\s*\d{1,3})\s*[\]\)）】]\s*$/iu',
            ' $1',
            trim($title),
        );
        return $this->normalizer->normalize(is_string($value) ? $value : $title);
    }

    /** 重新计算专辑派生聚合；空专辑保留零值，后续由独立清理流程安全回收。 */
    private function refreshAlbumStats(string $albumId, string $now): void
    {
        $aggregate = Db::table('media_songs')->where('album_id', $albumId)
            ->selectRaw('COUNT(*) AS song_count, COALESCE(SUM(duration_ms), 0) AS duration_ms')->first();
        Db::table('media_albums')->where('id', $albumId)->update([
            'song_count' => (int) ($aggregate->song_count ?? 0),
            'duration_ms' => (int) ($aggregate->duration_ms ?? 0),
            'updated_at' => $now,
        ]);
    }

    /** release ID 只做 trim 与大小写统一；空值不参与强身份匹配。 */
    private function normalizedExternalId(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        return mb_strtolower(trim($value), 'UTF-8');
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
            $normalized = $this->artistNames->storageKey($name);
            $artistId = Db::table('media_artists')->whereIn('normalized_name', $this->artistNames->lookupKeys($name))
                ->orderBy('created_at')->orderBy('id')->value('id');
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
