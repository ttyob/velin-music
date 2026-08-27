<?php

declare(strict_types=1);

namespace app\application\User;

use app\application\Auth\AuthorizationDenied;
use app\application\Media\MediaQueryService;
use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * 读取管理员明确获准查看的播放隐私投影（ADMIN-USER-005）。
 *
 * `view_play_privacy` 只解除“可否查看播放活动”边界，不扩大媒体对象范围；所有歌曲仍与操作者当前
 * manage 级音乐库求交集。指定账号历史和导出任务还要求 `manage_users`，避免只有播放隐私能力的
 * 角色枚举账号。服务永不返回路径、文件身份、Session/Cookie/令牌、导出文件名或摘要。
 */
final readonly class AdminPlaybackPrivacyService
{
    public function __construct(private MediaQueryService $media = new MediaQueryService())
    {
    }

    /** @return array{items:list<array<string,mixed>>,total:int,limit:int} */
    public function nowPlaying(array $actor, int $limit = 50): array
    {
        $this->require($actor, ['view_play_privacy']);
        $limit = max(1, min(100, $limit));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $base = Db::table('playback_leases as leases')
            ->join('users', 'users.id', '=', 'leases.user_id')
            ->where('leases.expires_at', '>', $now)->where('users.status', 'active')->whereNull('users.deleted_at');
        $this->media->constrainToVisibleSongs($base, $actor, 'leases.song_id');
        $this->constrainToManagedSongs($base, $actor, 'leases.song_id');
        $total = (clone $base)->count('leases.id');
        /** @var list<stdClass> $rows */
        $rows = $base->orderByDesc('leases.heartbeat_at')->orderBy('leases.id')->limit($limit)->get([
            'leases.id', 'leases.user_id', 'leases.player_id', 'leases.song_id', 'leases.acquired_at',
            'leases.heartbeat_at', 'leases.expires_at', 'users.username', 'users.display_name',
        ])->all();
        $songs = $this->media->songsByIds($actor, array_values(array_unique(array_map(
            static fn (stdClass $row): string => (string) $row->song_id,
            $rows,
        ))));
        $items = [];
        foreach ($rows as $row) {
            $songId = (string) $row->song_id;
            if (!isset($songs[$songId])) continue;
            $items[] = [
                'leaseId' => (string) $row->id,
                'user' => ['id' => (string) $row->user_id, 'username' => (string) $row->username,
                    'displayName' => (string) $row->display_name],
                'playerId' => (string) $row->player_id,
                'song' => $this->privacySong($songs[$songId]),
                'acquiredAt' => (string) $row->acquired_at,
                'heartbeatAt' => (string) $row->heartbeat_at,
                'expiresAt' => (string) $row->expires_at,
            ];
        }
        return ['items' => $items, 'total' => $total, 'limit' => $limit];
    }

    /** @return array{history:list<array<string,mixed>>,total:int,limit:int,offset:int} */
    public function history(array $actor, string $userId, int $limit = 50, int $offset = 0): array
    {
        $this->requireTarget($actor, $userId);
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $query = Db::table('playback_sessions as sessions')->where('sessions.user_id', $userId)
            ->whereNull('sessions.cleared_at')->whereNotExists(
                static function (Builder $newer) use ($userId): void {
                    $newer->selectRaw('1')->from('playback_sessions as newer')
                        ->where('newer.user_id', $userId)->whereNull('newer.cleared_at')
                        ->whereColumn('newer.song_id', 'sessions.song_id')
                        ->where(static function (Builder $order): void {
                            $order->whereColumn('newer.updated_at', '>', 'sessions.updated_at')
                                ->orWhere(static function (Builder $tie): void {
                                    $tie->whereColumn('newer.updated_at', 'sessions.updated_at')
                                        ->whereColumn('newer.id', '>', 'sessions.id');
                                });
                        });
                },
            );
        $this->media->constrainToVisibleSongs($query, $actor, 'sessions.song_id');
        $this->constrainToManagedSongs($query, $actor, 'sessions.song_id');
        $total = (clone $query)->count('sessions.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('sessions.updated_at')->orderByDesc('sessions.id')
            ->offset($offset)->limit($limit)->get([
                'sessions.song_id', 'sessions.started_at', 'sessions.updated_at', 'sessions.last_position_ms',
                'sessions.listened_ms', 'sessions.status', 'sessions.counted_at',
            ])->all();
        $songs = $this->media->songsByIds($actor, array_values(array_unique(array_map(
            static fn (stdClass $row): string => (string) $row->song_id,
            $rows,
        ))));
        $history = [];
        foreach ($rows as $row) {
            $songId = (string) $row->song_id;
            if (!isset($songs[$songId])) continue;
            $history[] = [
                'song' => $this->privacySong($songs[$songId]), 'startedAt' => (string) $row->started_at,
                'updatedAt' => (string) $row->updated_at, 'positionMs' => (int) $row->last_position_ms,
                'listenedMs' => (int) $row->listened_ms, 'status' => (string) $row->status,
                'playCounted' => $row->counted_at !== null,
            ];
        }
        return ['history' => $history, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array{jobs:list<array<string,mixed>>} */
    public function exports(array $actor, string $userId): array
    {
        $this->requireTarget($actor, $userId);
        if (!Db::connection()->getSchemaBuilder()->hasTable('personal_data_export_jobs')) return ['jobs' => []];
        /** @var list<stdClass> $rows */
        $rows = Db::table('personal_data_export_jobs')->where('user_id', $userId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get([
                'id', 'status', 'phase', 'record_count', 'byte_size', 'attempt', 'error_code',
                'created_at', 'started_at', 'finished_at', 'expires_at',
            ])->all();
        return ['jobs' => array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id, 'status' => (string) $row->status, 'phase' => (string) $row->phase,
            'recordCount' => (int) $row->record_count,
            'byteSize' => $row->byte_size === null ? null : (int) $row->byte_size,
            'attempt' => (int) $row->attempt,
            'errorCode' => $row->error_code === null ? null : (string) $row->error_code,
            'createdAt' => (string) $row->created_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'expiresAt' => $row->expires_at === null ? null : (string) $row->expires_at,
        ], $rows)];
    }

    /** 指定账号读取必须同时具备账号管理和隐私能力，并验证目标仍存在但不读取其秘密。 */
    private function requireTarget(array $actor, string $userId): void
    {
        $this->require($actor, ['manage_users', 'view_play_privacy']);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1
            || !Db::table('users')->where('id', $userId)->whereNull('deleted_at')->exists()) {
            throw new UserNotFound('用户不存在。');
        }
    }

    /** @param list<string> $required */
    private function require(array $actor, array $required): void
    {
        $owned = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        foreach ($required as $capability) {
            if (!in_array($capability, $owned, true)) throw new AuthorizationDenied('Playback privacy capability is missing.');
        }
    }

    /**
     * 非超级管理员必须对歌曲所属库有当前 manage 授权；普通 read 授权不足以查看其他账号活动。
     * 该 EXISTS 只引用服务端固定表列，调用者传入的 outerSongColumn 由本类常量调用点控制。
     */
    private function constrainToManagedSongs(Builder $query, array $actor, string $outerSongColumn): void
    {
        if ($actor['isSuperAdmin'] ?? false) return;
        $query->whereExists(static function (Builder $scope) use ($actor, $outerSongColumn): void {
            $scope->selectRaw('1')->from('media_songs as managed_songs')
                ->join('library_user_grants as managed_grants', 'managed_grants.library_id', '=', 'managed_songs.library_id')
                ->whereColumn('managed_songs.id', $outerSongColumn)
                ->where('managed_grants.user_id', (string) $actor['id'])
                ->where('managed_grants.access_level', 'manage');
        });
    }

    /** 管理员隐私视图不混入操作者自己的收藏状态；其余字段沿用统一路径无关歌曲投影。 */
    private function privacySong(array $song): array
    {
        unset($song['preferences']);
        return $song;
    }
}
