<?php

declare(strict_types=1);

namespace app\application\Dlna;

use app\application\System\PublicUrlConfig;
use app\application\System\DlnaSettingsService;
use app\http\RequestContext;
use app\infrastructure\Audit\AuditLogger;
use app\infrastructure\Dlna\RedisDlnaDeviceLease;
use app\infrastructure\Dlna\RedisDlnaDeviceRouteCache;
use support\Log;

/**
 * 编排 DLNA Renderer 发现、短期媒体票据和基础 AVTransport/RenderingControl 命令。
 *
 * 服务可由 CLI 或将来的 HTTP 适配层调用，不依赖浏览器生命周期。调用者必须是实时活动账号并具有
 * play 与 cast；转码投放继承这两项授权。SSDP/SOAP 只由固定 helper 执行，Webman 不接受控制 URL、局域网 IP、
 * 任意 SOAP 或 FFmpeg 参数。play 在设备接收 URI 失败时撤销新票据；设备已经开始播放后无法与 SQLite
 * 组成事务，后续 stop 失败只能报告，不能声称已回滚音响状态。共享 Renderer 使用 Redis 中 90 秒的
 * 账号级短租约协调：同账号所有 Session/标签页复用，不同账号不能静默替换 URI；该租约只约束 Velin
 * Music，无法阻止厂商 App 等外部 DMC 控制设备。发现得到的加密路由令牌在 Redis 中短期缓存，使页面
 * 刷新后也能直接单播读取设备描述；缓存不是授权边界，故障或未命中只会安全回退 SSDP。
 */
