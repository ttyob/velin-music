<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Scrape\ChineseQueryVariantNormalizer;
use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use support\Db;

/**
 * 管理主程序统一的刮削字段优先级与中文入库投影策略。
 *
 * 配置只决定未锁定自动来源在后续扫描/刮削中的有效投影，不越过 manual/locked 边界，也不批量重写
 * 历史歌曲。`metadata` 表示文件标签或本地派生资源存在时保留，缺失才使用第三方；`provider` 表示经过
 * 既有证据与可靠性校验的第三方结果可覆盖本地自动来源。繁转简仅作用于准备物化的文本值，raw/scraped
 * 来源快照和音频文件保持原文，以便审计、重新选择和关闭开关后的后续重算仍有完整事实。
 */
final class MetadataScrapePolicyService
{
    private const KEY = 'metadata.scrape_policy';
    public const METADATA = 'metadata';
    public const PROVIDER = 'provider';

    /** @var list<string> */
    public const FIELDS = [
        'title', 'sortTitle', 'artists', 'albumArtists', 'album', 'trackNumber', 'trackTotal',
        'discNumber', 'discTotal', 'releaseDate', 'genres', 'moods', 'contributors', 'label',
        'edition', 'explicit', 'externalIds', 'lyrics', 'songArtwork', 'albumArtwork',
    ];

    /** @var list<string> */
    private const SIMPLIFIABLE_FIELDS = [
        'title', 'sortTitle', 'artists', 'albumArtists', 'album', 'genres', 'moods', 'contributors',
        'label', 'edition', 'name',
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ChineseQueryVariantNormalizer $chinese = new ChineseQueryVariantNormalizer(),
    ) {}

    /**
     * 返回严格校验的全局策略快照。
     *
     * 缺行、未知字段或损坏 JSON 均视为迁移/持久状态故障并失败关闭；不能在运行时悄悄使用另一套默认值，
     * 否则不同 Worker 可能对同一首歌选择不同来源。读取没有写入、文件或网络副作用。
     *
     * @return array{fieldPriorities:array<string,string>,convertTraditionalToSimplified:bool,version:int,updatedAt:string}
     */
    public function get(): array
    {
        $row = Db::table('system_settings')->where('setting_key', self::KEY)->first();
        if (!$row instanceof stdClass) throw new MetadataScrapePolicyUnavailable('刮削策略不存在。');
        return $this->map($row);
    }

    /** 返回字段当前是否允许可靠第三方结果覆盖存在的文件元数据。 */
    public function providerOverridesMetadata(string $field): bool
    {
        $field = $this->canonicalField($field);
        return $this->runtime()['fieldPriorities'][$field] === self::PROVIDER;
    }

    /**
     * 将已经选出的自动来源值转换为业务有效投影。
     *
     * 调用方必须先完成来源优先级、manual/locked 与字段类型判断。仅文本描述字段参与转换，日期、编号、
     * 布尔值、外部 ID、歌词正文和图片引用不处理。OpenCC 缺失或失败由转换器原文回退，数据库事务不因
     * 可选转换能力失败；方法不修改传入数组、不写文件或数据库。
     */
    public function effectiveValue(string $field, mixed $value): mixed
    {
        if (!$this->runtime()['convertTraditionalToSimplified']) return $value;
        $canonical = $this->canonicalField($field);
        if (!in_array($field, self::SIMPLIFIABLE_FIELDS, true)
            && !in_array($canonical, self::SIMPLIFIABLE_FIELDS, true)) return $value;
        if (is_string($value)) return $this->chinese->simplifyText($value);
        if (!is_array($value) || !array_is_list($value)) return $value;
        return array_map(fn (mixed $item): mixed => is_string($item)
            ? $this->chinese->simplifyText($item) : $item, $value);
    }

