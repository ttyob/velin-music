<?php

declare(strict_types=1);

namespace app\application\System;

use app\application\Dlna\DlnaUnavailable;
use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use support\Db;

/**
 * 管理全站 DLNA 功能开关。
 *
 * 开关独立保存在版本化 `feature.dlna` 设置中，默认值由迁移写入为关闭。读取和更新都严格验证固定
 * JSON 结构；更新使用短事务和版本 CAS，并记录不含设备、账号或网络地址的审计事件。DLNA 业务调用
 * 必须在执行 helper、SSDP 或 SOAP 前读取该开关，关闭时失败关闭且不产生外部副作用。
 */
final readonly class DlnaSettingsService
{
    private const KEY = 'feature.dlna';

    public function __construct(private AuditLogger $audit = new AuditLogger())
    {
    }

    /**
     * 返回后台编辑所需的脱敏开关快照。
     *
     * 缺行、损坏 JSON、未知字段或非法版本都视为迁移/数据故障；不会用代码默认值掩盖故障。
     *
     * @return array{enabled:bool,version:int,updatedAt:string}
     * @throws DlnaSettingsUnavailable 设置不存在或损坏
     */
    public function get(): array
    {
        try {
            $row = Db::table('system_settings')->where('setting_key', self::KEY)->first();
        } catch (\Throwable $exception) {
            throw new DlnaSettingsUnavailable('DLNA 设置不可用。', previous: $exception);
        }
        if (!$row instanceof stdClass) {
            throw new DlnaSettingsUnavailable('DLNA 设置不存在。');
        }
        return $this->map($row);
    }

    /**
     * 判断 DLNA 是否启用；关闭时抛出稳定领域错误，调用方因此不会启动网络 helper。
     *
     * @throws DlnaUnavailable 设置关闭或暂不可用
     */
    public function assertEnabled(): void
    {
        try {
            if ($this->get()['enabled'] !== true) {
                throw new DlnaUnavailable('DLNA_DISABLED', 'DLNA 功能未启用。');
            }
        } catch (DlnaUnavailable $exception) {
            throw $exception;
        } catch (DlnaSettingsUnavailable $exception) {
            throw new DlnaUnavailable('DLNA_SETTINGS_UNAVAILABLE', 'DLNA 设置暂不可用。', $exception);
        }
    }

    /**
     * 以 expectedVersion 原子替换全站开关并记录审计。
     *
     * 请求只能包含 enabled 与 expectedVersion；版本冲突整体回滚，管理员必须刷新后重新确认。该操作
     * 设置提交后由管理员 API 协调 helper daemon 的启停；本服务本身不执行网络操作。
     *
     * @param array<string,mixed> $command
     * @return array{enabled:bool,version:int,updatedAt:string}
     * @throws DlnaSettingsInvalid 参数无效
     * @throws DlnaSettingsConflict 版本已变化
     * @throws DlnaSettingsUnavailable 设置不存在或损坏
     */
    public function update(array $command, string $actorUserId, string $requestId): array
    {
        $keys = array_keys($command);
        sort($keys);
        if (array_is_list($command) || $keys !== ['enabled', 'expectedVersion']
            || !is_bool($command['enabled'] ?? null) || !is_int($command['expectedVersion'] ?? null)
            || $command['expectedVersion'] < 1) {
            throw new DlnaSettingsInvalid('DLNA 设置参数无效。');
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
                throw new DlnaSettingsConflict('DLNA 设置版本已变化。');
            }
            $this->audit->record($actorUserId, 'system.settings.dlna.update', 'system_setting', self::KEY,
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
            throw new DlnaSettingsUnavailable('DLNA 设置损坏。', previous: $exception);
        }
        if (!is_array($value) || array_is_list($value) || array_keys($value) !== ['enabled']
            || !is_bool($value['enabled']) || !is_int((int) $row->version) || (int) $row->version < 1) {
            throw new DlnaSettingsUnavailable('DLNA 设置损坏。');
        }
        return [
            'enabled' => $value['enabled'],
            'version' => (int) $row->version,
            'updatedAt' => (string) $row->updated_at,
        ];
    }

}

/** 表示 DLNA 系统设置缺失或持久内容损坏。 */
final class DlnaSettingsUnavailable extends \RuntimeException
{
}

/** 表示 DLNA 系统设置命令不符合固定字段契约。 */
final class DlnaSettingsInvalid extends \RuntimeException
{
}

/** 表示管理员提交的 DLNA 设置版本已过期。 */
final class DlnaSettingsConflict extends \RuntimeException
{
}
