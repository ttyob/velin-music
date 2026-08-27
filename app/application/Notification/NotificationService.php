<?php

declare(strict_types=1);

namespace app\application\Notification;

use JsonException;
use stdClass;
use support\Db;

/**
 * 管理当前后台用户的通知列表、已读状态和非安全类静音偏好。
 *
 * 所有查询先按接收者 ID 隔离，再以调用者当前 manage 级音乐库快照重新授权对象链接与上下文。撤权后
 * 仍保留可审计的脱敏事件，但移除库名、计数、对象 ID 和跳转。标记已读命令幂等且不修改任务；过期行
 * 留给维护 Worker 分批清理，在此之前也不会进入任何列表或未读计数。
 */
final class NotificationService
{
    /**
     * 返回有界、按最新事件倒序的通知页，并逐行应用当前对象权限。
     *
     * @param array<string, mixed> $actor SessionService 解析的当前身份和音乐库授权快照。
     * @return array{notifications: list<array<string, mixed>>, total: int, allTotal: int, unread: int, limit: int, offset: int, mutes: array<string, bool>}
     * @throws JsonException 持久上下文违反发布器与 schema 契约时失败，不返回不可信的部分列表。
     */
    public function list(
        array $actor,
        string $status,
        ?string $severity,
        ?string $type,
        int $limit,
        int $offset,
    ): array {
        $userId = $this->actorId($actor);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $query = Db::table('user_notifications')
            ->where('user_id', $userId)
            ->where('expires_at', '>', $now);
        $allTotal = (clone $query)->count();
        if ($status === 'unread') {
            $query->whereNull('read_at');
        }
        if ($severity !== null) {
            $query->where('severity', $severity);
        }
        if ($type !== null) {
            $query->where('notification_type', $type);
        }

        $total = (clone $query)->count();
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('last_occurred_at')->orderByDesc('id')
            ->offset($offset)->limit($limit)->get()->all();

        return [
            'notifications' => array_map(fn (stdClass $row): array => $this->map($row, $actor), $rows),
            'total' => $total,
            'allTotal' => $allTotal,
            'unread' => $this->unreadCount($userId, $now),
            'limit' => $limit,
            'offset' => $offset,
            'mutes' => $this->mutes($userId),
        ];
    }

    /** 返回当前接收者未过期的未读数，供后台导航角标读取；本方法不改变已读状态。 */
    public function counts(array $actor): array
    {
        $userId = $this->actorId($actor);

        return ['unread' => $this->unreadCount($userId, gmdate('Y-m-d\TH:i:s\Z'))];
    }

    /** 把一条本人且未过期的通知标为已读；重复命令成功但不重复写入，也不修改关联任务。 */
    public function markRead(array $actor, string $notificationId): array
    {
        $userId = $this->actorId($actor);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        /** @var stdClass|null $row */
        $row = Db::table('user_notifications')
            ->where('id', $notificationId)
            ->where('user_id', $userId)
            ->where('expires_at', '>', $now)
            ->first();
        if (!$row instanceof stdClass) {
            throw new NotificationNotFound('Notification not found.');
        }
        if ($row->read_at === null) {
            Db::table('user_notifications')->where('id', $notificationId)->where('user_id', $userId)->update([
                'read_at' => $now,
                'updated_at' => $now,
            ]);
            $row->read_at = $now;
        }

        return $this->map($row, $actor);
    }

