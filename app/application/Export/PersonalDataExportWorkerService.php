<?php

declare(strict_types=1);

namespace app\application\Export;

use app\infrastructure\Audit\AuditLogger;
use RuntimeException;
use stdClass;
use support\Db;
use Throwable;

/**
 * 在独立单消费者中流式生成个人数据 JSON/M3U8 导出。
 *
 * Worker 不持有 SQLite 写事务读取个人数据；每个分页/列表边界重查 cancel_requested。产物使用固定
 * runtime 私有根、服务端 Job ULID 文件名、独占临时文件、fflush/fsync 和原子 rename。JSON 只包含
 * 账户公开字段、稳定媒体 ID、逻辑流 URL 与用户自己的播放/列表事实，不包含服务器路径、凭据或审计。
 */
final readonly class PersonalDataExportWorkerService
{
    private const LEASE_SECONDS = 600;
    private const RETENTION_SECONDS = 86400;

    public function __construct(private AuditLogger $audit = new AuditLogger())
    {
    }

    /** 原子领取最早 queued 任务，并再次要求目标账号仍然 active。 */
    public function claimNext(string $workerId): ?array
    {
        return Db::transaction(function () use ($workerId): ?array {
            /** @var stdClass|null $row */
            $row = Db::table('personal_data_export_jobs as jobs')
                ->join('users', 'users.id', '=', 'jobs.user_id')
                ->where('jobs.status', 'queued')->where('users.status', 'active')->whereNull('users.deleted_at')
                ->orderBy('jobs.created_at')->first(['jobs.id', 'jobs.user_id', 'jobs.request_id']);
            if (!$row instanceof stdClass) return null;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('personal_data_export_jobs')->where('id', (string) $row->id)
                ->where('status', 'queued')->update([
                    'status' => 'running', 'phase' => 'collecting', 'attempt' => Db::raw('attempt + 1'),
                    'worker_id' => $workerId, 'heartbeat_at' => $now, 'started_at' => $now,
                    'error_code' => null, 'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
            if ($changed !== 1) return null;
            return ['id' => (string) $row->id, 'userId' => (string) $row->user_id,
                'requestId' => (string) $row->request_id];
        });
    }

    /** 生成并验证一个已领取任务；所有文件写入和哈希均在数据库事务外。 */
    public function execute(array $job): void
    {
        $jobId = (string) $job['id'];
        $userId = (string) $job['userId'];
        $root = $this->root(true);
        $filename = 'personal-export-' . $jobId . '.json';
        $target = $root . DIRECTORY_SEPARATOR . $filename;
        $temporary = $root . DIRECTORY_SEPARATOR . '.' . $jobId . '.part';
        $handle = null;
        try {
            $this->throwIfCancelled($jobId);
            if (file_exists($target) || is_link($target) || file_exists($temporary) || is_link($temporary)) {
                throw new RuntimeException('Personal export target already exists.');
            }
            $handle = @fopen($temporary, 'x+b');
            if (!is_resource($handle)) throw new RuntimeException('Personal export temporary file unavailable.');
            @chmod($temporary, 0600);

            $count = 0;
            $this->write($handle, '{"format":"velin-personal-data","version":1,"generatedAt":');
            $this->write($handle, $this->json(gmdate('Y-m-d\TH:i:s\Z')));
            $this->write($handle, ',"profile":');
            /** @var stdClass|null $profile */
            $profile = Db::table('users')->where('id', $userId)->first([
                'id', 'username', 'display_name', 'email', 'locale', 'timezone', 'created_at',
            ]);
            if (!$profile instanceof stdClass) throw new RuntimeException('Personal export owner unavailable.');
            $this->write($handle, $this->json([
                'id' => (string) $profile->id, 'username' => (string) $profile->username,
                'displayName' => (string) $profile->display_name,
                'email' => $profile->email === null ? null : (string) $profile->email,
                'locale' => (string) $profile->locale, 'timezone' => (string) $profile->timezone,
                'createdAt' => (string) $profile->created_at,
            ]));
            ++$count;

            $this->phase($jobId, 'writing');
            $this->write($handle, ',"playlists":');
            $playlists = Db::table('playlists')->where('owner_user_id', $userId);
            if (Db::connection()->getSchemaBuilder()->hasColumn('playlists', 'scope')) $playlists->where('scope', 'user');
            $playlists = $playlists
                ->orderBy('created_at')->orderBy('id')->cursor();
            $this->writeArray($handle, $playlists, static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'name' => (string) $row->name,
                'description' => $row->description === null ? null : (string) $row->description,
                'visibility' => (string) $row->visibility, 'songCount' => (int) $row->song_count,
                'durationMs' => (int) $row->duration_ms, 'version' => (int) $row->version,
                'createdAt' => (string) $row->created_at, 'updatedAt' => (string) $row->updated_at,
            ], $jobId, $count);

            $this->write($handle, ',"playlistItems":');
            $items = Db::table('playlist_items as items')->join('playlists', 'playlists.id', '=', 'items.playlist_id')
                ->where('playlists.owner_user_id', $userId);
            if (Db::connection()->getSchemaBuilder()->hasColumn('playlists', 'scope')) $items->where('playlists.scope', 'user');
            $items = $items->orderBy('items.playlist_id')->orderBy('items.position')
                ->cursor();
            $this->writeArray($handle, $items, static fn (stdClass $row): array => [
                'playlistId' => (string) $row->playlist_id, 'position' => (int) $row->position,
                'songId' => (string) $row->song_id, 'streamUrl' => '/api/v1/streams/' . (string) $row->song_id,
                'addedAt' => (string) $row->added_at,
            ], $jobId, $count);

            $this->write($handle, ',"playlistM3u8":');
            $this->writePlaylistM3u($handle, $userId, $jobId, $count);
            foreach ([
                'favoriteSongs' => ['user_song_preferences', 'song_id'],
                'favoriteAlbums' => ['user_album_preferences', 'album_id'],
                'favoriteArtists' => ['user_artist_preferences', 'artist_id'],
            ] as $key => [$table, $column]) {
                $this->write($handle, ',' . $this->json($key) . ':');
                $rows = Db::table($table)->where('user_id', $userId)->where('is_favorite', 1)
                    ->orderBy('favorited_at')->orderBy($column)->cursor();
                $this->writeArray($handle, $rows, static fn (stdClass $row): array => [
                    'mediaId' => (string) $row->{$column},
                    'favoritedAt' => $row->favorited_at === null ? null : (string) $row->favorited_at,
                ], $jobId, $count);
            }
            foreach ([
                'songRatings' => ['user_song_preferences', 'song_id'],
                'albumRatings' => ['user_album_preferences', 'album_id'],
                'artistRatings' => ['user_artist_preferences', 'artist_id'],
            ] as $key => [$table, $column]) {
                $this->write($handle, ',' . $this->json($key) . ':');
                $rows = Db::table($table)->where('user_id', $userId)->whereNotNull('rating')
                    ->orderBy('rated_at')->orderBy($column)->cursor();
                $this->writeArray($handle, $rows, static fn (stdClass $row): array => [
                    'mediaId' => (string) $row->{$column},
                    'rating' => (int) $row->rating,
                    'ratedAt' => $row->rated_at === null ? null : (string) $row->rated_at,
                ], $jobId, $count);
            }

            $this->write($handle, ',"playbackHistory":');
            $history = Db::table('playback_sessions')->where('user_id', $userId)->whereNull('cleared_at')
                ->orderBy('started_at')->orderBy('id')->cursor();
            $this->writeArray($handle, $history, static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'playbackId' => (string) $row->playback_id,
                'playerId' => (string) $row->player_id, 'songId' => (string) $row->song_id,
                'startedAt' => (string) $row->started_at, 'lastOccurredAt' => (string) $row->last_occurred_at,
                'listenedMs' => (int) $row->listened_ms, 'lastPositionMs' => (int) $row->last_position_ms,
                'status' => (string) $row->status, 'countedAt' => $row->counted_at === null ? null : (string) $row->counted_at,
            ], $jobId, $count);

            $this->write($handle, ',"playStats":');
            $stats = Db::table('user_song_play_stats')->where('user_id', $userId)->orderBy('song_id')->cursor();
            $this->writeArray($handle, $stats, static fn (stdClass $row): array => [
                'songId' => (string) $row->song_id, 'playCount' => (int) $row->play_count,
                'lastPlayedAt' => $row->last_played_at === null ? null : (string) $row->last_played_at,
                'lastActivityAt' => (string) $row->last_activity_at,
                'lastPositionMs' => (int) $row->last_position_ms,
            ], $jobId, $count);

            // 日听歌时长是独立个人事实，不能要求用户从会话累计值自行重建；导出仍保持稀疏，且只含
            // 日期与正毫秒数，不附带歌曲、播放器或设备明细。表约束保证 0 行不存在，按日期稳定排序。
            $this->write($handle, ',"dailyListeningTimes":');
            $listeningTimes = Db::table('user_daily_listening_times')->where('user_id', $userId)
                ->orderBy('local_date')->cursor();
            $this->writeArray($handle, $listeningTimes, static fn (stdClass $row): array => [
                'date' => (string) $row->local_date,
                'listenedMs' => (int) $row->listened_ms,
            ], $jobId, $count);
            $this->write($handle, '}');
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Personal export flush failed.');
            }
            fclose($handle);
            $handle = null;
            $this->throwIfCancelled($jobId);
            $this->phase($jobId, 'verifying');
            $size = filesize($temporary);
            $sha256 = hash_file('sha256', $temporary);
            if (!is_int($size) || $size < 2 || !is_string($sha256) || !$this->validJsonFile($temporary)) {
                throw new RuntimeException('Personal export verification failed.');
            }
            $this->throwIfCancelled($jobId);
            if (!rename($temporary, $target)) throw new RuntimeException('Personal export publication failed.');

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('personal_data_export_jobs')->where('id', $jobId)
                ->where('status', 'running')->update([
                    'status' => 'succeeded', 'phase' => 'completed', 'artifact_filename' => $filename,
                    'byte_size' => $size, 'sha256' => $sha256, 'record_count' => $count,
                    'worker_id' => null, 'heartbeat_at' => null, 'finished_at' => $now,
                    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::RETENTION_SECONDS),
                    'error_code' => null, 'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                @unlink($target);
                throw new PersonalDataExportCancelled('Personal export cancellation won publication race.');
            }
            $this->audit->record($userId, 'personal_export.complete', 'personal_data_export_job',
                $jobId, 'success', (string) $job['requestId'], ['recordCount' => $count, 'byteSize' => $size]);
        } catch (PersonalDataExportCancelled) {
            if (is_resource($handle)) fclose($handle);
            $this->finishCancelled($jobId);
        } catch (Throwable) {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temporary) && !is_link($temporary)) @unlink($temporary);
            if (is_file($target) && !is_link($target)) @unlink($target);
            $this->fail($jobId);
        }
    }

    /** 崩溃后 running 租约过期转失败；cancel_requested 则优先收敛为取消。 */
    public function recoverStaleLeases(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
        /** @var list<stdClass> $rows */
        $rows = Db::table('personal_data_export_jobs')->whereIn('status', ['running', 'cancel_requested'])
            ->where('heartbeat_at', '<=', $threshold)->get(['id', 'status', 'heartbeat_at'])->all();
        foreach ($rows as $row) {
            if ((string) $row->status === 'cancel_requested') {
                $this->finishCancelled((string) $row->id);
                continue;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::table('personal_data_export_jobs')->where('id', (string) $row->id)->where('status', 'running')
                ->where('heartbeat_at', (string) $row->heartbeat_at)->update([
                    'status' => 'failed', 'phase' => 'failed', 'worker_id' => null, 'heartbeat_at' => null,
                    'finished_at' => $now, 'error_code' => 'PERSONAL_EXPORT_LEASE_EXPIRED',
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
        }
    }

    /** 删除一个已过期、仍与任务大小/文件名匹配的产物；不扫描未知文件。 */
    public function purgeOneExpired(): bool
    {
        /** @var stdClass|null $row */
        $row = Db::table('personal_data_export_jobs')->where('status', 'succeeded')
            ->whereNotNull('artifact_filename')->where('expires_at', '<=', gmdate('Y-m-d\TH:i:s\Z'))
            ->orderBy('expires_at')->first(['id', 'artifact_filename', 'byte_size']);
        if (!$row instanceof stdClass) return false;
        $expected = 'personal-export-' . (string) $row->id . '.json';
        $path = $this->root(false) . DIRECTORY_SEPARATOR . $expected;
        $stat = @lstat($path);
        if ((string) $row->artifact_filename !== $expected) return false;
        if (is_array($stat)) {
            if (is_link($path) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
                || (int) $stat['size'] !== (int) $row->byte_size || !@unlink($path)) {
                // 身份变化或删除失败时保留数据库所有权事实，后续轮询仍可重试且不会声称产物已清理。
                Db::table('personal_data_export_jobs')->where('id', (string) $row->id)
                    ->where('status', 'succeeded')->update([
                        'error_code' => 'PERSONAL_EXPORT_CLEANUP_BLOCKED',
                        'version' => Db::raw('version + 1'), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
                return false;
            }
        }
        Db::table('personal_data_export_jobs')->where('id', (string) $row->id)->where('status', 'succeeded')->update([
            'artifact_filename' => null, 'error_code' => 'PERSONAL_EXPORT_EXPIRED',
            'version' => Db::raw('version + 1'), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        return true;
    }

    /** @param resource $handle @param iterable<mixed> $rows @param callable(stdClass):array<string,mixed> $map */
    private function writeArray($handle, iterable $rows, callable $map, string $jobId, int &$count): void
    {
        $this->write($handle, '[');
        $first = true;
        foreach ($rows as $row) {
            if (!$row instanceof stdClass) continue;
            if (!$first) $this->write($handle, ',');
            $this->write($handle, $this->json($map($row)));
            $first = false;
            ++$count;
            if ($count % 100 === 0) {
                $this->heartbeat($jobId);
                $this->throwIfCancelled($jobId);
            }
        }
        $this->write($handle, ']');
        $this->throwIfCancelled($jobId);
    }

    /** @param resource $handle */
    private function writePlaylistM3u($handle, string $userId, string $jobId, int &$count): void
    {
        $this->write($handle, '[');
        $first = true;
        // 单个有序 LEFT JOIN 游标避免 SQLite PDO 在尚未消费完外层游标时再打开同连接的内层游标。
        $rows = Db::table('playlists as playlists')
            ->leftJoin('playlist_items as items', 'items.playlist_id', '=', 'playlists.id')
            ->where('playlists.owner_user_id', $userId);
        if (Db::connection()->getSchemaBuilder()->hasColumn('playlists', 'scope')) $rows->where('playlists.scope', 'user');
        $rows = $rows
            ->orderBy('playlists.id')->orderBy('items.position')
            ->select(['playlists.id as playlist_id', 'playlists.name as playlist_name', 'items.song_id'])
            ->cursor();
        $playlistId = null;
        $playlistName = '';
        $lines = [];
        $emit = function () use ($handle, &$count, &$first, &$lines, &$playlistId, &$playlistName): void {
            if ($playlistId === null) return;
            if (!$first) $this->write($handle, ',');
            $this->write($handle, $this->json([
                'playlistId' => $playlistId,
                'fileName' => $this->safeM3uName($playlistName) . '.m3u8',
                'content' => implode("\n", ['#EXTM3U', ...$lines]) . "\n",
            ]));
            $first = false;
            ++$count;
        };
        foreach ($rows as $row) {
            if (!$row instanceof stdClass) continue;
            $nextId = (string) $row->playlist_id;
            if ($playlistId !== null && $nextId !== $playlistId) {
                $emit();
                $lines = [];
                $this->throwIfCancelled($jobId);
            }
            if ($nextId !== $playlistId) {
                $playlistId = $nextId;
                $playlistName = (string) $row->playlist_name;
            }
            if ($row->song_id !== null) $lines[] = '/api/v1/streams/' . (string) $row->song_id;
        }
        if ($playlistId !== null) {
            $emit();
            $this->throwIfCancelled($jobId);
        }
        $this->write($handle, ']');
    }

    private function throwIfCancelled(string $jobId): void
    {
        $status = Db::table('personal_data_export_jobs')->where('id', $jobId)->value('status');
        if ($status !== 'running') throw new PersonalDataExportCancelled('Personal export cancelled.');
    }

    private function heartbeat(string $jobId): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('personal_data_export_jobs')->where('id', $jobId)->where('status', 'running')->update([
            'heartbeat_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function phase(string $jobId, string $phase): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('personal_data_export_jobs')->where('id', $jobId)->where('status', 'running')->update([
            'phase' => $phase, 'heartbeat_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function finishCancelled(string $jobId): void
    {
        if (!$this->cleanupJobFiles($jobId)) {
            // 无法证明临时/发布文件已删除时保留 cancel_requested，避免状态对外声称隐私产物已经收敛。
            Db::table('personal_data_export_jobs')->where('id', $jobId)
                ->whereIn('status', ['running', 'cancel_requested'])->update([
                    'status' => 'cancel_requested', 'error_code' => 'PERSONAL_EXPORT_CLEANUP_BLOCKED',
                    'heartbeat_at' => gmdate('Y-m-d\TH:i:s\Z'), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            return;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('personal_data_export_jobs')->where('id', $jobId)
            ->whereIn('status', ['running', 'cancel_requested'])->update([
                'status' => 'cancelled', 'phase' => 'cancelled', 'worker_id' => null, 'heartbeat_at' => null,
                'finished_at' => $now, 'error_code' => 'PERSONAL_EXPORT_CANCELLED',
                'version' => Db::raw('version + 1'), 'updated_at' => $now,
            ]);
    }

    private function fail(string $jobId): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('personal_data_export_jobs')->where('id', $jobId)->where('status', 'running')->update([
            'status' => 'failed', 'phase' => 'failed', 'worker_id' => null, 'heartbeat_at' => null,
            'finished_at' => $now, 'error_code' => 'PERSONAL_EXPORT_FAILED',
            'version' => Db::raw('version + 1'), 'updated_at' => $now,
        ]);
    }

    private function root(bool $create): string
    {
        $runtime = rtrim((string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime')), DIRECTORY_SEPARATOR);
        $root = $runtime . DIRECTORY_SEPARATOR . 'personal-exports';
        if ($create && !is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Personal export directory creation failed.');
        }
        // 清理轮询允许目录尚未创建；此时固定任务文件必然不存在，可以安全收敛过期或取消状态。
        if (!$create && !file_exists($root) && !is_link($root)) return $root;
        if (!is_dir($root) || is_link($root) || realpath($root) !== $root || ($create && !is_writable($root))) {
            throw new RuntimeException('Personal export directory unavailable.');
        }
        return $root;
    }

    /** 只删除由一个 Job ULID 推导出的两个固定普通文件；身份异常或删除失败时返回 false。 */
    private function cleanupJobFiles(string $jobId): bool
    {
        $root = $this->root(false);
        foreach ([
            $root . DIRECTORY_SEPARATOR . '.' . $jobId . '.part',
            $root . DIRECTORY_SEPARATOR . 'personal-export-' . $jobId . '.json',
        ] as $path) {
            $stat = @lstat($path);
            if ($stat === false) continue;
            if (is_link($path) || (($stat['mode'] ?? 0) & 0170000) !== 0100000 || !@unlink($path)) return false;
        }
        return true;
    }

    /** @param resource $handle */
    private function write($handle, string $bytes): void
    {
        $length = strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $next = fwrite($handle, substr($bytes, $written));
            if (!is_int($next) || $next < 1) throw new RuntimeException('Personal export write failed.');
            $written += $next;
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function validJsonFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) return false;
        $first = fread($handle, 1);
        if (fseek($handle, -1, SEEK_END) !== 0) { fclose($handle); return false; }
        $last = fread($handle, 1);
        fclose($handle);
        return $first === '{' && $last === '}';
    }

    private function safeM3uName(string $name): string
    {
        $name = preg_replace('~[\\\\/\x00-\x1F\x7F]+~u', '-', trim($name)) ?? 'playlist';
        if ($name === '') $name = 'playlist';
        return function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
    }
}
