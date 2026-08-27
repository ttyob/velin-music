<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Scan\ScanJobService;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/**
 * 管理受管本地歌曲进入隔离回收区、恢复和永久清理的完整生命周期。
 *
 * 只有当前操作者实时拥有音乐库 manage 范围、音乐库为 local 且文件身份仍与扫描库存一致时才允许执行。
 * 本服务不接受浏览器路径，也不删除网络库、刮削缓存或未知文件；文件先在同一文件系统内原子 rename，
 * 再在短数据库事务中写回收凭据并删除歌曲。事务失败时仅在回收条目仍归本次操作所有的前提下逆向 rename，
 * 补偿失败会保留现场并抛出不可用错误，绝不对外声称已完成。恢复和永久清理只接受删除记录 ID，先按
 * 当前音乐库 manage 范围裁剪，再复验内部 receipt、普通文件身份和根目录边界；两种操作通过数据库领取
 * 状态互斥。永久删除不可回滚，进程中断时保留 purging 状态供后续同类请求继续收口。
 */
final class MediaDeletionService
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ScanJobService $scans = new ScanJobService(),
    )
    {
    }

    /**
     * 删除一首歌曲的索引并把音频移入隔离回收区。
     *
     * @param array<string,mixed> $actor 已通过 `manage_library` 全局能力校验的操作者。
     * @return array{deleted:bool,deletionId:string,songId:string,status:string}
     * @throws MediaDeletionInvalid ID、库路径或请求结构不满足安全前置条件。
     * @throws MediaDeletionNotFound 歌曲不存在、失权或属于外部音乐库。
     * @throws MediaDeletionConflict 文件身份变化或删除已被其他操作领取。
     * @throws MediaDeletionUnavailable 文件系统或数据库提交失败且无法安全补偿。
     */
    public function delete(array $actor, string $songId, string $requestId): array
    {
        if (!Ulid::isValid($songId)) {
            throw new MediaDeletionInvalid('歌曲标识无效。');
        }
        if ($requestId === '') {
            throw new MediaDeletionInvalid('请求标识无效。');
        }

        /** 相同请求重试时返回同一安全结果，不重新触碰已经移入回收区的文件。 */
        $existing = Db::table('media_song_deletions')->where('request_id', $requestId)
            ->where('status', 'completed')->first(['id', 'song_id', 'status']);
        if ($existing instanceof stdClass) {
            return ['deleted' => true, 'deletionId' => (string) $existing->id,
                'songId' => (string) $existing->song_id, 'status' => (string) $existing->status];
        }

        $row = $this->findManagedLocalSong($actor, $songId);
        if (!$row instanceof stdClass) {
            throw new MediaDeletionNotFound('歌曲不存在或不可管理。');
        }
        if ((string) $row->source_type !== 'local') {
            throw new MediaDeletionNotFound('外部音乐库不支持删除。');
        }

        $this->assertLocalFileIdentity($row);
        $trashRoot = $this->trashRoot();
        $this->assertTrashDoesNotOverlapLibraries($trashRoot);

        $deletionId = (string) new Ulid();
        $trashDirectory = $trashRoot . DIRECTORY_SEPARATOR . $deletionId;
        if (!mkdir($trashDirectory, 0750, false) && !is_dir($trashDirectory)) {
            throw new MediaDeletionUnavailable('无法创建隔离回收目录。');
        }
        if (is_link($trashDirectory) || realpath($trashDirectory) !== $trashDirectory) {
            throw new MediaDeletionUnavailable('隔离回收目录身份异常。');
        }

        $sourcePath = (string) $row->resolved_path;
        $trashEntry = basename($sourcePath);
        if ($trashEntry === '' || $trashEntry === '.' || $trashEntry === '..') {
            throw new MediaDeletionInvalid('媒体文件名无效。');
        }
        $trashPath = $trashDirectory . DIRECTORY_SEPARATOR . $trashEntry;
        $receiptPath = $trashDirectory . DIRECTORY_SEPARATOR . 'receipt.json';
        if (file_exists($trashPath) || file_exists($receiptPath)) {
            throw new MediaDeletionConflict('回收区目标已存在。');
        }

        if (!rename($sourcePath, $trashPath)) {
            @rmdir($trashDirectory);
            throw new MediaDeletionUnavailable('媒体文件移动失败。');
        }
        try {
            $this->writeReceipt($receiptPath, $row, $deletionId);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::transaction(function () use ($actor, $requestId, $row, $deletionId, $now): void {
                Db::table('media_song_deletions')->insert([
                    'id' => $deletionId,
                    'song_id' => (string) $row->song_id,
                    'library_id' => (string) $row->library_id,
                    'inventory_file_id' => (string) $row->inventory_file_id,
                    'original_relative_path' => (string) $row->relative_path,
                    'trash_entry_name' => basename((string) $row->resolved_path),
                    'device_id' => (int) $row->device_id,
                    'inode' => (int) $row->inode,
                    'file_size' => (int) $row->file_size,
                    'modified_at' => (int) $row->modified_at,
                    'actor_user_id' => (string) $actor['id'],
                    'request_id' => $requestId,
                    'status' => 'completed',
                    'created_at' => $now,
                    'completed_at' => $now,
                ]);
                $deleted = Db::table('media_songs')->where('id', (string) $row->song_id)->delete();
                if ($deleted !== 1) {
                    throw new MediaDeletionConflict('歌曲状态已变化。');
                }
                $this->audit->record((string) $actor['id'], 'media.song.delete', 'song', (string) $row->song_id,
                    'success', $requestId, ['deletionId' => $deletionId, 'libraryId' => (string) $row->library_id]);
            });
        } catch (Throwable $failure) {
            $this->compensateMove($sourcePath, $trashPath, $receiptPath, $trashDirectory, $failure);
            if ($failure instanceof MediaDeletionConflict) {
                throw $failure;
            }
            throw new MediaDeletionUnavailable('删除提交失败，已尝试恢复媒体文件。', previous: $failure);
        }

        return ['deleted' => true, 'deletionId' => $deletionId, 'songId' => $songId, 'status' => 'completed'];
    }

    /**
     * 返回当前管理员可管理音乐库中的回收条目。
     *
     * 查询只投影安全 basename、库名、字节数、操作者显示名和时间；原始相对路径、解析路径、inode、
     * receipt 与回收根永不进入 HTTP。已恢复或永久清理的历史记录不在活动回收站展示。分页被限制在
     * 1-100 条，非超级管理员始终通过实时 manage grant 过滤。
     *
     * @param array<string,mixed> $actor 已通过全局 `manage_library` 校验的操作者。
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public function page(array $actor, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(1_000_000, $offset));
        $query = $this->scopedDeletionQuery($actor)
            ->where('deletions.status', 'completed')->whereNull('deletions.purged_at');
        $total = (clone $query)->count('deletions.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('deletions.created_at')->orderByDesc('deletions.id')
            ->offset($offset)->limit($limit)->get([
                'deletions.id', 'deletions.song_id', 'deletions.trash_entry_name', 'deletions.file_size',
                'deletions.created_at', 'deletions.operation_state', 'libraries.id as library_id',
                'libraries.name as library_name', 'actors.display_name as actor_name',
            ])->all();

        return ['items' => array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'songId' => (string) $row->song_id,
            'fileName' => (string) $row->trash_entry_name,
            'fileSizeBytes' => (int) $row->file_size,
            'deletedAt' => (string) $row->created_at,
            'deletedBy' => $row->actor_name === null ? null : (string) $row->actor_name,
            'operationState' => (string) $row->operation_state,
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
        ], $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * 把一个回收文件原子移回原音乐库位置，并请求增量扫描重建业务索引。
     *
     * 目标必须仍是原 active local 音乐库，父目录真实路径不得含符号链接，且目标必须不存在。领取后会
     * 同时校验数据库、receipt 与回收文件的 deletion/library/song/relativePath/device/inode/size/mtime；
     * 任一不符都不会移动文件。数据库提交失败时仅在目标仍是本次文件时补偿移回回收区。扫描入队发生在
     * 恢复提交之后，失败不回滚已经恢复的文件，响应以 scanStatus 提示管理员可手工重新扫描。
     *
     * @return array{id:string,status:string,fileName:string,scanStatus:string}
     */
    public function restore(array $actor, string $deletionId, string $requestId): array
    {
        $row = $this->findScopedDeletion($actor, $deletionId);
        if ((string) $row->status === 'restored') {
            return ['id' => $deletionId, 'status' => 'restored', 'fileName' => (string) $row->trash_entry_name,
                'scanStatus' => 'not_requested'];
        }
        if ($row->purged_at !== null) throw new MediaDeletionNotFound('回收条目不存在。');
        if ((string) $row->operation_state === 'purging') {
            throw new MediaDeletionConflict('回收条目正在永久删除。');
        }
        $resuming = (string) $row->operation_state === 'restoring';
        if (!$resuming) $this->claimOperation($row, 'restoring', $requestId);
        $row = $this->findScopedDeletion($actor, $deletionId);

        $moved = false;
        $trashPath = '';
        $target = '';
        try {
            [$directory, $trashPath, $receipt] = $this->verifiedTrashEntry($row, $resuming);
            $target = $this->restoreTarget($row, $resuming);
            if (is_file($trashPath)) {
                if (!rename($trashPath, $target)) throw new MediaDeletionUnavailable('媒体文件恢复移动失败。');
            } elseif (!$resuming || !is_file($target)) {
                throw new MediaDeletionConflict('恢复媒体现场不完整。');
            }
            $moved = true;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::transaction(function () use ($actor, $deletionId, $requestId, $now): void {
                $updated = Db::table('media_song_deletions')->where('id', $deletionId)
                    ->where('status', 'completed')->where('operation_state', 'restoring')->update([
                        'status' => 'restored', 'operation_state' => 'idle', 'operation_request_id' => $requestId,
                        'restored_at' => $now,
                    ]);
                if ($updated !== 1) throw new MediaDeletionConflict('回收条目状态已经变化。');
                $this->audit->record((string) $actor['id'], 'media.trash.restore', 'media_song_deletion',
                    $deletionId, 'success', $requestId, []);
            });
            @unlink($receipt);
            @rmdir($directory);
        } catch (Throwable $failure) {
            if ($moved && $target !== '' && $trashPath !== '' && !file_exists($trashPath) && is_file($target)) {
                if (!rename($target, $trashPath)) {
                    throw new MediaDeletionUnavailable('恢复提交失败且无法把媒体移回回收区。', previous: $failure);
                }
            }
            Db::table('media_song_deletions')->where('id', $deletionId)->where('operation_state', 'restoring')
                ->update(['operation_state' => 'idle', 'operation_request_id' => null]);
            if ($failure instanceof MediaDeletionConflict || $failure instanceof MediaDeletionInvalid) throw $failure;
            throw new MediaDeletionUnavailable('媒体恢复未完成。', previous: $failure);
        }

        try {
            $scanStatus = $this->scans->queueAutomaticJob((string) $row->library_id, 'download_import');
        } catch (Throwable) {
            $scanStatus = 'unavailable';
        }
        return ['id' => $deletionId, 'status' => 'restored', 'fileName' => (string) $row->trash_entry_name,
            'scanStatus' => $scanStatus];
    }

    /**
     * 永久删除一个已领取的回收文件和内部 receipt，并保留去路径化数据库历史。
     *
     * 调用方必须提交固定确认文本。第一次执行在删除任何字节前复验完整回收身份；unlink 后无法补偿，
     * 因此中途失败会保留 `purging` 状态。后续永久删除请求只允许继续清理该目录或在目录已经消失时提交
     * `purged_at`，绝不把文件缺失误当成一次新的普通删除成功。恢复命令不能领取 purging 条目。
     *
     * @return array{id:string,status:string,fileName:string}
     */
    public function purge(array $actor, string $deletionId, string $confirmation, string $requestId): array
    {
        if ($confirmation !== 'PURGE MEDIA') throw new MediaDeletionInvalid('永久删除确认文本无效。');
        $row = $this->findScopedDeletion($actor, $deletionId);
        if ($row->purged_at !== null) {
            return ['id' => $deletionId, 'status' => 'purged', 'fileName' => (string) $row->trash_entry_name];
        }
        if ((string) $row->status !== 'completed') throw new MediaDeletionConflict('已恢复媒体不能永久删除。');
        $resuming = (string) $row->operation_state === 'purging';
        if (!$resuming) $this->claimOperation($row, 'purging', $requestId);
        $row = $this->findScopedDeletion($actor, $deletionId);

        $irreversible = false;
        try {
            $trashRoot = $this->trashRoot();
            $directory = $trashRoot . DIRECTORY_SEPARATOR . $deletionId;
            if (is_dir($directory)) {
                $remaining = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
                if ($resuming && $remaining === []) {
                    if (!rmdir($directory)) throw new MediaDeletionUnavailable('回收目录清理失败。');
                } else {
                    [$directory, $trashPath, $receipt] = $this->verifiedTrashEntry($row, $resuming);
                    if (is_file($trashPath)) {
                        if (!unlink($trashPath)) throw new MediaDeletionUnavailable('回收媒体永久删除失败。');
                        $irreversible = true;
                    }
                    if (is_file($receipt) && !unlink($receipt)) {
                        throw new MediaDeletionUnavailable('回收凭据清理失败。');
                    }
                    if (!rmdir($directory)) throw new MediaDeletionUnavailable('回收目录清理失败。');
                }
            } elseif (!$resuming) {
                throw new MediaDeletionConflict('回收文件已经变化。');
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::transaction(function () use ($actor, $deletionId, $requestId, $now): void {
                $updated = Db::table('media_song_deletions')->where('id', $deletionId)
                    ->where('status', 'completed')->whereNull('purged_at')->where('operation_state', 'purging')
                    ->update(['purged_at' => $now, 'operation_state' => 'idle', 'operation_request_id' => $requestId]);
                if ($updated !== 1) throw new MediaDeletionConflict('回收条目状态已经变化。');
                $this->audit->record((string) $actor['id'], 'media.trash.purge', 'media_song_deletion',
                    $deletionId, 'success', $requestId, []);
            });
        } catch (Throwable $failure) {
            if (!$resuming && !$irreversible) {
                Db::table('media_song_deletions')->where('id', $deletionId)->where('operation_state', 'purging')
                    ->update(['operation_state' => 'idle', 'operation_request_id' => null]);
            }
            if ($failure instanceof MediaDeletionConflict || $failure instanceof MediaDeletionInvalid) throw $failure;
            throw new MediaDeletionUnavailable('永久删除未完成，操作现场已保留以便重试。', previous: $failure);
        }

        return ['id' => $deletionId, 'status' => 'purged', 'fileName' => (string) $row->trash_entry_name];
    }

    /**
     * 构造按当前音乐库管理范围裁剪的回收查询。
     *
     * 超级管理员仍只看到当前存在的音乐库；普通管理员必须拥有目标库 manage grant。查询只连接显示名，
     * 不把用户邮箱、库根或回收路径加入默认投影，具体命令必须显式选择内部校验列。
     */
    private function scopedDeletionQuery(array $actor): mixed
    {
        $query = Db::table('media_song_deletions as deletions')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'deletions.library_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'deletions.actor_user_id');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as grants', function ($join) use ($actor): void {
                $join->on('grants.library_id', '=', 'deletions.library_id')
                    ->where('grants.user_id', '=', (string) $actor['id'])
                    ->where('grants.access_level', '=', 'manage');
            });
        }
        return $query;
    }

    /** 读取命令所需内部事实；不存在、失权和无效 ID统一为不泄露对象存在性的 404。 */
    private function findScopedDeletion(array $actor, string $deletionId): stdClass
    {
        if (!Ulid::isValid($deletionId)) throw new MediaDeletionNotFound('回收条目不存在。');
        /** @var stdClass|null $row */
        $row = $this->scopedDeletionQuery($actor)->where('deletions.id', $deletionId)->first([
            'deletions.*', 'libraries.name as library_name', 'libraries.status as library_status',
            'libraries.source_type', 'libraries.resolved_root_path',
        ]);
        if (!$row instanceof stdClass) throw new MediaDeletionNotFound('回收条目不存在。');
        return $row;
    }

    /**
     * 以条件更新领取恢复或永久删除操作。
     *
     * 只有 completed、未永久清理且 idle 的条目可领取；失败表示另一个请求已经取得文件操作所有权。
     * 领取事务不接触文件，后续任何可补偿失败会归还 idle，不可逆永久删除失败则保留 purging。
     */
    private function claimOperation(stdClass $row, string $operation, string $requestId): void
    {
        if ($requestId === '' || !in_array($operation, ['restoring', 'purging'], true)) {
            throw new MediaDeletionInvalid('回收操作请求无效。');
        }
        $updated = Db::table('media_song_deletions')->where('id', (string) $row->id)
            ->where('status', 'completed')->whereNull('purged_at')->where('operation_state', 'idle')
            ->update(['operation_state' => $operation, 'operation_request_id' => $requestId]);
        if ($updated !== 1) throw new MediaDeletionConflict('回收条目正在执行其他操作。');
    }

    /**
     * 复验一个回收目录、receipt 和普通文件的完整身份。
     *
     * 目录必须精确位于配置回收根的 deletion ULID 子目录，且只能包含数据库声明的 basename 与
     * `receipt.json`。receipt 的业务标识和原相对路径必须与数据库一致；普通文件继续比较删除前的
     * device/inode/size/mtime。永久删除恢复阶段可允许音频已被前次 unlink，但 receipt 仍必须存在并
     * 通过校验；任何未知内容或链接都失败关闭。
     *
     * @return array{string,string,string} 回收目录、音频路径和 receipt 路径
     */
    private function verifiedTrashEntry(stdClass $row, bool $allowMissingAudio = false): array
    {
        $trashRoot = $this->trashRoot();
        $this->assertTrashDoesNotOverlapLibraries($trashRoot);
        $directory = $trashRoot . DIRECTORY_SEPARATOR . (string) $row->id;
        if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
            throw new MediaDeletionConflict('回收目录身份已经变化。');
        }
        $entry = (string) $row->trash_entry_name;
        if ($entry === '' || basename($entry) !== $entry || in_array($entry, ['.', '..', 'receipt.json'], true)) {
            throw new MediaDeletionConflict('回收文件名无效。');
        }
        $trashPath = $directory . DIRECTORY_SEPARATOR . $entry;
        $receiptPath = $directory . DIRECTORY_SEPARATOR . 'receipt.json';
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        foreach ($entries as $candidate) {
            if (!in_array($candidate, [$entry, 'receipt.json'], true)) {
                throw new MediaDeletionConflict('回收目录包含未知内容。');
            }
        }
        if (!is_file($receiptPath) || is_link($receiptPath)) throw new MediaDeletionConflict('回收凭据缺失。');
        try {
            $receipt = json_decode((string) file_get_contents($receiptPath), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $failure) {
            throw new MediaDeletionConflict('回收凭据无效。', previous: $failure);
        }
        $expected = [
            'version' => 1, 'deletionId' => (string) $row->id, 'songId' => (string) $row->song_id,
            'libraryId' => (string) $row->library_id, 'relativePath' => (string) $row->original_relative_path,
            'deviceId' => (int) $row->device_id, 'inode' => (int) $row->inode,
            'fileSize' => (int) $row->file_size, 'modifiedAt' => (int) $row->modified_at,
        ];
        if (!is_array($receipt) || $receipt !== $expected) throw new MediaDeletionConflict('回收凭据不匹配。');
        if (!file_exists($trashPath)) {
            if (!$allowMissingAudio) throw new MediaDeletionConflict('回收媒体缺失。');
            return [$directory, $trashPath, $receiptPath];
        }
        if (is_link($trashPath) || !is_file($trashPath)) throw new MediaDeletionConflict('回收媒体身份异常。');
        $stat = lstat($trashPath);
        if (!is_array($stat) || (int) ($stat['dev'] ?? -1) !== (int) $row->device_id
            || (int) ($stat['ino'] ?? -1) !== (int) $row->inode
            || (int) ($stat['size'] ?? -1) !== (int) $row->file_size
            || (int) ($stat['mtime'] ?? -1) !== (int) $row->modified_at) {
            throw new MediaDeletionConflict('回收媒体身份已经变化。');
        }
        return [$directory, $trashPath, $receiptPath];
    }

    /**
     * 解析并复验恢复目标，目标和所有父目录必须仍位于原音乐库真实根内。
     *
     * 本方法不创建目录、不覆盖文件，也不接受绝对路径、空段、`.`、`..` 或 NUL。父目录 lexical 路径
     * 必须等于 realpath，借此拒绝任一层符号链接；音乐库停用、改为外部源或根身份漂移均失败关闭。
     * 只有恢复操作已经被领取且回收文件缺失时，才接受目标处 device/inode/size/mtime 全部匹配的同一
     * 文件，用于进程在 rename 后、数据库提交前中断的幂等收口；其他已存在目标一律视为冲突。
     */
    private function restoreTarget(stdClass $row, bool $allowOwnedTarget = false): string
    {
        if ((string) $row->library_status !== 'active' || (string) $row->source_type !== 'local') {
            throw new MediaDeletionConflict('原音乐库当前不可恢复。');
        }
        $relative = str_replace('\\', '/', (string) $row->original_relative_path);
        $segments = explode('/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, "\0")
            || array_filter($segments, static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..') !== []) {
            throw new MediaDeletionConflict('原媒体相对路径无效。');
        }
        $root = rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR);
        if (realpath($root) !== $root || !is_dir($root) || is_link($root)) {
            throw new MediaDeletionConflict('原音乐库根身份已经变化。');
        }
        $target = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $parent = dirname($target);
        $resolvedParent = realpath($parent);
        if ($resolvedParent === false || $resolvedParent !== $parent
            || ($resolvedParent !== $root && !str_starts_with($resolvedParent, $root . DIRECTORY_SEPARATOR))
            || !is_writable($resolvedParent) || is_link($target)) {
            throw new MediaDeletionConflict('原位置不存在、不可写或已被占用。');
        }
        if (file_exists($target)) {
            $stat = lstat($target);
            if (!$allowOwnedTarget || !is_file($target) || !is_array($stat)
                || (int) ($stat['dev'] ?? -1) !== (int) $row->device_id
                || (int) ($stat['ino'] ?? -1) !== (int) $row->inode
                || (int) ($stat['size'] ?? -1) !== (int) $row->file_size
                || (int) ($stat['mtime'] ?? -1) !== (int) $row->modified_at) {
                throw new MediaDeletionConflict('原位置不存在、不可写或已被占用。');
            }
        }
        return $target;
    }

    /** @return stdClass|null */
    private function findManagedLocalSong(array $actor, string $songId): ?stdClass
    {
        $query = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('songs.id', $songId)->where('libraries.status', 'active')->where('files.status', 'available');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as grants', function ($join) use ($actor): void {
                $join->on('grants.library_id', '=', 'songs.library_id')
                    ->where('grants.user_id', '=', (string) $actor['id'])
                    ->where('grants.access_level', '=', 'manage');
            });
        }
        return $query->first([
            'songs.id as song_id', 'songs.library_id', 'songs.inventory_file_id',
            'files.relative_path', 'files.resolved_path', 'files.device_id', 'files.inode',
            'files.file_size', 'files.modified_at', 'libraries.resolved_root_path', 'libraries.source_type',
        ]);
    }

    /** 复验根、规范路径和 stat 身份，防止挂载漂移、软链接和路径替换导致误删。 */
    private function assertLocalFileIdentity(stdClass $row): void
    {
        $root = rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR);
        $currentRoot = realpath($root);
        if ($currentRoot === false || $currentRoot !== $root || !is_dir($currentRoot) || is_link($root)) {
            throw new MediaDeletionConflict('音乐库根目录身份已变化。');
        }
        $path = (string) $row->resolved_path;
        $currentPath = realpath($path);
        $prefix = $root . DIRECTORY_SEPARATOR;
        if (is_link($path) || $currentPath === false || $currentPath !== $path
            || !str_starts_with($currentPath, $prefix) || !is_file($currentPath)
            || !is_readable($currentPath) || !is_writable(dirname($currentPath))) {
            throw new MediaDeletionConflict('媒体文件路径或权限已变化。');
        }
        $stat = lstat($path);
        if (!is_array($stat) || (int) ($stat['dev'] ?? -1) !== (int) $row->device_id
            || (int) ($stat['ino'] ?? -1) !== (int) $row->inode
            || (int) ($stat['size'] ?? -1) !== (int) $row->file_size
            || (int) ($stat['mtime'] ?? -1) !== (int) $row->modified_at) {
            throw new MediaDeletionConflict('媒体文件身份已变化，请重新扫描后重试。');
        }
    }

    /** 创建并校验固定媒体回收根；环境变量只能选择 `/media` 下的非缓存目录。 */
    private function trashRoot(): string
    {
        $root = rtrim((string) (getenv('VELIN_MEDIA_TRASH_PATH') ?: '/media/.velin-trash'), DIRECTORY_SEPARATOR);
        if ($root === '' || !str_starts_with($root, '/media/') || str_contains($root, "\0")
            || preg_match('#(?:^|/)(?:\\.|\\.\\.)(?:/|$)#', $root) === 1) {
            throw new MediaDeletionInvalid('媒体回收根目录配置无效。');
        }
        $cache = rtrim((string) (getenv('VELIN_SCRAPE_CACHE_PATH') ?: '/media/cache/scrape'), DIRECTORY_SEPARATOR);
        if ($root === $cache || str_starts_with($root . '/', $cache . '/') || str_starts_with($cache . '/', $root . '/')) {
            throw new MediaDeletionInvalid('媒体回收根目录不能与刮削缓存重叠。');
        }
        $parent = realpath(dirname($root));
        if ($parent === false || !str_starts_with($parent . '/', '/media/')) {
            throw new MediaDeletionUnavailable('媒体回收根目录父目录不可用。');
        }
        if (file_exists($root) && is_link($root)) {
            throw new MediaDeletionUnavailable('媒体回收根目录不能是符号链接。');
        }
        if (!file_exists($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
            throw new MediaDeletionUnavailable('无法创建媒体回收根目录。');
        }
        $resolved = realpath($root);
        if ($resolved === false || $resolved !== $root || !is_dir($root) || !is_writable($root)) {
            throw new MediaDeletionUnavailable('媒体回收根目录不可写。');
        }
        return $resolved;
    }

    /** 回收根不能落入任何已登记音乐库，防止扫描再次索引回收文件。 */
    private function assertTrashDoesNotOverlapLibraries(string $trashRoot): void
    {
        foreach (Db::table('music_libraries')->where('status', 'active')->where('source_type', 'local')
            ->get(['resolved_root_path']) as $library) {
            $root = rtrim((string) $library->resolved_root_path, DIRECTORY_SEPARATOR);
            if ($root === $trashRoot || str_starts_with($trashRoot . '/', $root . '/') || str_starts_with($root . '/', $trashRoot . '/')) {
                throw new MediaDeletionInvalid('媒体回收根目录与音乐库重叠。');
            }
        }
    }

    /** 写入内部回收凭据；不把物理路径写入审计或 HTTP 响应。 */
    private function writeReceipt(string $path, stdClass $row, string $deletionId): void
    {
        $payload = json_encode([
            'version' => 1, 'deletionId' => $deletionId, 'songId' => (string) $row->song_id,
            'libraryId' => (string) $row->library_id, 'relativePath' => (string) $row->relative_path,
            'deviceId' => (int) $row->device_id, 'inode' => (int) $row->inode,
            'fileSize' => (int) $row->file_size, 'modifiedAt' => (int) $row->modified_at,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new MediaDeletionUnavailable('无法写入回收凭据。');
        }
    }

    /** 数据库失败后只恢复本次创建的目标，失败则保留回收现场供人工处理。 */
    private function compensateMove(string $source, string $trashPath, string $receipt, string $directory, Throwable $failure): void
    {
        $restored = !file_exists($source) && is_file($trashPath) && rename($trashPath, $source);
        if ($restored) {
            @unlink($receipt);
            @rmdir($directory);
            return;
        }
        throw new MediaDeletionUnavailable('数据库失败且无法恢复媒体文件，回收现场已保留。', previous: $failure);
    }
}
