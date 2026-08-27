<?php

declare(strict_types=1);

namespace app\application\Notification;

use JsonException;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 在调用者业务事务内持久化仅供后台人员查看的任务、安全和权限通知。
 *
 * 发布器不开始或提交事务，扫描终态、审计与通知必须由 Worker 的同一事务保证原子性。输入只接受服务端
 * 生成的任务快照和标量计数，禁止保存请求正文、物理路径、异常消息或命令输出。写入前实时复核接收者
 * 仍持有后台能力，并遵守扫描静音偏好；成功任务按任务 ID 去重，同库同稳定错误码的重复失败原子聚合并
 * 重新变为未读。任一编码或数据库失败向调用者抛出，使外围事务整体回滚。
 */
final class NotificationPublisher
{
    /** @var list<string> 只有后台能力持有者可以成为通知中心接收者。 */
    private const ADMIN_CAPABILITIES = [
        'manage_users', 'manage_library', 'manage_storage', 'manage_system',
        'view_audit', 'run_scrape', 'edit_metadata', 'view_play_privacy',
    ];

    /**
     * 发布逐曲刮削进入人工候选确认边界的后台提醒。
     *
     * 调用方必须与候选快照、awaiting_confirmation 和父任务计数处于同一 SQLite 事务；通知按任务 ID
     * 幂等，Worker 重试不会重复计数或把管理员已经读过的同一事件重新变为未读。上下文只保存有界歌曲名
     * 与 matched 渠道数量，绝不保存候选 JSON、歌词正文、封面 URL 或物理路径。接收者失去后台身份时
     * 无副作用返回；数据库写入失败由调用方回滚整个等待状态，避免提醒与任务事实分叉。
     */
    public function publishScrapeAwaitingConfirmation(
        string $userId,
        string $libraryId,
        string $jobId,
        string $songTitle,
        int $matchedCandidateCount,
        string $now,
    ): void {
        // 隔离模块测试和迁移前滚动实例尚无通知表时保持原有刮削边界；正式入口会先完成全部迁移再启动 Worker。
        if (!Db::connection()->getSchemaBuilder()->hasTable('user_notifications')) return;
        if (!$this->isAdminRecipient($userId)) return;
        foreach ([$libraryId, $jobId] as $id) {
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
                throw new NotificationInvalid('Invalid scrape notification object.');
            }
        }
        $contextJson = json_encode([
            'songTitle' => $this->bounded($songTitle, 160),
            'matchedCandidateCount' => max(0, min(7, $matchedCandidateCount)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', (strtotime($now) ?: time()) + 90 * 86400);
        Db::statement(<<<'SQL'
INSERT INTO user_notifications (
    id, user_id, notification_type, kind, severity, library_id, object_type, object_id,
    context_json, dedupe_key, aggregate_count, read_at, expires_at,
    first_occurred_at, last_occurred_at, created_at, updated_at
) VALUES (?, ?, 'scrape', 'scrape.awaiting_confirmation', 'warning', ?,
    'metadata_sync_scrape_job', ?, ?, ?, 1, NULL, ?, ?, ?, ?, ?)
ON CONFLICT(user_id, dedupe_key) DO NOTHING
SQL, [
            (string) new Ulid(), $userId, $libraryId, $jobId, $contextJson,
            'scrape.awaiting_confirmation:' . $jobId,
            $expiresAt, $now, $now, $now, $now,
        ]);
    }

    /**
     * 按新建数据库会话记录发布一条不可变登录成功事件。
     *
     * 前置条件是目标账号仍为后台人员；普通用户直接跳过。事件刻意不保存 Cookie/会话摘要、IP、User-Agent
     * 或请求头，接收者和发生时间已足够形成安全历史。安全事件不允许被静音；相同会话键重试只会聚合。
     */
    public function publishLogin(string $userId, string $sessionRecordId, string $now): void
    {
        if (!$this->isAdminRecipient($userId)) return;
        $this->publishImmutable(
            $userId,
            'security',
            'security.login',
            'info',
            ['event' => 'login'],
            'security.login:' . $sessionRecordId,
            $now,
            180,
        );
    }

    /**
     * 向仍有后台能力的受影响账号发布一条不可变权限变更通知。
     *
     * `changeType` 和值必须来自固定服务端状态，不能传入任意控制器文本。库名称在 JSON 编码前截断，
     * 不保存路径、角色秘密、邮箱或操作者身份。调用者提供由 requestId 与接收者等组成的稳定事件键，事务
     * 重试时只聚合而不会制造重复通知；非法变更类型会抛错并由外围事务回滚。
     */
    public function publishPermissionChange(
        string $userId,
        string $changeType,
        string $value,
        string $eventKey,
        string $now,
        ?string $libraryName = null,
    ): void {
        if (!$this->isAdminRecipient($userId)) return;
        if (!in_array($changeType, ['account_status', 'library_access', 'role', 'direct_capabilities'], true)) {
            throw new NotificationInvalid('Unsupported permission change type.');
        }
        $this->publishImmutable(
            $userId,
            'permission',
            'permission.changed',
            'warning',
            [
                'changeType' => $changeType,
                'value' => substr($value, 0, 32),
                'libraryName' => $libraryName === null ? null : $this->bounded($libraryName, 160),
            ],
            'permission.changed:' . substr(hash('sha256', $eventKey), 0, 40),
            $now,
            365,
        );
    }

    /**
     * 为存储和破坏性操作失败提供稳定的高严重度后台通知入口。
     *
     * 仅接受服务端稳定错误码和去重范围；正文不接收异常或路径。该入口不能发布扫描事件，因为扫描另有
     * 静音和对象范围语义。未知类型抛出校验异常，账号不再属于后台时无副作用返回。
     */
    public function publishOperationalFailure(
        string $userId,
        string $type,
        string $stableErrorCode,
        string $dedupeScope,
        string $now,
    ): void {
        if (!$this->isAdminRecipient($userId)) return;
        $definition = match ($type) {
            'storage' => ['storage.critical', 'critical'],
            'destructive' => ['destructive.failed', 'critical'],
            default => throw new NotificationInvalid('Unsupported operational notification type.'),
        };
        $this->publishImmutable(
            $userId,
            $type,
            $definition[0],
            $definition[1],
            ['errorCode' => $this->bounded($stableErrorCode, 80)],
            $type . '.failure:' . substr(hash('sha256', $dedupeScope . ':' . $stableErrorCode), 0, 40),
            $now,
            365,
        );
    }

    /**
     * 在不执行外部 I/O 的前提下发布一条扫描成功或失败事件。
     *
     * 请求者为空或已不具备后台能力时不创建通知；扫描静音同样在写入前检查。成功按任务 ID 幂等，失败按
     * 音乐库与稳定错误码聚合。JSON 编码或数据库写入失败必须让外围终态事务回滚，不能提交一个缺失其必需
     * 通知的任务终态。
     *
     * @param array<string, mixed> $job Worker 持有的任务快照，包含任务、请求者、音乐库和扫描类型。
     * @param array<string, int> $facts 只用于本地化摘要的安全终态计数。
     * @throws JsonException 上下文无法编码时抛出，调用者必须回滚整个业务事务。
     */
    public function publishScanTerminal(
        array $job,
        string $status,
        array $facts,
        ?string $errorCode,
        string $now,
    ): void {
        $userId = $job['requestedBy'] ?? null;
        if (!is_string($userId) || $userId === '') {
            return;
        }
        if (!$this->isAdminRecipient($userId)) return;
        if (!in_array($status, ['succeeded', 'failed'], true)) {
            throw new NotificationInvalid('Unsupported scan terminal status.');
        }
        if (Db::table('user_notification_mutes')
            ->where('user_id', $userId)
            ->where('notification_type', 'scan')
            ->exists()) {
            return;
        }

        $jobId = (string) ($job['id'] ?? '');
        $libraryId = (string) ($job['libraryId'] ?? '');
        $libraryName = Db::table('music_libraries')->where('id', $libraryId)->value('name');
        $kind = 'scan.' . $status;
        $severity = $status === 'failed' ? 'error' : 'info';
        $stableError = $status === 'failed' && is_string($errorCode) && $errorCode !== ''
            ? substr($errorCode, 0, 80)
            : null;
        $context = [
            'libraryName' => is_string($libraryName) ? $libraryName : '',
            'scanType' => in_array($job['scanType'] ?? null, ['incremental', 'full'], true)
                ? (string) $job['scanType']
                : 'incremental',
            'discoveredFiles' => max(0, (int) ($facts['discoveredFiles'] ?? 0)),
            'addedFiles' => max(0, (int) ($facts['addedFiles'] ?? 0)),
            'failedEntries' => max(0, (int) ($facts['failedEntries'] ?? 0)),
            'errorCode' => $stableError,
        ];
        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $dedupeKey = $status === 'failed'
            ? sprintf('scan.failed:%s:%s', $libraryId, $stableError ?? 'UNKNOWN')
            : sprintf('scan.succeeded:%s', $jobId);
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', (strtotime($now) ?: time()) + ($status === 'failed' ? 90 : 30) * 86400);

        // SQLite 冲突语法集中在此边界；未来 MySQL 适配必须保持计数递增与重新未读的原子性。
        Db::statement(<<<'SQL'
INSERT INTO user_notifications (
    id, user_id, notification_type, kind, severity, library_id, object_type, object_id,
    context_json, dedupe_key, aggregate_count, read_at, expires_at,
    first_occurred_at, last_occurred_at, created_at, updated_at
) VALUES (?, ?, 'scan', ?, ?, ?, 'library_scan_job', ?, ?, ?, 1, NULL, ?, ?, ?, ?, ?)
ON CONFLICT(user_id, dedupe_key) DO UPDATE SET
    severity = excluded.severity,
    library_id = excluded.library_id,
    object_type = excluded.object_type,
    object_id = excluded.object_id,
    context_json = excluded.context_json,
    aggregate_count = user_notifications.aggregate_count + 1,
    read_at = NULL,
    expires_at = excluded.expires_at,
    last_occurred_at = excluded.last_occurred_at,
    updated_at = excluded.updated_at
SQL, [
            (string) new Ulid(), $userId, $kind, $severity, $libraryId, $jobId,
            $contextJson, $dedupeKey, $expiresAt, $now, $now, $now, $now,
        ]);
    }

    /**
     * 不读取静音偏好，插入或聚合一条不可变接收者事件。
     *
     * 外围事务由调用者持有；相同稳定键会原子递增、刷新到期时间并重新标为未读。方法不执行外部 I/O，
     * 只保存公共发布方法组装的白名单标量上下文。编码或写入失败由调用者回滚，不提供局部补偿。
     *
     * @param array<string, scalar|null> $context
     * @throws JsonException 上下文编码失败时抛出，调用者必须回滚业务事务。
     */
    private function publishImmutable(
        string $userId,
        string $type,
        string $kind,
        string $severity,
        array $context,
        string $dedupeKey,
        string $now,
        int $retentionDays,
    ): void {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1) {
            throw new NotificationInvalid('Invalid notification recipient.');
        }
        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', (strtotime($now) ?: time()) + $retentionDays * 86400);
        Db::statement(<<<'SQL'
INSERT INTO user_notifications (
    id, user_id, notification_type, kind, severity, library_id, object_type, object_id,
    context_json, dedupe_key, aggregate_count, read_at, expires_at,
    first_occurred_at, last_occurred_at, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, 1, NULL, ?, ?, ?, ?, ?)
ON CONFLICT(user_id, dedupe_key) DO UPDATE SET
    kind = excluded.kind,
    severity = excluded.severity,
    context_json = excluded.context_json,
    aggregate_count = user_notifications.aggregate_count + 1,
    read_at = NULL,
    expires_at = excluded.expires_at,
    last_occurred_at = excluded.last_occurred_at,
    updated_at = excluded.updated_at
SQL, [
            (string) new Ulid(), $userId, $type, $kind, $severity, $contextJson, $dedupeKey,
            $expiresAt, $now, $now, $now, $now,
        ]);
    }

    /** 截断可信展示文本或稳定错误码；没有多字节扩展时仍保持确定的最大字节边界。 */
    private function bounded(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }

    /**
     * 判断目标账号当前是否仍属于后台人员。
     *
     * 超级管理员天然成立；其他账号必须通过实时角色关联持有至少一项固定管理能力。查询在调用者事务内
     * 执行，因此角色收回和通知写入保持同一数据库顺序。账号不存在、已删除或已停用时返回 false，不
     * 创建稍后无法读取的孤立通知。此方法不缓存权限，也不把角色或能力详情写入事件正文。
     */
    private function isAdminRecipient(string $userId): bool
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1) {
            throw new NotificationInvalid('Invalid notification recipient.');
        }
        return Db::table('users')->where('users.id', $userId)
            ->where('users.status', 'active')->whereNull('users.deleted_at')
            ->where(static function ($query): void {
                $query->where('users.is_super_admin', 1)
                    ->orWhereExists(static function ($capabilities): void {
                        $capabilities->selectRaw('1')->from('user_roles')
                            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'user_roles.role_id')
                            ->whereColumn('user_roles.user_id', 'users.id')
                            ->whereIn('role_capabilities.capability_key', self::ADMIN_CAPABILITIES);
                    });
            })->exists();
    }
}