    /**
     * 以 expectedVersion 原子替换完整策略并写入脱敏审计。
     *
     * actor 与 requestId 必须来自认证请求上下文。命令必须完整列出固定字段，防止旧客户端保存时丢掉新
     * 策略；CAS 冲突不自动合并或重放。成功只影响后续自动来源选择，不启动扫描、不修改历史业务数据、
     * 歌词文件、封面或音频标签；事务失败时设置与审计一并回滚。
     *
     * @return array{fieldPriorities:array<string,string>,convertTraditionalToSimplified:bool,version:int,updatedAt:string}
     */
    public function update(array $command, string $actorUserId, string $requestId): array
    {
        $value = $this->validateCommand($command);
        return Db::transaction(function () use ($command, $value, $actorUserId, $requestId): array {
            $before = $this->get();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('system_settings')->where('setting_key', self::KEY)
                ->where('version', $command['expectedVersion'])->update([
                    'value_json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'version' => Db::raw('version + 1'), 'updated_by' => $actorUserId, 'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new MetadataScrapePolicyConflict('刮削策略版本已变化。');
            $changedFields = 0;
            foreach (self::FIELDS as $field) {
                if ($before['fieldPriorities'][$field] !== $value['fieldPriorities'][$field]) ++$changedFields;
            }
            if ($before['convertTraditionalToSimplified'] !== $value['convertTraditionalToSimplified']) ++$changedFields;
            $this->audit->record($actorUserId, 'metadata.scrape_policy.update', 'system_setting', self::KEY,
                'success', $requestId, ['changed_count' => $changedFields, 'version' => $command['expectedVersion'] + 1]);
            return $this->get();
        });
    }

    /** @return array{fieldPriorities:array<string,string>,convertTraditionalToSimplified:bool} */
    private function validateCommand(array $command): array
    {
        $keys = array_keys($command);
        sort($keys);
        if ($keys !== ['convertTraditionalToSimplified', 'expectedVersion', 'fieldPriorities']
            || !is_array($command['fieldPriorities'] ?? null)
            || !is_bool($command['convertTraditionalToSimplified'] ?? null)
            || !is_int($command['expectedVersion'] ?? null) || $command['expectedVersion'] < 1) {
            throw new MetadataScrapePolicyInvalid('刮削策略参数无效。');
        }
        $priorities = $command['fieldPriorities'];
        $fieldKeys = array_keys($priorities);
        sort($fieldKeys);
        $expected = self::FIELDS;
        sort($expected);
        if (array_is_list($priorities) || $fieldKeys !== $expected) {
            throw new MetadataScrapePolicyInvalid('刮削策略字段不完整。');
        }
        $normalized = [];
        foreach (self::FIELDS as $field) {
            $priority = $priorities[$field] ?? null;
            if (!is_string($priority) || !in_array($priority, [self::METADATA, self::PROVIDER], true)) {
                throw new MetadataScrapePolicyInvalid('刮削策略优先级无效。');
            }
            $normalized[$field] = $priority;
        }
        return ['fieldPriorities' => $normalized,
            'convertTraditionalToSimplified' => $command['convertTraditionalToSimplified']];
    }

    /** @return array{fieldPriorities:array<string,string>,convertTraditionalToSimplified:bool,version:int,updatedAt:string} */
    private function map(stdClass $row): array
    {
        try {
            $value = json_decode((string) $row->value_json, true, 32, JSON_THROW_ON_ERROR);
            $keys = is_array($value) ? array_keys($value) : [];
            sort($keys);
            if (!is_array($value) || $keys !== ['convertTraditionalToSimplified', 'fieldPriorities']) {
                throw new MetadataScrapePolicyInvalid('刮削策略损坏。');
            }
            $normalized = $this->validateCommand($value + ['expectedVersion' => (int) $row->version]);
        } catch (JsonException|MetadataScrapePolicyInvalid $exception) {
            throw new MetadataScrapePolicyUnavailable('刮削策略损坏。', previous: $exception);
        }
        return $normalized + ['version' => (int) $row->version, 'updatedAt' => (string) $row->updated_at];
    }

    /** 把实体字段映射到后台表单中的统一策略键。 */
    private function canonicalField(string $field): string
    {
        $canonical = match ($field) {
            'name' => 'artists',
            default => $field,
        };
        if (!in_array($canonical, self::FIELDS, true)) {
            throw new MetadataScrapePolicyInvalid('未知刮削策略字段。');
        }
        return $canonical;
    }

    /**
     * 返回 Worker 可安全使用的策略；迁移窗口或精简契约库缺行时采用最保守默认值。
     *
     * 该回退不会用于管理 API，因而不会掩盖正式部署的迁移故障。自动任务只会保留文件元数据且关闭文本
     * 转换，不会扩大第三方覆盖或修改来源事实；迁移完成后的新服务实例会正常读取版本化设置。
     *
     * @return array{fieldPriorities:array<string,string>,convertTraditionalToSimplified:bool,version:int,updatedAt:string}
     */
    private function runtime(): array
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('system_settings')) {
            return $this->conservativeDefault();
        }
        try {
            return $this->get();
        } catch (MetadataScrapePolicyUnavailable) {
            return $this->conservativeDefault();
        }
    }

    /** @return array{fieldPriorities:array<string,string>,convertTraditionalToSimplified:bool,version:int,updatedAt:string} */
    private function conservativeDefault(): array
    {
        return [
            'fieldPriorities' => array_fill_keys(self::FIELDS, self::METADATA),
            'convertTraditionalToSimplified' => false,
            'version' => 0,
            'updatedAt' => '',
        ];
    }
}

/** 请求字段集合、类型或枚举不符合固定策略契约。 */
final class MetadataScrapePolicyInvalid extends \RuntimeException {}

/** 并发管理员已保存新版本，旧表单必须刷新后重新确认。 */
final class MetadataScrapePolicyConflict extends \RuntimeException {}

/** 迁移缺失或设置损坏，自动来源选择必须失败关闭。 */
final class MetadataScrapePolicyUnavailable extends \RuntimeException {}
