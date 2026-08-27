<?php

declare(strict_types=1);

namespace app\application\System;

use app\infrastructure\Audit\AuditLogger;
use DateTimeZone;
use JsonException;
use stdClass;
use support\Db;

/**
 * 管理后台首批站点级基础设置。
 *
 * 服务只处理固定 `site.basic` 对象，不读取部署密钥、路径或运行时环境变量。读取会严格验证数据库 JSON，
 * 损坏时失败关闭；更新在 SQLite 短事务中执行版本比较、整对象替换和审计写入，不产生网络或文件副作用。
 */
final readonly class BasicSystemSettingsService
{
    private const KEY = 'site.basic';
    private const LOCALES = ['zh-CN', 'en-US'];
    private const PAGE_SIZES = [20, 50, 100];

    public function __construct(private AuditLogger $audit = new AuditLogger())
    {
    }

    /**
     * 返回已校验的基础设置快照。
     *
     * 返回值包含当前版本和更新时间，供管理表单执行乐观锁更新。缺行、未知字段、损坏 JSON 或非法值
     * 都表示迁移/数据故障并失败关闭；读取无写入、网络或文件副作用，不能用代码默认值掩盖部署问题。
     *
     * @return array{siteName:string,defaultLocale:string,defaultTimezone:string,defaultPageSize:int,version:int,updatedAt:string}
     * @throws BasicSystemSettingsUnavailable 配置缺失、JSON 损坏或字段不符合当前契约
     */
    public function get(): array
    {
        $row = Db::table('system_settings')->where('setting_key', self::KEY)->first();
        if (!$row instanceof stdClass) {
            throw new BasicSystemSettingsUnavailable('基础设置不存在。');
        }

        return $this->map($row);
    }

    /**
     * 以 expectedVersion 原子替换完整配置并记录脱敏审计。
     *
     * actorUserId 必须来自实时认证主体，command 必须精确包含四个业务字段和 expectedVersion。审计只
     * 记录版本和变更数量，不保存站点名称等表单原值。CAS 冲突不自动重试且事务整体回滚，调用方必须
     * 刷新后重新确认，保证并发管理员不会互相覆盖。本方法不访问网络或文件系统，成功后可幂等地再次
     * 提交新版本，但旧版本重放始终返回冲突。
     *
     * @return array{siteName:string,defaultLocale:string,defaultTimezone:string,defaultPageSize:int,version:int,updatedAt:string}
     * @throws BasicSystemSettingsInvalid 字段集合、类型或业务值不符合固定契约
     * @throws BasicSystemSettingsConflict expectedVersion 已过期
     * @throws BasicSystemSettingsUnavailable 数据库中的配置缺失或损坏
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
            if ($changed !== 1) throw new BasicSystemSettingsConflict('基础设置版本已变化。');
            $changedCount = 0;
            foreach (array_keys($value) as $field) {
                if ($before[$field] !== $value[$field]) ++$changedCount;
            }
            $this->audit->record(
                $actorUserId,
                'system.settings.basic.update',
                'system_setting',
                self::KEY,
                'success',
                $requestId,
                [
                    'changed_count' => $changedCount,
                    'version' => $command['expectedVersion'] + 1,
                ],
            );

            return $this->get();
        });
    }

    /**
     * 将不可信命令映射为可持久化的固定对象。
     *
     * 未知或缺失字段、列表型 JSON、控制字符和非白名单枚举全部拒绝；时区必须是 PHP 当前时区数据库
     * 支持的 IANA 标识。返回值故意不包含 expectedVersion，确保版本只参与 CAS 而不会写入配置正文。
     *
     * @return array{siteName:string,defaultLocale:string,defaultTimezone:string,defaultPageSize:int}
     * @throws BasicSystemSettingsInvalid 命令无法安全映射
     */
    private function validate(array $command): array
    {
        $allowed = ['siteName', 'defaultLocale', 'defaultTimezone', 'defaultPageSize', 'expectedVersion'];
        if (array_is_list($command) || array_diff(array_keys($command), $allowed) !== []
            || count($command) !== count($allowed) || !is_string($command['siteName'] ?? null)
            || !is_string($command['defaultLocale'] ?? null) || !is_string($command['defaultTimezone'] ?? null)
            || !is_int($command['defaultPageSize'] ?? null)
            || !is_int($command['expectedVersion'] ?? null)
        ) {
            throw new BasicSystemSettingsInvalid('基础设置参数无效。');
        }
        $siteName = trim($command['siteName']);
        if (
            $siteName === ''
            || mb_strlen($siteName) > 80
            || preg_match('/[\x00-\x1F\x7F]/u', $siteName) === 1
            || !in_array($command['defaultLocale'], self::LOCALES, true)
            || mb_strlen($command['defaultTimezone']) > 64
            || !in_array($command['defaultTimezone'], DateTimeZone::listIdentifiers(), true)
            || !in_array($command['defaultPageSize'], self::PAGE_SIZES, true)
            || $command['expectedVersion'] < 1
        ) {
            throw new BasicSystemSettingsInvalid('基础设置参数无效。');
        }

        return [
            'siteName' => $siteName,
            'defaultLocale' => $command['defaultLocale'],
            'defaultTimezone' => $command['defaultTimezone'],
            'defaultPageSize' => $command['defaultPageSize'],
        ];
    }

    /**
     * 严格映射数据库行，未知或缺失字段都拒绝。
     *
     * 数据库内容仍被视为不可信持久状态，必须复用写入校验，防止损坏配置进入后台表单后被再次保存。
     * 解析失败只抛稳定领域异常，原始 JSON 和 JsonException 内容不得进入 HTTP 响应或审计。
     *
     * @return array{siteName:string,defaultLocale:string,defaultTimezone:string,defaultPageSize:int,version:int,updatedAt:string}
     * @throws BasicSystemSettingsUnavailable 行内容不符合当前配置契约
     */
    private function map(stdClass $row): array
    {
        try {
            $value = json_decode((string) $row->value_json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BasicSystemSettingsUnavailable('基础设置损坏。', previous: $exception);
        }
        $fields = ['siteName', 'defaultLocale', 'defaultTimezone', 'defaultPageSize'];
        if (
            !is_array($value)
            || array_is_list($value)
            || array_diff(array_keys($value), $fields) !== []
            || count($value) !== count($fields)
        ) {
            throw new BasicSystemSettingsUnavailable('基础设置损坏。');
        }
        try {
            $normalized = $this->validate($value + ['expectedVersion' => (int) $row->version]);
        } catch (BasicSystemSettingsInvalid $exception) {
            throw new BasicSystemSettingsUnavailable('基础设置损坏。', previous: $exception);
        }

        return $normalized + ['version' => (int) $row->version, 'updatedAt' => (string) $row->updated_at];
    }
}

/** 表示客户端设置命令不符合固定字段或取值契约。 */
final class BasicSystemSettingsInvalid extends \RuntimeException
{
}

/** 表示并发管理员已先保存新版本，当前旧表单不能继续覆盖。 */
final class BasicSystemSettingsConflict extends \RuntimeException
{
}

/** 表示迁移缺失或持久化配置损坏，调用方应返回可恢复的服务不可用状态。 */
final class BasicSystemSettingsUnavailable extends \RuntimeException
{
}
