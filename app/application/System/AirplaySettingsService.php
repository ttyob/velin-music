<?php

declare(strict_types=1);

namespace app\application\System;

use app\application\Airplay\AirplayUnavailable;
use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use support\Db;

/**
 * 管理全站 AirPlay companion 功能开关。
 *
 * 开关独立保存在版本化 `feature.airplay` 设置中，默认关闭。读取与更新严格验证固定 JSON 结构；更新
 * 使用短事务和版本 CAS，并记录不含设备、网络地址、进程路径或配对信息的审计事件。进程生命周期由
 * Controller 和单实例 Worker 在提交后协调，本服务不在数据库事务内启动或停止外部进程。
 */
final readonly class AirplaySettingsService implements AirplayFeatureGate
{
    private const KEY = 'feature.airplay';

    public function __construct(private AuditLogger $audit = new AuditLogger())
    {
    }

    /**
     * 返回后台编辑所需的持久设置快照。
     *
     * 缺行、损坏 JSON、未知字段或非法版本都视为迁移/数据故障，不能用代码默认值掩盖。
     *
     * @return array{enabled:bool,version:int,updatedAt:string}
     * @throws AirplaySettingsUnavailable 设置不存在或损坏
     */
    public function get(): array
    {
        try {
            $row = Db::table('system_settings')->where('setting_key', self::KEY)->first();
        } catch (\Throwable $exception) {
            throw new AirplaySettingsUnavailable('AirPlay 设置不可用。', previous: $exception);
        }
        if (!$row instanceof stdClass) {
            throw new AirplaySettingsUnavailable('AirPlay 设置不存在。');
        }
        return $this->map($row);
    }

    /**
     * 在业务副作用前确认 AirPlay 已启用；设置不可读时同样失败关闭。
     *
     * @throws AirplayUnavailable 设置关闭或暂不可用
     */
    public function assertEnabled(): void
    {
        try {
            if ($this->get()['enabled'] !== true) {
                throw new AirplayUnavailable('AIRPLAY_DISABLED', 'AirPlay 功能未启用。');
            }
        } catch (AirplayUnavailable $exception) {
            throw $exception;
        } catch (AirplaySettingsUnavailable $exception) {
            throw new AirplayUnavailable('AIRPLAY_SETTINGS_UNAVAILABLE', 'AirPlay 设置暂不可用。', $exception);
        }
    }

    /**
     * 以 expectedVersion 原子替换全站开关并记录脱敏审计。
     *
     * 请求只能包含 enabled 与 expectedVersion；版本冲突整体回滚。设置提交与进程启停不能原子化，调用
     * 方应在提交后立即协调，并由周期 Worker 最终收敛；协调失败不能伪造数据库回滚或自动重放请求。
     *
     * @param array<string,mixed> $command
     * @return array{enabled:bool,version:int,updatedAt:string}
     */
    public function update(array $command, string $actorUserId, string $requestId): array
    {
        $keys = array_keys($command);
        sort($keys);
        if (array_is_list($command) || $keys !== ['enabled', 'expectedVersion']
            || !is_bool($command['enabled'] ?? null) || !is_int($command['expectedVersion'] ?? null)
            || $command['expectedVersion'] < 1) {
            throw new AirplaySettingsInvalid('AirPlay 设置参数无效。');
        }

        return Db::transaction(function () use ($command, $actorUserId, $requestId): array {
            $before = $this->get();
            $changed = Db::table('system_settings')->where('setting_key', self::KEY)
                ->where('version', $command['expectedVersion'])->update([
                    'value_json' => json_encode(['enabled' => $command['enabled']], JSON_THROW_ON_ERROR),
                    'version' => Db::raw('version + 1'),
                    'updated_by' => $actorUserId,
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            if ($changed !== 1) {
                throw new AirplaySettingsConflict('AirPlay 设置版本已变化。');
            }
            $this->audit->record($actorUserId, 'system.settings.airplay.update', 'system_setting', self::KEY,
                'success', $requestId, [
                    'enabled' => $command['enabled'],
                    'version' => $command['expectedVersion'] + 1,
                    'changed' => $before['enabled'] !== $command['enabled'],
                ]);
            return $this->get();
        });
    }

    /** @return array{enabled:bool,version:int,updatedAt:string} */
    private function map(stdClass $row): array
    {
        try {
            $value = json_decode((string) $row->value_json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AirplaySettingsUnavailable('AirPlay 设置损坏。', previous: $exception);
        }
        if (!is_array($value) || array_is_list($value) || array_keys($value) !== ['enabled']
            || !is_int((int) $row->version) || (int) $row->version < 1
            || !is_bool($value['enabled'] ?? null)) {
            throw new AirplaySettingsUnavailable('AirPlay 设置损坏。');
        }
        return [
            'enabled' => $value['enabled'],
            'version' => (int) $row->version,
            'updatedAt' => (string) $row->updated_at,
        ];
    }
}

/** 表示 AirPlay 系统设置缺失或持久内容损坏。 */
final class AirplaySettingsUnavailable extends \RuntimeException
{
}

/** 表示 AirPlay 系统设置命令不符合固定字段契约。 */
final class AirplaySettingsInvalid extends \RuntimeException
{
}

/** 表示管理员提交的 AirPlay 设置版本已过期。 */
final class AirplaySettingsConflict extends \RuntimeException
{
}
