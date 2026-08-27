<?php

declare(strict_types=1);

namespace app\application\Playback;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;

/**
 * 自动登记并管理每账号播放器档案，把设备覆盖安全地合并进播放协商。
 *
 * Web playerId 只接受与租约相同的 16-64 位字符集；Subsonic 客户端名先做 SHA-256 匹配摘要，同时作为
 * 可编辑初始显示名。API 不返回内部匹配键。touch 使用唯一键幂等登记并只更新 last_seen_at，不覆盖用户名称和设置。
 * 列表、编辑、删除始终按账号过滤；删除只重置档案，下次设备访问会以继承模式重新创建。
 */
final readonly class PlayerProfileService
{
    private const BITRATES = [64, 96, 128, 160, 192, 256, 320];
    private const LAST_SEEN_WRITE_INTERVAL_SECONDS = 300;

    public function __construct(private AuditLogger $audit = new AuditLogger())
    {
    }

    /**
     * 列出当前账号最近使用的播放器档案。
     *
     * 查询在数据库层按 user_id 隔离并限制为 100 条，返回投影刻意排除内部 player_key，避免浏览器
     * 稳定标识或 Subsonic 客户端摘要成为可枚举设备指纹。本方法只读且不刷新最近访问时间。
     *
     * @param array<string,mixed> $actor 已认证 Session 的账号投影
     * @return list<array<string,mixed>>
     */
    public function list(array $actor): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('user_player_profiles')->where('user_id', $this->userId($actor))
            ->orderByDesc('last_seen_at')->orderBy('id')->limit(100)->get([
                'id', 'source', 'name', 'preferred_format', 'max_bitrate_kbps',
                'last_seen_at', 'version', 'created_at', 'updated_at',
            ])->all();
        return array_map($this->project(...), $rows);
    }

    /**
     * 在 Web 拉流时幂等登记浏览器播放器，并返回该设备的播放覆盖值。
     *
     * playerId 必须由当前浏览器播放器持久边界生成并符合租约字符集；无效值直接失败，不能降级为账号
     * 默认值后继续播放，否则攻击者可以制造无界设备记录。登记只影响档案最近访问时间，不修改队列或历史。
     *
     * @return array{preferredFormat:?string,maxBitrateKbps:?int}
     */
    public function webOverrides(array $actor, string $playerId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $playerId) !== 1) throw new PlayerProfileInvalid('播放器标识无效。');
        return $this->touch($this->userId($actor), $playerId, 'web', 'Web Browser');
    }

    /**
     * 在 Subsonic 请求中登记客户端，并返回该客户端的默认播放覆盖值。
     *
     * 客户端名只用于生成账号内匹配摘要和首次显示名，不保存密码、salt、token 或请求参数。相同名称在
     * 同一账号内幂等，不同账号仍由 user_id 隔离；非法名称以领域错误结束当前协议请求。
     *
     * @return array{preferredFormat:?string,maxBitrateKbps:?int}
     */
    public function subsonicOverrides(array $actor, mixed $client): array
    {
        if (!is_string($client) || trim($client) === '' || strlen($client) > 64) {
            throw new PlayerProfileInvalid('Subsonic 客户端标识无效。');
        }
        $name = trim($client);
        return $this->touch($this->userId($actor), 'subsonic-' . hash('sha256', $name), 'subsonic', $name);
    }

    /**
     * 把 Subsonic 设备覆盖作为客户端未显式提交字段的默认值。
     *
     * 协议的单次 format/maxBitRate 命令优先，避免用户保存的 raw 与客户端正偏移请求组成矛盾参数；管理员
     * 账号上限仍由 SubsonicTranscodeService 在最终协商时强制执行。返回新数组，不修改 Controller 合并参数。
     *
     * @param array<string,mixed> $parameters
     * @return array<string,mixed>
     */
    public function applySubsonicParameters(array $actor, array $parameters): array
    {
        // 领域服务测试和未来内部调用可以在没有协议客户端字段时复用媒体发送；只有真实 Subsonic 请求
        // 携带 `c` 时才登记设备。Controller 认证层仍负责协议必填校验，这里不把缺省内部调用误建档案。
        if (!array_key_exists('c', $parameters)) return $parameters;
        $overrides = $this->subsonicOverrides($actor, $parameters['c'] ?? null);
        if (!array_key_exists('format', $parameters) && $overrides['preferredFormat'] !== null) {
            $parameters['format'] = $overrides['preferredFormat'];
        }
        if (!array_key_exists('maxBitRate', $parameters) && $overrides['maxBitrateKbps'] !== null
            && ($parameters['format'] ?? null) !== 'raw') {
            $parameters['maxBitRate'] = $overrides['maxBitrateKbps'];
        }
        return $parameters;
    }

    /**
     * 版本化保存名称和可空覆盖；不接受 player_key、source、userId 或最近访问时间。
     *
     * expectedVersion 与账号范围共同组成乐观锁。raw 表示强制原始直放，因此码率没有语义并统一清空，
     * 防止后来切换回自动协商时意外复用界面中的陈旧值。更新和审计位于同一短事务；冲突不产生审计记录，
     * 外部转码上限不在这里快照，实际拉流时仍以管理员当前限制为准。
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function update(array $actor, string $profileId, array $command, string $requestId): array
    {
        $this->ulid($profileId);
        $userId = $this->userId($actor);
        $version = $command['expectedVersion'] ?? null;
        $name = $command['name'] ?? null;
        $format = $command['preferredFormat'] ?? null;
        $bitrate = $command['maxBitrateKbps'] ?? null;
        if (!is_int($version) || $version < 1 || !is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 80
            || ($format !== null && !in_array($format, ['raw', 'mp3', 'aac', 'opus'], true))
            || ($bitrate !== null && (!is_int($bitrate) || !in_array($bitrate, self::BITRATES, true)))) {
            throw new PlayerProfileInvalid('播放器配置无效。');
        }
        if ($format === 'raw') $bitrate = null;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::transaction(function () use ($profileId, $userId, $version, $name, $format, $bitrate, $now, $requestId): int {
            $affected = Db::table('user_player_profiles')->where('id', $profileId)->where('user_id', $userId)
                ->where('version', $version)->update([
                    'name' => trim($name), 'preferred_format' => $format, 'max_bitrate_kbps' => $bitrate,
                    'version' => $version + 1, 'updated_at' => $now,
                ]);
            if ($affected !== 1) throw new PlayerProfileConflict('播放器配置已变化，请刷新后重试。');
            $this->audit->record($userId, 'player_profile.update', 'user_player_profile', $profileId,
                'success', $requestId, ['format' => $format, 'maxBitrateKbps' => $bitrate, 'version' => $version + 1]);
            return $affected;
        });
        if ($changed !== 1) throw new PlayerProfileConflict('播放器配置已变化，请刷新后重试。');
        $row = Db::table('user_player_profiles')->where('id', $profileId)->where('user_id', $userId)->first();
        if (!$row instanceof stdClass) throw new PlayerProfileConflict('播放器配置已被重置，请刷新后重试。');
        return $this->project($row);
    }

    /**
     * 删除当前账号档案并审计。
     *
     * 删除按 user_id 过滤且对不存在记录幂等；它不撤销 Session、终止当前音频、删除队列或播放历史。
     * 设备下次访问会以默认名称和继承设置重新登记。删除与审计在同一短事务内提交。
     */
    public function delete(array $actor, string $profileId, string $requestId): void
    {
        $this->ulid($profileId);
        $userId = $this->userId($actor);
        Db::transaction(function () use ($profileId, $userId, $requestId): void {
            $changed = Db::table('user_player_profiles')->where('id', $profileId)->where('user_id', $userId)->delete();
            if ($changed === 0) return;
            $this->audit->record($userId, 'player_profile.delete', 'user_player_profile', $profileId,
                'success', $requestId);
        });
    }

    /**
     * 幂等创建内部播放器记录，并以五分钟窗口节流最近访问写入。
     *
     * 唯一键负责收敛同账号并发的首次请求；insertOrIgnore 后始终重新读取实际行，不能假设本次生成的 ULID
     * 获胜。已有记录只在 last_seen_at 超过窗口时更新，从而避免每个音频请求放大 SQLite 写锁；这最多让
     * 列表中的最近时间滞后五分钟，不影响格式协商。名称和用户保存的覆盖值绝不由访问刷新覆盖。
     *
     * @return array{preferredFormat:?string,maxBitrateKbps:?int}
     */
    private function touch(string $userId, string $key, string $source, string $defaultName): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $created = Db::table('user_player_profiles')->insertOrIgnore([
            'id' => (string) new Ulid(), 'user_id' => $userId, 'player_key' => $key,
            'source' => $source, 'name' => mb_substr($defaultName, 0, 80),
            'preferred_format' => null, 'max_bitrate_kbps' => null, 'last_seen_at' => $now,
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($created === 0) {
            $writeBefore = gmdate('Y-m-d\TH:i:s\Z', time() - self::LAST_SEEN_WRITE_INTERVAL_SECONDS);
            Db::table('user_player_profiles')->where('user_id', $userId)->where('player_key', $key)
                ->where('last_seen_at', '<=', $writeBefore)->update(['last_seen_at' => $now]);
        }
        /** @var stdClass $row */
        $row = Db::table('user_player_profiles')->where('user_id', $userId)->where('player_key', $key)
            ->first(['preferred_format', 'max_bitrate_kbps']);
        return [
            'preferredFormat' => $row->preferred_format === null ? null : (string) $row->preferred_format,
            'maxBitrateKbps' => $row->max_bitrate_kbps === null ? null : (int) $row->max_bitrate_kbps,
        ];
    }

    /** @return array<string,mixed> */
    private function project(stdClass $row): array
    {
        return [
            'id' => (string) $row->id, 'source' => (string) $row->source, 'name' => (string) $row->name,
            'preferredFormat' => $row->preferred_format === null ? null : (string) $row->preferred_format,
            'maxBitrateKbps' => $row->max_bitrate_kbps === null ? null : (int) $row->max_bitrate_kbps,
            'lastSeenAt' => (string) $row->last_seen_at, 'version' => (int) $row->version,
            'createdAt' => (string) $row->created_at, 'updatedAt' => (string) $row->updated_at,
        ];
    }

    private function userId(array $actor): string
    {
        $id = (string) ($actor['id'] ?? '');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) throw new PlayerProfileInvalid('账号标识无效。');
        return $id;
    }

    private function ulid(string $id): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) throw new PlayerProfileInvalid('播放器档案不存在。');
    }
}
