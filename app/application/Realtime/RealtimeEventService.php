<?php

declare(strict_types=1);

namespace app\application\Realtime;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\CapabilityResolver;
use app\application\Library\LibraryAccessResolver;
use stdClass;
use support\Db;

/**
 * 重建订阅者实时权限范围后读取不含路径的失效事件。
 *
 * 事件只提示客户端重新拉取 REST 快照，绝不是任务或通知正文。每次轮询都会验证账户仍处于活动状态，
 * 重新解析能力与音乐库授权，并在数据离开 SQLite 前完成 SQL 过滤。回放区间缺失或超过有界页时返回
 * reset=true，让客户端读取完整快照，不能猜测遗漏状态。本服务只读，不延长 Session 或修改游标表。
 */
final readonly class RealtimeEventService
{
    public function __construct(
        private CapabilityResolver $capabilities = new CapabilityResolver(),
        private LibraryAccessResolver $libraries = new LibraryAccessResolver(),
    ) {
    }

    /** 返回当前全局最大序号，供新连接建立 REST 快照后的回放检查点。 */
    public function currentCursor(): int
    {
        return (int) (Db::table('realtime_events')->max('sequence') ?? 0);
    }

    /**
     * 返回指定游标后的授权事件，并跨过全局序列中当前用户不可见的行。
     *
     * actor 只信任已认证用户 ID，账户状态、角色能力和音乐库范围均实时重载。结果最多 250 条；若一次
     * 可见事件超过上限或历史区间已清理，会要求 reset 而不是返回不完整增量。刮削或存储管理员只要
     * 具有对应 `run_scrape`/`manage_storage` 能力且对目标库仍有 manage 授权，即可接收该库任务失效
     * 事件；`edit_metadata` 依照歌词写回的对象边界接受 read/manage grant。事件不区分任务类型，只
     * 促使前端重取已授权 REST 快照，额外失效提示不能替代来源 API 授权。
     *
     * @param array<string, mixed> $actor 已通过 Session 认证的身份；连接后只信任不可变用户 ID。
     * @return array{events: list<array{id: int, topic: string, resourceVersion: string}>, cursor: int, reset: bool}
     * @throws AuthenticationRequired The account became inactive, expired, or deleted.
     */
    public function read(array $actor, int $afterSequence, int $limit = 100): array
    {
        if ($afterSequence < 0) {
            throw new RealtimeEventInvalid('Realtime cursor cannot be negative.');
        }
        $limit = max(1, min(250, $limit));
        $scope = $this->liveScope($this->actorId($actor));
        $current = $this->currentCursor();
        if ($afterSequence > $current) {
            return ['events' => [], 'cursor' => $current, 'reset' => true];
        }
        if ($afterSequence === $current) {
            return ['events' => [], 'cursor' => $current, 'reset' => false];
        }

        $oldest = (int) (Db::table('realtime_events')->min('sequence') ?? 0);
        if ($oldest > 0 && $afterSequence + 1 < $oldest) {
            return ['events' => [], 'cursor' => $current, 'reset' => true];
        }

        $query = Db::table('realtime_events')
            ->where('sequence', '>', $afterSequence)
            ->where('expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))
            ->where(function ($builder) use ($scope): void {
                $builder->where(function ($personal) use ($scope): void {
                    $personal->where('audience_user_id', $scope['userId'])
                        ->whereIn('topic', ['notifications.changed', 'permissions.changed']);
                });
                if ($scope['canReadLibraryJobs'] && $scope['libraryIds'] !== []) {
                    $builder->orWhere(function ($jobs) use ($scope): void {
                        $jobs->where('topic', 'jobs.changed')->whereIn('library_id', $scope['libraryIds']);
                    });
                }
            })
            ->orderBy('sequence')
            ->limit($limit + 1);

        /** @var list<stdClass> $rows */
        $rows = $query->get(['sequence', 'topic', 'resource_version'])->all();
        if (count($rows) > $limit) {
            return ['events' => [], 'cursor' => $current, 'reset' => true];
        }

        return [
            'events' => array_map(static fn (stdClass $row): array => [
                'id' => (int) $row->sequence,
                'topic' => (string) $row->topic,
                'resourceVersion' => (string) $row->resource_version,
            ], $rows),
            'cursor' => $current,
            'reset' => false,
        ];
    }

    /**
     * 每次流轮询只重建 SQL 授权所需的实时字段。
     *
     * 非活动、已删除或过期账户立即终止读取。扫描、刮削和存储能力仍只使用 manage 库；
     * `edit_metadata` 使用 read/manage 库以匹配歌词写回权限。事件没有任务类型和业务正文，因此编辑者
     * 可能收到同一可读库内其他任务的失效提示，但随后 REST 查询仍按各来源能力失败关闭。
     *
     * @return array{userId:string,canReadLibraryJobs:bool,libraryIds:list<string>}
     */
    private function liveScope(string $userId): array
    {
        /** @var stdClass|null $user */
        $user = Db::table('users')->where('id', $userId)
            ->where('status', 'active')->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->whereNull('account_expires_at')->orWhere('account_expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'));
            })->first(['id', 'is_super_admin']);
        if (!$user instanceof stdClass) {
            throw new AuthenticationRequired('Realtime subscriber is no longer active.');
        }

        $isSuperAdmin = (int) $user->is_super_admin === 1;
        $capabilities = $this->capabilities->resolve($userId, $isSuperAdmin);
        $libraries = $this->libraries->resolve($userId, $isSuperAdmin);
        $canEditMetadata = in_array('edit_metadata', $capabilities, true);

        return [
            'userId' => $userId,
            'canReadLibraryJobs' => in_array('manage_library', $capabilities, true)
                || in_array('run_scrape', $capabilities, true)
                || in_array('manage_storage', $capabilities, true)
                || $canEditMetadata,
            'libraryIds' => array_values(array_map(
                static fn (array $library): string => (string) $library['id'],
                array_filter(
                    $libraries,
                    static fn (array $library): bool => ($library['accessLevel'] ?? null) === 'manage'
                        || ($canEditMetadata && ($library['accessLevel'] ?? null) === 'read'),
                ),
            )),
        ];
    }

    /** 提取已认证身份主键；格式错误立即终止，浏览器不能借事件参数选择其他用户。 */
    private function actorId(array $actor): string
    {
        $id = $actor['id'] ?? null;
        if (!is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
            throw new AuthenticationRequired('Realtime subscriber identity is invalid.');
        }

        return $id;
    }
}
