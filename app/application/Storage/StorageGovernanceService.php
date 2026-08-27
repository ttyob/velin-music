<?php

declare(strict_types=1);

namespace app\application\Storage;

use app\application\Auth\AuthorizationDenied;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use stdClass;
use support\Db;

/**
 * 管理容量策略与挂载身份基线（ADMIN-STORAGE-005/006）。
 *
 * 固定根清单来自 StorageHealthService，客户端只能回传一次只读采样中的根键和身份字段。确认前会在
 * SQLite 事务外重新探测全部根，并与客户端采样逐字段比较；这样管理员不能确认一个已经被替换或通过
 * 请求路径注入的目录。事务内只写基线、版本和脱敏审计，不执行 stat、realpath 或目录 I/O。
 */
final readonly class StorageGovernanceService
{
    public const DEFAULT_ATTENTION_PERCENT = 10;
    public const DEFAULT_CRITICAL_PERCENT = 5;
    public const DEFAULT_SAFETY_RESERVE_BYTES = 536_870_912;

    public function __construct(
        private StorageHealthService $health = new StorageHealthService(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /** 返回应用了持久化阈值和挂载基线的完整管理员诊断。 */
    public function report(array $actor): array
    {
        $this->requireManager($actor);
        return $this->health->inspect($actor, [
            'policy' => $this->policy(),
            'baselines' => $this->baselines(),
        ]);
    }

    /** 后台总览复用同一策略采样，但只返回逻辑根、容量和状态，绝不泄露物理身份。 */
    public function summary(array $actor): array
    {
        $report = $this->report($actor);
        return [
            'status' => $report['status'],
            'roots' => array_map(static fn (array $root): array => [
                'key' => $root['key'], 'status' => $root['status'], 'readable' => $root['readable'],
                'writable' => $root['writable'], 'writeRequired' => $root['writeRequired'],
                'totalBytes' => $root['totalBytes'], 'freeBytes' => $root['freeBytes'],
            ], array_values(array_filter($report['roots'], static fn (array $root): bool =>
                in_array($root['key'], ['library', 'scrapeCache'], true)))),
        ];
    }

    /** 返回稳定默认值；仅为兼容迁移滚动窗口而容忍表尚未出现。 */
    public function policy(): array
    {
        try {
            /** @var stdClass|null $row */
            $row = Db::table('storage_governance_policy')->where('id', 1)->first();
        } catch (QueryException) {
            $row = null;
        }
        return [
            'attentionFreePercent' => $row instanceof stdClass ? (int) $row->attention_free_percent : self::DEFAULT_ATTENTION_PERCENT,
            'criticalFreePercent' => $row instanceof stdClass ? (int) $row->critical_free_percent : self::DEFAULT_CRITICAL_PERCENT,
            'safetyReserveBytes' => $row instanceof stdClass ? (int) $row->safety_reserve_bytes : self::DEFAULT_SAFETY_RESERVE_BYTES,
            'version' => $row instanceof stdClass ? (int) $row->version : 0,
            'updatedAt' => $row instanceof stdClass ? (string) $row->updated_at : null,
        ];
    }

    /** @return array<string,array<string,mixed>> 以固定根键索引确认事实。 */
    public function baselines(): array
    {
        try {
            /** @var list<stdClass> $rows */
            $rows = Db::table('storage_mount_baselines')->get()->all();
        } catch (QueryException) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->root_key] = [
                'resolvedPath' => (string) $row->resolved_path,
                'deviceId' => (string) $row->device_id,
                'mountPoint' => (string) $row->mount_point,
                'filesystemType' => (string) $row->filesystem_type,
                'version' => (int) $row->version,
                'confirmedAt' => (string) $row->confirmed_at,
            ];
        }
        return $result;
    }

    /** 以乐观锁更新三个全局阈值；单位和上下界均由服务端固定。 */
    public function updatePolicy(array $actor, int $attention, int $critical, int $reserve, int $expectedVersion, string $requestId): array
    {
        $this->requireManager($actor);
        if ($attention < 2 || $attention > 50 || $critical < 1 || $critical > 25 || $critical >= $attention
            || $reserve < 67_108_864 || $reserve > 1_099_511_627_776 || $expectedVersion < 1) {
            throw new StorageGovernanceInvalid('存储阈值无效。');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $attention, $critical, $reserve, $expectedVersion, $requestId, $now): void {
            $changed = Db::table('storage_governance_policy')->where('id', 1)->where('version', $expectedVersion)->update([
                'attention_free_percent' => $attention,
                'critical_free_percent' => $critical,
                'safety_reserve_bytes' => $reserve,
                'version' => Db::raw('version + 1'),
                'updated_by' => (string) $actor['id'],
                'updated_at' => $now,
            ]);
            if ($changed !== 1) throw new StorageGovernanceConflict('存储策略版本已变化。');
            $this->audit->record((string) $actor['id'], 'storage.policy.change', 'storage_policy', '1', 'success', $requestId, [
                'attentionFreePercent' => $attention,
                'criticalFreePercent' => $critical,
                'safetyReserveBytes' => $reserve,
            ]);
        });
        return $this->report($actor);
    }

    /**
     * 确认一个或多个固定登记根的当前身份。
     *
     * @param list<array<string,mixed>> $samples 页面刚显示的身份快照；不接受 path 之外的新增根或缺失字段。
     */
    public function confirm(array $actor, array $samples, string $requestId): array
    {
        $this->requireManager($actor);
        if ($samples === [] || count($samples) > 8) throw new StorageGovernanceInvalid('请选择需要确认的登记目录。');
        $fresh = $this->health->inspect($actor);
        $freshByKey = [];
        foreach ($fresh['roots'] as $root) $freshByKey[(string) $root['key']] = $root;
        $confirmed = [];
        $allowedFields = ['key', 'resolvedPath', 'deviceId', 'mountPoint', 'filesystemType'];
        foreach ($samples as $sample) {
            if (!is_array($sample) || count($sample) !== count($allowedFields)
                || array_diff($allowedFields, array_keys($sample)) !== []) {
                throw new StorageGovernanceInvalid('挂载确认字段无效。');
            }
            $key = $sample['key'];
            $root = is_string($key) ? ($freshByKey[$key] ?? null) : null;
            if (!is_array($root) || isset($confirmed[$key])) throw new StorageGovernanceInvalid('登记目录键无效或重复。');
            foreach (['resolvedPath', 'deviceId', 'mountPoint', 'filesystemType'] as $field) {
                if (!is_string($sample[$field]) || $sample[$field] === '' || !hash_equals((string) ($root[$field] ?? ''), $sample[$field])) {
                    throw new StorageGovernanceConflict('挂载身份已变化，请刷新诊断后重试。');
                }
            }
            $confirmed[$key] = $sample;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $confirmed, $requestId, $now): void {
            foreach ($confirmed as $key => $sample) {
                /** @var stdClass|null $current */
                $current = Db::table('storage_mount_baselines')->where('root_key', $key)->first(['version']);
                $values = [
                    'resolved_path' => $sample['resolvedPath'], 'device_id' => $sample['deviceId'],
                    'mount_point' => $sample['mountPoint'], 'filesystem_type' => $sample['filesystemType'],
                    'confirmed_by' => (string) $actor['id'], 'confirmed_at' => $now,
                ];
                if ($current instanceof stdClass) {
                    Db::table('storage_mount_baselines')->where('root_key', $key)->update($values + ['version' => Db::raw('version + 1')]);
                } else {
                    Db::table('storage_mount_baselines')->insert($values + ['root_key' => $key, 'version' => 1]);
                }
            }
            $this->audit->record((string) $actor['id'], 'storage.mount.confirm', 'storage_mount', null, 'success', $requestId, [
                'rootCount' => count($confirmed),
                'rootKeys' => implode(',', array_keys($confirmed)),
            ]);
        });
        return $this->report($actor);
    }

    /** 应用层重复授权，防止控制器或 Worker 未来绕过 HTTP 边界。 */
    private function requireManager(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('manage_storage', $capabilities, true)) throw new AuthorizationDenied('Storage governance requires manage_storage.');
    }
}
