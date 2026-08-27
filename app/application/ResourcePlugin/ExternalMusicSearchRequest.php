<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

/**
 * 表示一次统一第三方音乐搜索命令。
 *
 * 请求只允许资源类型、搜索词、单插件结果上限和可选插件 key 白名单。搜索词不会进入日志或 URL；插件集合缺省时
 * 由服务端从当前活动注册表决定。对象构造完成后字段不可变，可安全交给多个插件，但每个插件仍必须
 * 独立执行上游超时、响应大小与租约加密约束。
 */
final readonly class ExternalMusicSearchRequest
{
    /**
     * @param list<string>|null $pluginKeys null 表示调用全部当前可搜索插件；显式列表最多八项且保持调用顺序
     */
    private function __construct(
        public string $query,
        public string $resourceType,
        public int $limit,
        public ?array $pluginKeys,
    ) {
    }

    /**
     * 从 HTTP JSON 对象建立有界命令。
     *
     * 未知字段、控制字符、重复或非法插件 key 均失败关闭。resourceType 只允许 `track|album`；旧客户端
     * 省略时兼容为 track，但显式未知值不能降级。limit 是“每个插件”的最大结果数而不是全局分页游标，
     * 范围固定 1..50；这样核心不会替插件猜测分页语义，也不会允许单次响应无限放大。
     *
     * @param array<string,mixed> $payload
     * @throws ExternalMusicInvalid 请求不满足固定协议
     */
    public static function fromArray(array $payload): self
    {
        if (array_is_list($payload)) throw new ExternalMusicInvalid();
        $keys = array_keys($payload);
        sort($keys);
        if (!in_array($keys, [['query'], ['limit', 'query'], ['pluginKeys', 'query'],
            ['limit', 'pluginKeys', 'query'], ['query', 'resourceType'],
            ['limit', 'query', 'resourceType'], ['pluginKeys', 'query', 'resourceType'],
            ['limit', 'pluginKeys', 'query', 'resourceType']], true)) throw new ExternalMusicInvalid();

        $query = is_string($payload['query'] ?? null) ? trim($payload['query']) : '';
        if (!mb_check_encoding($query, 'UTF-8')) throw new ExternalMusicInvalid();
        $length = mb_strlen($query, 'UTF-8');
        if ($length < 2 || $length > 200
            || preg_match('/[\x00-\x1F\x7F]/u', $query) === 1) throw new ExternalMusicInvalid();

        $limit = $payload['limit'] ?? 30;
        if (!is_int($limit) || $limit < 1 || $limit > 50) throw new ExternalMusicInvalid();

        $resourceType = $payload['resourceType'] ?? 'track';
        if (!is_string($resourceType) || !in_array($resourceType, ['track', 'album'], true)) {
            throw new ExternalMusicInvalid();
        }

        $pluginKeys = null;
        if (array_key_exists('pluginKeys', $payload)) {
            $value = $payload['pluginKeys'];
            if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 8) {
                throw new ExternalMusicInvalid();
            }
            $pluginKeys = [];
            foreach ($value as $pluginKey) {
                if (!is_string($pluginKey)
                    || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $pluginKey) !== 1
                    || in_array($pluginKey, $pluginKeys, true)) throw new ExternalMusicInvalid();
                $pluginKeys[] = $pluginKey;
            }
        }

        return new self($query, $resourceType, $limit, $pluginKeys);
    }
}