final readonly class DlnaService
{
    public function __construct(
        private DlnaHelperClient $helper = new DlnaHelperClient(),
        private DlnaTicketService $tickets = new DlnaTicketService(),
        private AuditLogger $audit = new AuditLogger(),
        private DlnaDeviceLease $leases = new RedisDlnaDeviceLease(),
        private DlnaDeviceRouteCache $routes = new RedisDlnaDeviceRouteCache(),
        private DlnaAccountStateService $accountState = new DlnaAccountStateService(),
        private DlnaSettingsService $settings = new DlnaSettingsService(),
    ) {
    }

    /**
     * 在可信局域网执行一次有界 SSDP 搜索，返回去地址化 Renderer 摘要。
     *
     * @param array<string,mixed> $actor
     * 每个 routeToken 都把 Renderer UDN、可达宿主地址、SSDP Location 和两小时期限用部署密钥加密；
     * 浏览器只把令牌原样交回 play/control，不会看到或选择局域网 IP。令牌无效时失败关闭，CLI 或旧 Web
     * 调用未提供令牌时仍按实时 SSDP 路由回退。
     *
     * @return list<array{id:string,name:string,manufacturer:?string,model:?string,routeToken:?string}>
     */
    public function discover(array $actor): array
    {
        $this->assertEnabledAndAuthorized($actor);
        $payload = $this->helper->invoke(['version' => 1, 'operation' => 'discover', 'timeoutMs' => 3500]);
        // Go JSON 的 omitempty 会在未发现 Renderer 时省略空数组；这与显式 [] 都表示成功的空结果。
        $devices = $payload['devices'] ?? [];
        if (!is_array($devices) || !array_is_list($devices) || count($devices) > 256) {
            throw new DlnaUnavailable('DLNA_PROTOCOL_INVALID', 'DLNA 设备列表无效。');
        }
        $result = [];
        foreach ($devices as $device) {
            if (!is_array($device) || array_is_list($device)
                || preg_match('/^uuid:[A-Za-z0-9._:-]{1,180}$/D', $device['id'] ?? '') !== 1
                || !is_string($device['name'] ?? null) || trim($device['name']) === ''
                || mb_strlen($device['name']) > 200) {
                throw new DlnaUnavailable('DLNA_PROTOCOL_INVALID', 'DLNA 设备字段无效。');
            }
            if (!$this->deviceAllowed($device['id'])) continue;
            $routeToken = is_string($device['localAddress'] ?? null) && is_string($device['location'] ?? null)
                ? $this->sealDeviceRoute($device['id'], $device['localAddress'], $device['location']) : null;
            if ($routeToken !== null) $this->routes->put($device['id'], $routeToken);
            $result[] = [
                'id' => $device['id'],
                'name' => trim($device['name']),
                'manufacturer' => $this->optionalText($device['manufacturer'] ?? null),
                'model' => $this->optionalText($device['model'] ?? null),
                'routeToken' => $routeToken,
            ];
        }
        try {
            $this->accountState->rememberDevices($actor, $result);
        } catch (\Throwable $failure) {
            // 历史是可重建体验数据，发现本身已经成功；记录异常类别但不把 DB 故障伪装成 SSDP 失败。
            $this->logPersistenceFailure('DLNA device history persistence failed.', $failure);
        }
        return $result;
    }

    /**
     * 创建投放票据、设置 Renderer URI 并从受限位置开始播放。
     *
     * 设备租约在创建媒体票据和发送 SetAVTransportURI 前取得，因此不同账号不会消耗转码资源或先改变
     * Renderer 再得知冲突。同账号已有租约视为续租；本次新建租约时，Origin 解析、媒体授权、票据创建
     * 或 helper 失败都会幂等释放。复用租约的失败不能释放整个账号仍在其他标签页使用的设备。
     *
     * @param array<string,mixed> $actor
     * initialPositionMs 只用于同一 Web 播放绑定在未投递区间快进时签发新 URL；helper 会优先在 Play 前
     * Seek，使 Renderer 基于新 URI 建立目标区间连接。位置仍限制到曲库时长，失败撤销新票据且不重试
     * SetURI；pauseAfterPlay 用于保持用户原有暂停意图，成功后活动快照按目标位置和暂停状态原子投影。
     *
     * @return array{succeeded:bool,deviceId:string,songId:string,format:string,positionMs:int}
     */
    public function play(
        array $actor,
        string $deviceId,
        string $songId,
        string $format,
        string $requestId,
        ?string $routeToken = null,
        int $initialPositionMs = 0,
        bool $pauseAfterPlay = false,
    ): array {
        $this->assertEnabledAndAuthorized($actor);
        $this->assertAllowedDevice($deviceId);
        if (!in_array($format, ['raw', 'mp3', 'aac', 'opus'], true)) {
            throw new DlnaUnavailable('DLNA_FORMAT_INVALID', 'DLNA 输出格式无效。');
        }
        if ($initialPositionMs < 0 || $initialPositionMs > 604_800_000) {
            throw new DlnaUnavailable('DLNA_POSITION_INVALID', 'DLNA 起播位置无效。');
        }
        $leaseCreated = $this->leases->acquire($actor, $deviceId);
        $ticket = null;
        try {
            $route = $this->resolveDeviceRoute($deviceId, $routeToken);
            $publicBaseUrl = $this->automaticPublicBaseUrl($deviceId, $route);
            // 自动 Origin 的首次 SSDP 已回填路由；立刻重读可避免紧随其后的 Play 再做第二次发现。
            if ($route === null) $route = $this->resolveDeviceRoute($deviceId, null);
            $ticket = $this->tickets->create(
                $actor,
                $songId,
                $deviceId,
                $format,
                $requestId,
                $publicBaseUrl,
            );
            $mime = match ($format) {
                'mp3' => 'audio/mpeg',
                'aac' => 'audio/aac',
                'opus' => 'audio/ogg',
                default => $ticket['media']->mimeType,
            };
            $positionMs = $ticket['media']->durationMs > 0
                ? min($initialPositionMs, $ticket['media']->durationMs)
                : $initialPositionMs;
            $command = [
                'version' => 1,
                'operation' => 'play',
                'deviceId' => $deviceId,
                'mediaUrl' => $ticket['url'],
                'metadata' => $this->didlMetadata(
                    $ticket['media']->downloadName,
                    $ticket['url'],
                    $mime,
                    $format === 'raw',
                    $format !== 'raw',
                ),
                'timeoutMs' => 7000,
            ];
            if ($positionMs > 0) $command['positionMs'] = $positionMs;
            if ($pauseAfterPlay) $command['pauseAfterPlay'] = true;
            if ($route !== null) $command['deviceLocation'] = $route['location'];
            $payload = $this->helper->invoke($command);
            $this->rememberResolvedRoute($deviceId, $payload);
        } catch (\Throwable $failure) {
            if (is_array($ticket)) {
                try {
                    $this->tickets->revoke($ticket['ticketId']);
                } catch (\Throwable $compensationFailure) {
                    $this->logPersistenceFailure('DLNA ticket revocation compensation failed.', $compensationFailure, $requestId);
                }
            }
            if ($leaseCreated) {
                try {
                    $this->leases->release($actor, $deviceId);
                } catch (\Throwable $compensationFailure) {
                    $this->logPersistenceFailure('DLNA lease release compensation failed.', $compensationFailure, $requestId);
                }
            }
            throw $failure;
        }
        try {
            $this->accountState->activate(
                $actor,
                $deviceId,
                $songId,
                $format,
                is_array($ticket) ? $ticket['media']->durationMs : 0,
            );
            if ($positionMs > 0) $this->accountState->applyControl($actor, $deviceId, 'seek', $positionMs);
            if ($pauseAfterPlay) $this->accountState->applyControl($actor, $deviceId, 'pause', null);
        } catch (\Throwable $failure) {
            // Renderer 已确认 Play，不能因恢复快照写入失败撤销票据或向调用方谎报投放失败。
            $this->logPersistenceFailure('DLNA active playback persistence failed.', $failure, $requestId);
        }
        return [
            'succeeded' => true,
            'deviceId' => $deviceId,
            'songId' => $songId,
            'format' => $format,
            'positionMs' => $positionMs,
        ];
    }

    /**
     * 执行 pause/resume/stop/seek/volume/status；命令不修改队列或播放统计。
     *
     * 所有动作先原子取得或复用当前账号的设备租约，冲突时不会调用 helper。status 初次只建立租约而不
     * 延长已有租约，确认设备仍非 STOPPED 后才续租；STOPPED 和成功 stop 会释放。其他成功命令续租；
     * 若本次刚创建租约但 helper 失败，则释放该新占用，避免一次不可达设备阻塞其他账号 90 秒。已有
     * 租约遇到不确定失败时保留到自然过期，避免另一个账号在首个命令实际已生效时立即覆盖设备。
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function control(
        array $actor,
        string $deviceId,
        string $operation,
        ?int $value,
        string $requestId,
        ?string $routeToken = null,
    ): array {
        $this->assertEnabledAndAuthorized($actor);
        $this->assertAllowedDevice($deviceId);
        if (!in_array($operation, ['pause', 'resume', 'stop', 'seek', 'volume', 'status'], true)) {
            throw new DlnaUnavailable('DLNA_OPERATION_INVALID', 'DLNA 控制动作无效。');
        }
        $command = ['version' => 1, 'operation' => $operation, 'deviceId' => $deviceId, 'timeoutMs' => 7000];
        if ($operation === 'seek') {
            if ($value === null || $value < 0 || $value > 604_800_000) {
                throw new DlnaUnavailable('DLNA_POSITION_INVALID', 'DLNA 跳转位置无效。');
            }
            $command['positionMs'] = $value;
        } elseif ($operation === 'volume') {
            if ($value === null || $value < 0 || $value > 100) {
                throw new DlnaUnavailable('DLNA_VOLUME_INVALID', 'DLNA 音量无效。');
            }
            $command['volume'] = $value;
        } elseif ($value !== null) {
            throw new DlnaUnavailable('DLNA_OPERATION_INVALID', '当前 DLNA 动作不接受数值。');
        }
        $leaseCreated = $this->leases->acquire($actor, $deviceId, $operation !== 'status');
        try {
            $route = $this->resolveDeviceRoute($deviceId, $routeToken);
            if ($route !== null) $command['deviceLocation'] = $route['location'];
            $payload = $this->helper->invoke($command);
            $this->rememberResolvedRoute($deviceId, $payload);
        } catch (\Throwable $failure) {
            if ($leaseCreated) {
                try {
                    $this->leases->release($actor, $deviceId);
                } catch (\Throwable $compensationFailure) {
                    $this->logPersistenceFailure('DLNA lease release compensation failed.', $compensationFailure, $requestId);
                }
            }
            throw $failure;
        }
        if ($operation === 'status') {
            try {
                $state = $this->state($payload['state'] ?? null);
            } catch (\Throwable $failure) {
                if ($leaseCreated) {
                    try {
                        $this->leases->release($actor, $deviceId);
                    } catch (\Throwable $compensationFailure) {
                        $this->logPersistenceFailure('DLNA lease release compensation failed.', $compensationFailure, $requestId);
                    }
                }
                throw $failure;
            }
            if ($state['transportState'] === 'STOPPED') {
                $this->leases->release($actor, $deviceId);
            } else {
                $this->leases->refresh($actor, $deviceId);
            }
            try {
                if ($state['transportState'] === 'STOPPED') $this->accountState->deactivate($actor, $deviceId);
                else $this->accountState->observe($actor, $deviceId, $state);
            } catch (\Throwable $failure) {
                $this->logPersistenceFailure('DLNA renderer snapshot persistence failed.', $failure, $requestId);
            }
            return [
                'succeeded' => true,
                'deviceId' => $deviceId,
                'state' => [...$state, ...$this->tickets->deliveryProgress($actor, $deviceId)],
            ];
        }
        if ($operation === 'stop') {
            $this->leases->release($actor, $deviceId);
        }
        try {
            if ($operation === 'stop') $this->accountState->deactivate($actor, $deviceId);
            else $this->accountState->applyControl($actor, $deviceId, $operation, $value);
        } catch (\Throwable $failure) {
            // SOAP 已成功，持久化失败不得触发同一非幂等控制命令重试。
            $this->logPersistenceFailure('DLNA control snapshot persistence failed.', $failure, $requestId);
        }
        $this->audit->record((string) $actor['id'], 'dlna.' . $operation, 'dlna_renderer',
            $this->deviceAuditId($deviceId), 'success', $requestId);
        return ['succeeded' => true, 'deviceId' => $deviceId, 'operation' => $operation];
    }

    /**
     * 返回当前账号的服务端设备历史、最后格式与可恢复活动快照。
     *
     * 方法先实时要求 play+cast，随后由账号状态服务重新验证活动歌曲的音乐库授权和文件身份。返回值不含
     * routeToken、设备地址、媒体 URL 或票据；调用者恢复绑定后仍必须执行 status，不能把 SQLite 快照当作
     * Renderer 当前在线证明。本方法不取得设备租约，也不触发 SSDP/SOAP。
     *
     * @param array<string,mixed> $actor
     * @return array{devices:list<array<string,mixed>>,preferredFormat:string,playback:?array<string,mixed>}
     */
    public function session(array $actor): array
    {
        $this->assertEnabledAndAuthorized($actor);
        return $this->accountState->snapshot($actor);
    }

    /**
     * 记录不可回滚的恢复快照写入失败，同时避免测试引导尚未安装 Webman 日志处理器时遮蔽原行为。
     *
     * 日志只包含稳定消息、异常类和可空 requestId，不记录账号、UDN、歌曲、地址、令牌或数据库正文。
     * `VELIN_TESTING` 下测试会直接验证主要命令结果，跳过日志副作用；生产日志失败不应触发设备命令重试。
     */
    private function logPersistenceFailure(string $message, \Throwable $failure, ?string $requestId = null): void
    {
        if (getenv('VELIN_TESTING') === '1') return;
        $context = ['exception_class' => $failure::class];
        if ($requestId !== null && $requestId !== '') $context['request_id'] = $requestId;
        try {
            Log::error($message, $context);
        } catch (\Throwable) {
        }
    }

    /**
     * 生成带真实 Seek 能力的最小 DIDL-Lite，所有文本与 URL 均 XML 转义。
     *
     * raw 与取流响应一致声明 byte Range（OP=01、CI=0），使依赖 DIDL 而不只读取 HTTP 响应头的 Renderer
     * 能在 REL_TIME 后重新发起 Range；整文件转码当前不支持 Range，必须声明 OP=00、CI=1，不能虚假扩大
     * 能力。参数都来自服务端媒体解析和固定格式映射，URL 仍是短期票据，方法无网络或持久化副作用。
     */
    private function didlMetadata(
        string $title,
        string $url,
        string $mime,
        bool $supportsByteRange = true,
        bool $converted = false,
    ): string
    {
        $escape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $features = 'DLNA.ORG_OP=' . ($supportsByteRange ? '01' : '00')
            . ';DLNA.ORG_CI=' . ($converted ? '1' : '0')
            . ';DLNA.ORG_FLAGS=01700000000000000000000000000000';
        return '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">'
            . '<item id="0" parentID="-1" restricted="1"><dc:title>' . $escape($title) . '</dc:title>'
            . '<upnp:class>object.item.audioItem.musicTrack</upnp:class>'
            . '<res protocolInfo="http-get:*:' . $escape($mime) . ':' . $escape($features) . '">'
            . $escape($url) . '</res>'
            . '</item></DIDL-Lite>';
    }

    /** @return array{transportState:string,positionMs:int,durationMs:int,volume:?int} */
    private function state(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)
            || !is_string($value['transportState'] ?? null)
            || !is_int($value['positionMs'] ?? null) || $value['positionMs'] < 0
            || !is_int($value['durationMs'] ?? null) || $value['durationMs'] < 0
            || (isset($value['volume']) && (!is_int($value['volume'])
                || $value['volume'] < 0 || $value['volume'] > 100))) {
            throw new DlnaUnavailable('DLNA_PROTOCOL_INVALID', 'DLNA 状态响应无效。');
        }
        return [
            'transportState' => substr($value['transportState'], 0, 40),
            'positionMs' => $value['positionMs'],
            'durationMs' => $value['durationMs'],
            'volume' => $value['volume'] ?? null,
        ];
    }

    /** 全站开关与账号播放/投放能力必须同时满足；任何一项撤销都会在下一次命令立即生效。 */
    private function assertEnabledAndAuthorized(array $actor): void
    {
        $this->settings->assertEnabled();
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!is_string($actor['id'] ?? null) || !in_array('play', $capabilities, true)
            || !in_array('cast', $capabilities, true)) {
            throw new DlnaUnavailable('DLNA_PERMISSION_DENIED', '账号没有播放或投放权限。');
        }
    }

    /**
     * 根据操作系统前往目标 Renderer 的实际路由生成开箱即用的 HTTP Origin。
     *
     * helper 只解析本次 SSDP 发现到的设备，不接受调用方 URL；返回值必须是非保留 IP。端口复用 Webman
     * 当前监听端口，Compose host 网络下即为宿主端口。多网卡或 HTTPS 代理部署仍可通过统一公开 Origin
     * 覆盖；配置非法时不会静默回退自动地址，避免部署错误把票据发往非预期接口。
     */
    private function automaticPublicBaseUrl(string $deviceId, ?array $route = null): string
    {
        try {
            $configured = PublicUrlConfig::publicOrigin();
        } catch (\InvalidArgumentException) {
            throw new DlnaUnavailable('DLNA_PUBLIC_URL_INVALID', 'DLNA 对外地址未正确配置。');
        }
        if ($configured !== null) return $configured;
        if ($route !== null) return $this->baseUrlForAddress($route['address']);
        $payload = $this->helper->invoke([
            'version' => 1,
            'operation' => 'resolve-origin',
            'deviceId' => $deviceId,
            'timeoutMs' => 7000,
        ]);
        $this->rememberResolvedRoute($deviceId, $payload);
        $address = $payload['localAddress'] ?? null;
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new DlnaUnavailable('DLNA_PUBLIC_URL_INVALID', 'DLNA 自动地址无效。');
        }
        if (preg_match('/^(?:0|127|169\.254)\./D', $address) === 1
            || in_array($address, ['::', '::1'], true) || str_starts_with(strtolower($address), 'fe80:')) {
            throw new DlnaUnavailable('DLNA_PUBLIC_URL_INVALID', 'DLNA 自动地址不可由音响访问。');
        }
        return $this->baseUrlForAddress($address);
    }

    /**
     * 把 helper 在 SSDP 回退期间得到的可信路由重新密封并写回短期缓存。
     *
     * localAddress 与 deviceLocation 都来自 helper 已按 Renderer UDN 选中的 RootDevice，不接受浏览器明文；
     * 两者必须再次通过 PHP 私网校验才会写 Redis。缺字段或校验失败只放弃性能缓存，不改变已经成功的设备
     * 命令，也不持久化到 SQLite、响应或日志。相同 UDN 的并发写是幂等覆盖，令牌自然续期两小时。
     *
     * @param array<string,mixed> $payload 已通过 DlnaHelperClient 根协议校验的内部响应
     */
    private function rememberResolvedRoute(string $deviceId, array $payload): void
    {
        $address = $payload['localAddress'] ?? null;
        $location = $payload['deviceLocation'] ?? null;
        if (!is_string($address) || !is_string($location)) return;
        $token = $this->sealDeviceRoute($deviceId, $address, $location);
        if ($token !== null) $this->routes->put($deviceId, $token);
    }

    /** 把已验证的单播地址转换为当前 Webman 监听 Origin；地址不来自浏览器明文字段。 */
    private function baseUrlForAddress(string $address): string
    {
        $port = (int) (getenv('VELIN_API_PORT') ?: 8787);
        if ($port < 1 || $port > 65535) {
            throw new DlnaUnavailable('DLNA_PUBLIC_URL_INVALID', 'DLNA 服务端口无效。');
        }
        $host = str_contains($address, ':') ? '[' . $address . ']' : $address;
        return 'http://' . $host . ':' . $port;
    }

    /**
     * 把发现阶段的可达地址封装为短期不透明令牌。
     *
     * 令牌使用现有认证部署密钥经用途分离后执行 secretbox 加密，浏览器不能读取或篡改本机 IP、Renderer
     * Location 或 UDN。随机 nonce 使同一设备每次发现也得到不同令牌；两小时期限覆盖一次长音频播放，
     * 浏览器只在当前页面内存持有；服务端另把同一密文短期写入 Redis，使刷新或同账号其他标签页无需
     * 暴露地址也能复用。Location 让音量、状态和播放命令直接单播读取设备描述，避免每次等待 SSDP；
     * Go helper 仍会复验私网目标和根 UDN。该操作不写业务数据库，失败只影响快速路由，调用方仍可把
     * routeToken 投影为 null 或安全回退发现。
     */
    private function sealDeviceRoute(string $deviceId, string $address, string $location): ?string
    {
        if (!$this->usableRouteAddress($address) || !$this->usableDeviceLocation($location)) return null;
        try {
            $plain = json_encode(['v' => 2, 'd' => $deviceId, 'a' => $address, 'l' => $location, 'e' => time() + 7200],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $this->routeTokenKey());
            return rtrim(strtr(base64_encode($nonce . $cipher), '+/', '-_'), '=');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 解开并校验路由令牌，返回仅供服务内部调用的本机地址与 Renderer Location。
     *
     * 设备不匹配、过期、损坏、非私网 Location 或不可达本机地址统一按无效请求失败；调用方不能在失败
     * 后采用令牌内部分字段，也不能把解密结果写日志或响应。Go helper 会再次校验 Location 和实际 UDN。
     *
     * @return array{address:string,location:string}
     */
    private function openDeviceRoute(string $deviceId, string $token): array
    {
        $encoded = strtr($token, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $binary = strlen($token) <= 1024 ? base64_decode($encoded, true) : false;
        $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (!is_string($binary) || strlen($binary) <= $nonceSize) {
            throw new DlnaUnavailable('DLNA_ROUTE_TOKEN_INVALID', 'DLNA 路由令牌无效。');
        }
        $plain = sodium_crypto_secretbox_open(substr($binary, $nonceSize), substr($binary, 0, $nonceSize),
            $this->routeTokenKey());
        $payload = is_string($plain) ? json_decode($plain, true) : null;
        $address = is_array($payload) ? ($payload['a'] ?? null) : null;
        $location = is_array($payload) ? ($payload['l'] ?? null) : null;
        if (($payload['v'] ?? null) !== 2 || ($payload['d'] ?? null) !== $deviceId
            || !is_int($payload['e'] ?? null) || $payload['e'] < time()
            || !is_string($address) || !$this->usableRouteAddress($address)
            || !is_string($location) || !$this->usableDeviceLocation($location)) {
            throw new DlnaUnavailable('DLNA_ROUTE_TOKEN_INVALID', 'DLNA 路由令牌无效。');
        }
        return ['address' => $address, 'location' => $location];
    }

    /**
     * 选择显式路由令牌或服务端缓存，并返回已经解密校验的内部路由。
     *
     * 浏览器显式提交的令牌属于请求输入：损坏、过期或设备不匹配必须失败关闭，不能悄悄改走 SSDP；通过
     * 校验后可回写服务端缓存供刷新后的请求复用。请求未带令牌时才读取 Redis；缓存令牌无效表示可丢失
     * 数据损坏或自然过期，必须删除并返回 null，由既有 helper 安全执行 SSDP。无论命中与否，本方法都不
     * 改变实时权限、设备白名单或账号租约，也不把 IP、Location 或令牌写入日志和响应。
     *
     * @return array{address:string,location:string}|null
     */
    private function resolveDeviceRoute(string $deviceId, ?string $routeToken): ?array
    {
        if ($routeToken !== null && $routeToken !== '') {
            $route = $this->openDeviceRoute($deviceId, $routeToken);
            $this->routes->put($deviceId, $routeToken);
            return $route;
        }
        $cached = $this->routes->get($deviceId);
        if ($cached === null) return null;
        try {
            return $this->openDeviceRoute($deviceId, $cached);
        } catch (DlnaUnavailable $failure) {
            $this->routes->forget($deviceId);
            return null;
        }
    }

    /** 路由令牌使用独立 HKDF 上下文，不能与登录摘要、票据或设备审计摘要互换。 */
    private function routeTokenKey(): string
    {
        return hash_hkdf('sha256', RequestContext::authenticationHashKey(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            'velin-dlna-route-token-v1');
    }

    /** 自动地址只允许另一台局域网设备可访问的单播 IP。 */
    private function usableRouteAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && preg_match('/^(?:0|127|169\.254)\./D', $address) !== 1
            && !in_array($address, ['::', '::1'], true)
            && !str_starts_with(strtolower($address), 'fe80:');
    }

    /**
     * 限制加密令牌中的 SSDP Location 为明确的 RFC1918/ULA 单播 HTTP 地址。
     *
     * Location 原始值来自 helper 的发现响应而非浏览器；此处仍执行独立校验，避免协议回归把公网、主机名、
     * 凭据或片段签入令牌。路径和查询由设备固件定义，可保留；最终单播请求仍由 Go 校验并绑定 UDN。
     */
    private function usableDeviceLocation(string $location): bool
    {
        if ($location === '' || strlen($location) > 1024) return false;
        $parts = parse_url($location);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http'
            || !is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])) return false;
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) return false;
        $private = preg_match('/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/D', $host) === 1
            || preg_match('/^(?:fc|fd)[0-9a-f]{2}:/iD', $host) === 1;
        if (!$private) return false;
        $port = $parts['port'] ?? null;
        return $port === null || (is_int($port) && $port >= 1 && $port <= 65535);
    }

    /** 可选设备白名单为空时允许发现到的 Renderer；配置后只接受精确 UDN。 */
    private function deviceAllowed(string $deviceId): bool
    {
        $configured = trim((string) getenv('VELIN_DLNA_DEVICE_IDS'));
        if ($configured === '') return true;
        $allowed = array_values(array_filter(array_map('trim', explode(',', $configured))));
        return in_array($deviceId, $allowed, true);
    }

    private function assertAllowedDevice(string $deviceId): void
    {
        if (preg_match('/^uuid:[A-Za-z0-9._:-]{1,180}$/D', $deviceId) !== 1 || !$this->deviceAllowed($deviceId)) {
            throw new DlnaUnavailable('DLNA_DEVICE_NOT_ALLOWED', 'DLNA 设备不在允许范围内。');
        }
    }

    private function optionalText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 200) : null;
    }

    /** 设备 UDN 只以用途分离摘要前缀进入审计目标，不保存可反查的局域网设备标识。 */
    private function deviceAuditId(string $deviceId): string
    {
        return 'dlna-' . substr(hash_hmac('sha256', "velin-dlna-device-audit-v1\0" . $deviceId,
            RequestContext::authenticationHashKey()), 0, 26);
    }
}
