<?php

declare(strict_types=1);

namespace app\application\User;

use app\application\Auth\AuthorizationDenied;
use app\application\Notification\NotificationPublisher;
use app\http\RequestContext;
use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use support\Db;
use Throwable;

/**
 * 为批量启停和音乐库授权提供“先预览、后提交”的冻结工作流（ADMIN-USER-001）。
 *
 * 预览令牌只保存命令摘要、事实快照摘要和五分钟过期时间，不保存密码、Session、路径或库目录。提交会
 * 重新计算快照，任何目标状态、permission_version 或授权变化都会整体返回冲突，避免确认内容与实际影响
 * 不一致。所有账号修改、Session 撤销、授权、审计和通知在一个短事务内完成。
 */
final readonly class UserBulkService
{
    public function __construct(
        private AuditLogger $audit = new AuditLogger(),
        private NotificationPublisher $notifications = new NotificationPublisher(),
        private AccountDeactivationService $deactivation = new AccountDeactivationService(),
    ) {
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $actor @return array<string,mixed> */
    public function preview(array $payload, array $actor): array
    {
        $command = $this->command($payload);
        $facts = $this->facts($command, $actor);
        $expiresAt = time() + 300;
        $claims = [
            'v' => 1,
            'exp' => $expiresAt,
            'actor' => (string) $actor['id'],
            'command' => $this->digest($command),
            'facts' => $this->digest($facts['snapshot']),
        ];

        return [
            'previewToken' => $this->sign($claims),
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
            'action' => $command['action'],
            'userCount' => count($facts['users']),
            'users' => $facts['users'],
            'loginSessionsToRevoke' => $facts['loginSessionsToRevoke'],
            'tokensToRevoke' => $facts['tokensToRevoke'],
            'uploadsToCancel' => $facts['uploadsToCancel'],
            'uploadsToRequestCancel' => $facts['uploadsToRequestCancel'],
            'playbackLeasesToRelease' => $facts['playbackLeasesToRelease'],
            'libraries' => $facts['libraries'],
            'grantAdds' => $facts['grantAdds'],
            'grantRemovals' => $facts['grantRemovals'],
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $actor @return array<string,mixed> */
    public function apply(array $payload, array $actor, string $requestId): array
    {
        $command = $this->command($payload);
        $token = $payload['previewToken'] ?? null;
        if (!is_string($token)) throw new UserBulkInvalid('批量预览令牌缺失。');
        $claims = $this->verify($token);
        if (($claims['actor'] ?? null) !== (string) $actor['id']
            || ($claims['command'] ?? null) !== $this->digest($command)) {
            throw new UserConflict('批量预览不属于当前命令，请重新预览。');
        }
        $facts = $this->facts($command, $actor);
        if (($claims['facts'] ?? null) !== $this->digest($facts['snapshot'])) {
            throw new UserConflict('目标用户或授权已变化，请重新预览。');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');

        Db::transaction(function () use ($actor, $command, $facts, $now, $requestId): void {
            $userIds = array_column($facts['users'], 'id');
            $revocation = null;
            if ($command['action'] === 'setStatus') {
                Db::table('users')->whereIn('id', $userIds)->update([
                    'status' => $command['status'],
                    'permission_version' => Db::raw('permission_version + 1'),
                    'updated_at' => $now,
                ]);
                if ($command['status'] === 'disabled') {
                    $revocation = $this->deactivation->deactivateInTransaction($userIds, $now);
                }
            } else {
                $manageableIds = array_column($facts['manageableLibraries'], 'id');
                foreach ($userIds as $userId) {
                    if ($manageableIds !== []) {
                        Db::table('library_user_grants')->where('user_id', $userId)
                            ->where('access_level', 'read')->whereIn('library_id', $manageableIds)->delete();
                    }
                    foreach ($command['libraryIds'] as $libraryId) {
                        $existing = Db::table('library_user_grants')->where('user_id', $userId)
                            ->where('library_id', $libraryId)->first(['access_level']);
                        if (!$existing instanceof stdClass) {
                            Db::table('library_user_grants')->insert([
                                'library_id' => $libraryId,
                                'user_id' => $userId,
                                'access_level' => 'read',
                                'granted_by' => (string) $actor['id'],
                                'granted_at' => $now,
                            ]);
                        }
                    }
                    Db::table('users')->where('id', $userId)->update([
                        'permission_version' => Db::raw('permission_version + 1'),
                        'updated_at' => $now,
                    ]);
                }
            }

            foreach ($facts['users'] as $user) {
                $targetId = (string) $user['id'];
                $this->audit->record((string) $actor['id'], 'user.bulk.' . $command['action'], 'user',
                    $targetId, 'success', $requestId, [
                        'batchSize' => count($facts['users']),
                        'libraryCount' => count($facts['libraries']),
                        'sessionsRevoked' => $revocation['sessionsRevoked'] ?? 0,
                        'tokensRevoked' => $revocation['tokensRevoked'] ?? 0,
                        'uploadsCancelled' => $revocation['uploadsCancelled'] ?? 0,
                        'uploadsCancelRequested' => $revocation['uploadsCancelRequested'] ?? 0,
                    ]);
                $this->notifications->publishPermissionChange(
                    $targetId,
                    $command['action'] === 'setStatus' ? 'account_status' : 'library_access',
                    $command['action'] === 'setStatus' ? (string) $command['status'] : 'changed',
                    $requestId . ':user_bulk:' . $targetId,
                    $now,
                );
            }
        });

        return [
            'applied' => true,
            'action' => $command['action'],
            'userCount' => count($facts['users']),
            'libraryCount' => count($facts['libraries']),
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function command(array $payload): array
    {
        $action = $payload['action'] ?? null;
        $rawIds = $payload['userIds'] ?? null;
        if (!is_string($action) || !is_array($rawIds) || !array_is_list($rawIds)
            || count($rawIds) < 1 || count($rawIds) > 100) {
            throw new UserBulkInvalid('批量用户命令无效。');
        }
        $userIds = [];
        foreach ($rawIds as $id) {
            if (!is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
                throw new UserBulkInvalid('批量用户标识无效。');
            }
            $userIds[$id] = true;
        }
        $ids = array_keys($userIds);
        sort($ids, SORT_STRING);
        if ($action === 'setStatus') {
            $status = $payload['status'] ?? null;
            if (!in_array($status, ['active', 'disabled'], true)) throw new UserBulkInvalid('批量状态无效。');
            return ['action' => $action, 'userIds' => $ids, 'status' => $status];
        }
        if ($action !== 'replaceLibraries') throw new UserBulkInvalid('不支持的批量操作。');
        $rawLibraries = $payload['libraryIds'] ?? null;
        if (!is_array($rawLibraries) || !array_is_list($rawLibraries) || count($rawLibraries) > 100) {
            throw new UserBulkInvalid('批量音乐库授权无效。');
        }
        $libraryIds = [];
        foreach ($rawLibraries as $id) {
            if (!is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
                throw new UserBulkInvalid('批量音乐库标识无效。');
            }
            $libraryIds[$id] = true;
        }
        $libraries = array_keys($libraryIds);
        sort($libraries, SORT_STRING);
        return ['action' => $action, 'userIds' => $ids, 'libraryIds' => $libraries];
    }

    /** @param array<string,mixed> $command @param array<string,mixed> $actor @return array<string,mixed> */
    private function facts(array $command, array $actor): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('users')->whereIn('id', $command['userIds'])->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'display_name', 'status', 'is_super_admin', 'permission_version'])->all();
        if (count($rows) !== count($command['userIds'])) throw new UserNotFound('部分目标用户不存在。');
        foreach ($rows as $row) {
            if ((int) $row->is_super_admin === 1 && !($actor['isSuperAdmin'] ?? false)) {
                throw new AuthorizationDenied('Only super administrators may batch-manage super administrators.');
            }
            if ($command['action'] === 'setStatus' && $command['status'] === 'disabled'
                && (string) $row->id === (string) $actor['id']) {
                throw new UserConflict('批量停用不能包含当前登录账户。');
            }
        }
        $manageable = $command['action'] === 'replaceLibraries' ? $this->manageableLibraries($actor) : [];
        $manageableIds = array_column($manageable, 'id');
        if ($command['action'] === 'replaceLibraries'
            && array_diff($command['libraryIds'], $manageableIds) !== []) {
            throw new AuthorizationDenied('A selected library is outside the actor management scope.');
        }
        $allGrants = [];
        if ($command['action'] === 'replaceLibraries') {
            /** @var list<stdClass> $grantRows */
            $grantRows = Db::table('library_user_grants')->whereIn('user_id', $command['userIds'])
                ->whereIn('library_id', $manageableIds ?: [''])->orderBy('user_id')->orderBy('library_id')
                ->get(['user_id', 'library_id', 'access_level'])->all();
            $allGrants = array_map(static fn (stdClass $grant): array => [
                'userId' => (string) $grant->user_id,
                'libraryId' => (string) $grant->library_id,
                'accessLevel' => (string) $grant->access_level,
            ], $grantRows);
        }
        $deactivationImpact = [
            'loginSessionsToRevoke' => 0,
            'tokensToRevoke' => 0,
            'uploadsToCancel' => 0,
            'uploadsToRequestCancel' => 0,
            'playbackLeasesToRelease' => 0,
            'snapshot' => [],
        ];
        if ($command['action'] === 'setStatus' && $command['status'] === 'disabled') {
            $deactivationImpact = $this->deactivation->impact($command['userIds']);
        }
        $selectedLibraries = array_values(array_filter($manageable,
            static fn (array $library): bool => in_array($library['id'], $command['libraryIds'] ?? [], true)));
        $adds = 0;
        $removals = 0;
        if ($command['action'] === 'replaceLibraries') {
            foreach ($command['userIds'] as $userId) {
                $byLibrary = [];
                foreach ($allGrants as $grant) if ($grant['userId'] === $userId) $byLibrary[$grant['libraryId']] = $grant['accessLevel'];
                foreach ($command['libraryIds'] as $libraryId) if (!isset($byLibrary[$libraryId])) ++$adds;
                foreach ($byLibrary as $libraryId => $level) {
                    if ($level === 'read' && !in_array($libraryId, $command['libraryIds'], true)) ++$removals;
                }
            }
        }
        $users = array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'displayName' => (string) $row->display_name,
            'status' => (string) $row->status,
        ], $rows);
        $snapshot = [
            'users' => array_map(static fn (stdClass $row): array => [
                (string) $row->id, (string) $row->status, (int) $row->permission_version,
            ], $rows),
            'grants' => $allGrants,
            'manageableLibraryIds' => $manageableIds,
            'deactivation' => $deactivationImpact['snapshot'],
        ];
        return [
            'users' => $users,
            'loginSessionsToRevoke' => $deactivationImpact['loginSessionsToRevoke'],
            'tokensToRevoke' => $deactivationImpact['tokensToRevoke'],
            'uploadsToCancel' => $deactivationImpact['uploadsToCancel'],
            'uploadsToRequestCancel' => $deactivationImpact['uploadsToRequestCancel'],
            'playbackLeasesToRelease' => $deactivationImpact['playbackLeasesToRelease'],
            'libraries' => $selectedLibraries,
            'manageableLibraries' => $manageable,
            'grantAdds' => $adds,
            'grantRemovals' => $removals,
            'snapshot' => $snapshot,
        ];
    }

    /** @param array<string,mixed> $actor @return list<array{id:string,name:string}> */
    private function manageableLibraries(array $actor): array
    {
        $query = Db::table('music_libraries')->where('status', 'active');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as actor_grants', 'actor_grants.library_id', '=', 'music_libraries.id')
                ->where('actor_grants.user_id', (string) $actor['id'])->where('actor_grants.access_level', 'manage');
        }
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('music_libraries.name')->get([
            'music_libraries.id', 'music_libraries.name',
        ])->all();
        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
        ], $rows);
    }

    /** @throws JsonException */
    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $claims */
    private function sign(array $claims): string
    {
        $json = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', "velin-user-bulk-preview-v1\0" . $payload,
            RequestContext::authenticationHashKey());
        return $payload . '.' . $signature;
    }

    /** @return array<string,mixed> */
    private function verify(string $token): array
    {
        if (strlen($token) > 2048 || preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $token, $matches) !== 1) {
            throw new UserBulkInvalid('批量预览令牌无效。');
        }
        $expected = hash_hmac('sha256', "velin-user-bulk-preview-v1\0" . $matches[1],
            RequestContext::authenticationHashKey());
        if (!hash_equals($expected, $matches[2])) throw new UserBulkInvalid('批量预览令牌无效。');
        $encoded = strtr($matches[1], '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        try {
            $claims = json_decode((string) base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new UserBulkInvalid('批量预览令牌无效。');
        }
        if (!is_array($claims) || ($claims['v'] ?? null) !== 1 || !is_int($claims['exp'] ?? null)
            || $claims['exp'] < time()) {
            throw new UserConflict('批量预览已过期，请重新预览。');
        }
        return $claims;
    }
}
