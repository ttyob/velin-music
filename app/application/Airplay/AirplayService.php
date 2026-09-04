<?php

declare(strict_types=1);

namespace app\application\Airplay;

use app\application\Dlna\DlnaTicketService;
use app\http\RequestContext;
use app\infrastructure\Airplay\OwnToneClient;
use app\infrastructure\Airplay\RedisAirplayServiceLease;
use app\infrastructure\Audit\AuditLogger;
use support\Log;

/**
 * 编排当前账号到 OwnTone AirPlay 输出的发现、播放和控制。
 *
 * OwnTone 29.3 负责 mDNS、配对状态、音频解码与 AirPlay 1/2 网络传输；本服务负责实时 play+cast 权限、
 * 单队列账号互斥、歌曲库授权、短期媒体票据和脱敏审计。浏览器只看到 `airplay:<数字>` 与展示名，不能
 * 提交 URL、PIN、密码或 OwnTone 参数。OwnTone 的网络副作用无法与 SQLite/Redis 原子提交，失败路径只做
 * 尽力停止、票据撤销和“仅释放本次新建租约”的补偿，绝不重试播放命令。
 */
final readonly class AirplayService
{
    public function __construct(
        private AirplayGateway $gateway = new OwnToneClient(),
        private AirplayServiceLease $lease = new RedisAirplayServiceLease(),
        private DlnaTicketService $tickets = new DlnaTicketService(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 返回当前 OwnTone 实时发现的去地址化 AirPlay 输出。
     *
     * 发现只读取 companion 缓存，不取得播放租约、不创建票据；需要配对的设备仍显示，并用 model 提示，
     * 但 play 会明确失败为 AIRPLAY_PAIRING_REQUIRED。响应不含 IP、mDNS TXT、密码、PIN 或 OwnTone 地址。
     *
     * @return list<array{id:string,name:string,manufacturer:string,model:?string,routeToken:null}>
     */
    public function discover(array $actor): array
    {
        $this->assertAuthorized($actor);
        return array_map(static fn (array $output): array => [
            'id' => 'airplay:' . $output['id'],
            'name' => $output['name'],
            'manufacturer' => 'AirPlay',
            'model' => $output['requiresAuth'] ? '需要配对' : null,
            'routeToken' => null,
        ], $this->gateway->outputs());
    }

    /**
     * 用原始歌曲票据替换 OwnTone 单一队列，并只启用目标输出。
     *
     * 前置条件是 actor 仍有 play+cast 且 output 来自当前发现；媒体票据使用 raw，让 OwnTone 为 AirPlay
     * 协议统一解码/编码，不要求用户额外拥有可选下载转码能力。租约先于输出和队列副作用取得。成功后
     * 票据最长两小时且每次取流继续复验权限；失败撤销票据并尽力 stop，只有本次新建租约会被释放。
     *
     * @return array{succeeded:true,deviceId:string,songId:string,format:'raw'}
     */
    public function play(array $actor, string $deviceId, string $songId, string $requestId): array
    {
        $this->assertAuthorized($actor);
        $outputId = $this->outputId($deviceId);
        $newLease = $this->lease->acquire($actor);
        $ticketId = null;
        try {
            $port = max(1, min(65535, (int) (getenv('VELIN_API_PORT') ?: 8787)));
            $ticket = $this->tickets->create(
                $actor,
                $songId,
                'uuid:airplay-' . $outputId,
                'raw',
                $requestId,
                'http://127.0.0.1:' . $port,
                'airplay',
            );
            $ticketId = $ticket['ticketId'];
            $this->gateway->selectOutput($outputId);
            $this->gateway->playUrl($ticket['url']);
            return ['succeeded' => true, 'deviceId' => $deviceId, 'songId' => $songId, 'format' => 'raw'];
        } catch (\Throwable $failure) {
            if (is_string($ticketId)) {
                try {
                    $this->tickets->revoke($ticketId);
                } catch (\Throwable $compensationFailure) {
                    $this->logCompensationFailure('AirPlay ticket revocation compensation failed.', $compensationFailure, $requestId);
                }
            }
            try {
                $this->gateway->control($outputId, 'stop');
            } catch (\Throwable $compensationFailure) {
                $this->logCompensationFailure('AirPlay stop compensation failed.', $compensationFailure, $requestId);
            }
            if ($newLease) {
                try {
                    $this->lease->release($actor);
                } catch (\Throwable $compensationFailure) {
                    $this->logCompensationFailure('AirPlay lease release compensation failed.', $compensationFailure, $requestId);
                }
            }
            throw $failure;
        }
    }

    /**
     * 控制当前账号占用的 OwnTone 队列，并把状态规范化为播放器共用毫秒契约。
     *
     * status 先只校验租约，观察到 stop 后释放，否则续租；副作用命令先续租再调用 OwnTone。seek 与 volume
     * 已由 Controller 限定范围。stop 成功释放租约；网络成功但审计失败不得重发命令。返回不含队列 URI、
     * OwnTone item_id 或其他输出状态。
     *
     * @return array<string,mixed>
     */
    public function control(
        array $actor,
        string $deviceId,
        string $operation,
        ?int $value,
        string $requestId,
    ): array {
        $this->assertAuthorized($actor);
        $outputId = $this->outputId($deviceId);
        if ($operation === 'status') {
            $newLease = $this->lease->acquire($actor, false);
            try {
                $state = $this->gateway->status($outputId);
            } catch (\Throwable $failure) {
                if ($newLease) $this->lease->release($actor);
                throw $failure;
            }
            if ($state['state'] === 'stop') $this->lease->release($actor);
            else $this->lease->refresh($actor);
            return ['succeeded' => true, 'deviceId' => $deviceId, 'state' => [
                'transportState' => match ($state['state']) {
                    'play' => 'PLAYING',
                    'pause' => 'PAUSED_PLAYBACK',
                    default => 'STOPPED',
                },
                'positionMs' => $state['positionMs'],
                'durationMs' => $state['durationMs'],
                'volume' => $state['volume'],
                'deliveredBytes' => null,
                'totalBytes' => null,
            ]];
        }
        $this->lease->acquire($actor);
        $this->gateway->control($outputId, $operation, $value);
        if ($operation === 'stop') $this->lease->release($actor);
        $this->audit->record(
            (string) $actor['id'],
            'airplay.' . $operation,
            'airplay_output',
            $this->auditId($outputId),
            'success',
            $requestId,
        );
        return ['succeeded' => true, 'deviceId' => $deviceId, 'operation' => $operation];
    }

    /** 默认开箱启用，但每次调用都实时要求 play 与独立 cast。 */
    private function assertAuthorized(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!is_string($actor['id'] ?? null) || !in_array('play', $capabilities, true)
            || !in_array('cast', $capabilities, true)) {
            throw new AirplayUnavailable('AIRPLAY_PERMISSION_DENIED', '账号没有 AirPlay 投放权限。');
        }
    }

    /** 从前端协议 ID 提取 OwnTone 数字 ID，禁止路径、URL 或负数进入 companion 路由。 */
    private function outputId(string $deviceId): string
    {
        if (preg_match('/^airplay:([0-9]{1,20})$/D', $deviceId, $matches) !== 1) {
            throw new AirplayUnavailable('AIRPLAY_DEVICE_INVALID', 'AirPlay 输出标识无效。');
        }
        return $matches[1];
    }

    /** 输出 ID 只以用途分离摘要前缀进入审计，避免审计库保存可枚举设备标识。 */
    private function auditId(string $outputId): string
    {
        return 'airplay-' . substr(hash_hmac(
            'sha256',
            "velin-airplay-output-audit-v1\0" . $outputId,
            RequestContext::authenticationHashKey(),
        ), 0, 26);
    }

    /**
     * 记录投放失败后的尽力补偿异常，同时永远保留触发补偿的原始错误。
     *
     * 日志只包含固定消息、异常类与 requestId，不包含账号、输出 ID、媒体 URL、票据或 OwnTone 响应。
     * logger 自身失败会被丢弃，因为设备副作用已经发生且不能安全重试；票据与租约仍会按有效期自然失效。
     */
    private function logCompensationFailure(string $message, \Throwable $failure, string $requestId): void
    {
        if (getenv('VELIN_TESTING') === '1') return;
        try {
            Log::error($message, ['exception_class' => $failure::class, 'request_id' => $requestId]);
        } catch (\Throwable) {
        }
    }
}
