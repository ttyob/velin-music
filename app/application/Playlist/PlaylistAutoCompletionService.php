<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\application\ResourcePlugin\Contract\ExternalMusicPluginRegistry;
use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use app\application\Scrape\ChineseQueryVariantNormalizer;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 编排后台可管理歌单的自动补全任务。
 *
 * 只有 `manage_system` 的后台入口可以改变开关；Worker 只消费开启歌单中已经冻结的导入条目，不读取
 * 用户实时编辑内容。搜索结果继续使用插件的 actor 绑定租约，下载继续使用插件原有入库策略，因此
 * 本服务不接触搜索列表、URL、Cookie、物理路径或插件私有参数。插件只返回不透明任务句柄，核心只有
 * 在补全钩子报告下载已成功发布后才回填歌单；插件状态读取失败不会被伪装成成功。所有数据库更新使用
 * 短事务，网络和插件下载永远在事务外执行，进程重启可依据状态继续且不会重复提交同一任务。
 */
final readonly class PlaylistAutoCompletionService
{
    public function __construct(
        private ExternalMusicPluginRegistry $registry = new PhpResourcePluginRegistry(),
        private AuditLogger $audit = new AuditLogger(),
        private ChineseQueryVariantNormalizer $identityNormalizer = new ChineseQueryVariantNormalizer(),
    ) {
    }

    /**
     * 在后台可管理歌单版本锁下切换自动补全，并为当前缺失条目建立幂等任务。
     *
     * payload 只能包含 expectedVersion 与 enabled。开启时只快照没有 song_id 的 unmatched/ambiguous 条目，
     * 已存在任务由 `(playlist_id,position)` 唯一键收敛；关闭只阻止后续领取，不取消已经提交给插件的下载。
     * 目标库选择当前管理员可管理的第一个 active 本地库，找不到时任务会明确失败而不会写任意路径。
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed> 最新用户或系统歌单摘要和补全统计
     */
    public function update(string $playlistId, array $payload, string $actorId, string $requestId, bool $isSuperAdmin = true): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $playlistId) !== 1
            || !is_int($payload['expectedVersion'] ?? null) || !is_bool($payload['enabled'] ?? null)
            || array_diff(array_keys($payload), ['expectedVersion', 'enabled']) !== []) {
            throw new PlaylistInvalid('自动补全请求无效。');
        }
        $enabled = $payload['enabled'];
        Db::transaction(function () use ($playlistId, $payload, $actorId, $requestId, $enabled, $isSuperAdmin): void {
            /** @var stdClass|null $playlist */
            $playlist = Db::table('playlists')->where('id', $playlistId)->whereIn('scope', ['user', 'system'])->first([
                'id', 'version', 'auto_completion_enabled', 'owner_user_id',
            ]);
            if (!$playlist instanceof stdClass) throw new PlaylistNotFound('Playlist not found.');
            if ((int) $playlist->version !== $payload['expectedVersion']) throw new PlaylistConflict('歌单已发生变化，请重新加载。');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::table('playlists')->where('id', $playlistId)->where('version', $payload['expectedVersion'])->update([
                'auto_completion_enabled' => $enabled ? 1 : 0,
                'auto_completion_updated_at' => $now,
                'version' => $payload['expectedVersion'] + 1,
                'updated_at' => $now,
            ]);
            if ($enabled) $this->enqueueMissing($playlistId, $actorId, $isSuperAdmin, $now);
            $this->audit->record($actorId, 'playlist.auto_completion.update', 'playlist', $playlistId,
                'success', $requestId, ['enabled' => $enabled]);
        });
        return (new AdminPlaylistService())->findAdminPlaylist($playlistId);
    }

    /** 返回用户或系统歌单的后台开关、三次尝试统计和最后失败原因，不返回租约或插件内部引用。 */
    public function summary(string $playlistId): array
    {
        $row = Db::table('playlists')->where('id', $playlistId)->whereIn('scope', ['user', 'system'])->first([
            'auto_completion_enabled', 'auto_completion_updated_at',
        ]);
        if (!$row instanceof stdClass) throw new PlaylistNotFound('Playlist not found.');
        $counts = Db::table('playlist_auto_completion_jobs')->where('playlist_id', $playlistId)
            ->select(['status', Db::raw('COUNT(*) AS amount')])->groupBy('status')->get()->all();
        $result = ['queued' => 0, 'searching' => 0, 'downloading' => 0, 'importing' => 0,
            'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($counts as $count) if (array_key_exists((string) $count->status, $result)) $result[(string) $count->status] = (int) $count->amount;
        return ['enabled' => (int) $row->auto_completion_enabled === 1,
            'updatedAt' => $row->auto_completion_updated_at === null ? null : (string) $row->auto_completion_updated_at,
            'counts' => $result, 'total' => array_sum($result), 'maxAttempts' => 3];
    }

    /**
     * 返回后台歌单补全日志的分页投影。
     *
     * 前置条件：调用者已通过 Controller 获得 `manage_system`；歌单必须属于 user/system scope。返回值只
     * 包含导入证据、状态、尝试次数、稳定错误码和安全时间戳，不返回 actor、库 ID、插件句柄、下载任务 ID、
     * 路径或第三方响应。任务仍由 Worker 异步更新，读取不加写锁；同一页可在刷新时出现状态变化，但不会改变
     * 歌曲顺序事实。limit/offset 有界，避免管理员打开日志时一次加载整张超大歌单。
     *
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int,counts:array<string,int>}
     */
    public function log(string $playlistId, int $limit = 100, int $offset = 0): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $playlistId) !== 1) {
            throw new PlaylistInvalid('歌单 ID 无效。');
        }
        if (!Db::table('playlists')->where('id', $playlistId)->whereIn('scope', ['user', 'system'])->exists()) {
            throw new PlaylistNotFound('Playlist not found.');
        }
        $limit = max(1, min(200, $limit));
        $offset = max(0, min(10_000, $offset));
        $query = Db::table('playlist_auto_completion_jobs')->where('playlist_id', $playlistId);
        $total = (clone $query)->count();
        $rows = $query->orderBy('position')->offset($offset)->limit($limit)
            ->get(['position', 'source_title', 'source_artists_json', 'source_album', 'status', 'attempt',
                'max_attempts', 'error_code', 'error_detail', 'next_attempt_at', 'started_at', 'finished_at',
                'created_at', 'updated_at'])->all();
        $counts = ['queued' => 0, 'searching' => 0, 'downloading' => 0, 'importing' => 0,
            'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        $countRows = Db::table('playlist_auto_completion_jobs')->where('playlist_id', $playlistId)
            ->select(['status', Db::raw('COUNT(*) AS amount')])->groupBy('status')->get()->all();
        foreach ($countRows as $countRow) {
            $status = (string) $countRow->status;
            if (array_key_exists($status, $counts)) $counts[$status] = (int) $countRow->amount;
        }
        return ['items' => array_map(fn (stdClass $row): array => [
            'position' => (int) $row->position,
            'title' => (string) $row->source_title,
            'artists' => $this->artists($row->source_artists_json),
            'album' => is_string($row->source_album) ? $row->source_album : null,
            'status' => (string) $row->status,
            'attempt' => (int) $row->attempt,
            'maxAttempts' => (int) $row->max_attempts,
            'errorCode' => is_string($row->error_code) ? $row->error_code : null,
            'errorDetail' => is_string($row->error_detail) ? $row->error_detail : null,
            'nextAttemptAt' => is_string($row->next_attempt_at) ? $row->next_attempt_at : null,
            'startedAt' => is_string($row->started_at) ? $row->started_at : null,
            'finishedAt' => is_string($row->finished_at) ? $row->finished_at : null,
            'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
        ], $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'counts' => $counts];
    }

    /**
     * 重置当前歌单中仍缺失歌曲的补全任务。
     *
     * failed/skipped 且导入条目仍没有 song_id 的任务会被重置；没有任务记录的缺失条目会同时补登记。成功、
     * 运行中和已被其他流程匹配的任务保持不变。重置清除旧插件句柄、尝试次数、Worker 租约和错误信息，
     * 并在同一短事务中使用歌单版本 CAS 后重新排入 queued。它不删除搜索租约、媒体文件或原始导入证据；插件下载任务的旧记录仍
     * 由插件自身生命周期保留，避免核心跨边界删除第三方账本。自动补全开关关闭时任务仍可排队，重新开启
     * 后由 Worker 领取。重复提交同一版本只会有一个请求成功，便于管理员安全重试。
     *
     * @return array<string,mixed> 最新歌单摘要和重置数量
     */
    public function reset(
        string $playlistId,
        int $expectedVersion,
        string $actorId,
        string $requestId,
        bool $isSuperAdmin = false,
    ): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $playlistId) !== 1 || $expectedVersion < 1) {
            throw new PlaylistInvalid('自动补全重置请求无效。');
        }
        $resetCount = 0;
        Db::transaction(function () use ($playlistId, $expectedVersion, $actorId, $requestId, $isSuperAdmin, &$resetCount): void {
            /** @var stdClass|null $playlist */
            $playlist = Db::table('playlists')->where('id', $playlistId)->whereIn('scope', ['user', 'system'])
                ->first(['id', 'version']);
            if (!$playlist instanceof stdClass) throw new PlaylistNotFound('Playlist not found.');
            if ((int) $playlist->version !== $expectedVersion) throw new PlaylistConflict('歌单已发生变化，请重新加载。');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $jobIds = Db::table('playlist_auto_completion_jobs as jobs')
                ->join('playlist_import_entries as entries', function ($join): void {
                    $join->on('entries.playlist_id', '=', 'jobs.playlist_id')
                        ->on('entries.position', '=', 'jobs.position');
                })->where('jobs.playlist_id', $playlistId)->whereIn('jobs.status', ['failed', 'skipped'])
                ->whereNull('entries.song_id')->pluck('jobs.id')->all();
            $resetCount = $jobIds === [] ? 0 : Db::table('playlist_auto_completion_jobs')->whereIn('id', $jobIds)->update([
                    'status' => 'queued', 'attempt' => 0, 'candidate_index' => 0,
                    'plugin_key' => null, 'download_job_id' => null, 'worker_id' => null,
                    'error_code' => null, 'error_detail' => null, 'next_attempt_at' => null,
                    'started_at' => null, 'finished_at' => null, 'updated_at' => $now,
                ]);
            // 同时补登记尚未创建任务的缺失条目；关闭开关时也允许先排队，重新开启后 Worker 才会领取。
            $resetCount += $this->enqueueMissing($playlistId, $actorId, $isSuperAdmin, $now, false);
            $changed = Db::table('playlists')->where('id', $playlistId)->where('version', $expectedVersion)->update([
                'version' => $expectedVersion + 1, 'updated_at' => $now,
            ]);
            if ($changed !== 1) throw new PlaylistConflict('歌单已发生变化，请重新加载。');
            $this->audit->record($actorId, 'playlist.auto_completion.reset', 'playlist', $playlistId,
                'success', $requestId, ['resetCount' => $resetCount]);
        });
        return ['playlist' => (new AdminPlaylistService())->findAdminPlaylist($playlistId), 'resetCount' => $resetCount];
    }

    /**
     * 为已开启自动补全的歌单补登记缺失条目任务。
     *
     * 手动添加歌曲发生在歌单服务事务之后，因此这里使用 `(playlist_id, position)` 幂等插入；开关关闭时
     * 不创建任务。目标库和权限沿用开启开关时的规则，没有可管理本地库则创建明确失败任务，不写任意路径。
     * 该方法供手动添加接口调用，也可安全重复调用，不会重置已达到三次上限的历史任务。
     */
    public function enqueueMissingForPlaylist(string $playlistId, string $actorId, bool $isSuperAdmin = false): void
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('playlist_auto_completion_jobs')) return;
        if (!Db::table('playlists')->where('id', $playlistId)->whereIn('scope', ['user', 'system'])
            ->where('auto_completion_enabled', 1)->exists()) return;
        $this->enqueueMissing($playlistId, $actorId, $isSuperAdmin, gmdate('Y-m-d\TH:i:s\Z'));
    }

    /**
     * 领取一条到期的自动补全任务。
     *
     * SQLite 阶段保持单消费者和短事务：事务内只按状态优先级选择任务并写入 Worker 身份，插件网络调用、
     * 媒体扫描匹配和歌单回填都在事务外执行。已经发布媒体的 importing 以及需要读取插件终态的
     * downloading 必须先于新 queued/searching 任务，否则持续导入大歌单时，新任务会让插件已完成的歌曲
     * 长期停留在“下载中”。轮询中的插件任务通过 next_attempt_at 让出十秒，因此优先处理到期任务不会让
     * 单个慢下载独占 Worker；进程重启后仍依据持久状态继续，且不会再次创建插件下载任务。
     */
    public function processNext(string $workerId): bool
    {
        $this->recoverStale();
        $row = null;
        Db::transaction(function () use (&$row, $workerId): void {
            /** @var stdClass|null $candidate */
            $candidate = Db::table('playlist_auto_completion_jobs as jobs')
                ->join('playlists', 'playlists.id', '=', 'jobs.playlist_id')
                ->where('playlists.auto_completion_enabled', 1)
                ->whereIn('jobs.status', ['queued', 'searching', 'importing', 'downloading'])
                ->where(function ($query): void {
                    $query->whereNull('jobs.next_attempt_at')->orWhere('jobs.next_attempt_at', '<=', gmdate('Y-m-d\TH:i:s\Z'));
                })
                ->orderByRaw("CASE jobs.status WHEN 'importing' THEN 0 WHEN 'downloading' THEN 1 WHEN 'queued' THEN 2 WHEN 'searching' THEN 3 ELSE 4 END")
                ->orderBy('jobs.created_at')->orderBy('jobs.id')->first(['jobs.*']);
            if (!$candidate instanceof stdClass) return;
            $changed = Db::table('playlist_auto_completion_jobs')->where('id', (string) $candidate->id)
                ->whereIn('status', ['queued', 'searching', 'importing', 'downloading'])->update([
                    'worker_id' => $workerId, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            if ($changed === 1) $row = $candidate;
        });
        if (!$row instanceof stdClass) return false;
        try {
            return $this->execute($row, $workerId);
        } catch (Throwable $failure) {
            $this->fail((string) $row->id, 'AUTOCOMPLETION_INTERNAL_FAILED', $failure::class);
            return true;
        }
    }

    /** 处理搜索、候选下载状态和扫描后的歌单回填。 */
    private function execute(stdClass $row, string $workerId): bool
    {
        $status = (string) $row->status;
        if ($status === 'queued' || $status === 'searching') return $this->startCompletion($row, $workerId);
        if ($status === 'downloading') return $this->pollCompletion($row, $workerId);
        if ($status === 'importing') return $this->attachImportedSong($row);
        return true;
    }

    /**
     * 调用插件通用补全钩子；插件内部搜索，核心只保存不透明任务句柄。
     *
     * 注册表没有任何可用实现时才返回 AUTOCOMPLETION_NO_PROVIDER。只要至少一个插件声明并安装了补全
     * 能力，后续拒绝候选、协议错误或执行异常都必须收敛为 AUTOCOMPLETION_RESOURCE_FAILED；不能用
     * “没有声明能力”覆盖真实失败类别，也不能把插件异常正文写入管理员日志。多个插件按注册表顺序尝试，
     * 首个创建耐久任务的实现获胜；全部失败不会留下核心 download_job_id，管理员可通过重置重新排队。
     */
    private function startCompletion(stdClass $row, string $workerId): bool
    {
        $artists = $this->artists($row->source_artists_json);
        $completionPlugins = [];
        foreach ($this->registry->list() as $plugin) {
            $key = $plugin['key'] ?? null;
            if (($plugin['valid'] ?? false) !== true || ($plugin['enabled'] ?? true) !== true
                || ($plugin['databaseInstalled'] ?? false) !== true
                || !is_string($key)) continue;
            if (in_array('external_music_completion', $plugin['capabilities'] ?? [], true)) {
                $completionPlugins[] = $key;
            }
        }
        foreach ($completionPlugins as $key) {
            try {
                $hook = $this->registry->completion((string) $key);
                // 只转换发给插件的身份字段；source_* 仍保留导入时的原文，便于审计和重新匹配。
                $identity = [['title' => (string) $row->source_title, 'artist' => $artists[0] ?? '',
                    'album' => is_string($row->source_album) && trim($row->source_album) !== ''
                        ? trim($row->source_album) : '']];
                $convertedIdentity = $this->identityNormalizer->simplify($identity)[0] ?? $identity[0];
                $identity = is_array($convertedIdentity) ? $convertedIdentity : $identity[0];
                $request = ['title' => is_string($identity['title'] ?? null) ? $identity['title'] : (string) $row->source_title,
                    'artist' => is_string($identity['artist'] ?? null) ? $identity['artist'] : ($artists[0] ?? '')];
                if (($identity['album'] ?? '') !== '' && is_string($identity['album'] ?? null)) $request['album'] = $identity['album'];
                if (is_string($row->library_id) && $row->library_id !== '') $request['libraryId'] = (string) $row->library_id;
                else throw new \RuntimeException('AUTOCOMPLETION_LIBRARY_REQUIRED');
                $accepted = $hook->completeMusic($request, ['id' => (string) $row->actor_id, 'isSuperAdmin' => true],
                    'playlist-auto-' . (string) $row->id);
                $taskId = $accepted['taskId'] ?? null;
                if (!is_string($taskId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $taskId) !== 1
                    || ($accepted['accepted'] ?? null) !== true || !in_array($accepted['status'] ?? null, ['queued', 'running'], true)) {
                    throw new \RuntimeException('AUTOCOMPLETION_PLUGIN_PROTOCOL_INVALID');
                }
                Db::table('playlist_auto_completion_jobs')->where('id', (string) $row->id)->update([
                    'status' => 'downloading', 'attempt' => 1, 'candidate_index' => 0,
                    'plugin_key' => (string) $key, 'download_job_id' => $taskId, 'worker_id' => $workerId,
                    'error_code' => null, 'error_detail' => null,
                    'started_at' => $row->started_at ?: gmdate('Y-m-d\TH:i:s\Z'),
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                return true;
            } catch (Throwable $failure) {
                Db::table('playlist_auto_completion_jobs')->where('id', (string) $row->id)->update([
                    'attempt' => min(3, (int) $row->attempt + 1), 'error_code' => 'AUTOCOMPLETION_RESOURCE_FAILED',
                    'error_detail' => mb_substr($failure::class, 0, 500), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            }
        }
        if ($completionPlugins === []) {
            $this->fail((string) $row->id, 'AUTOCOMPLETION_NO_PROVIDER', '没有声明歌曲补全能力的插件。');
            return true;
        }
        $this->fail((string) $row->id, 'AUTOCOMPLETION_RESOURCE_FAILED',
            '所有歌曲补全插件均未接受该歌曲或执行失败。');
        return true;
    }

    /** 轮询通用补全任务；插件只有在媒体发布完成后才可返回 succeeded。 */
    private function pollCompletion(stdClass $row, string $workerId): bool
    {
        try {
            $state = $this->registry->completion((string) $row->plugin_key)->completionStatus(
                (string) $row->download_job_id, ['id' => (string) $row->actor_id, 'isSuperAdmin' => true],
            );
            $status = (string) ($state['status'] ?? 'running');
            if ($status === 'succeeded') {
                Db::table('playlist_auto_completion_jobs')->where('id', (string) $row->id)->update([
                    // 下载表已经是 succeeded，下一轮只需等待扫描器建立索引；不再额外延迟十秒。
                    'status' => 'importing', 'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'worker_id' => $workerId,
                ]);
                return true;
            }
            if ($status === 'failed') {
                $this->fail((string) $row->id, (string) ($state['errorCode'] ?? 'AUTOCOMPLETION_RESOURCE_FAILED'),
                    '插件确认下载失败。');
                return true;
            }
        } catch (Throwable) {
            // 插件瞬时不可用时保持 downloading，下一轮重试；不能把读取失败伪装为成功或终态失败。
        }
        Db::table('playlist_auto_completion_jobs')->where('id', (string) $row->id)->update([
            'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 10), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        return true;
    }

    /** 扫描完成后按标题和艺人关系匹配唯一歌曲，并把它补回原位置。 */
    private function attachImportedSong(stdClass $row): bool
    {
        $normalizer = new \app\application\Search\SearchTextNormalizer();
        // 导入证据必须原样保留，但比较键要和插件请求一样先转为简体，避免“鄧麗君/邓丽君”
        // 被当成两个艺人。标题同时保留去掉末尾版本说明的安全候选，覆盖渠道把 Remix/年份
        // 写入标题而本地扫描标签只保留主标题的常见差异；候选仍要求同一音乐库和唯一艺人命中。
        $titleCandidates = $this->titleCandidates(
            $this->identityNormalizer->simplifyText((string) $row->source_title),
            $normalizer,
        );
        $artists = array_values(array_unique(array_map(
            fn (string $artist): string => $this->identityNormalizer->simplifyText($artist),
            $this->artists($row->source_artists_json),
        )));
        $artistCandidates = array_values(array_unique(array_filter(array_map(
            fn (string $artist): string => $normalizer->normalize($artist),
            $artists,
        ), static fn (string $artist): bool => $artist !== '')));
        $query = Db::table('media_songs as songs')->where('songs.library_id', (string) $row->library_id)
            ->whereIn('songs.normalized_title', $titleCandidates)
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('files.status', 'available');
        if ($artistCandidates !== []) $query->join('media_song_artists as links', 'links.song_id', '=', 'songs.id')
            ->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->whereIn('artists.normalized_name', $artistCandidates);
        $songs = $query->distinct()->get(['songs.id', 'songs.duration_ms'])->all();
        if (count($songs) !== 1) {
            $attempt = max(1, (int) $row->attempt);
            $maxAttempts = max(1, (int) ($row->max_attempts ?? 3));
            if ($attempt >= $maxAttempts) {
                $this->fail((string) $row->id, 'AUTOCOMPLETION_IMPORT_MATCH_NOT_FOUND',
                    '媒体扫描已完成，但无法按简体标题和艺人唯一匹配导入歌曲。');
                return true;
            }
            // 扫描器可能晚于插件发布几秒；有限退避后必须收敛为失败，不能无限占用消费者。
            $delay = min(120, 20 * (2 ** max(0, $attempt - 1)));
            Db::table('playlist_auto_completion_jobs')->where('id', (string) $row->id)->update([
                'attempt' => $attempt + 1,
                'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $delay),
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            return true;
        }
        $song = $songs[0];
        Db::transaction(function () use ($row, $song): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $playlist = Db::table('playlists')->where('id', (string) $row->playlist_id)
                ->whereIn('scope', ['user', 'system'])->first(['version', 'owner_user_id']);
            if (!$playlist instanceof stdClass) throw new PlaylistNotFound('Playlist not found.');
            Db::table('playlist_items')->insertOrIgnore(['playlist_id' => (string) $row->playlist_id, 'position' => (int) $row->position,
                'song_id' => (string) $song->id, 'added_by_user_id' => (string) $playlist->owner_user_id, 'added_at' => $now]);
            Db::table('playlist_import_entries')->where('playlist_id', (string) $row->playlist_id)->where('position', (int) $row->position)->update([
                'status' => 'matched', 'song_id' => (string) $song->id, 'reason_code' => null,
            ]);
            $stats = Db::table('playlist_items as items')->join('media_songs as songs', 'songs.id', '=', 'items.song_id')
                ->where('items.playlist_id', (string) $row->playlist_id)->selectRaw('COUNT(*) AS amount, COALESCE(SUM(songs.duration_ms),0) AS duration')->first();
            Db::table('playlists')->where('id', (string) $row->playlist_id)->update([
                'song_count' => (int) ($stats->amount ?? 0), 'duration_ms' => (int) ($stats->duration ?? 0),
                'version' => (int) $playlist->version + 1, 'updated_at' => $now,
            ]);
            Db::table('playlist_auto_completion_jobs')->where('id', (string) $row->id)->update([
                'status' => 'succeeded', 'finished_at' => $now, 'next_attempt_at' => null, 'updated_at' => $now,
            ]);
        });
        return true;
    }

    /** 创建缺失条目任务；已有终态任务不因重复开启而重置，避免三次限制被开关绕过。 */
    private function enqueueMissing(
        string $playlistId,
        string $actorId,
        bool $isSuperAdmin,
        string $now,
        bool $requireEnabled = true,
    ): int
    {
        $libraryQuery = Db::table('music_libraries')->where('status', 'active')->where('source_type', 'local');
        if (!$isSuperAdmin) $libraryQuery->join('library_user_grants as grants', function ($join) use ($actorId): void {
            $join->on('grants.library_id', '=', 'music_libraries.id')->where('grants.user_id', $actorId)->where('grants.access_level', 'manage');
        });
        $libraryId = (string) ($libraryQuery->orderBy('music_libraries.id')->value('music_libraries.id') ?? '');
        if ($requireEnabled && !Db::table('playlists')->where('id', $playlistId)->whereIn('scope', ['user', 'system'])
            ->where('auto_completion_enabled', 1)->exists()) return 0;
        $entries = Db::table('playlist_import_entries')->where('playlist_id', $playlistId)->whereNull('song_id')
            ->whereIn('status', ['unmatched', 'ambiguous'])->orderBy('position')->get(['position', 'source_title', 'source_artists_json', 'source_album'])->all();
        $created = 0;
        foreach ($entries as $entry) {
            if (!is_string($entry->source_title ?? null) || trim($entry->source_title) === '') continue;
            $created += Db::table('playlist_auto_completion_jobs')->insertOrIgnore([
                'id' => (string) new Ulid(), 'playlist_id' => $playlistId, 'position' => (int) $entry->position,
                'actor_id' => $actorId, 'library_id' => $libraryId !== '' ? $libraryId : null,
                'source_title' => (string) $entry->source_title, 'source_artists_json' => $entry->source_artists_json,
                'source_album' => $entry->source_album, 'status' => $libraryId === '' ? 'failed' : 'queued',
                'error_code' => $libraryId === '' ? 'AUTOCOMPLETION_NO_LIBRARY' : null,
                'error_detail' => $libraryId === '' ? '没有可管理的本地音乐库。' : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return $created;
    }

    /** 将孤儿搜索/下载任务退回队列；已达到三次的任务永不恢复。 */
    private function recoverStale(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - 900);
        Db::table('playlist_auto_completion_jobs')->whereIn('status', ['searching', 'importing'])
            ->where('updated_at', '<', $threshold)->where('attempt', '<', 3)->update([
                'status' => 'queued', 'worker_id' => null, 'next_attempt_at' => null, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
    }

    private function fail(string $id, string $code, string $detail): void
    {
        Db::table('playlist_auto_completion_jobs')->where('id', $id)->whereNotIn('status', ['succeeded', 'skipped'])->update([
            'status' => 'failed', 'error_code' => $code, 'error_detail' => mb_substr($detail, 0, 500),
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'), 'next_attempt_at' => null, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @return list<string> */
    private function artists(mixed $json): array
    {
        if (!is_string($json) || $json === '') return [];
        $value = json_decode($json, true);
        return is_array($value) && array_is_list($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * 生成受控的标题比较键集合。
     *
     * 标题证据来自外部渠道，可能把年份、Remix 或括号说明附加到主标题；扫描器则可能把括号前的空格
     * 省略。这里只生成完整标题、括号空格归一化和末尾括号说明剥离三类候选，不做模糊包含匹配，仍由
     * 音乐库范围、艺人键和最终唯一性共同保证不会把相似歌曲错误回填。返回值非空，以满足 SQL IN 的
     * 参数边界；OpenCC 不可用时候选自然回退到原文键。
     *
     * @return list<string> 去重后的标准化标题键
     */
    private function titleCandidates(string $title, \app\application\Search\SearchTextNormalizer $normalizer): array
    {
        $values = [$title];
        $base = preg_replace('/\s*[\(\[【].*[\)\]】]\s*$/u', '', $title);
        if (is_string($base) && trim($base) !== '' && trim($base) !== trim($title)) $values[] = $base;
        $keys = [];
        foreach ($values as $value) {
            $normalized = $normalizer->normalize($value);
            if ($normalized !== '') $keys[] = $normalized;
            $compact = preg_replace('/\s*([\(\)\[\]\{\}])\s*/u', '$1', $normalized);
            if (is_string($compact) && $compact !== '' && $compact !== $normalized) $keys[] = $compact;
        }
        return array_values(array_unique($keys)) ?: [''];
    }
}