    /** 在一次有界 SQL 更新中把当前接收者全部未过期通知标为已读，不影响其他账号或过期历史。 */
    public function markAllRead(array $actor): int
    {
        $userId = $this->actorId($actor);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return Db::table('user_notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->where('expires_at', '>', $now)
            ->update(['read_at' => $now, 'updated_at' => $now]);
    }

    /**
     * 清空当前管理员账号的全部通知记录。
     *
     * 接收者 ID 只能来自可信会话快照，不接受请求体或筛选条件。带用户范围的单条 DELETE
     * 具备原子边界，删除触发器会为同一接收者写入实时失效事件；任务、审计记录和静音偏好
     * 不受影响。重复调用安全地返回 0，数据库错误向上抛出以避免页面误报成功。
     *
     * @return int 实际删除的通知行数。
     */
    public function clearAll(array $actor): int
    {
        $userId = $this->actorId($actor);

        return Db::table('user_notifications')
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * 幂等设置或清除白名单内的非安全通知静音偏好。
     *
     * 静音只影响后续发布，现有事件继续保留，不能借此删除已经送达的运维记录。当前只有非破坏性的
     * `scan` 可静音；安全、权限和破坏性类型在此边界失败关闭。数据库失败不吞掉，由控制器返回失败。
     */
    public function setMuted(array $actor, string $type, bool $muted): array
    {
        if ($type !== 'scan') {
            throw new NotificationInvalid('Security and operational notification types cannot be muted.');
        }
        $userId = $this->actorId($actor);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($muted) {
            Db::table('user_notification_mutes')->upsert([[
                'user_id' => $userId,
                'notification_type' => $type,
                'muted_at' => $now,
                'updated_at' => $now,
            ]], ['user_id', 'notification_type'], ['updated_at']);
        } else {
            Db::table('user_notification_mutes')
                ->where('user_id', $userId)
                ->where('notification_type', $type)
                ->delete();
        }

        return ['type' => $type, 'muted' => $muted];
    }

    /** 映射一条本人通知；扫描和刮削任务都必须重新验证目标库当前 manage 权限，失权时只返回脱敏摘要。 */
    private function map(stdClass $row, array $actor): array
    {
        $isScan = (string) $row->notification_type === 'scan';
        $isScrape = (string) $row->notification_type === 'scrape';
        $isScopedTask = $isScan || $isScrape;
        $authorized = !$isScopedTask
            || $this->canOpenLibrary($actor, $row->library_id === null ? null : (string) $row->library_id);
        $context = $authorized
            ? $this->safeContext(
                json_decode((string) $row->context_json, true, 32, JSON_THROW_ON_ERROR),
                (string) $row->notification_type,
            )
            : [];
        $objectId = $isScopedTask && $authorized && is_string($row->object_id ?? null)
            && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $row->object_id) === 1
            ? (string) $row->object_id
            : null;

        return [
            'id' => (string) $row->id,
            'type' => (string) $row->notification_type,
            'kind' => (string) $row->kind,
            'severity' => (string) $row->severity,
            'context' => $context,
            'aggregateCount' => (int) $row->aggregate_count,
            'readAt' => $row->read_at === null ? null : (string) $row->read_at,
            'expiresAt' => (string) $row->expires_at,
            'createdAt' => (string) $row->first_occurred_at,
            'lastOccurredAt' => (string) $row->last_occurred_at,
            'redacted' => $isScopedTask && !$authorized,
            'actionHref' => $objectId === null ? null : ($isScrape
                ? '/admin/media?scrapeJobId=' . rawurlencode($objectId)
                : '/admin/jobs?type=scan&jobId=' . rawurlencode($objectId)),
        ];
    }

    /** 只返回本地化视图理解的固定标量字段；未知持久字段、候选正文和外部定位不会进入响应。 */
    private function safeContext(mixed $decoded, string $type): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        if ($type === 'security') {
            return ['event' => ($decoded['event'] ?? null) === 'login' ? 'login' : null];
        }
        if ($type === 'permission') {
            return [
                'changeType' => in_array($decoded['changeType'] ?? null, ['account_status', 'library_access', 'role'], true)
                    ? (string) $decoded['changeType'] : null,
                'value' => is_string($decoded['value'] ?? null) ? substr((string) $decoded['value'], 0, 32) : null,
                'libraryName' => is_string($decoded['libraryName'] ?? null)
                    ? $this->bounded((string) $decoded['libraryName'], 160) : null,
            ];
        }
        if (in_array($type, ['storage', 'destructive'], true)) {
            return ['errorCode' => is_string($decoded['errorCode'] ?? null)
                ? substr((string) $decoded['errorCode'], 0, 80) : null];
        }
        if ($type === 'scrape') {
            return [
                'songTitle' => is_string($decoded['songTitle'] ?? null)
                    ? $this->bounded((string) $decoded['songTitle'], 160) : '未知歌曲',
                'matchedCandidateCount' => max(0, min(7, (int) ($decoded['matchedCandidateCount'] ?? 0))),
            ];
        }

        return [
            'libraryName' => is_string($decoded['libraryName'] ?? null) ? (string) $decoded['libraryName'] : '',
            'scanType' => in_array($decoded['scanType'] ?? null, ['incremental', 'full'], true) ? (string) $decoded['scanType'] : 'incremental',
            'discoveredFiles' => max(0, (int) ($decoded['discoveredFiles'] ?? 0)),
            'addedFiles' => max(0, (int) ($decoded['addedFiles'] ?? 0)),
            'failedEntries' => max(0, (int) ($decoded['failedEntries'] ?? 0)),
            'errorCode' => is_string($decoded['errorCode'] ?? null) ? (string) $decoded['errorCode'] : null,
        ];
    }

    /** 对展示文本做长度上限；生产镜像有 mbstring，兼容测试环境缺失扩展时仍保持确定性。 */
    private function bounded(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }

    /** 即使通知行属于当前用户，任务对象也必须具备目标库当前 manage 权限才能展开。 */
    private function canOpenLibrary(array $actor, ?string $libraryId): bool
    {
        if ($libraryId === null) {
            return false;
        }
        if (($actor['isSuperAdmin'] ?? false) === true) {
            return true;
        }
        if (!in_array('manage_library', is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [], true)) {
            return false;
        }
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && ($library['id'] ?? null) === $libraryId && ($library['accessLevel'] ?? null) === 'manage') {
                return true;
            }
        }

        return false;
    }

    /** 只统计一个已认证接收者尚未过期的未读行。 */
    private function unreadCount(string $userId, string $now): int
    {
        return Db::table('user_notifications')->where('user_id', $userId)
            ->whereNull('read_at')->where('expires_at', '>', $now)->count();
    }

    /** @return array<string, bool> 返回完整偏好投影，未保存的类型显式为 false。 */
    private function mutes(string $userId): array
    {
        $muted = Db::table('user_notification_mutes')->where('user_id', $userId)
            ->pluck('notification_type')->map(static fn (mixed $value): string => (string) $value)->all();

        return ['scan' => in_array('scan', $muted, true)];
    }

    /** 从可信 SessionService 身份快照提取接收者键；缺失时失败关闭，禁止查询无用户范围的通知。 */
    private function actorId(array $actor): string
    {
        $id = $actor['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new NotificationInvalid('Authenticated actor ID is missing.');
        }

        return $id;
    }
}
