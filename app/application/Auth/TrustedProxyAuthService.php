<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use support\Request;

/**
 * 把可信代理提供的外部身份严格映射到现有 Velin Music 本地账号。
 *
 * 本服务不自动创建账号、不授予角色/音乐库、不接受代理提供显示名或邮箱。身份只按现有 username
 * 匹配，账号状态和到期时间每次重新检查。所有拒绝对外使用同一错误，审计仅保存稳定原因码，禁止
 * 保存来源 IP、头名、头值或用户名，避免外部身份进入日志侧信道。
 */
final readonly class TrustedProxyAuthService
{
    public function __construct(
        private TrustedProxyAuthConfig $config,
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /** 使用当前部署环境创建服务；错误配置会在处理请求前失败关闭。 */
    public static function fromEnvironment(): self
    {
        return new self(TrustedProxyAuthConfig::fromEnvironment());
    }

    /** 返回匿名可见的最小能力状态，不披露头名或可信网络。 */
    public function status(): array
    {
        return ['enabled' => $this->config->enabled];
    }

    /**
     * 校验请求直连来源和身份头，并返回现有活动账号 ID。
     *
     * Header 只有在来源先通过 CIDR 检查后才读取和解释。成功仅返回内部账号 ID，Session 建立由
     * Controller 在独立边界完成；失败不修改 Session。重复调用可以再次映射同一账号，但每次都会
     * 重新验证账号状态，因此停用即时生效。
     *
     * @throws TrustedProxyAuthDenied 来源、头值、账号或账号状态不满足要求
     * @throws TrustedProxyAuthUnavailable 功能关闭或部署配置不可用
     */
    public function authenticate(Request $request, string $requestId): string
    {
        if (!$this->config->enabled) throw new TrustedProxyAuthUnavailable('可信代理认证未启用。');
        if (!$this->config->trusts($request->getRemoteIp())) {
            $this->denied($requestId, 'untrusted_source');
        }
        $identity = $request->header($this->config->identityHeader);
        if (!is_string($identity)) $this->denied($requestId, 'identity_missing');
        $identity = strtolower(trim($identity));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $identity) !== 1) {
            $this->denied($requestId, 'identity_invalid');
        }

        /** @var stdClass|null $user */
        $user = Db::table('users')->where('username', $identity)
            ->where('status', 'active')->whereNull('deleted_at')
            ->where(static function ($query): void {
                $query->whereNull('account_expires_at')
                    ->orWhere('account_expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'));
            })->first(['id']);
        if (!$user instanceof stdClass) $this->denied($requestId, 'identity_unavailable');
        return (string) $user->id;
    }

    /**
     * 写入脱敏拒绝审计并以统一异常结束流程。
     *
     * reason 是服务端固定枚举，不包含请求数据。审计失败会向上冒泡并使登录失败，避免安全边界在
     * 无审计状态下继续建立 Session；该行为不需要补偿，因为此时尚未修改认证状态。
     */
    private function denied(string $requestId, string $reason): never
    {
        $this->audit->record(null, 'auth.proxy.denied', 'session', null, 'denied', $requestId, ['reason' => $reason]);
        throw new TrustedProxyAuthDenied('外部认证失败。');
    }
}
