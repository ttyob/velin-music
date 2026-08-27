<?php

declare(strict_types=1);

namespace app\application\Preference;

use app\application\System\SystemLimitSettingsService;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Throwable;

/**
 * 管理当前账号的播放偏好，并把用户音质选择与管理员实时上限组合成可执行配置。
 *
 * 本服务不接受 URL 或请求体中的 userId，唯一行选择器来自已复验 Session。偏好保存值允许高于管理员
 * 当前上限，以便管理员日后放宽策略时恢复用户原选择；公开快照额外返回 effectiveMaxBitrateKbps，
 * 每次真实拉流也重新计算该值，浏览器缓存不能绕过后续策略变更。历史 `auto` 是三档界面遗留值，
 * 读取时统一投影为原始音质 `direct`，新写入只产生 `direct|transcode`，避免界面与真实播放策略分叉。
 */
final class PlaybackPreferenceService
{
    public function __construct(
        private readonly AuditLogger $auditLogger = new AuditLogger(),
        private readonly SystemLimitSettingsService $limits = new SystemLimitSettingsService(),
    ) {
    }

    /**
     * 返回当前账号的路径无关播放配置及动态管理员上限。
     *
     * @param array<string,mixed> $actor 已复验的当前 Session 投影。
     * @return array{streamMode:string,transcodeFormat:string,maxBitrateKbps:int,effectiveMaxBitrateKbps:int,administratorMaxBitrateKbps:int,replayGainMode:string,preventClipping:bool,version:int,updatedAt:string}
     */
    public function snapshot(array $actor): array
    {
        $userId = $this->userId($actor);
        /** @var stdClass|null $row */
        $row = Db::table('user_preferences')->where('user_id', $userId)->first([
            'stream_mode', 'transcode_format', 'max_bitrate_kbps', 'replaygain_mode',
            'replaygain_prevent_clipping', 'version', 'updated_at',
        ]);
        if (!$row instanceof stdClass) {
            throw new UserPreferenceConflict('个人设置不存在，请重新登录后再试。');
        }

        $administratorMax = (int) $this->limits->get()['maxTranscodeBitrateKbps'];
        $selectedMax = max(16, min(320, (int) $row->max_bitrate_kbps));
        $streamMode = (string) $row->stream_mode === 'transcode' ? 'transcode' : 'direct';

        return [
            'streamMode' => $streamMode,
            'transcodeFormat' => (string) $row->transcode_format,
            'maxBitrateKbps' => $selectedMax,
            'effectiveMaxBitrateKbps' => min($selectedMax, $administratorMax),
            'administratorMaxBitrateKbps' => $administratorMax,
            'replayGainMode' => $streamMode === 'transcode' ? (string) $row->replaygain_mode : 'off',
            'preventClipping' => (int) $row->replaygain_prevent_clipping === 1,
            'version' => (int) $row->version,
            'updatedAt' => (string) $row->updated_at,
        ];
    }

    /**
     * 在共享偏好版本锁下原子保存完整播放配置。
     *
     * SQLite 的 `BEGIN IMMEDIATE` 在读取版本前取得写保留，确保资料、主题、语言和播放设置的并发写入
     * 中只有一个成功。审计只记录封闭枚举、整数和布尔值，不包含歌曲、文件、Session 或请求正文。
     *
     * @param array<string,mixed> $actor 已复验的当前 Session 投影。
     * @param array{expectedVersion:int,streamMode:string,transcodeFormat:string,maxBitrateKbps:int,replayGainMode:string,preventClipping:bool} $command
     * @return array{streamMode:string,transcodeFormat:string,maxBitrateKbps:int,effectiveMaxBitrateKbps:int,administratorMaxBitrateKbps:int,replayGainMode:string,preventClipping:bool,version:int,updatedAt:string}
     */
    public function update(array $actor, array $command, string $requestId): array
    {
        $userId = $this->userId($actor);
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $command['expectedVersion'] + 1;
            $affected = Db::table('user_preferences')->where('user_id', $userId)
                ->where('version', $command['expectedVersion'])->update([
                    'stream_mode' => $command['streamMode'],
                    'transcode_format' => $command['transcodeFormat'],
                    'max_bitrate_kbps' => $command['maxBitrateKbps'],
                    'replaygain_mode' => $command['replayGainMode'],
                    'replaygain_prevent_clipping' => $command['preventClipping'] ? 1 : 0,
                    'version' => $nextVersion,
                    'updated_at' => $now,
                ]);
            if ($affected !== 1) {
                throw new UserPreferenceConflict('个人设置已在其他页面更新，请重新加载后再试。');
            }
            $this->auditLogger->record(
                $userId,
                'user.preferences.playback.update',
                'user_preferences',
                $userId,
                'success',
                $requestId,
                [
                    'streamMode' => $command['streamMode'],
                    'transcodeFormat' => $command['transcodeFormat'],
                    'maxBitrateKbps' => $command['maxBitrateKbps'],
                    'replayGainMode' => $command['replayGainMode'],
                    'preventClipping' => $command['preventClipping'],
                    'version' => $nextVersion,
                ],
            );
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) $pdo->exec('ROLLBACK');
            throw $throwable;
        }

        return $this->snapshot($actor);
    }

    /** 从可信投影提取规范 ULID，禁止将空值或任意账号选择器送入查询。 */
    private function userId(array $actor): string
    {
        $userId = (string) ($actor['id'] ?? '');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1) {
            throw new UserPreferenceInvalid('Authenticated user ID is invalid.');
        }
        return $userId;
    }
}
