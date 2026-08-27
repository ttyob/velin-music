<?php

declare(strict_types=1);

namespace app\application\Dlna;

use app\application\Media\MediaStreamService;
use stdClass;
use support\Db;

/**
 * 管理当前账号的 DLNA 设备历史、输出格式和可恢复活动快照。
 *
 * 所有查询都从已认证 actor 提取 user_id，不接受独立账号选择器。持久化字段刻意排除设备 IP、Location、
 * routeToken、媒体 URL 和票据；历史 UDN 只能在后续实时 play+cast 授权下用于服务端加密路由缓存或安全
 * SSDP 回退。活动快照用于恢复界面和选择控制目标，不证明设备仍在线或仍在播放，恢复后必须重新 status。
 * SQLite 写事务保持短小且不包含网络操作；MySQL 迁移时可保留相同仓储接口和复合唯一键。
 */
final readonly class DlnaAccountStateService
{
    private const HISTORY_LIMIT = 32;
    private const POSITION_WRITE_INTERVAL_SECONDS = 10;

    public function __construct(private MediaStreamService $media = new MediaStreamService())
    {
    }

    /**
     * 返回当前账号的去地址化历史、最后成功格式和可空活动快照。
     *
     * 活动歌曲会通过 MediaStreamService 重新检查账号状态、play/cast 之外的音乐库授权、媒体可用性和文件
     * 身份；失败时按账号删除陈旧快照并返回 null，不泄露已撤权歌曲。设备历史本身不读取网络，也不返回
     * routeToken。没有保存过格式的账号返回 raw，以便首次投放直接读取原始文件；已有偏好逐值返回，不因
     * 默认策略升级而覆盖用户主动选择的 MP3/AAC/Opus。该 GET 只可能产生一次陈旧快照清理短事务。
     *
     * @param array<string,mixed> $actor 已实时认证并具有 play+cast 的账号投影
     * @return array{devices:list<array{id:string,name:string,manufacturer:?string,model:?string,routeToken:null}>,preferredFormat:string,playback:?array<string,mixed>}
     */
    public function snapshot(array $actor): array
    {
        $userId = $this->userId($actor);
        /** @var list<stdClass> $history */
        $history = Db::table('user_dlna_devices')->where('user_id', $userId)
            ->orderByRaw('CASE WHEN last_used_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_used_at')->orderByDesc('last_seen_at')->orderBy('device_id')
            ->limit(self::HISTORY_LIMIT)->get(['device_id', 'device_name', 'manufacturer', 'model'])->all();
        /** @var stdClass|null $preference */
        $preference = Db::table('user_dlna_preferences')->where('user_id', $userId)->first(['preferred_format']);
        /** @var stdClass|null $active */
        $active = Db::table('user_dlna_playback_states')->where('user_id', $userId)->first();
        $playback = null;
        if ($active instanceof stdClass) {
            try {
                $media = $this->media->resolve($actor, (string) $active->song_id);
                $duration = max(0, $media->durationMs);
                $position = max(0, (int) $active->position_ms);
                if ($duration > 0) $position = min($position, $duration);
                $playback = [
                    'deviceId' => (string) $active->device_id,
                    'deviceName' => (string) $active->device_name,
                    'songId' => (string) $active->song_id,
                    'format' => (string) $active->output_format,
                    'rendererState' => [
                        'transportState' => (string) $active->transport_state,
                        'positionMs' => $position,
                        'durationMs' => $duration,
                        'volume' => $active->volume === null ? null : (int) $active->volume,
                        'deliveredBytes' => null,
                        'totalBytes' => null,
                    ],
                    'observedAt' => (string) $active->observed_at,
                    'stateVersion' => (int) $active->state_version,
                ];
            } catch (\Throwable) {
                Db::table('user_dlna_playback_states')->where('user_id', $userId)->delete();
            }
        }
        return [
            'devices' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->device_id,
                'name' => (string) $row->device_name,
                'manufacturer' => $row->manufacturer === null ? null : (string) $row->manufacturer,
                'model' => $row->model === null ? null : (string) $row->model,
                'routeToken' => null,
            ], $history),
            'preferredFormat' => $preference instanceof stdClass ? (string) $preference->preferred_format : 'raw',
            'playback' => $playback,
        ];
    }

    /**
     * 把一次可信发现结果保存为当前账号的设备历史，并裁剪为最近 32 条。
     *
     * devices 必须已经由 DlnaService 校验 UDN、名称、白名单并删除地址字段；本方法再次只取固定展示字段，
     * routeToken 不进入 SQL。复合主键收敛同账号并发发现，不同账号互不覆盖。裁剪删除的只是可重建设备
     * 摘要，不影响活动状态、Redis 路由、账号租约或 Renderer。
     *
     * @param list<array{id:string,name:string,manufacturer:?string,model:?string,routeToken:?string}> $devices
     */
    public function rememberDevices(array $actor, array $devices): void
    {
        if ($devices === []) return;
        $userId = $this->userId($actor);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($devices, $now, $userId): void {
            foreach (array_slice($devices, 0, 256) as $device) {
                Db::table('user_dlna_devices')->upsert([[
                    'user_id' => $userId,
                    'device_id' => $device['id'],
                    'device_name' => mb_substr(trim($device['name']), 0, 200),
                    'manufacturer' => $this->text($device['manufacturer'] ?? null),
                    'model' => $this->text($device['model'] ?? null),
                    'last_seen_at' => $now,
                    'last_used_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['user_id', 'device_id'], ['device_name', 'manufacturer', 'model', 'last_seen_at', 'updated_at']);
            }
            $keep = Db::table('user_dlna_devices')->where('user_id', $userId)
                ->orderByRaw('CASE WHEN last_used_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('last_used_at')->orderByDesc('last_seen_at')->limit(self::HISTORY_LIMIT)
                ->pluck('device_id')->all();
            if ($keep !== []) {
                Db::table('user_dlna_devices')->where('user_id', $userId)->whereNotIn('device_id', $keep)->delete();
            }
        });
    }

    /**
     * 在 Renderer 已确认 Play 后保存活动绑定和最后成功格式。
     *
     * 外部 SOAP 已经发生，因此本方法失败不能回滚音响；调用者应记录内部错误但仍向用户返回投放成功。
     * 同一账号只有一行活动状态，后一次成功投放完整替换前一次。设备名称来自该账号历史，不存在时使用
     * 中性名称且不把 UDN当展示名。位置从零开始，后续 status/控制命令再写入可信快照。
     */
    public function activate(array $actor, string $deviceId, string $songId, string $format, int $durationMs): void
    {
        $userId = $this->userId($actor);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($deviceId, $durationMs, $format, $now, $songId, $userId): void {
            /** @var stdClass|null $history */
            $history = Db::table('user_dlna_devices')->where('user_id', $userId)->where('device_id', $deviceId)
                ->first(['device_name']);
            $name = $history instanceof stdClass ? (string) $history->device_name : 'DLNA Renderer';
            Db::table('user_dlna_preferences')->upsert([[
                'user_id' => $userId, 'preferred_format' => $format, 'updated_at' => $now,
            ]], ['user_id'], ['preferred_format', 'updated_at']);
            Db::table('user_dlna_playback_states')->upsert([[
                'user_id' => $userId,
                'device_id' => $deviceId,
                'device_name' => $name,
                'song_id' => $songId,
                'output_format' => $format,
                'duration_ms' => max(0, $durationMs),
                'position_ms' => 0,
                'transport_state' => 'PLAYING',
                'volume' => null,
                'state_version' => 1,
                'observed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['user_id'], [
                'device_id', 'device_name', 'song_id', 'output_format', 'duration_ms', 'position_ms',
                'transport_state', 'volume', 'state_version', 'observed_at', 'updated_at',
            ]);
            Db::table('user_dlna_devices')->where('user_id', $userId)->where('device_id', $deviceId)
                ->update(['last_used_at' => $now, 'updated_at' => $now]);
        });
    }

    /**
     * 持久化一次 Renderer 事实快照，并限制位置不超过当前曲库时长。
     *
     * 高频 PLAYING 轮询只有状态/音量变化、位置跨过十秒窗口或上次观察超过十秒才写 SQLite；跳过写入最多
     * 让崩溃恢复位置滞后十秒，不影响内存 UI 或设备事实。更新同时匹配 user_id/device_id，迟到的上一台
     * 设备状态不能覆盖后一次成功投放。STOPPED 由调用方删除活动绑定而不保留伪活动记录。
     *
     * @param array{transportState:string,positionMs:int,durationMs:int,volume:?int} $state
     */
    public function observe(array $actor, string $deviceId, array $state): void
    {
        $userId = $this->userId($actor);
        /** @var stdClass|null $current */
        $current = Db::table('user_dlna_playback_states')->where('user_id', $userId)
            ->where('device_id', $deviceId)->first();
        if (!$current instanceof stdClass) return;
        // 曲库时长在 activate 时已绑定当前 song；设备切歌过渡期可能迟到返回上一首更长 duration，只有曲库
        // 时长未知时才采用 Renderer 值，不能用 max 把上一首边界永久写入新歌快照。
        $duration = (int) $current->duration_ms > 0
            ? (int) $current->duration_ms
            : max(0, $state['durationMs']);
        $position = max(0, $state['positionMs']);
        if ($duration > 0) $position = min($position, $duration);
        $volume = $state['volume'];
        $recent = (strtotime((string) $current->observed_at) ?: 0) > time() - self::POSITION_WRITE_INTERVAL_SECONDS;
        $onlySmallPositionChange = (string) $current->transport_state === $state['transportState']
            && ($current->volume === null ? null : (int) $current->volume) === $volume
            && abs((int) $current->position_ms - $position) < 10_000;
        if ($recent && $onlySmallPositionChange) return;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('user_dlna_playback_states')->where('user_id', $userId)->where('device_id', $deviceId)->update([
            'duration_ms' => $duration,
            'position_ms' => $position,
            'transport_state' => mb_substr($state['transportState'], 0, 40),
            'volume' => $volume,
            'state_version' => (int) $current->state_version + 1,
            'observed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 在 SOAP 成功后把 pause/resume/seek/volume 的确定副作用写入活动快照。
     *
     * 本方法不创建缺失绑定，也不接受 stop；stop 必须调用 deactivate 删除活动事实。更新按账号和设备双重
     * 限定，失败只影响恢复精度，不能重发已经成功的设备命令。
     */
    public function applyControl(array $actor, string $deviceId, string $operation, ?int $value): void
    {
        if (!in_array($operation, ['pause', 'resume', 'seek', 'volume'], true)) return;
        $userId = $this->userId($actor);
        $updates = [
            'state_version' => Db::raw('state_version + 1'),
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if ($operation === 'pause') $updates['transport_state'] = 'PAUSED_PLAYBACK';
        if ($operation === 'resume') $updates['transport_state'] = 'PLAYING';
        if ($operation === 'seek' && $value !== null) {
            /** @var stdClass|null $active */
            $active = Db::table('user_dlna_playback_states')->where('user_id', $userId)
                ->where('device_id', $deviceId)->first(['duration_ms']);
            $position = max(0, $value);
            if ($active instanceof stdClass && (int) $active->duration_ms > 0) {
                $position = min($position, (int) $active->duration_ms);
            }
            $updates['position_ms'] = $position;
        }
        if ($operation === 'volume' && $value !== null) $updates['volume'] = max(0, min(100, $value));
        Db::table('user_dlna_playback_states')->where('user_id', $userId)
            ->where('device_id', $deviceId)->update($updates);
    }

    /** 删除当前账号与指定设备匹配的活动绑定；历史和格式偏好保持不变。 */
    public function deactivate(array $actor, string $deviceId): void
    {
        Db::table('user_dlna_playback_states')->where('user_id', $this->userId($actor))
            ->where('device_id', $deviceId)->delete();
    }

    /** 从可信认证投影提取账号 ULID，禁止跨账号选择器进入 SQL。 */
    private function userId(array $actor): string
    {
        $userId = (string) ($actor['id'] ?? '');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $userId) !== 1) {
            throw new DlnaUnavailable('DLNA_PERMISSION_DENIED', 'DLNA 账号身份无效。');
        }
        return $userId;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 200) : null;
    }
}
