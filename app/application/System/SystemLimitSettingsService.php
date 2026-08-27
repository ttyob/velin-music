<?php

declare(strict_types=1);

namespace app\application\System;

use app\application\User\UserRuntimeLimitExceeded;
use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use support\Db;

/**
 * 管理全站统一的播放、转码、下载和高成本任务限制（ADMIN-PAGE-028）。
 *
 * 所有限制只来自版本化 `system.limits` 对象，不再按账号读取或覆盖。调用方必须在执行新动作前读取
 * 最新快照；降低限制不会强制中断已经取得的播放或转码租约。后台上传属于存储管理员工作流，不读取
 * 此对象，也不设置上传开关、文件额度或会话额度。更新使用 SQLite 短事务和版本比较，不执行媒体、
 * 网络或文件操作；失败时整个配置与脱敏审计一起回滚。
 */
final readonly class SystemLimitSettingsService
{
    private const KEY = 'system.limits';

    public function __construct(private AuditLogger $audit = new AuditLogger())
    {
    }

    /**
     * 返回经过完整契约校验的全局限制快照。
     *
     * 缺行、损坏 JSON、未知字段或越界值均视为部署故障并失败关闭，不能用代码默认值静默放宽权限。
     * 本方法只读数据库，不产生锁、文件或进程副作用。
     *
     * @return array<string,int|bool|string>
     * @throws SystemLimitSettingsUnavailable 配置缺失或持久状态损坏
     */
    public function get(): array
    {
        $row = Db::table('system_settings')->where('setting_key', self::KEY)->first();
        if (!$row instanceof stdClass) {
            throw new SystemLimitSettingsUnavailable('系统限制不存在。');
        }

        return $this->map($row);
    }

    /**
     * 以 expectedVersion 原子替换全部全局限制。
     *
     * actorUserId 必须来自实时 `manage_system` 主体；命令必须精确包含全部字段。版本冲突不重试，调用方
     * 需重新读取后确认。成功只影响后续准入，不取消现有租约、会话、任务或 FFmpeg 进程。
     *
     * @param array<string,mixed> $command 管理端提交的完整固定对象和 expectedVersion
     * @return array<string,int|bool|string>
     * @throws SystemLimitSettingsInvalid 字段、类型或范围无效
     * @throws SystemLimitSettingsConflict 配置已被其他管理员更新
     */
    public function update(array $command, string $actorUserId, string $requestId): array
    {
        $value = $this->validate($command);

        return Db::transaction(function () use ($value, $command, $actorUserId, $requestId): array {
            $before = $this->get();
            $changed = Db::table('system_settings')->where('setting_key', self::KEY)
                ->where('version', $command['expectedVersion'])->update([
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'version' => Db::raw('version + 1'),
                    'updated_by' => $actorUserId,
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            if ($changed !== 1) {
                throw new SystemLimitSettingsConflict('系统限制版本已变化。');
            }
            $changedCount = 0;
            foreach (array_keys($value) as $field) {
                if ($before[$field] !== $value[$field]) {
                    ++$changedCount;
                }
            }
            $this->audit->record($actorUserId, 'system.settings.limits.update', 'system_setting', self::KEY,
                'success', $requestId, [
                    'changed_count' => $changedCount,
                    'version' => $command['expectedVersion'] + 1,
                ]);

            return $this->get();
        });
    }

    /**
     * 校验仍由系统设置控制的全局功能开关；角色 capability 与本开关必须同时允许。
     *
     * 调用方应在解析受保护对象前执行，避免通过错误差异枚举媒体。拒绝只携带稳定原因码，不泄露配置行。
     */
    public function assertFeatureAllowed(string $feature): void
    {
        $field = match ($feature) {
            'download' => 'downloadAllowed',
            default => throw new \InvalidArgumentException('UNKNOWN_SYSTEM_LIMIT_FEATURE'),
        };
        if ($this->get()[$field] !== true) {
            throw new UserRuntimeLimitExceeded(strtoupper($feature) . '_DISABLED_BY_SYSTEM_POLICY', 0, 0);
        }
    }

    /** @return list<string> 固定业务字段顺序同时用于写入、读取和 OpenAPI 契约。 */
    public static function fields(): array
    {
        return [
            'maxConcurrentStreams', 'maxConcurrentTranscodes', 'maxTranscodeBitrateKbps',
            'downloadAllowed', 'maxHighCostJobs',
        ];
    }

    /**
     * 将不可信命令规范为固定 JSON 对象。
     *
     * 未知和遗漏字段一律拒绝，避免新版客户端把服务器尚不理解的策略悄悄写入。已经退役的上传开关
     * 与上传额度也按未知字段拒绝，确保旧管理页面不能把后台上传重新纳入全局配置。
     *
     * @return array<string,int|bool>
     */
    private function validate(array $command): array
    {
        $expected = [...self::fields(), 'expectedVersion'];
        $actual = array_keys($command);
        sort($expected);
        sort($actual);
        if (array_is_list($command) || $actual !== $expected || !is_int($command['expectedVersion'] ?? null)
            || $command['expectedVersion'] < 1) {
            throw new SystemLimitSettingsInvalid('系统限制参数无效。');
        }
        $ranges = [
            'maxConcurrentStreams' => [1, 32],
            'maxConcurrentTranscodes' => [1, 16],
            'maxTranscodeBitrateKbps' => [32, 320],
            'maxHighCostJobs' => [1, 16],
        ];
        $value = [];
        foreach (self::fields() as $field) {
            $candidate = $command[$field] ?? null;
            if ($field === 'downloadAllowed') {
                if (!is_bool($candidate)) {
                    throw new SystemLimitSettingsInvalid('系统限制开关无效。');
                }
            } else {
                [$minimum, $maximum] = $ranges[$field];
                if (!is_int($candidate) || $candidate < $minimum || $candidate > $maximum) {
                    throw new SystemLimitSettingsInvalid('系统限制数值超出允许范围。');
                }
            }
            $value[$field] = $candidate;
        }
        return $value;
    }

    /** @return array<string,int|bool|string> */
    private function map(stdClass $row): array
    {
        try {
            $value = json_decode((string) $row->value_json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SystemLimitSettingsUnavailable('系统限制损坏。', previous: $exception);
        }
        $expected = self::fields();
        $actual = is_array($value) ? array_keys($value) : [];
        sort($expected);
        sort($actual);
        if (!is_array($value) || array_is_list($value) || $actual !== $expected) {
            throw new SystemLimitSettingsUnavailable('系统限制损坏。');
        }
        try {
            $normalized = $this->validate($value + ['expectedVersion' => (int) $row->version]);
        } catch (SystemLimitSettingsInvalid $exception) {
            throw new SystemLimitSettingsUnavailable('系统限制损坏。', previous: $exception);
        }

        return $normalized + ['version' => (int) $row->version, 'updatedAt' => (string) $row->updated_at];
    }
}

/** 表示管理端提交的全局限制对象不符合固定契约。 */
final class SystemLimitSettingsInvalid extends \RuntimeException
{
}

/** 表示全局限制版本已变化，旧表单必须刷新而不能覆盖。 */
final class SystemLimitSettingsConflict extends \RuntimeException
{
}

/** 表示全局限制缺失或持久化内容损坏，所有依赖入口应失败关闭。 */
final class SystemLimitSettingsUnavailable extends \RuntimeException
{
}
