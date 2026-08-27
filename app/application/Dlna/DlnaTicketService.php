<?php

declare(strict_types=1);

namespace app\application\Dlna;

use app\application\Auth\CapabilityResolver;
use app\application\Library\LibraryAccessResolver;
use app\application\Media\MediaStreamService;
use app\application\System\PublicUrlConfig;
use app\http\RequestContext;
use app\infrastructure\Audit\AuditLogger;
use app\infrastructure\Dlna\DlnaDeliveryProgressStore;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;

/**
 * 创建并解析供无 Header 投放端拉流的短期能力票据。
 *
 * DLNA Renderer 与内置 OwnTone companion 无法可靠携带用户 Cookie 或 Authorization Header，因此这里只把高熵明文放入一次性返回的 URL，
 * 数据库保存 HMAC 摘要。票据固定用户、歌曲和输出格式，并记录预期设备摘要用于审计；传统 HTTP 拉流
 * 无法密码学证明请求者就是该 UDN，实际授权依赖 256 bit bearer secret 与两小时有效期。每次取流仍
 * 重建实时 actor，并通过
 * MediaStreamService 复验音乐库授权、规范根、真实路径和文件身份。过期、撤销、账号停用、能力撤回、
 * 库失权和文件变化统一按不存在处理，不能依赖票据行扩大权限。
 */
