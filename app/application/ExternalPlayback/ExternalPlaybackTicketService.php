<?php

declare(strict_types=1);

namespace app\application\ExternalPlayback;

use app\application\Auth\UserActorProjector;
use app\application\Media\MediaStreamService;
use app\application\Media\PlayableMedia;
use app\application\Subsonic\SubsonicTranscodeService;
use app\application\System\PublicUrlConfig;
use app\http\TranscodeResponse;
use app\http\RequestContext;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;

/**
 * 准备、签发并解析供手机当前局域网接收器无 Header 拉流的短期 URL。
 *
 * 签发请求只描述协议、固定 MIME 列表和 Range 能力；本服务自行解析歌曲、选择原始或固定转码格式，并
 * 使用现有规范投放 HTTP(S) Origin。请求 Host、客户端 Origin、设备地址和任意 Header 均不参与 URL。
 * 需要转码时，准备阶段复用来源无关的接收器派生缓存，完成后 App 才把票据 URL 交给 Renderer；缓存不
 * 包含设备身份、票据或 WebDAV 原始文件。解析时由票据绑定的 userId 重建实时账号投影，权限、库授权、
 * 账号状态或文件身份变化立即按 404 收敛。
 */
final readonly class ExternalPlaybackTicketService
{
    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'audio/mpeg', 'audio/flac', 'audio/aac', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/aiff',
    ];

    public function __construct(
        private MediaStreamService $streams = new MediaStreamService(),
        private UserActorProjector $actors = new UserActorProjector(),
        private AuditLogger $audit = new AuditLogger(),
        private SubsonicTranscodeService $transcodes = new SubsonicTranscodeService(),
    ) {
    }

    /**
     * @param array<string,mixed> $actor 必须来自 App access token，且已具备 play。
     * @param array<string,mixed> $payload 严格 `{protocol,mimeTypes,supportsRange}`。
     * @return array{url:string,mimeType:string,contentLength:?int,supportsRange:bool,expiresAt:string}
     */
    public function create(array $actor, string $songId, array $payload, string $requestId): array
    {
        [$media, $format, $mime] = $this->resolvePlan($actor, $songId, $payload);
        $supportsRange = $format === 'raw' && $payload['supportsRange'];
        $baseUrl = $this->publicBaseUrl();
        $id = (string) new Ulid();
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $plain = 'velin_ext_' . $id . '_' . $secret;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $startsBefore = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        Db::transaction(function () use ($actor, $format, $id, $mime, $now, $payload, $requestId,
            $songId, $startsBefore, $plain, $supportsRange): void {
            // 清理仅限最多一百条从未开始或已经结束的票据行，不触碰媒体、接收器和播放历史。
            $expired = Db::table('external_playback_tickets')->where(static function ($query) use ($now): void {
                $query->where(static function ($notStarted) use ($now): void {
                    $notStarted->whereNull('started_at')->where('starts_before', '<=', $now);
                })->orWhere(static function ($started) use ($now): void {
                    $started->whereNotNull('expires_at')->where('expires_at', '<=', $now);
                });
            })->orderBy('created_at')->limit(100)->pluck('id')->all();
            if ($expired !== []) Db::table('external_playback_tickets')->whereIn('id', $expired)->delete();
            Db::table('external_playback_tickets')->insert([
                'id' => $id, 'token_digest' => self::digest($plain), 'user_id' => (string) $actor['id'],
                'song_id' => $songId, 'protocol' => $payload['protocol'], 'output_format' => $format,
                'mime_type' => $mime, 'supports_range' => $supportsRange ? 1 : 0,
                'starts_before' => $startsBefore, 'started_at' => null, 'expires_at' => null,
                'last_used_at' => null, 'revoked_at' => null, 'created_at' => $now,
            ]);
            $this->audit->record((string) $actor['id'], 'external_playback.ticket.create',
                'external_playback_ticket', $id, 'success', $requestId,
                ['protocol' => $payload['protocol'], 'format' => $format]);
        });
        return ['url' => $baseUrl . '/external/v1/streams/' . rawurlencode($plain), 'mimeType' => $mime,
            'contentLength' => $format === 'raw' ? $media->fileSize : null,
            'supportsRange' => $supportsRange, 'expiresAt' => $startsBefore];
    }

    /**
     * 在签发和下发票据之前准备接收器需要的精确长度转码。
     *
     * 前置条件与 create 完全一致：调用者必须是具有 play 的 App access token，payload 只能描述协议、MIME
     * 和 Range 能力。源格式可直放时幂等返回 null，不创建缓存；需要转码时继承 play 授权，并返回一个由
     * Webman supervisor 执行的 cache-only 响应。Runner 只有在完整输出原子发布后才发送 204，连接
     * 取消、WebDAV 版本漂移、FFmpeg 失败或空间不足都会删除本次临时文件且不会签发错误的“已准备”事实。
     *
     * 缓存键只绑定统一媒体 ETag 和去地址化编码计划，因而本地文件与 WebDAV 共用同一生命周期；缓存内容
     * 是可重建的目标编码，不包含票据、账号、设备地址、远端凭据或完整原始音频。
     *
     * @param array<string,mixed> $actor 必须来自 App access token，且已具备 play。
     * @param array<string,mixed> $payload 严格 `{protocol,mimeTypes,supportsRange}`。
     */
    public function prepare(
        array $actor,
        string $songId,
        array $payload,
        string $requestId,
    ): ?TranscodeResponse {
        [$media, $format] = $this->resolvePlan($actor, $songId, $payload);
        if ($format === 'raw') {
            return null;
        }
        $response = $this->transcodes->negotiate($media, [
            'format' => $format,
            'converted' => 'true',
            'estimateContentLength' => 'true',
        ], $requestId, $actor, persistentCache: true, lowLatency: true, cacheOnly: true);
        if (!$response instanceof TranscodeResponse || !$response->plan->cacheOnly) {
            throw new ExternalPlaybackUnavailable(
                'EXTERNAL_PREPARATION_FAILED',
                '附近播放音频准备失败。',
            );
        }

        return $response;
    }

    /**
     * 首次读取原子启动两小时窗口，随后返回实时 actor 与固定流计划。
     *
     * @return array{actor:array<string,mixed>,songId:string,format:string,mimeType:string,supportsRange:bool,ticketId:string}
     */
    public function resolve(string $plain): array
    {
        if (preg_match('/^velin_ext_([0-9A-HJKMNP-TV-Z]{26})_[A-Za-z0-9_-]{43}$/D', $plain, $matches) !== 1) {
            throw new ExternalPlaybackTicketNotFound('External ticket not found.');
        }
        /** @var stdClass|null $row */
        $row = Db::table('external_playback_tickets')->where('id', $matches[1])->first();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if (!$row instanceof stdClass || $row->revoked_at !== null
            || !hash_equals((string) $row->token_digest, self::digest($plain))
            || ($row->started_at === null && (string) $row->starts_before <= $now)
            || ($row->expires_at !== null && (string) $row->expires_at <= $now)) {
            throw new ExternalPlaybackTicketNotFound('External ticket not found.');
        }
        $actor = $this->actors->project((string) $row->user_id);
        $required = ['play'];
        $owned = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if ($actor === null || array_diff($required, $owned) !== []) {
            throw new ExternalPlaybackTicketNotFound('External ticket not found.');
        }
        // 只有实时账号、能力、库范围和文件身份均合法的 GET/HEAD 才启动两小时窗口。该解析不读取正文，
        // Controller 在构造实际响应前会再解析一次，封闭验证与打开媒体之间的替换窗口。
        $this->streams->resolve($actor, (string) $row->song_id);
        if ($row->started_at === null) {
            $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 7200);
            Db::table('external_playback_tickets')->where('id', (string) $row->id)
                ->whereNull('started_at')->where('starts_before', '>', $now)->whereNull('revoked_at')->update([
                    'started_at' => $now, 'expires_at' => $expires, 'last_used_at' => $now,
                ]);
            /** @var stdClass|null $row */
            $row = Db::table('external_playback_tickets')->where('id', (string) $row->id)->first();
            if (!$row instanceof stdClass || $row->started_at === null || $row->revoked_at !== null) {
                throw new ExternalPlaybackTicketNotFound('External ticket not found.');
            }
        }
        $lastUsed = $row->last_used_at === null ? 0 : (strtotime((string) $row->last_used_at) ?: 0);
        if ($lastUsed <= time() - 300) Db::table('external_playback_tickets')->where('id', (string) $row->id)
            ->whereNull('revoked_at')->update(['last_used_at' => $now]);
        return ['actor' => $actor, 'songId' => (string) $row->song_id,
            'format' => (string) $row->output_format, 'mimeType' => (string) $row->mime_type,
            'supportsRange' => (int) $row->supports_range === 1, 'ticketId' => (string) $row->id];
    }

    /** @param list<mixed> $mimeTypes @return array{string,string} */
    private function negotiate(array $actor, PlayableMedia $media, array $mimeTypes): array
    {
        if (in_array($media->mimeType, $mimeTypes, true) && in_array($media->mimeType, self::ALLOWED_MIMES, true)) {
            return ['raw', $media->mimeType];
        }
        foreach ([['audio/mpeg', 'mp3'], ['audio/aac', 'aac'], ['audio/ogg', 'opus']] as [$mime, $format]) {
            if (in_array($mime, $mimeTypes, true)) return [$format, $mime];
        }
        throw new ExternalPlaybackUnavailable('EXTERNAL_OUTPUT_UNAVAILABLE', '没有兼容的接收器音频格式。');
    }

    /**
     * 以同一授权和协商边界解析准备、签发共同使用的不可变播放计划。
     *
     * 方法先拒绝 Web Session、PAT 和畸形 payload，再由统一媒体层实时复验歌曲权限与文件身份，最后只从
     * 固定格式表选择 raw/MP3/AAC/Opus。它不创建票据、不启动 FFmpeg，也不访问接收器；相同输入在媒体
     * ETag 和账号播放能力未变化时结果一致，变化时调用方必须以本次结果为准，不能复用旧计划。
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $payload
     * @return array{PlayableMedia,string,string}
     */
    private function resolvePlan(array $actor, string $songId, array $payload): array
    {
        if (($actor['authenticationType'] ?? null) !== 'app_access_token') {
            throw new ExternalPlaybackUnavailable('APP_ACCESS_TOKEN_REQUIRED', '附近投放需要 App 访问令牌。');
        }
        $this->assertPayload($payload);
        $media = $this->streams->resolve($actor, $songId);
        [$format, $mime] = $this->negotiate($actor, $media, $payload['mimeTypes']);

        return [$media, $format, $mime];
    }

    /** 请求字段完全封闭，避免把设备定位或任意 FFmpeg 参数带入服务端。 */
    private function assertPayload(array $payload): void
    {
        $keys = array_keys($payload);
        sort($keys);
        if (array_is_list($payload) || $keys !== ['mimeTypes', 'protocol', 'supportsRange']
            || !is_string($payload['protocol'] ?? null)
            || !in_array($payload['protocol'], ['dlna', 'google_cast'], true)
            || !is_bool($payload['supportsRange'] ?? null)
            || !is_array($payload['mimeTypes'] ?? null) || !array_is_list($payload['mimeTypes'])
            || $payload['mimeTypes'] === [] || count($payload['mimeTypes']) > 16) {
            throw new ExternalPlaybackUnavailable('EXTERNAL_TICKET_REQUEST_INVALID', '外部播放请求无效。');
        }
        foreach ($payload['mimeTypes'] as $mime) {
            if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIMES, true)) {
                throw new ExternalPlaybackUnavailable('EXTERNAL_TICKET_REQUEST_INVALID', '接收器格式无效。');
            }
        }
    }

    /**
     * 使用统一的显式公开地址生成票据 URL。
     *
     * 缺失或非法配置时失败关闭，绝不采用请求 Host 或客户端输入。HTTP 用于不支持 TLS 的传统局域网
     * Renderer；它会暴露同网监听风险，但不改变票据随机强度、五分钟首用期限和每次取流实时复验。
     */
    private function publicBaseUrl(): string
    {
        try {
            $origin = PublicUrlConfig::externalPlaybackOrigin();
        } catch (\InvalidArgumentException) {
            $origin = null;
        }
        if ($origin === null) {
            throw new ExternalPlaybackUnavailable('EXTERNAL_OUTPUT_UNAVAILABLE', '服务器未提供接收器可达的 HTTP(S) 地址。');
        }
        return $origin;
    }

    private static function digest(string $plain): string
    {
        $key = hash_hkdf('sha256', RequestContext::authenticationHashKey(), 32, 'velin-external-ticket-key-v1');
        return hash_hmac('sha256', "velin-external-ticket-v1\0" . $plain, $key);
    }
}
