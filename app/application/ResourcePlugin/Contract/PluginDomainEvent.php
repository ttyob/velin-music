<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

/**
 * PluginDomainEvent 是核心向受信 PHP 插件发送的版本化、只读业务通知。
 *
 * 事件只在核心业务已经提交后创建，因此插件不能通过返回值阻止或改写扫描、入库、播放、收藏或歌单事务。
 * subjectId/actorId 仅允许稳定业务 ID，不接受路径、URL、凭据或第三方原始响应；payload 继续执行字段名、
 * 深度和总字节上限，防止未来调用点误把敏感或无界内容写入 Redis。Redis 过期意味着通知可丢失，插件必须
 * 把它用于同步、统计和缓存刷新等可重建扩展，不得把收到事件当成核心业务成功的唯一事实。
 */
final readonly class PluginDomainEvent
{
    public const MEDIA_INDEXED = 'media.indexed.v1';
    public const LIBRARY_SCAN_COMPLETED = 'library.scan.completed.v1';
    public const METADATA_SCRAPE_COMPLETED = 'metadata.scrape.completed.v1';
    public const MEDIA_PUBLISHED = 'media.published.v1';
    public const PLAYLIST_CHANGED = 'playlist.changed.v1';
    public const FAVORITE_CHANGED = 'favorite.changed.v1';
    public const PLAYBACK_COMPLETED = 'playback.completed.v1';
    public const BOOKMARK_CHANGED = 'bookmark.changed.v1';
    public const MEDIA_DELETED = 'media.deleted.v1';
    public const RUNTIME_MAINTENANCE_COMPLETED = 'runtime.maintenance.completed.v1';
    public const PLUGIN_ADMIN_ACTION_COMPLETED = 'plugin.admin_action.completed.v1';

    private const NAMES = [
        self::MEDIA_INDEXED,
        self::LIBRARY_SCAN_COMPLETED,
        self::METADATA_SCRAPE_COMPLETED,
        self::MEDIA_PUBLISHED,
        self::PLAYLIST_CHANGED,
        self::FAVORITE_CHANGED,
        self::PLAYBACK_COMPLETED,
        self::BOOKMARK_CHANGED,
        self::MEDIA_DELETED,
        self::RUNTIME_MAINTENANCE_COMPLETED,
        self::PLUGIN_ADMIN_ACTION_COMPLETED,
    ];
    private const ACTOR_TYPES = ['system', 'user', 'plugin'];
    private const SENSITIVE_FIELD = '/(?:path|url|uri|cookie|token|secret|password|credential|authorization|header)/i';
    private const MAX_JSON_BYTES = 8192;

    /**
     * 构造器只接受已经完成协议校验的值；核心发布与 Redis 反序列化都必须使用 create/fromArray。
     *
     * @param array<string,mixed> $payload 已脱敏、有界且可 JSON 序列化的事件摘要
     */
    private function __construct(
        public string $eventId,
        public string $name,
        public string $occurredAt,
        public string $subjectType,
        public string $subjectId,
        public string $actorType,
        public ?string $actorId,
        public array $payload,
    ) {
    }

    /**
     * 在核心事务提交后创建新事件。
     *
     * actorId 对 system 事件必须为空，对 user/plugin 事件必须是稳定 ID。方法不访问 Redis；任何输入不满足
     * 白名单、文本边界或脱敏规则都会在发布前抛错，调用层只记录稳定告警且不得回滚已提交业务。
     *
     * @param array<string,mixed> $payload 不含正文、二进制、地址、路径或凭据的标量/数组摘要
     */
    public static function create(
        string $name,
        string $subjectType,
        string $subjectId,
        string $actorType = 'system',
        ?string $actorId = null,
        array $payload = [],
    ): self {
        return self::validated(
            (string) new Ulid(),
            $name,
            gmdate('Y-m-d\TH:i:s\Z'),
            $subjectType,
            $subjectId,
            $actorType,
            $actorId,
            $payload,
        );
    }

    /**
     * 从 Redis 中恢复事件并重新执行全部合同校验。
     *
     * 过期、截断或被非本应用写入的内容会抛出 InvalidArgumentException，消费者随后只推进当前插件游标，
     * 不把不可信数组交给插件，也不尝试从字段猜测兼容协议。
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['actorId', 'actorType', 'eventId', 'name', 'occurredAt', 'payload', 'subjectId', 'subjectType']
            || !is_string($data['eventId'] ?? null)
            || !is_string($data['name'] ?? null)
            || !is_string($data['occurredAt'] ?? null)
            || !is_string($data['subjectType'] ?? null)
            || !is_string($data['subjectId'] ?? null)
            || !is_string($data['actorType'] ?? null)
            || (!is_string($data['actorId'] ?? null) && ($data['actorId'] ?? null) !== null)
            || !is_array($data['payload'] ?? null)) {
            throw new InvalidArgumentException('PLUGIN_EVENT_PROTOCOL_INVALID');
        }
        return self::validated(
            $data['eventId'],
            $data['name'],
            $data['occurredAt'],
            $data['subjectType'],
            $data['subjectId'],
            $data['actorType'],
            $data['actorId'],
            $data['payload'],
        );
    }

    /**
     * 返回插件可以声明订阅的完整版本化事件集合。
     *
     * 调用方必须按精确字符串匹配，不支持通配符、前缀或忽略版本；返回新副本，不允许插件修改核心白名单。
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * 返回可以写入 Redis 的稳定协议数组。
     *
     * 数据已经在构造阶段完成脱敏与大小校验；本方法不添加运行时路径、插件信息或 Redis 游标，也不改变
     * payload。消费者仍须通过 fromArray 重新校验，不能直接信任缓存内容。
     *
     * @return array{eventId:string,name:string,occurredAt:string,subjectType:string,subjectId:string,actorType:string,actorId:?string,payload:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'name' => $this->name,
            'occurredAt' => $this->occurredAt,
            'subjectType' => $this->subjectType,
            'subjectId' => $this->subjectId,
            'actorType' => $this->actorType,
            'actorId' => $this->actorId,
            'payload' => $this->payload,
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function validated(
        string $eventId,
        string $name,
        string $occurredAt,
        string $subjectType,
        string $subjectId,
        string $actorType,
        ?string $actorId,
        array $payload,
    ): self {
        if (!Ulid::isValid($eventId)
            || !in_array($name, self::NAMES, true)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $occurredAt) !== 1
            || preg_match('/^[a-z][a-z0-9_]{1,47}$/D', $subjectType) !== 1
            || !self::boundedText($subjectId, 1, 160)
            || !in_array($actorType, self::ACTOR_TYPES, true)
            || ($actorType === 'system' ? $actorId !== null : !is_string($actorId) || !self::boundedText($actorId, 1, 160))) {
            throw new InvalidArgumentException('PLUGIN_EVENT_PROTOCOL_INVALID');
        }
        self::validatePayload($payload, 0);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_JSON_BYTES) throw new InvalidArgumentException('PLUGIN_EVENT_PAYLOAD_TOO_LARGE');
        return new self($eventId, $name, $occurredAt, $subjectType, $subjectId, $actorType, $actorId, $payload);
    }

    /**
     * 递归验证 Redis 载荷，只允许最多四层的标量、列表和字符串键对象。
     *
     * 敏感词按字段名失败关闭；字符串控制在 500 字节内。失败不修改输入，也不会尝试删除部分字段后继续，
     * 从而让新调用点在测试阶段显式修正协议，而不是静默发布含义不完整的通知。
     */
    private static function validatePayload(array $payload, int $depth): void
    {
        if ($depth > 4 || count($payload) > 64) throw new InvalidArgumentException('PLUGIN_EVENT_PAYLOAD_INVALID');
        foreach ($payload as $key => $value) {
            if (!is_int($key)) {
                if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $key) !== 1
                    || preg_match(self::SENSITIVE_FIELD, $key) === 1) {
                    throw new InvalidArgumentException('PLUGIN_EVENT_PAYLOAD_INVALID');
                }
            }
            if (is_array($value)) {
                self::validatePayload($value, $depth + 1);
                continue;
            }
            if (is_string($value) && !self::boundedText($value, 0, 500)) {
                throw new InvalidArgumentException('PLUGIN_EVENT_PAYLOAD_INVALID');
            }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
                throw new InvalidArgumentException('PLUGIN_EVENT_PAYLOAD_INVALID');
            }
        }
    }

    private static function boundedText(string $value, int $minimum, int $maximum): bool
    {
        return strlen($value) >= $minimum && strlen($value) <= $maximum
            && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
