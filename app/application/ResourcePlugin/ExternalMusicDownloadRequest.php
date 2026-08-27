<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

/**
 * 表示统一 API 从搜索租约创建下载入库任务的最小命令。
 *
 * 浏览器只能选择提供方、租约和目标音乐库。音质、第三方资源 ID、URL、Header、Cookie、入库策略及
 * 下载器参数必须已固化在 actor 绑定租约或插件服务端配置中，不能通过本命令覆盖。该约束使任意实现
 * ExternalDownloadHook 的插件都能接入，同时不会把统一端点变成任意参数透传器。
 */
final readonly class ExternalMusicDownloadRequest
{
    private function __construct(
        public string $pluginKey,
        public string $leaseId,
        public string $libraryId,
    ) {
    }

    /**
     * 从 HTTP JSON 对象建立下载命令；三个字段缺一或出现未知字段都失败关闭。
     *
     * leaseId 与 libraryId 使用不透明 ULID。格式正确不代表对象存在或有权使用，插件必须在创建任务的
     * 短事务内重新验证租约 actor、有效期、目标库状态和 manage 授权；失败时不能创建暂存目录或网络任务。
     *
     * @param array<string,mixed> $payload
     * @throws ExternalMusicInvalid 请求字段或标识符无效
     */
    public static function fromArray(array $payload): self
    {
        $keys = array_keys($payload);
        sort($keys);
        if (array_is_list($payload) || $keys !== ['leaseId', 'libraryId', 'pluginKey']) {
            throw new ExternalMusicInvalid();
        }
        $pluginKey = $payload['pluginKey'] ?? null;
        $leaseId = $payload['leaseId'] ?? null;
        $libraryId = $payload['libraryId'] ?? null;
        if (!is_string($pluginKey) || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $pluginKey) !== 1
            || !is_string($leaseId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $leaseId) !== 1
            || !is_string($libraryId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $libraryId) !== 1) {
            throw new ExternalMusicInvalid();
        }
        return new self($pluginKey, $leaseId, $libraryId);
    }

    /**
     * 生成插件钩子的固定 v1 命令。
     *
     * 返回值不会包含 pluginKey，因为注册表已经用它选择了钩子。插件只能消费 leaseId 与 libraryId；
     * 同一租约的重复提交必须由插件唯一约束收敛为同一任务。
     *
     * @return array{leaseId:string,libraryId:string}
     */
    public function pluginCommand(): array
    {
        return ['leaseId' => $this->leaseId, 'libraryId' => $this->libraryId];
    }
}