final readonly class DlnaTicketService
{
    public function __construct(
        private MediaStreamService $streams = new MediaStreamService(),
        private CapabilityResolver $capabilities = new CapabilityResolver(),
        private LibraryAccessResolver $libraries = new LibraryAccessResolver(),
        private AuditLogger $audit = new AuditLogger(),
        private DlnaDeliveryProgressStore $deliveryProgress = new DlnaDeliveryProgressStore(),
    ) {
    }

    /**
     * 创建两小时有效的投放票据；媒体复验和随机数生成都在短事务之前完成。
     *
     * @param array<string,mixed> $actor 当前活动账号及实时能力/库授权
     * @param string $automaticBaseUrl 根据目标 Renderer 路由或可信 companion 选择的本机 HTTP Origin
     * @param string $purpose `dlna|airplay`；仅影响审计分类和是否允许 DLNA 高级 Origin 覆盖
     * @return array{url:string,format:string,media:\app\application\Media\PlayableMedia,ticketId:string}
     */
    public function create(
        array $actor,
        string $songId,
        string $deviceId,
        string $format,
        string $requestId,
        string $automaticBaseUrl,
        string $purpose = 'dlna',
    ): array
    {
        if (!in_array($purpose, ['dlna', 'airplay'], true)) {
            throw new DlnaUnavailable('DLNA_REQUEST_INVALID', '投放票据用途无效。');
        }
        $this->assertDeviceId($deviceId);
        if (!in_array($format, ['raw', 'mp3', 'aac', 'opus'], true)) {
            throw new DlnaUnavailable('DLNA_FORMAT_INVALID', 'DLNA 输出格式无效。');
        }
        $owned = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!$this->hasRequiredCapabilities($owned, $format)) {
            throw new DlnaUnavailable('DLNA_PERMISSION_DENIED', '账号缺少 DLNA 播放、投放或转码能力。');
        }
        $media = $this->streams->resolve($actor, $songId);
        $baseUrl = $this->publicBaseUrl($automaticBaseUrl, $purpose === 'dlna');
        $id = (string) new Ulid();
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $plainText = 'velin_dlna_' . $id . '_' . $secret;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + 7200);
        $tokenDigest = $this->digest("ticket\0" . $plainText);
        $deviceDigest = $this->digest("device\0" . $deviceId);

        Db::transaction(function () use (
            $actor, $deviceDigest, $expiresAt, $format, $id, $now, $purpose, $requestId, $songId, $tokenDigest,
        ): void {
            // 有界清理只处理过期票据行；已交给音响的 URL 在到期后本就无效，不涉及媒体或网络补偿。
            $expired = Db::table('dlna_playback_tickets')->where('expires_at', '<=', $now)
                ->orderBy('expires_at')->limit(100)->pluck('id')->all();
            if ($expired !== []) Db::table('dlna_playback_tickets')->whereIn('id', $expired)->delete();
            Db::table('dlna_playback_tickets')->insert([
                'id' => $id,
                'token_digest' => $tokenDigest,
                'user_id' => (string) $actor['id'],
                'song_id' => $songId,
                'device_digest' => $deviceDigest,
                'output_format' => $format,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'last_used_at' => null,
                'created_at' => $now,
            ]);
            $this->audit->record((string) $actor['id'], $purpose . '.play', 'dlna_playback_ticket', $id,
                'success', $requestId, ['format' => $format, 'protocol' => $purpose]);
        });

        return [
            'url' => $baseUrl . '/dlna/v1/streams/' . rawurlencode($plainText),
            'format' => $format,
            'media' => $media,
            'ticketId' => $id,
        ];
    }

    /**
     * 解析 Renderer 提交的票据并重新构造实时账号权限。
     *
     * @return array{actor:array<string,mixed>,songId:string,format:string,ticketId:string}
     */
    public function resolve(string $plainText): array
    {
        if (preg_match('/^velin_dlna_([0-9A-HJKMNP-TV-Z]{26})_[A-Za-z0-9_-]{43}$/D', $plainText, $matches) !== 1) {
            throw new DlnaTicketNotFound('DLNA 票据不存在。');
        }
        /** @var stdClass|null $row */
        $row = Db::table('dlna_playback_tickets as tickets')
            ->join('users', 'users.id', '=', 'tickets.user_id')
            ->where('tickets.id', $matches[1])->first([
                'tickets.id', 'tickets.token_digest', 'tickets.user_id', 'tickets.song_id',
                'tickets.output_format', 'tickets.expires_at', 'tickets.revoked_at', 'tickets.last_used_at',
                'users.username', 'users.display_name', 'users.email', 'users.is_super_admin',
                'users.permission_version', 'users.status', 'users.deleted_at', 'users.account_expires_at',
            ]);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if (!$row instanceof stdClass || $row->revoked_at !== null || (string) $row->expires_at <= $now
            || !hash_equals((string) $row->token_digest, $this->digest("ticket\0" . $plainText))
            || (string) $row->status !== 'active' || $row->deleted_at !== null
            || ($row->account_expires_at !== null && (string) $row->account_expires_at <= $now)) {
            throw new DlnaTicketNotFound('DLNA 票据不存在。');
        }
        $userId = (string) $row->user_id;
        $isSuper = (int) $row->is_super_admin === 1;
        $capabilities = $this->capabilities->resolve($userId, $isSuper);
        if (!$this->hasRequiredCapabilities($capabilities, (string) $row->output_format)) {
            throw new DlnaTicketNotFound('DLNA 票据不存在。');
        }
        $actor = [
            'id' => $userId,
            'username' => (string) $row->username,
            'displayName' => (string) $row->display_name,
            'email' => $row->email === null ? null : (string) $row->email,
            'isSuperAdmin' => $isSuper,
            'permissionVersion' => (int) $row->permission_version,
            'capabilities' => $capabilities,
            'libraries' => $this->libraries->resolve($userId, $isSuper),
            'authenticationType' => 'dlna_ticket',
        ];
        // 高频 Range 拉取只每五分钟更新一次使用时间，减少 SQLite 写锁；失败不影响票据的权限事实。
        $lastUsed = $row->last_used_at === null ? 0 : (strtotime((string) $row->last_used_at) ?: 0);
        if ($lastUsed <= time() - 300) {
            Db::table('dlna_playback_tickets')->where('id', (string) $row->id)->whereNull('revoked_at')
                ->update(['last_used_at' => $now]);
        }
        return ['actor' => $actor, 'songId' => (string) $row->song_id,
            'format' => (string) $row->output_format, 'ticketId' => (string) $row->id];
    }

    /** 控制命令失败时撤销尚未过期的票据；撤销是幂等的且不联系 Renderer。 */
    public function revoke(string $ticketId): void
    {
        Db::table('dlna_playback_tickets')->where('id', $ticketId)->whereNull('revoked_at')
            ->update(['revoked_at' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    /**
     * 读取当前账号投向指定 Renderer 的最新有效票据投递进度。
     *
     * 查询先以用户 ID 与用途分离的设备 HMAC 摘要限定范围，不会让 UDN、明文票据或其他用户状态进入
     * Redis 键。调用方已经实时校验 play/cast，本方法仍只接受当前 actor，过期或撤销票据不参与；Redis
     * 数据缺失/故障返回两个 null，Renderer 状态查询本身继续成功且不创建任何业务记录。
     *
     * @param array<string,mixed> $actor 当前控制命令的实时授权账号
     * @return array{deliveredBytes:?int,totalBytes:?int}
     */
    public function deliveryProgress(array $actor, string $deviceId): array
    {
        $this->assertDeviceId($deviceId);
        $userId = $actor['id'] ?? null;
        if (!is_string($userId) || $userId === '') {
            return ['deliveredBytes' => null, 'totalBytes' => null];
        }
        /** @var stdClass|null $ticket */
        $ticket = Db::table('dlna_playback_tickets')
            ->where('user_id', $userId)
            ->where('device_digest', $this->digest("device\0" . $deviceId))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))
            ->orderByDesc('created_at')
            ->first(['id']);
        if (!$ticket instanceof stdClass) {
            return ['deliveredBytes' => null, 'totalBytes' => null];
        }
        $snapshot = $this->deliveryProgress->read((string) $ticket->id);
        return $snapshot ?? ['deliveredBytes' => null, 'totalBytes' => null];
    }

    /**
     * 判断当前能力快照是否仍可使用指定 DLNA 输出格式。
     *
     * `play` 只代表读取有权媒体，`cast` 才允许控制共享 Renderer，两者缺一不可；输出转码不再设置独立
     * 账号权限。创建和每次票据解析共用该判断，使角色撤权立即阻断后续 Range 请求，而不是等待票据
     * 两小时到期。调用方负责把失败分别映射为 403 或不可枚举的 404，本方法不访问数据库也无副作用。
     *
     * @param list<string> $capabilities 服务端实时解析、尚未包含音乐库范围的全局能力快照
     */
    private function hasRequiredCapabilities(array $capabilities, string $format): bool
    {
        return in_array('play', $capabilities, true)
            && in_array('cast', $capabilities, true);
    }

    /**
     * 使用现有部署密钥派生 DLNA 专用 HMAC 子密钥。
     *
     * 域分离常量确保票据摘要不能与登录标识、分享令牌或设备审计摘要互换；部署密钥轮换只会让最长
     * 两小时的既有票据失效，不修改媒体或 Renderer 状态。复用应用已有必需密钥可避免新增 DLNA 配置。
     */
    private function digest(string $value): string
    {
        $key = hash_hkdf('sha256', RequestContext::authenticationHashKey(), 32, 'velin-dlna-ticket-key-v1');
        return hash_hmac('sha256', "velin-dlna-ticket-v1\0" . $value, $key);
    }

    /** DLNA 自动 Origin 可被统一公开地址覆盖；AirPlay 回环 Origin 不受外部部署变量影响。 */
    private function publicBaseUrl(string $automaticBaseUrl, bool $allowConfiguredOverride): string
    {
        try {
            $configured = $allowConfiguredOverride ? PublicUrlConfig::publicOrigin() : null;
            return PublicUrlConfig::normalizeOrigin($configured ?? $automaticBaseUrl);
        } catch (\InvalidArgumentException) {
            throw new DlnaUnavailable('DLNA_PUBLIC_URL_INVALID', 'DLNA 对外地址未正确配置。');
        }
    }

    private function assertDeviceId(string $deviceId): void
    {
        if (preg_match('/^uuid:[A-Za-z0-9._:-]{1,180}$/D', $deviceId) !== 1) {
            throw new DlnaUnavailable('DLNA_DEVICE_INVALID', 'DLNA 设备标识无效。');
        }
    }
}
